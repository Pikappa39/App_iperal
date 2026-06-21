<?php
require __DIR__ . '/app_config.php';
require __DIR__ . '/session_bootstrap.php';
app_session_start();

$capo = (int) ($_SESSION["user"]["capo"] ?? 0);
if (!isset($_SESSION["user"]) || !in_array($capo, [1, 3], true)) {
    header("Location: index.php");
    exit;
}

$repartoCode = (string) ($_SESSION['user']['reparto'] ?? '');
$repartoLabel = appDepartments()[$repartoCode] ?? 'non assegnato';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload turni</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
    <div class="container">
        <h1 class="mb-4">Carica file turni</h1>
        <p class="text-muted">I file caricati verranno salvati solo per il reparto: <strong><?php echo htmlspecialchars($repartoLabel, ENT_QUOTES, 'UTF-8'); ?></strong>.</p>

        <form id="uploadForm" class="d-grid gap-3" enctype="multipart/form-data">
            <input type="file" id="excelFiles" name="excelFiles[]" class="form-control" accept=".xlsx" multiple required>
            <button type="submit" id="submitUpload" class="btn btn-primary">Analizza file e associa nominativi</button>
        </form>

        <div id="mappingPanel" class="card mt-4" hidden>
            <div class="card-body">
                <h2 class="h5">Associa i nominativi</h2>
                <p class="text-muted mb-3">Il file Excel non contiene il codice fiscale. Seleziona l'utente corretto per ogni nominativo: la scelta verrà ricordata per i prossimi caricamenti del tuo reparto.</p>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Nominativo nel file</th><th>Utente registrato</th></tr></thead>
                        <tbody id="mappingRows"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="status" class="mt-3"></div>

        <button type="button" id="back" class="btn btn-secondary mt-4">Indietro</button>
    </div>

    <script>
        const form = document.getElementById("uploadForm");
        const statusBox = document.getElementById("status");
        const back = document.getElementById("back");
        const submitUpload = document.getElementById("submitUpload");
        const mappingPanel = document.getElementById("mappingPanel");
        const mappingRows = document.getElementById("mappingRows");
        let readyToUpload = false;

        function showError(message) {
            statusBox.innerHTML = "";
            const alert = document.createElement("div");
            alert.className = "alert alert-danger";
            alert.textContent = message;
            statusBox.appendChild(alert);
        }

        async function sendForm(mode, mappings = null) {
            const formData = new FormData(form);
            formData.append("mode", mode);
            if (mappings !== null) {
                formData.append("mappings", JSON.stringify(mappings));
            }

            const res = await fetch("connection_files/upload.php", {
                method: "POST",
                body: formData,
                cache: "no-cache"
            });
            const text = await res.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch {
                throw new Error(text || "Risposta non valida");
            }
            if (!res.ok || data.ok === false) {
                throw new Error(data.error || "Errore upload");
            }
            return data;
        }

        function renderMappings(data) {
            mappingRows.innerHTML = "";
            const users = Array.isArray(data.users) ? data.users : [];

            (data.names || []).forEach((entry) => {
                const tr = document.createElement("tr");
                const nameCell = document.createElement("td");
                nameCell.textContent = entry.name;

                const userCell = document.createElement("td");
                const select = document.createElement("select");
                select.className = "form-select schedule-mapping";
                select.dataset.key = entry.key;
                select.required = true;

                const placeholder = document.createElement("option");
                placeholder.value = "";
                placeholder.textContent = "Seleziona utente…";
                select.appendChild(placeholder);

                users.forEach((user) => {
                    const option = document.createElement("option");
                    option.value = user.cod_fiscale;
                    option.textContent = `${user.nome} ${user.cognome} (${user.cod_fiscale})`;
                    option.selected = user.cod_fiscale === entry.userCf;
                    select.appendChild(option);
                });

                userCell.appendChild(select);
                tr.append(nameCell, userCell);
                mappingRows.appendChild(tr);
            });

            mappingPanel.hidden = false;
            readyToUpload = true;
            submitUpload.textContent = "Salva associazioni e carica turni";
        }

        function getMappings() {
            const mappings = {};
            const selects = mappingRows.querySelectorAll(".schedule-mapping");
            for (const select of selects) {
                if (!select.value) {
                    select.focus();
                    throw new Error("Scegli un utente per ogni nominativo.");
                }
                mappings[select.dataset.key] = select.value;
            }
            return mappings;
        }

        form.addEventListener("submit", async (e) => {
            e.preventDefault();

            submitUpload.disabled = true;

            try {
                if (!readyToUpload) {
                    statusBox.textContent = "Analisi del file in corso...";
                    const data = await sendForm("preview");
                    renderMappings(data);
                    statusBox.textContent = "Controlla le associazioni e conferma il caricamento.";
                    return;
                }

                statusBox.textContent = "Caricamento in corso...";
                const data = await sendForm("upload", getMappings());
                const items = (data.results || []).map((item) => {
                    if (item.error) {
                        return '<li class="text-danger">' + item.file + ': ' + item.error + '</li>';
                    }
                    const historyCount = item.history && Number.isFinite(Number(item.history.stored))
                        ? ' - storico: ' + Number(item.history.stored) + ' modifiche'
                        : '';
                    return '<li>' + item.file + ' -> ' + item.output + ' (' + item.righe + ' righe)' + historyCount + '</li>';
                }).join('');

                statusBox.innerHTML = '<div class="alert alert-success"><ul class="mb-0">' + items + '</ul></div>';
            } catch (error) {
                showError(error.message);
            } finally {
                submitUpload.disabled = false;
            }
        });

        document.getElementById("excelFiles").addEventListener("change", () => {
            readyToUpload = false;
            mappingPanel.hidden = true;
            mappingRows.innerHTML = "";
            submitUpload.textContent = "Analizza file e associa nominativi";
            statusBox.innerHTML = "";
        });

        back.addEventListener("click", () => {
            window.location.href = "index.php";
        });
    </script>
</body>
</html>

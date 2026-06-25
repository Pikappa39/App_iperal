<?php
require __DIR__ . '/app_config.php';
require __DIR__ . '/session_bootstrap.php';
app_session_start();

$capo = (int) ($_SESSION["user"]["capo"] ?? 0);
if (!isset($_SESSION["user"]) || !in_array($capo, [1, 2, 3], true)) {
    header("Location: index.php");
    exit;
}

$repartoCode = (string) ($_SESSION['user']['reparto'] ?? '');
$repartoLabel = appDepartments()[$repartoCode] ?? 'non assegnato';
$isGlobalAdmin = $capo === 3;
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload turni</title>
    <script>
    (function () {
        try {
            document.documentElement.dataset.theme = localStorage.getItem("app-iperal-theme") === "dark" ? "dark" : "light";
        } catch (error) {
            document.documentElement.dataset.theme = "light";
        }
    })();
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="sfera.css?v=<?php echo rawurlencode(APP_VERSION); ?>">
</head>
<body class="p-4 upload-page">
    <div class="container">
        <h1 class="mb-4">Carica file turni</h1>
        <?php if ($isGlobalAdmin): ?>
            <p class="text-muted">Seleziona il reparto di destinazione prima di analizzare e caricare i file.</p>
        <?php else: ?>
            <p class="text-muted">I file caricati verranno salvati solo per il reparto: <strong><?php echo htmlspecialchars($repartoLabel, ENT_QUOTES, 'UTF-8'); ?></strong>.</p>
        <?php endif; ?>

        <form id="uploadForm" class="d-grid gap-3" enctype="multipart/form-data">
            <?php if ($isGlobalAdmin): ?>
                <div>
                    <label class="form-label" for="reparto">Reparto di destinazione</label>
                    <select id="reparto" name="reparto" class="form-select" required>
                        <option value="" selected disabled>Seleziona reparto</option>
                        <?php foreach (appDepartments() as $code => $label): ?>
                            <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $code === $repartoCode ? ' selected' : ''; ?>>
                                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <input type="hidden" name="reparto" value="<?php echo htmlspecialchars($repartoCode, ENT_QUOTES, 'UTF-8'); ?>">
            <?php endif; ?>
            <input type="file" id="excelFiles" name="excelFiles[]" class="form-control" accept=".xlsx" multiple required>
            <button type="submit" id="submitUpload" class="btn btn-primary">Analizza file e associa nominativi</button>
        </form>

        <div id="mappingPanel" class="card mt-4" hidden>
            <div class="card-body">
                <h2 class="h5">Associa i nominativi</h2>
                <p class="text-muted mb-3">Questi nominativi non hanno ancora un'associazione salvata. Seleziona l'utente corretto per ciascuno. Se una persona non usa l'app, scegli “Utente non registrato”.</p>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="markAllUnregistered">
                    <label class="form-check-label" for="markAllUnregistered">Segna tutti come “Utente non registrato”</label>
                </div>
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

    <script>window.appCsrfToken = <?php echo json_encode(app_csrf_token()); ?>;</script>
    <script>
        const form = document.getElementById("uploadForm");
        const statusBox = document.getElementById("status");
        const back = document.getElementById("back");
        const submitUpload = document.getElementById("submitUpload");
        const mappingPanel = document.getElementById("mappingPanel");
        const mappingRows = document.getElementById("mappingRows");
        const markAllUnregistered = document.getElementById("markAllUnregistered");
        const repartoField = document.getElementById("reparto");
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
            formData.append("csrf_token", window.appCsrfToken || "");
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
            markAllUnregistered.checked = false;
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

                const unregistered = document.createElement("option");
                unregistered.value = "__UNREGISTERED__";
                unregistered.textContent = "Utente non registrato";
                select.appendChild(unregistered);

                users.forEach((user) => {
                    const option = document.createElement("option");
                    option.value = user.cod_fiscale;
                    option.textContent = `${user.nome} ${user.cognome}`;
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

        function showUploadResult(data) {
            statusBox.innerHTML = "";
            const alert = document.createElement("div");
            alert.className = "alert alert-success";
            const list = document.createElement("ul");
            list.className = "mb-0";

            (data.results || []).forEach((item) => {
                const row = document.createElement("li");
                if (item.error) {
                    row.className = "text-danger";
                    row.textContent = `${item.file || "File"}: ${item.error}`;
                } else {
                    const historyCount = item.history && Number.isFinite(Number(item.history.stored))
                        ? ` - storico: ${Number(item.history.stored)} modifiche`
                        : "";
                    row.textContent = `${item.file || "File"} → ${item.output || ""} (${item.righe || 0} righe)${historyCount}`;
                }
                list.appendChild(row);
            });

            alert.appendChild(list);
            statusBox.appendChild(alert);
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
                    if (!(data.names || []).length) {
                        statusBox.textContent = "Tutti i nominativi sono già associati. Caricamento in corso...";
                        const uploadData = await sendForm("upload", {});
                        showUploadResult(uploadData);
                        return;
                    }
                    renderMappings(data);
                    statusBox.textContent = "Controlla le associazioni e conferma il caricamento.";
                    return;
                }

                statusBox.textContent = "Caricamento in corso...";
                const data = await sendForm("upload", getMappings());
                showUploadResult(data);
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
            markAllUnregistered.checked = false;
            submitUpload.textContent = "Analizza file e associa nominativi";
            statusBox.innerHTML = "";
        });

        if (repartoField) {
            repartoField.addEventListener("change", () => {
                readyToUpload = false;
                mappingPanel.hidden = true;
                mappingRows.innerHTML = "";
                markAllUnregistered.checked = false;
                submitUpload.textContent = "Analizza file e associa nominativi";
                statusBox.innerHTML = "";
            });
        }

        markAllUnregistered.addEventListener("change", () => {
            if (!markAllUnregistered.checked) {
                return;
            }
            mappingRows.querySelectorAll(".schedule-mapping").forEach((select) => {
                select.value = "__UNREGISTERED__";
            });
        });

        back.addEventListener("click", () => {
            window.location.href = "index.php";
        });
    </script>
</body>
</html>

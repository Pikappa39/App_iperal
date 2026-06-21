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
            <button type="submit" class="btn btn-primary">Carica e converti</button>
        </form>

        <div id="status" class="mt-3"></div>

        <button type="button" id="back" class="btn btn-secondary mt-4">Indietro</button>
    </div>

    <script>
        const form = document.getElementById("uploadForm");
        const statusBox = document.getElementById("status");
        const back = document.getElementById("back");

        form.addEventListener("submit", async (e) => {
            e.preventDefault();

            const formData = new FormData(form);

            statusBox.innerHTML = "Caricamento in corso...";

            try {
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
                    statusBox.innerHTML = '<div class="alert alert-danger">' + (data.error || "Errore upload") + '</div>';
                    return;
                }

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
                statusBox.innerHTML = '<div class="alert alert-danger">' + error.message + '</div>';
            }
        });

        back.addEventListener("click", () => {
            window.location.href = "index.php";
        });
    </script>
</body>
</html>

<?php
require __DIR__ . '/modules/schedules/php/upload/upload_page_context.php';

$uploadContext = appScheduleUploadPageContext();
extract($uploadContext, EXTR_SKIP);
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
            <p class="text-muted">I file caricati verranno salvati solo per il reparto: <strong><?php echo appScheduleUploadEscape($repartoLabel); ?></strong>.</p>
        <?php endif; ?>

        <form id="uploadForm" class="d-grid gap-3" enctype="multipart/form-data">
            <?php if ($isGlobalAdmin): ?>
                <div>
                    <label class="form-label" for="reparto">Reparto di destinazione</label>
                    <select id="reparto" name="reparto" class="form-select" required>
                        <option value="" selected disabled>Seleziona reparto</option>
                        <?php foreach ($departments as $code => $label): ?>
                            <option value="<?php echo appScheduleUploadEscape((string) $code); ?>"<?php echo $code === $repartoCode ? ' selected' : ''; ?>>
                                <?php echo appScheduleUploadEscape((string) $label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <input type="hidden" name="reparto" value="<?php echo appScheduleUploadEscape($repartoCode); ?>">
            <?php endif; ?>
            <input type="file" id="excelFiles" name="excelFiles[]" class="form-control" accept=".xlsx" multiple required>
            <button type="submit" id="submitUpload" class="btn btn-primary">Analizza file e associa nominativi</button>
        </form>

        <div id="mappingPanel" class="card mt-4" hidden>
            <div class="card-body">
                <h2 class="h5">Associa i nominativi</h2>
                <p class="text-muted mb-3">Questi nominativi non hanno ancora un'associazione salvata. Seleziona l'utente corretto per ciascuno. Se una persona non usa l'app, scegli &quot;Utente non registrato&quot;.</p>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="markAllUnregistered">
                    <label class="form-check-label" for="markAllUnregistered">Segna tutti come &quot;Utente non registrato&quot;</label>
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
    <script>
    window.appScheduleUploadConfig = {
        csrfToken: <?php echo json_encode($csrfToken); ?>,
        endpoint: "connection_files/upload.php",
        backUrl: "index.php"
    };
    </script>
    <script src="assets/js/modules/schedules/upload-page.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
</body>
</html>

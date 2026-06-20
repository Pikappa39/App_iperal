<?php
use PhpOffice\PhpSpreadsheet\IOFactory;

header("Content-Type: application/json; charset=utf-8");

require __DIR__ . '/../session_bootstrap.php';
app_session_start();

$capo = (int) ($_SESSION["user"]["capo"] ?? 0);
if (!isset($_SESSION["user"]) || !in_array($capo, [1, 3], true)) {
    http_response_code(403);
    echo json_encode([
        "ok" => false,
        "error" => "Accesso negato",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../gestore_ods/orario_converter_lib.php';
require __DIR__ . '/connection.php';
require __DIR__ . '/push_lib.php';

$outputDir = __DIR__ . '/../turni_json';

if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Impossibile creare la cartella JSON",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_FILES["excelFiles"])) {
    http_response_code(400);
    echo json_encode([
        "ok" => false,
        "error" => "Nessun file ricevuto",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$files = $_FILES["excelFiles"];
$names = is_array($files["name"]) ? $files["name"] : [$files["name"]];
$tmpNames = is_array($files["tmp_name"]) ? $files["tmp_name"] : [$files["tmp_name"]];
$errors = is_array($files["error"]) ? $files["error"] : [$files["error"]];

$results = [];

foreach ($tmpNames as $index => $tmpName) {
    $originalName = $names[$index] ?? '';
    $uploadError = $errors[$index] ?? UPLOAD_ERR_NO_FILE;

    if ($uploadError !== UPLOAD_ERR_OK) {
        $results[] = [
            "file" => $originalName,
            "error" => "Errore upload: " . $uploadError,
        ];
        continue;
    }

    if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== "xlsx") {
        $results[] = [
            "file" => $originalName,
            "error" => "Formato non supportato, serve un file .xlsx",
        ];
        continue;
    }

    try {
        $spreadsheet = IOFactory::load($tmpName);
        $worksheet = $spreadsheet->getActiveSheet();
        $converted = convertWorkbookToScheduleData($worksheet, $originalName);

        $outputFile = $outputDir . DIRECTORY_SEPARATOR . $converted["settimana"] . ".json";
        $previousData = appPushDecodeJsonFile($outputFile);
        scriviJson($outputFile, $converted["data"]);

        $scheduleMeta = appPushExtractIsoWeekYear($originalName);
        $changeSet = appPushBuildChangeSet(
            $previousData,
            $converted["data"],
            $pdo,
            $scheduleMeta['year'],
            $scheduleMeta['week']
        );
        $batchId = bin2hex(random_bytes(16));
        $historySummary = [
            'batch' => $batchId,
            'stored' => 0,
            'errors' => [],
        ];
        $storedChangesByUser = [];

        foreach ($changeSet['targets'] as $userCf => $entries) {
            try {
                $storedChangesByUser[$userCf] = appPushStoreScheduleChanges(
                    $pdo,
                    $batchId,
                    (string) $userCf,
                    (string) ($_SESSION['user']['cf'] ?? ''),
                    $scheduleMeta['year'],
                    $scheduleMeta['week'],
                    $originalName,
                    $entries
                );
                $historySummary['stored'] += $storedChangesByUser[$userCf];
            } catch (Throwable $historyError) {
                $historySummary['errors'][$userCf] = $historyError->getMessage();
            }
        }

        $pushSummary = [
            'general' => null,
            'targets' => [],
        ];

        if ($changeSet['generalChanged']) {
            try {
                $pushSummary['general'] = appPushSendPayload($pdo, [
                    'title' => 'Nuovi orari caricati',
                    'body' => 'Gli orari sono stati aggiornati dal capo',
                    'url' => './index.php',
                ]);
            } catch (Throwable $pushError) {
                $pushSummary['general'] = [
                    'error' => $pushError->getMessage(),
                ];
            }
        }

        foreach ($changeSet['targets'] as $userCf => $entries) {
            if (!is_array($entries) || $entries === []) {
                continue;
            }

            $firstChange = $entries[0];
            $changeCount = count($entries);
            $body = $changeCount === 1
                ? 'Orario del ' . ($firstChange['dateLabel'] ?? 'giorno selezionato') . ' modificato da capo'
                : 'Hai ' . $changeCount . ' modifiche di orario';
            $title = $changeCount === 1
                ? 'Orario modificato'
                : 'Orari aggiornati';
            $changeUrl = !empty($storedChangesByUser[$userCf])
                ? './index.php?changes=1&batch=' . rawurlencode($batchId)
                : './index.php';

            try {
                $pushSummary['targets'][$userCf] = appPushSendPayload($pdo, [
                    'title' => $title,
                    'body' => $body,
                    'url' => $changeUrl,
                ], $userCf);
            } catch (Throwable $pushError) {
                $pushSummary['targets'][$userCf] = [
                    'error' => $pushError->getMessage(),
                ];
            }
        }

        $results[] = [
            "file" => $originalName,
            "settimana" => $converted["settimana"],
            "output" => basename($outputFile),
            "righe" => count($converted["data"]),
            "history" => $historySummary,
            "push" => $pushSummary,
        ];
    } catch (Throwable $e) {
        $results[] = [
            "file" => $originalName,
            "error" => $e->getMessage(),
        ];
    }
}

echo json_encode([
    "ok" => true,
    "results" => $results,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

<?php
use PhpOffice\PhpSpreadsheet\IOFactory;

header("Content-Type: application/json; charset=utf-8");

session_start();

if (!isset($_SESSION["user"]) || (int) ($_SESSION["user"]["capo"] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode([
        "ok" => false,
        "error" => "Accesso negato",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../gestore_ods/orario_converter_lib.php';

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
        scriviJson($outputFile, $converted["data"]);

        $results[] = [
            "file" => $originalName,
            "settimana" => $converted["settimana"],
            "output" => basename($outputFile),
            "righe" => count($converted["data"]),
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

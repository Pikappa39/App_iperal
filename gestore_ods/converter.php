<?php
use PhpOffice\PhpSpreadsheet\IOFactory;

header("Content-Type: application/json; charset=utf-8");

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/orario_converter_lib.php';

$inputDir = __DIR__ . '/../xlms';
$outputDir = __DIR__ . '/../turni_json';

if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
    http_response_code(500);
    echo json_encode(["error" => "Impossibile creare la cartella JSON"]);
    exit;
}

$risultati = [];
$files = glob($inputDir . DIRECTORY_SEPARATOR . '*.xlsx') ?: [];

foreach ($files as $file) {
    $filename = basename($file);

    if (str_starts_with($filename, '~') || str_starts_with($filename, '.~lock.')) {
        continue;
    }

    try {
        $spreadsheet = IOFactory::load($file);
        $worksheet = $spreadsheet->getActiveSheet();
        $converted = convertWorkbookToScheduleData($worksheet, $filename);

        $outputFile = $outputDir . DIRECTORY_SEPARATOR . $converted['settimana'] . '.json';
        scriviJson($outputFile, $converted['data']);

        $risultati[] = [
            "file" => $filename,
            "settimana" => $converted['settimana'],
            "output" => basename($outputFile),
            "righe" => count($converted['data']),
        ];
    } catch (Throwable $e) {
        $risultati[] = [
            "file" => $filename,
            "error" => $e->getMessage(),
        ];
    }
}

echo json_encode([
    "converted" => array_values(array_filter($risultati, fn ($item) => empty($item["skipped"]) && empty($item["error"]))),
    "skipped" => array_values(array_filter($risultati, fn ($item) => !empty($item["skipped"]))),
    "errors" => array_values(array_filter($risultati, fn ($item) => !empty($item["error"]))),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

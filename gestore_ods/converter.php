<?php
use PhpOffice\PhpSpreadsheet\IOFactory;

//questo file converte gli ods in json usabili dal calendario, prende in input un file ods e restituisce un file json con i dati del calendario
header("Content-Type: application/json; charset=utf-8");

require __DIR__ . '/../vendor/autoload.php';
$file = __DIR__ . '/../xlms/orario.ods';

if (!file_exists($file)) {
    die("File non trovato: " . $file);
}

$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
$sheet = $spreadsheet->getActiveSheet();

$data = [];

foreach ($sheet->getRowIterator() as $row) {

    $cellIterator = $row->getCellIterator();
    $cellIterator->setIterateOnlyExistingCells(false);

    $rowData = [];

    foreach ($cellIterator as $cell) {
        $rowData[] = $cell->getValue();
    }

    // salta righe vuote
    if (!empty($rowData[0])) {
        $data[] = [
            "nome" => $rowData[0],
            "giorno" => $rowData[1],
            "orario" => $rowData[2]
        ];
    }
}

header("Content-Type: application/json");
echo json_encode($data);

?>
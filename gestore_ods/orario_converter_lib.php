<?php

function normalizzaSpazi(string $value): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $value));
}

function normalizzaAddetto(string $addetto): string
{
    $addetto = normalizzaSpazi($addetto);

    if ($addetto === '') {
        return '';
    }

    $isMaiuscolo = $addetto === mb_strtoupper($addetto, 'UTF-8');
    $parti = explode(' ', $addetto);

    if ($isMaiuscolo && count($parti) > 1) {
        $nome = array_pop($parti);
        $addetto = $nome . ' ' . implode(' ', $parti);
    }

    return normalizzaSpazi($addetto);
}

function normalizzaChiaveAddetto(string $addetto): string
{
    $addetto = normalizzaSpazi($addetto);
    if ($addetto === '') {
        return '';
    }

    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $addetto);
    if ($ascii !== false) {
        $addetto = $ascii;
    }

    $addetto = mb_strtoupper($addetto, 'UTF-8');
    $addetto = preg_replace('/[^A-Z0-9 ]+/', ' ', $addetto) ?? $addetto;

    return normalizzaSpazi($addetto);
}

function suffissoDuplicatoAddetto(int $position): string
{
    $suffix = '';
    while ($position > 0) {
        $position--;
        $suffix = chr(65 + ($position % 26)) . $suffix;
        $position = intdiv($position, 26);
    }

    return $suffix;
}

function distingueNominativiDuplicati(array $rows): array
{
    $counts = [];
    foreach ($rows as $row) {
        $key = normalizzaChiaveAddetto((string) ($row['ADDETTO'] ?? ''));
        if ($key !== '') {
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
    }

    $positions = [];
    foreach ($rows as &$row) {
        $name = normalizzaSpazi((string) ($row['ADDETTO'] ?? ''));
        $key = normalizzaChiaveAddetto($name);
        if ($key === '' || ($counts[$key] ?? 0) < 2) {
            continue;
        }

        $positions[$key] = ($positions[$key] ?? 0) + 1;
        $row['ADDETTO'] = $name . ' ' . suffissoDuplicatoAddetto($positions[$key]);
    }
    unset($row);

    return $rows;
}

function valoreCella($worksheet, int $col, int $row): string
{
    $value = (string) $worksheet->getCell([$col, $row])->getFormattedValue();
    return normalizzaSpazi($value);
}

function orarioGiorno($worksheet, int $row, int $startCol): string
{
    $orari = [];

    for ($col = $startCol + 1; $col <= $startCol + 4; $col++) {
        $value = valoreCella($worksheet, $col, $row);
        if ($value !== '') {
            $orari[] = $value;
        }
    }

    return $orari === [] ? 'RIPOSO' : implode(' ', $orari);
}

function settimanaAnnoDaTesto(string $text): ?array
{
    if (preg_match('/SETTIMANA\s+(\d{1,2})\/(\d{4})/i', $text, $matches)) {
        return [
            'week' => (int) $matches[1],
            'year' => (int) $matches[2],
        ];
    }

    if (preg_match('/\((\d{1,2})-(\d{4})\)\.xlsx$/i', $text, $matches)) {
        return [
            'week' => (int) $matches[1],
            'year' => (int) $matches[2],
        ];
    }

    return null;
}

function settimanaDaTesto(string $text): ?string
{
    $metadata = settimanaAnnoDaTesto($text);
    return $metadata === null ? null : (string) $metadata['week'];
}

function settimanaAnnoDaWorkbook($worksheet, string $fallbackName = ''): ?array
{
    for ($row = 1; $row <= 5; $row++) {
        for ($col = 1; $col <= 12; $col++) {
            $value = valoreCella($worksheet, $col, $row);
            $metadata = settimanaAnnoDaTesto($value);
            if ($metadata !== null) {
                return $metadata;
            }
        }
    }

    return settimanaAnnoDaTesto($fallbackName);
}

function settimanaDaWorkbook($worksheet, string $fallbackName = ''): ?string
{
    $metadata = settimanaAnnoDaWorkbook($worksheet, $fallbackName);
    return $metadata === null ? null : (string) $metadata['week'];
}

function convertWorkbookToScheduleData($worksheet, string $fallbackName = ''): array
{
    $metadata = settimanaAnnoDaWorkbook($worksheet, $fallbackName);

    if ($metadata === null) {
        throw new RuntimeException('Settimana non trovata nel file');
    }

    $giorni = [
        'lunedì' => 5,
        'martedì' => 10,
        'mercoledì' => 15,
        'giovedì' => 20,
        'venerdì' => 25,
        'sabato' => 30,
        'domenica' => 35,
    ];

    $highestRow = $worksheet->getHighestRow();
    $data = [];

    for ($row = 3; $row <= $highestRow; $row++) {
        $addetto = normalizzaAddetto(valoreCella($worksheet, 3, $row));

        if ($addetto === '') {
            continue;
        }

        $riga = ['ADDETTO' => $addetto];

        foreach ($giorni as $giorno => $startCol) {
            $riga[$giorno] = orarioGiorno($worksheet, $row, $startCol);
        }

        $data[] = $riga;
    }

    return [
        'settimana' => $metadata['week'],
        'anno' => $metadata['year'],
        'data' => distingueNominativiDuplicati($data),
    ];
}

function associaUtentiAlleRigheOrario(array $rows, array $mappings, array $unregisteredKeys = []): array
{
    foreach ($rows as &$row) {
        $key = normalizzaChiaveAddetto((string) ($row['ADDETTO'] ?? ''));
        $userCf = trim((string) ($mappings[$key] ?? ''));

        if ($key === '') {
            throw new RuntimeException('Manca l\'associazione per il nominativo "' . ($row['ADDETTO'] ?? '') . '".');
        }

        if ($userCf !== '') {
            $row['COD_FISCALE'] = $userCf;
            unset($row['UTENTE_NON_REGISTRATO']);
            continue;
        }

        if (!empty($unregisteredKeys[$key])) {
            unset($row['COD_FISCALE']);
            $row['UTENTE_NON_REGISTRATO'] = true;
            continue;
        }

        throw new RuntimeException('Manca l\'associazione per il nominativo "' . ($row['ADDETTO'] ?? '') . '".');
    }
    unset($row);

    return $rows;
}

function scriviJson(string $outputFile, array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    if ($json === false) {
        throw new RuntimeException('Errore nella codifica JSON');
    }

    $temporaryFile = $outputFile . '.tmp-' . bin2hex(random_bytes(8));
    if (@file_put_contents($temporaryFile, $json . PHP_EOL, LOCK_EX) === false || !@rename($temporaryFile, $outputFile)) {
        @unlink($temporaryFile);
        $error = error_get_last();
        $message = $error['message'] ?? 'errore sconosciuto';
        throw new RuntimeException('Impossibile scrivere ' . basename($outputFile) . ': ' . $message);
    }

    @chmod($outputFile, 0640);
}

<?php
declare(strict_types=1);

function noteStorageDir(): string
{
    return __DIR__ . '/../../../../note_json';
}

function noteEnsureStorage(string $storageDir): void
{
    if (!is_dir($storageDir) && !mkdir($storageDir, 0750, true) && !is_dir($storageDir)) {
        noteResponse([
            'ok' => false,
            'error' => 'Impossibile creare la cartella delle note',
        ], 500);
    }

    if (!is_writable($storageDir)) {
        noteResponse([
            'ok' => false,
            'error' => 'Cartella note non scrivibile',
        ], 500);
    }
}

function noteMonthFilePath(string $storageDir, string $monthKey): string
{
    return $storageDir . DIRECTORY_SEPARATOR . $monthKey . '.json';
}

function noteEmptyPayload(string $monthKey): array
{
    return [
        'month' => $monthKey,
        'notes' => [],
    ];
}

function noteLoadMonth(string $filePath, string $monthKey): array
{
    if (!is_file($filePath)) {
        return noteEmptyPayload($monthKey);
    }

    $raw = file_get_contents($filePath);
    if ($raw === false || trim($raw) === '') {
        return noteEmptyPayload($monthKey);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return noteEmptyPayload($monthKey);
    }

    if (!isset($decoded['month'])) {
        $decoded['month'] = $monthKey;
    }

    if (!isset($decoded['notes']) || !is_array($decoded['notes'])) {
        $decoded['notes'] = [];
    }

    return $decoded;
}

function noteNormalizeStructure($notes): array
{
    if (!is_array($notes)) {
        return [];
    }

    foreach ($notes as $dateKey => $entries) {
        if (!is_array($entries)) {
            $notes[$dateKey] = [];
            continue;
        }

        $notes[$dateKey] = array_values(array_filter($entries, static function ($entry) {
            return is_array($entry) && isset($entry['userKey'], $entry['userName'], $entry['note']);
        }));
    }

    return $notes;
}

function noteEntryId(string $dateKey, array $entry): string
{
    return hash('sha256', implode("\n", [
        $dateKey,
        (string) ($entry['userKey'] ?? ''),
        (string) ($entry['updatedAt'] ?? ''),
        (string) ($entry['note'] ?? ''),
    ]));
}

function noteSaveMonth(string $filePath, array $payload): array
{
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return [
            'ok' => false,
            'details' => 'JSON non valido',
        ];
    }

    $temporaryPath = $filePath . '.tmp-' . bin2hex(random_bytes(8));
    $written = file_put_contents($temporaryPath, $json . PHP_EOL, LOCK_EX);
    if ($written === false || !rename($temporaryPath, $filePath)) {
        @unlink($temporaryPath);
        return [
            'ok' => false,
            'details' => 'Scrittura fallita su ' . $filePath,
        ];
    }

    return ['ok' => true];
}

function noteWithMonthLock(string $filePath, callable $callback)
{
    $lockHandle = fopen($filePath . '.lock', 'c');
    if ($lockHandle === false) {
        throw new RuntimeException('Impossibile bloccare il file delle note');
    }

    try {
        if (!flock($lockHandle, LOCK_EX)) {
            throw new RuntimeException('Impossibile ottenere il blocco delle note');
        }

        return $callback();
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}
<?php
declare(strict_types=1);

function noteAllMonthsPayload(string $storageDir, array $viewer): array
{
    $monthFiles = glob($storageDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    rsort($monthFiles, SORT_STRING);

    $months = [];
    foreach ($monthFiles as $monthFile) {
        $monthKey = basename($monthFile, '.json');
        $payload = noteLoadMonth($monthFile, $monthKey);
        $payload['notes'] = noteNormalizeStructure($payload['notes']);
        $payload['notes'] = noteFilterForViewer($payload['notes'], $viewer);

        $entries = [];
        foreach ($payload['notes'] as $entryDate => $dayEntries) {
            foreach ($dayEntries as $dayEntry) {
                $entries[] = [
                    'date' => $entryDate,
                    'entryId' => noteEntryId((string) $entryDate, $dayEntry),
                    'userKey' => (string) ($dayEntry['userKey'] ?? ''),
                    'userName' => (string) ($dayEntry['userName'] ?? ''),
                    'note' => (string) ($dayEntry['note'] ?? ''),
                    'updatedAt' => (string) ($dayEntry['updatedAt'] ?? ''),
                ];
            }
        }

        usort($entries, static function ($left, $right) {
            $dateCompare = strcmp((string) ($right['date'] ?? ''), (string) ($left['date'] ?? ''));
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return strcmp((string) ($right['updatedAt'] ?? ''), (string) ($left['updatedAt'] ?? ''));
        });

        $months[] = [
            'month' => $monthKey,
            'entries' => $entries,
        ];
    }

    return [
        'ok' => true,
        'months' => $months,
    ];
}

function noteMonthPayload(string $storageDir, string $monthKey, ?string $dateKey, array $viewer): array
{
    $filePath = noteMonthFilePath($storageDir, $monthKey);
    $payload = noteLoadMonth($filePath, $monthKey);
    $payload['notes'] = noteNormalizeStructure($payload['notes']);
    $payload['notes'] = noteFilterForViewer($payload['notes'], $viewer);

    $response = [
        'ok' => true,
        'month' => $monthKey,
        'notes' => $payload['notes'],
    ];

    if ($dateKey !== null) {
        $response['date'] = $dateKey;
        $response['dayNotes'] = array_values($payload['notes'][$dateKey] ?? []);
        $response['currentUserNote'] = '';

        foreach ($response['dayNotes'] as $entry) {
            if (($entry['userKey'] ?? '') === (string) $viewer['userKey']) {
                $response['currentUserNote'] = (string) ($entry['note'] ?? '');
                break;
            }
        }
    }

    return $response;
}

function noteDeleteAdminPayload(string $storageDir, string $dateKey, string $entryId, array $viewer): array
{
    $monthKey = substr($dateKey, 0, 7);
    $filePath = noteMonthFilePath($storageDir, $monthKey);
    $payload = noteWithMonthLock($filePath, static function () use ($filePath, $monthKey, $dateKey, $entryId, $viewer) {
        $payload = noteLoadMonth($filePath, $monthKey);
        $payload['notes'] = noteNormalizeStructure($payload['notes']);
        $entries = $payload['notes'][$dateKey] ?? [];
        $entryIndex = null;

        foreach ($entries as $index => $entry) {
            if (!is_array($entry) || !hash_equals(noteEntryId($dateKey, $entry), $entryId)) {
                continue;
            }
            if (!noteCanViewEntry($entry, $viewer)) {
                throw new RuntimeException('Non puoi eliminare questa nota.');
            }
            $entryIndex = $index;
            break;
        }

        if ($entryIndex === null) {
            throw new RuntimeException('La nota non è più disponibile. Ricarica l’elenco.');
        }

        array_splice($entries, $entryIndex, 1);
        if ($entries === []) {
            unset($payload['notes'][$dateKey]);
        } else {
            $payload['notes'][$dateKey] = array_values($entries);
        }

        $saveResult = noteSaveMonth($filePath, $payload);
        if (empty($saveResult['ok'])) {
            throw new RuntimeException('Impossibile eliminare la nota.');
        }

        return $payload;
    });

    return [
        'ok' => true,
        'month' => $monthKey,
        'date' => $dateKey,
        'notes' => array_values(noteFilterForViewer([
            $dateKey => $payload['notes'][$dateKey] ?? [],
        ], $viewer)[$dateKey] ?? []),
    ];
}

function noteAssertScheduleVersion(PDO $pdo, string $dateKey, string $clientScheduleVersion, int|false|null $clientScheduleYear, int|false|null $clientScheduleWeek, string $viewerDepartment): void
{
    if ($clientScheduleVersion === '') {
        return;
    }

    $dateInfo = appScheduleAdjustmentDateInfo($dateKey);
    if ($dateInfo === null) {
        noteResponse([
            'ok' => false,
            'error' => 'Data non valida',
        ], 400);
    }

    $expectedYear = (int) $dateInfo['year'];
    $expectedWeek = (int) $dateInfo['week'];
    if ($clientScheduleYear !== $expectedYear || $clientScheduleWeek !== $expectedWeek) {
        noteResponse([
            'ok' => false,
            'error' => 'Orario non allineato. Riapri il giorno e riprova.',
            'schedule_changed' => true,
        ], 409);
    }

    $currentScheduleVersion = appScheduleAdjustmentCurrentScheduleFingerprint(
        $pdo,
        $viewerDepartment,
        $expectedYear,
        $expectedWeek
    );
    if (!hash_equals($currentScheduleVersion, $clientScheduleVersion)) {
        noteResponse([
            'ok' => false,
            'error' => "L'orario è stato aggiornato. Ricarico i dati prima di salvare la nota.",
            'schedule_changed' => true,
            'schedule_version' => $currentScheduleVersion,
        ], 409);
    }
}

function noteSavePayload(string $storageDir, string $dateKey, string $note, array $viewer): array
{
    $monthKey = substr($dateKey, 0, 7);
    $filePath = noteMonthFilePath($storageDir, $monthKey);
    $payload = noteWithMonthLock($filePath, static function () use ($filePath, $monthKey, $dateKey, $note, $viewer) {
        $payload = noteLoadMonth($filePath, $monthKey);
        $payload['notes'] = noteNormalizeStructure($payload['notes']);

        if (!isset($payload['notes'][$dateKey]) || !is_array($payload['notes'][$dateKey])) {
            $payload['notes'][$dateKey] = [];
        }

        $entries = $payload['notes'][$dateKey];
        $entryIndex = null;
        foreach ($entries as $index => $entry) {
            if (($entry['userKey'] ?? '') === (string) $viewer['userKey']) {
                $entryIndex = $index;
                break;
            }
        }

        if ($note === '') {
            if ($entryIndex !== null) {
                array_splice($entries, $entryIndex, 1);
            }
        } else {
            $entry = [
                'userKey' => (string) $viewer['userKey'],
                'userName' => (string) $viewer['userName'] !== '' ? (string) $viewer['userName'] : (string) $viewer['userKey'],
                'note' => $note,
                'updatedAt' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
            ];

            if ($entryIndex !== null) {
                $entries[$entryIndex] = $entry;
            } else {
                $entries[] = $entry;
            }
        }

        if ($entries !== []) {
            $payload['notes'][$dateKey] = array_values($entries);
        } else {
            unset($payload['notes'][$dateKey]);
        }

        $saveResult = noteSaveMonth($filePath, $payload);
        if (empty($saveResult['ok'])) {
            throw new RuntimeException('Impossibile salvare le note');
        }

        return $payload;
    });

    return [
        'ok' => true,
        'month' => $monthKey,
        'date' => $dateKey,
        'notes' => array_values(noteFilterForViewer([
            $dateKey => $payload['notes'][$dateKey] ?? [],
        ], $viewer)[$dateKey] ?? []),
    ];
}
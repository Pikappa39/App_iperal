<?php
declare(strict_types=1);

function holidayUserName(array $user): string
{
    return trim((string) ($user['nome'] ?? '') . ' ' . (string) ($user['cognome'] ?? ''));
}

function holidayFetchUsersByCf(PDO $pdo, string $department): array
{
    $statement = $pdo->prepare(
        'SELECT cod_fiscale, nome, cognome, attivo
         FROM utenti
         WHERE reparto = ?
         ORDER BY cognome, nome, cod_fiscale'
    );
    $statement->execute([$department]);
    $users = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $user) {
        $users[(string) $user['cod_fiscale']] = $user;
    }
    return $users;
}

function holidayFetchMappings(PDO $pdo, string $department): array
{
    $statement = $pdo->prepare(
        'SELECT schedule_name, user_cf
         FROM schedule_name_mappings
         WHERE reparto = ?'
    );
    $statement->execute([$department]);
    $mappings = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $mapping) {
        $mappings[(string) $mapping['schedule_name']] = (string) $mapping['user_cf'];
    }
    return $mappings;
}

function holidayAppendSchedulePerson(array &$people, array $usersByCf, array $mappings, array $row): void
{
    $sourceName = trim((string) ($row['ADDETTO'] ?? ''));
    $scheduleName = normalizzaChiaveAddetto($sourceName);
    if ($scheduleName === '') {
        return;
    }

    $userCf = trim((string) ($row['COD_FISCALE'] ?? ''));
    if (!isset($usersByCf[$userCf])) {
        $userCf = $mappings[$scheduleName] ?? '';
    }
    $user = $usersByCf[$userCf] ?? null;
    $isRegistered = is_array($user);
    $personKey = $isRegistered ? 'user:' . $userCf : 'schedule:' . $scheduleName;

    if (isset($people[$personKey])) {
        if ((string) ($people[$personKey]['source_name'] ?? '') === '' && $sourceName !== '') {
            $people[$personKey]['source_name'] = $sourceName;
            $people[$personKey]['schedule_name'] = $scheduleName;
            if ($isRegistered && strcasecmp($sourceName, (string) $people[$personKey]['display_name']) !== 0) {
                $people[$personKey]['label'] = $people[$personKey]['display_name'] . ' - Excel: ' . $sourceName;
            }
        }
        return;
    }

    $displayName = $isRegistered ? holidayUserName($user) : $sourceName;
    $label = $displayName;
    if ($isRegistered && $sourceName !== '' && strcasecmp($sourceName, $displayName) !== 0) {
        $label .= ' - Excel: ' . $sourceName;
    } elseif (!$isRegistered) {
        $label .= ' - Solo Excel';
    }

    $people[$personKey] = [
        'person_key' => $personKey,
        'user_cf' => $isRegistered ? $userCf : '',
        'schedule_name' => $scheduleName,
        'source_name' => $sourceName,
        'display_name' => $displayName,
        'label' => $label,
        'state' => $isRegistered ? 'registered' : 'schedule_only',
    ];
}

function holidayScheduleRowsFromSnapshots(PDO $pdo, string $department): array
{
    try {
        $statement = $pdo->prepare(
            'SELECT v.schedule_snapshot
             FROM schedule_active_versions a
             JOIN schedule_upload_versions v ON v.id = a.version_id
             WHERE a.reparto = ?
             ORDER BY a.iso_year DESC, a.iso_week DESC'
        );
        $statement->execute([$department]);
    } catch (Throwable $error) {
        error_log('Anagrafica ferie da snapshot non disponibile: ' . $error->getMessage());
        return [];
    }

    $rows = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $snapshotRow) {
        $decoded = json_decode((string) ($snapshotRow['schedule_snapshot'] ?? ''), true);
        if (!is_array($decoded)) {
            continue;
        }
        foreach ($decoded as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
    }

    return $rows;
}

function holidayScheduleRowsFromJsonFiles(string $department): array
{
    $files = glob(HOLIDAY_MODULE_APP_ROOT . '/turni_json/*-' . $department . '.json') ?: [];
    rsort($files, SORT_NATURAL);

    $rows = [];
    foreach ($files as $file) {
        $raw = @file_get_contents($file);
        if (!is_string($raw)) {
            continue;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            continue;
        }
        foreach ($decoded as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
    }

    return $rows;
}

function holidayPeopleForDepartment(PDO $pdo, string $department): array
{
    $usersByCf = holidayFetchUsersByCf($pdo, $department);
    $mappings = holidayFetchMappings($pdo, $department);
    $people = [];

    foreach ($usersByCf as $userCf => $user) {
        $displayName = holidayUserName($user);
        $people['user:' . $userCf] = [
            'person_key' => 'user:' . $userCf,
            'user_cf' => $userCf,
            'schedule_name' => '',
            'source_name' => '',
            'display_name' => $displayName,
            'label' => $displayName . ' - Utente registrato',
            'state' => ((int) ($user['attivo'] ?? 1) === 1 ? 'registered' : 'inactive'),
        ];
    }

    foreach (holidayScheduleRowsFromSnapshots($pdo, $department) as $row) {
        holidayAppendSchedulePerson($people, $usersByCf, $mappings, $row);
    }

    foreach (holidayScheduleRowsFromJsonFiles($department) as $row) {
        holidayAppendSchedulePerson($people, $usersByCf, $mappings, $row);
    }

    foreach ($mappings as $scheduleName => $userCf) {
        $user = $usersByCf[$userCf] ?? null;
        if (!is_array($user)) {
            continue;
        }
        $personKey = 'user:' . $userCf;
        if (!isset($people[$personKey])) {
            $displayName = holidayUserName($user);
            $people[$personKey] = [
                'person_key' => $personKey,
                'user_cf' => $userCf,
                'schedule_name' => $scheduleName,
                'source_name' => $scheduleName,
                'display_name' => $displayName,
                'label' => $displayName . ' - Excel: ' . $scheduleName,
                'state' => 'registered',
            ];
        } elseif ((string) ($people[$personKey]['schedule_name'] ?? '') === '') {
            $people[$personKey]['schedule_name'] = $scheduleName;
            $people[$personKey]['source_name'] = $scheduleName;
            $people[$personKey]['label'] = $people[$personKey]['display_name'] . ' - Excel: ' . $scheduleName;
        }
    }

    $people = array_values($people);
    usort($people, static function (array $left, array $right): int {
        $stateOrder = ['registered' => 0, 'inactive' => 1, 'schedule_only' => 2];
        $stateCompare = ($stateOrder[(string) ($left['state'] ?? '')] ?? 9) <=> ($stateOrder[(string) ($right['state'] ?? '')] ?? 9);
        return $stateCompare !== 0 ? $stateCompare : strnatcasecmp((string) $left['display_name'], (string) $right['display_name']);
    });

    return $people;
}

function holidayFindPerson(array $people, string $personKey): ?array
{
    foreach ($people as $person) {
        if ((string) ($person['person_key'] ?? '') === $personKey) {
            return $person;
        }
    }
    return null;
}
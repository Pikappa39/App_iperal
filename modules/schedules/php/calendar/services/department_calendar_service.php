<?php
declare(strict_types=1);

function departmentScheduleUserName(array $user): string
{
    return trim((string) ($user['nome'] ?? '') . ' ' . (string) ($user['cognome'] ?? ''));
}

function departmentScheduleState(array $user): string
{
    return (int) ($user['attivo'] ?? 1) === 1 ? 'registered' : 'inactive';
}

function loadDepartmentScheduleOverview(PDO $pdo, string $department, int $year, int $week): array
{
    $usersStatement = $pdo->prepare(
        'SELECT cod_fiscale, nome, cognome, attivo
         FROM utenti
         WHERE reparto = ?
         ORDER BY cognome, nome, cod_fiscale'
    );
    $usersStatement->execute([$department]);
    $usersByCf = [];
    foreach ($usersStatement->fetchAll(PDO::FETCH_ASSOC) as $user) {
        $usersByCf[(string) $user['cod_fiscale']] = $user;
    }

    $mappingStatement = $pdo->prepare(
        'SELECT schedule_name, user_cf
         FROM schedule_name_mappings
         WHERE reparto = ?'
    );
    $mappingStatement->execute([$department]);
    $mappings = [];
    foreach ($mappingStatement->fetchAll(PDO::FETCH_ASSOC) as $mapping) {
        $mappings[(string) $mapping['schedule_name']] = (string) $mapping['user_cf'];
    }

    $adjustmentStatement = $pdo->prepare(
        "SELECT user_cf, day_name, requested_shift, status, created_at
         FROM schedule_adjustment_requests
         WHERE reparto = ? AND iso_year = ? AND iso_week = ?
           AND status IN ('pending', 'review', 'approved')
         ORDER BY created_at DESC, id DESC"
    );
    $adjustmentStatement->execute([$department, $year, $week]);
    $adjustments = [];
    foreach ($adjustmentStatement->fetchAll(PDO::FETCH_ASSOC) as $adjustment) {
        $key = (string) $adjustment['user_cf'] . ':' . (string) $adjustment['day_name'];
        if (!isset($adjustments[$key])) {
            $adjustments[$key] = $adjustment;
        }
    }

    $rows = appScheduleAdjustmentLoadCurrentScheduleRows($pdo, $department, $year, $week) ?? [];
    $people = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sourceName = trim((string) ($row['ADDETTO'] ?? ''));
        $scheduleName = normalizzaChiaveAddetto($sourceName);
        if ($scheduleName === '') {
            continue;
        }

        $userCf = trim((string) ($row['COD_FISCALE'] ?? ''));
        if (!isset($usersByCf[$userCf])) {
            $userCf = $mappings[$scheduleName] ?? '';
        }
        $user = $usersByCf[$userCf] ?? null;
        $isRegisteredUser = is_array($user);
        $state = $isRegisteredUser
            ? departmentScheduleState($user)
            : (!empty($row['UTENTE_NON_REGISTRATO']) || $userCf === '__UNREGISTERED__' ? 'unregistered' : 'unverified');
        if (!$isRegisteredUser) {
            $userCf = '';
        }
        $personKey = $isRegisteredUser ? 'user:' . $userCf : 'schedule:' . $scheduleName;

        $days = [];
        foreach (APP_SCHEDULE_ADJUSTMENT_DAYS as $dayName) {
            $originalShift = trim((string) ($row[$dayName] ?? 'RIPOSO')) ?: 'RIPOSO';
            $adjustment = $userCf !== '' ? ($adjustments[$userCf . ':' . $dayName] ?? null) : null;
            $variation = is_array($adjustment) ? (string) $adjustment['status'] : '';
            $shift = $variation === 'approved'
                ? trim((string) $adjustment['requested_shift'])
                : $originalShift;
            $days[$dayName] = [
                'shift' => $shift !== '' ? $shift : 'RIPOSO',
                'original_shift' => $originalShift,
                'variation' => $variation,
            ];
        }

        $people[$personKey] = [
            'key' => $personKey,
            'user_cf' => $userCf,
            'name' => is_array($user) ? departmentScheduleUserName($user) : $sourceName,
            'source_name' => $sourceName,
            'state' => $state,
            'days' => $days,
        ];
    }

    foreach ($usersByCf as $userCf => $user) {
        $personKey = 'user:' . $userCf;
        if (isset($people[$personKey])) {
            continue;
        }
        $days = [];
        foreach (APP_SCHEDULE_ADJUSTMENT_DAYS as $dayName) {
            $days[$dayName] = ['shift' => '', 'original_shift' => '', 'variation' => ''];
        }
        $people[$personKey] = [
            'key' => $personKey,
            'user_cf' => $userCf,
            'name' => departmentScheduleUserName($user),
            'source_name' => '',
            'state' => 'no_schedule',
            'days' => $days,
        ];
    }

    $stateOrder = ['registered' => 0, 'inactive' => 1, 'unregistered' => 2, 'unverified' => 3, 'no_schedule' => 4];
    $people = array_values($people);
    usort($people, static function (array $left, array $right) use ($stateOrder): int {
        $stateCompare = ($stateOrder[$left['state']] ?? 9) <=> ($stateOrder[$right['state']] ?? 9);
        return $stateCompare !== 0 ? $stateCompare : strnatcasecmp((string) $left['name'], (string) $right['name']);
    });

    return $people;
}
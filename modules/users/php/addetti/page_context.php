<?php
declare(strict_types=1);

function appAddettiEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function appAddettiDepartmentGroupLabel(?string $group): string
{
    return [
        'grocery_1' => 'Grocery 1',
        'grocery_2' => 'Grocery 2',
    ][$group ?? ''] ?? 'Non assegnato';
}

function appAddettiGroceryGroupSelect(string $name, string $currentGroup, string $id, array $attributes = []): string
{
    $options = [
        '' => 'Non assegnato',
        'grocery_1' => 'Grocery 1',
        'grocery_2' => 'Grocery 2',
    ];
    $attributeHtml = '';
    foreach ($attributes as $attribute => $value) {
        $attributeHtml .= ' ' . appAddettiEscape((string) $attribute) . '="' . appAddettiEscape((string) $value) . '"';
    }
    $html = '<select class="form-select form-select-sm" name="' . appAddettiEscape($name) . '" id="' . appAddettiEscape($id) . '"' . $attributeHtml . '>';
    foreach ($options as $value => $label) {
        $selected = $value === $currentGroup ? ' selected' : '';
        $html .= '<option value="' . appAddettiEscape($value) . '"' . $selected . '>' . appAddettiEscape($label) . '</option>';
    }
    return $html . '</select>';
}

function appAddettiLastSeenLabel($value): string
{
    if (!is_string($value) || trim($value) === '') {
        return 'Mai rilevato';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return 'Dato non valido';
    }

    $diff = time() - $timestamp;
    if ($diff < 0) {
        $diff = 0;
    }

    if ($diff < 120) {
        return 'Attivo adesso';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . ' min fa';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . ' h fa';
    }

    return date('d/m/Y H:i', $timestamp);
}

function appAddettiLoadScheduleNames(string $department): array
{
    $scheduleNames = [];
    $jsonFiles = glob(dirname(__DIR__, 4) . '/turni_json/*-' . $department . '.json') ?: [];
    foreach ($jsonFiles as $jsonFile) {
        $decoded = json_decode((string) @file_get_contents($jsonFile), true);
        if (!is_array($decoded)) {
            continue;
        }
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $displayName = trim((string) ($row['ADDETTO'] ?? ''));
            $key = normalizzaChiaveAddetto($displayName);
            if ($key !== '' && !isset($scheduleNames[$key])) {
                $scheduleNames[$key] = $displayName;
            }
        }
    }

    return $scheduleNames;
}

function appAddettiBuildScheduleRows(array $mappings, array $usersByCf, array $scheduleNames): array
{
    $namesByUser = [];
    $mappedScheduleRows = [];
    $scheduleOnlyRows = [];
    $mappedKeys = [];

    foreach ($mappings as $mapping) {
        $key = (string) $mapping['schedule_name'];
        $userCf = (string) $mapping['user_cf'];
        if ($userCf === APP_SCHEDULE_MAPPING_IGNORED_VALUE) {
            $mappedKeys[$key] = true;
            continue;
        }
        $mappedKeys[$key] = true;
        $scheduleName = $scheduleNames[$key] ?? $key;

        if (isset($usersByCf[$userCf])) {
            $namesByUser[$userCf][] = $scheduleName;
            $mappedScheduleRows[] = [
                'key' => $key,
                'name' => $scheduleName,
                'user_cf' => $userCf,
                'department_group' => (string) ($mapping['department_group'] ?? ''),
            ];
            continue;
        }

        $scheduleOnlyRows[] = [
            'key' => $key,
            'name' => $scheduleName,
            'status' => $userCf === '__UNREGISTERED__' ? 'Utente non registrato' : 'Associazione da verificare',
            'department_group' => (string) ($mapping['department_group'] ?? ''),
        ];
    }

    foreach ($scheduleNames as $key => $scheduleName) {
        if (isset($mappedKeys[$key])) {
            continue;
        }
        $scheduleOnlyRows[] = [
            'key' => $key,
            'name' => $scheduleName,
            'status' => 'Da associare',
            'department_group' => '',
        ];
    }

    usort($scheduleOnlyRows, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
    usort($mappedScheduleRows, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

    return [$namesByUser, $mappedScheduleRows, $scheduleOnlyRows];
}

function appAddettiBuildPageContext(?PDO $pdo, bool $connectionAvailable, array $sessionUser, array $query): array
{
    $capo = (int) ($sessionUser['capo'] ?? 0);
    $canViewLastSeen = $capo === 3;
    $isGlobalAdmin = $capo === 3;
    $canInvite = appInviteCanManage($sessionUser);

    if (empty($_SESSION['schedule_mapping_csrf'])) {
        $_SESSION['schedule_mapping_csrf'] = bin2hex(random_bytes(32));
    }
    $csrfToken = (string) $_SESSION['schedule_mapping_csrf'];
    $appCsrfToken = app_csrf_token();

    $sessionReparto = trim((string) ($sessionUser['reparto'] ?? ''));
    $requestedReparto = trim((string) ($query['reparto'] ?? ''));
    $reparto = $isGlobalAdmin && appIsValidDepartment($requestedReparto)
        ? $requestedReparto
        : $sessionReparto;
    $repartoLabel = appDepartments()[$reparto] ?? 'non assegnato';
    $isGroceryDepartment = $reparto === 'gro';
    $users = [];
    $mappings = [];
    $invites = [];
    $databaseError = !$connectionAvailable || !($pdo instanceof PDO);
    $inviteFlash = $_SESSION['invite_flash'] ?? null;
    unset($_SESSION['invite_flash']);
    $userManagementFlash = $_SESSION['user_management_flash'] ?? null;
    unset($_SESSION['user_management_flash']);

    if (!$databaseError) {
        $userQuery =
            'SELECT cod_fiscale, nome, cognome, reparto, department_group, capo, box_info, attivo, last_seen
             FROM utenti
             WHERE reparto = ?';
        if (!$isGlobalAdmin) {
            $userQuery .= ' AND attivo = 1';
        }
        $userQuery .= ' ORDER BY attivo DESC, cognome, nome, cod_fiscale';
        $userStatement = $pdo->prepare($userQuery);
        $userStatement->execute([$reparto]);
        $users = $userStatement->fetchAll(PDO::FETCH_ASSOC);

        $mappingStatement = $pdo->prepare(
            'SELECT schedule_name, user_cf, department_group, updated_at
             FROM schedule_name_mappings
             WHERE reparto = ?
             ORDER BY schedule_name'
        );
        $mappingStatement->execute([$reparto]);
        $mappings = $mappingStatement->fetchAll(PDO::FETCH_ASSOC);

        $inviteQuery = null;
        if ($canInvite) {
            if ($capo === 3) {
                $inviteQuery = $pdo->query(
                    'SELECT *
                     FROM user_invites
                     ORDER BY created_at DESC
                     LIMIT 30'
                );
            } else {
                $inviteQuery = $pdo->prepare(
                    'SELECT *
                     FROM user_invites
                     WHERE reparto = ?
                     ORDER BY created_at DESC
                     LIMIT 30'
                );
                $inviteQuery->execute([$reparto]);
            }
        }
        $invites = $inviteQuery ? $inviteQuery->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    $usersByCf = [];
    foreach ($users as $user) {
        $usersByCf[(string) $user['cod_fiscale']] = $user;
    }
    $assignableUsers = array_values(array_filter($users, static fn (array $user): bool => (int) ($user['attivo'] ?? 1) === 1));

    $scheduleNames = appAddettiLoadScheduleNames($reparto);
    [$namesByUser, $mappedScheduleRows, $scheduleOnlyRows] = appAddettiBuildScheduleRows($mappings, $usersByCf, $scheduleNames);

    return compact(
        'canViewLastSeen',
        'isGlobalAdmin',
        'canInvite',
        'csrfToken',
        'appCsrfToken',
        'sessionReparto',
        'requestedReparto',
        'reparto',
        'repartoLabel',
        'isGroceryDepartment',
        'users',
        'mappings',
        'invites',
        'databaseError',
        'inviteFlash',
        'userManagementFlash',
        'usersByCf',
        'assignableUsers',
        'namesByUser',
        'mappedScheduleRows',
        'scheduleOnlyRows'
    );
}

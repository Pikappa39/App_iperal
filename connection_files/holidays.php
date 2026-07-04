<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/../app_config.php';
require __DIR__ . '/../session_bootstrap.php';
app_session_start();
require __DIR__ . '/connection.php';
require __DIR__ . '/schedule_adjustment_lib.php';
require __DIR__ . '/../gestore_ods/orario_converter_lib.php';

function holidayResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function holidayEnsureTable(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS department_holidays (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            reparto VARCHAR(20) NOT NULL,
            iso_year SMALLINT UNSIGNED NOT NULL,
            iso_week TINYINT UNSIGNED NOT NULL,
            person_key VARCHAR(220) NOT NULL,
            user_cf VARCHAR(16) NULL DEFAULT NULL,
            schedule_name VARCHAR(191) NOT NULL,
            display_name VARCHAR(220) NOT NULL,
            created_by_cf VARCHAR(16) NOT NULL,
            updated_by_cf VARCHAR(16) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_department_holidays_week_person (reparto, iso_year, iso_week, person_key),
            INDEX idx_department_holidays_week (reparto, iso_year, iso_week),
            INDEX idx_department_holidays_user (user_cf, iso_year, iso_week)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );
}

function holidayUserName(array $user): string
{
    return trim((string) ($user['nome'] ?? '') . ' ' . (string) ($user['cognome'] ?? ''));
}

function holidayTargetDepartment(array $viewer): string
{
    $role = (int) ($viewer['capo'] ?? 0);
    $sessionDepartment = trim((string) ($viewer['reparto'] ?? ''));
    $requestedDepartment = trim((string) ($_REQUEST['reparto'] ?? ''));

    return $role === 3 && appIsValidDepartment($requestedDepartment)
        ? $requestedDepartment
        : $sessionDepartment;
}

function holidayCanManage(array $viewer, string $department): bool
{
    $role = (int) ($viewer['capo'] ?? 0);
    $viewerDepartment = trim((string) ($viewer['reparto'] ?? ''));
    return $role === 3 || ($role === 1 && $viewerDepartment !== '' && $viewerDepartment === $department);
}

function holidayValidYearWeek(int $year, int $week): bool
{
    return $year >= 2020 && $year <= 2100 && $week >= 1 && $week <= 53;
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
    $files = glob(__DIR__ . '/../turni_json/*-' . $department . '.json') ?: [];
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

$viewer = $_SESSION['user'] ?? null;
if (!is_array($viewer) || !$connessione || !($pdo instanceof PDO)) {
    holidayResponse(['ok' => false, 'error' => 'Accesso richiesto'], 401);
}

$department = holidayTargetDepartment($viewer);
$canManage = appIsValidDepartment($department) && holidayCanManage($viewer, $department);
if (!appIsValidDepartment($department)) {
    holidayResponse(['ok' => false, 'error' => 'Reparto non valido'], 403);
}

$viewerCf = (string) ($viewer['cf'] ?? '');
app_session_write_close_if_active();

try {
    holidayEnsureTable($pdo);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        $view = (string) ($_GET['view'] ?? 'year');
        $year = filter_var($_GET['year'] ?? null, FILTER_VALIDATE_INT);
        $week = filter_var($_GET['week'] ?? null, FILTER_VALIDATE_INT);

        if ($year === false || $year === null || $year < 2020 || $year > 2100) {
            holidayResponse(['ok' => false, 'error' => 'Anno non valido'], 400);
        }

        if ($view === 'personal') {
            $personKey = 'user:' . $viewerCf;
            $statement = $pdo->prepare(
                'SELECT id, reparto, iso_year, iso_week, person_key, user_cf, schedule_name, display_name, created_at, updated_at
                 FROM department_holidays
                 WHERE iso_year = ?
                   AND (BINARY user_cf = BINARY ? OR person_key = ?)
                 ORDER BY iso_year, iso_week, reparto, id'
            );
            $statement->execute([$year, $viewerCf, $personKey]);
            holidayResponse([
                'ok' => true,
                'year' => (int) $year,
                'viewer_cf' => $viewerCf,
                'holidays' => $statement->fetchAll(PDO::FETCH_ASSOC),
            ]);
        }

        if ($view === 'year') {
            $statement = $pdo->prepare(
                'SELECT iso_week, COUNT(*) AS total
                 FROM department_holidays
                 WHERE reparto = ? AND iso_year = ?
                 GROUP BY iso_week'
            );
            $statement->execute([$department, $year]);
            $weeks = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $weeks[(string) ((int) $row['iso_week'])] = (int) $row['total'];
            }

            holidayResponse([
                'ok' => true,
                'department' => $department,
                'department_label' => appDepartments()[$department] ?? $department,
                'can_manage' => $canManage,
                'year' => $year,
                'weeks' => $weeks,
            ]);
        }

        if ($view === 'week') {
            if ($week === false || $week === null || !holidayValidYearWeek((int) $year, (int) $week)) {
                holidayResponse(['ok' => false, 'error' => 'Settimana non valida'], 400);
            }

            $statement = $pdo->prepare(
                'SELECT id, person_key, user_cf, schedule_name, display_name, created_at, updated_at
                 FROM department_holidays
                 WHERE reparto = ? AND iso_year = ? AND iso_week = ?
                 ORDER BY display_name, id'
            );
            $statement->execute([$department, $year, $week]);
            $holidays = $statement->fetchAll(PDO::FETCH_ASSOC);
            $people = $canManage ? holidayPeopleForDepartment($pdo, $department) : [];

            holidayResponse([
                'ok' => true,
                'department' => $department,
                'department_label' => appDepartments()[$department] ?? $department,
                'can_manage' => $canManage,
                'year' => (int) $year,
                'week' => (int) $week,
                'people' => $people,
                'holidays' => $holidays,
            ]);
        }

        holidayResponse(['ok' => false, 'error' => 'Vista non valida'], 400);
    }

    if ($method !== 'POST') {
        holidayResponse(['ok' => false, 'error' => 'Metodo non consentito'], 405);
    }
    if (!app_csrf_request_is_valid()) {
        holidayResponse(['ok' => false, 'error' => 'Richiesta non valida. Ricarica la pagina e riprova.'], 403);
    }
    if (!$canManage) {
        holidayResponse(['ok' => false, 'error' => 'Accesso negato'], 403);
    }

    $action = (string) ($_POST['action'] ?? '');
    $year = filter_var($_POST['year'] ?? null, FILTER_VALIDATE_INT);
    $week = filter_var($_POST['week'] ?? null, FILTER_VALIDATE_INT);
    if ($year === false || $year === null || $week === false || $week === null || !holidayValidYearWeek((int) $year, (int) $week)) {
        holidayResponse(['ok' => false, 'error' => 'Settimana non valida'], 400);
    }

    if ($action === 'add') {
        $personKey = trim((string) ($_POST['person_key'] ?? ''));
        $people = holidayPeopleForDepartment($pdo, $department);
        $person = holidayFindPerson($people, $personKey);
        if (!$person) {
            holidayResponse(['ok' => false, 'error' => 'Addetto non trovato nell’anagrafica del reparto'], 422);
        }

        $statement = $pdo->prepare(
            'INSERT INTO department_holidays
                (reparto, iso_year, iso_week, person_key, user_cf, schedule_name, display_name, created_by_cf, updated_by_cf)
             VALUES (?, ?, ?, ?, NULLIF(?, \'\'), ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                user_cf = VALUES(user_cf),
                schedule_name = VALUES(schedule_name),
                display_name = VALUES(display_name),
                updated_by_cf = VALUES(updated_by_cf),
                updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            $department,
            $year,
            $week,
            (string) $person['person_key'],
            (string) $person['user_cf'],
            (string) $person['schedule_name'],
            (string) $person['display_name'],
            $viewerCf,
            $viewerCf,
        ]);

        holidayResponse(['ok' => true, 'saved' => true]);
    }

    if ($action === 'delete') {
        $holidayId = (int) ($_POST['holiday_id'] ?? 0);
        if ($holidayId < 1) {
            holidayResponse(['ok' => false, 'error' => 'Ferie non valide'], 400);
        }
        $statement = $pdo->prepare(
            'DELETE FROM department_holidays
             WHERE id = ? AND reparto = ? AND iso_year = ? AND iso_week = ?'
        );
        $statement->execute([$holidayId, $department, $year, $week]);
        holidayResponse(['ok' => true, 'deleted' => $statement->rowCount() > 0]);
    }

    holidayResponse(['ok' => false, 'error' => 'Azione non valida'], 400);
} catch (Throwable $error) {
    error_log('Errore ferie reparto: ' . $error->getMessage());
    holidayResponse(['ok' => false, 'error' => 'Gestione ferie temporaneamente non disponibile'], 500);
}

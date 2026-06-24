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

function departmentScheduleResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function departmentScheduleUserName(array $user): string
{
    return trim((string) ($user['nome'] ?? '') . ' ' . (string) ($user['cognome'] ?? ''));
}

function departmentScheduleState(array $user): string
{
    return (int) ($user['attivo'] ?? 1) === 1 ? 'registered' : 'inactive';
}

$sessionUser = $_SESSION['user'] ?? null;
if (!is_array($sessionUser) || !in_array((int) ($sessionUser['capo'] ?? 0), [1, 3], true) || !$connessione || !($pdo instanceof PDO)) {
    departmentScheduleResponse(['ok' => false, 'error' => 'Accesso negato'], 403);
}

$year = filter_var($_GET['year'] ?? null, FILTER_VALIDATE_INT);
$week = filter_var($_GET['week'] ?? null, FILTER_VALIDATE_INT);
if ($year === false || $year === null || $year < 2020 || $year > 2100 || $week === false || $week === null || $week < 1 || $week > 53) {
    departmentScheduleResponse(['ok' => false, 'error' => 'Settimana non valida'], 400);
}

$role = (int) $sessionUser['capo'];
$sessionDepartment = trim((string) ($sessionUser['reparto'] ?? ''));
$requestedDepartment = trim((string) ($_GET['reparto'] ?? ''));
$department = $role === 3 && appIsValidDepartment($requestedDepartment)
    ? $requestedDepartment
    : $sessionDepartment;
if (!appIsValidDepartment($department)) {
    departmentScheduleResponse(['ok' => false, 'error' => 'Reparto non valido'], 403);
}

try {
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
            // Gli orari non associati condividono il valore tecnico
            // __UNREGISTERED__; ogni nominativo Excel deve invece restare visibile.
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

    departmentScheduleResponse([
        'ok' => true,
        'department' => $department,
        'department_label' => appDepartments()[$department] ?? $department,
        'year' => $year,
        'week' => $week,
        'people' => $people,
    ]);
} catch (Throwable $error) {
    error_log('Panoramica reparto non disponibile: ' . $error->getMessage());
    departmentScheduleResponse(['ok' => false, 'error' => 'Panoramica reparto temporaneamente non disponibile'], 500);
}

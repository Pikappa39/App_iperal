<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

holidayModuleBootstrap([
    'connection_files/schedule_adjustment_lib.php',
    'gestore_ods/orario_converter_lib.php',
    'modules/holidays/php/support/response.php',
    'modules/holidays/php/support/week.php',
    'modules/holidays/php/permissions/holiday_permissions.php',
    'modules/holidays/php/repositories/department_holiday_repository.php',
    'modules/holidays/php/repositories/holiday_people_repository.php',
    'modules/holidays/php/services/department_holiday_service.php',
]);

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

        if ($year === false || $year === null || !holidayValidYear((int) $year)) {
            holidayResponse(['ok' => false, 'error' => 'Anno non valido'], 400);
        }

        if ($view === 'personal') {
            holidayResponse(holidayPersonalPayload($pdo, (int) $year, $viewerCf));
        }

        if ($view === 'year') {
            holidayResponse(holidayDepartmentYearPayload($pdo, $department, $canManage, (int) $year));
        }

        if ($view === 'week') {
            if ($week === false || $week === null || !holidayValidYearWeek((int) $year, (int) $week)) {
                holidayResponse(['ok' => false, 'error' => 'Settimana non valida'], 400);
            }
            holidayResponse(holidayDepartmentWeekPayload($pdo, $department, $canManage, (int) $year, (int) $week));
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
        holidayAddForPerson($pdo, $department, (int) $year, (int) $week, trim((string) ($_POST['person_key'] ?? '')), $viewerCf);
        holidayResponse(['ok' => true, 'saved' => true]);
    }

    if ($action === 'delete') {
        $deleted = holidayDeleteById($pdo, $department, (int) $year, (int) $week, (int) ($_POST['holiday_id'] ?? 0));
        holidayResponse(['ok' => true, 'deleted' => $deleted]);
    }

    holidayResponse(['ok' => false, 'error' => 'Azione non valida'], 400);
} catch (DomainException $error) {
    $status = $error->getCode() >= 400 && $error->getCode() <= 499 ? $error->getCode() : 422;
    holidayResponse(['ok' => false, 'error' => $error->getMessage()], $status);
} catch (Throwable $error) {
    error_log('Errore ferie reparto: ' . $error->getMessage());
    holidayResponse(['ok' => false, 'error' => 'Gestione ferie temporaneamente non disponibile'], 500);
}
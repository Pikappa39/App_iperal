<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/bootstrap.php';

$sessionUser = $_SESSION['user'] ?? null;
if (!is_array($sessionUser) || !$connessione || !($pdo instanceof PDO)) {
    scheduleJsonResponse(['ok' => false, 'error' => 'Accesso negato'], 403);
}

$year = filter_var($_GET['year'] ?? null, FILTER_VALIDATE_INT);
$week = filter_var($_GET['week'] ?? null, FILTER_VALIDATE_INT);
if ($year === false || $year === null || $year < 2020 || $year > 2100 || $week === false || $week === null || $week < 1 || $week > 53) {
    scheduleJsonResponse(['ok' => false, 'error' => 'Settimana non valida'], 400);
}

$role = (int) $sessionUser['capo'];
$sessionDepartment = trim((string) ($sessionUser['reparto'] ?? ''));
$requestedDepartment = trim((string) ($_GET['reparto'] ?? ''));
$department = $role === 3 && appIsValidDepartment($requestedDepartment)
    ? $requestedDepartment
    : $sessionDepartment;
app_session_write_close_if_active();
if (!appIsValidDepartment($department)) {
    scheduleJsonResponse(['ok' => false, 'error' => 'Reparto non valido'], 403);
}

try {
    scheduleJsonResponse([
        'ok' => true,
        'department' => $department,
        'department_label' => appDepartments()[$department] ?? $department,
        'year' => $year,
        'week' => $week,
        'people' => loadDepartmentScheduleOverview($pdo, $department, $year, $week),
    ]);
} catch (Throwable $error) {
    error_log('Panoramica reparto non disponibile: ' . $error->getMessage());
    scheduleJsonResponse(['ok' => false, 'error' => 'Panoramica reparto temporaneamente non disponibile'], 500);
}
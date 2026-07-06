<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/bootstrap.php';

if (!isset($_SESSION['user']['cf'])) {
    scheduleJsonResponse(['ok' => false, 'error' => 'Accesso richiesto'], 401);
}

$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT);
$week = filter_input(INPUT_GET, 'week', FILTER_VALIDATE_INT);
$department = trim((string) ($_SESSION['user']['reparto'] ?? ''));
$userCf = (string) $_SESSION['user']['cf'];
app_session_write_close_if_active();

if ($year === false || $year === null || $year < 2020 || $year > 2100 || $week === false || $week === null || $week < 1 || $week > 53) {
    scheduleJsonResponse(['ok' => false, 'error' => 'Settimana non valida'], 400);
}
if (!appIsValidDepartment($department)) {
    scheduleJsonResponse(['ok' => false, 'error' => 'Reparto non valido'], 403);
}

try {
    $rows = appScheduleAdjustmentLoadUserScheduleRows($pdo, $department, $year, $week, $userCf);
    scheduleJsonResponse(['ok' => true, 'rows' => $rows]);
} catch (Throwable $error) {
    error_log('Orario temporaneamente non disponibile: ' . $error->getMessage());
    scheduleJsonResponse(['ok' => false, 'error' => 'Orario temporaneamente non disponibile'], 500);
}
<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/../session_bootstrap.php';
app_session_start();
require __DIR__ . '/connection.php';
require __DIR__ . '/schedule_adjustment_lib.php';

function scheduleResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user']['cf'])) {
    scheduleResponse(['ok' => false, 'error' => 'Accesso richiesto'], 401);
}

$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT);
$week = filter_input(INPUT_GET, 'week', FILTER_VALIDATE_INT);
$department = trim((string) ($_SESSION['user']['reparto'] ?? ''));

if ($year === false || $year === null || $year < 2020 || $year > 2100 || $week === false || $week === null || $week < 1 || $week > 53) {
    scheduleResponse(['ok' => false, 'error' => 'Settimana non valida'], 400);
}
if (!appIsValidDepartment($department)) {
    scheduleResponse(['ok' => false, 'error' => 'Reparto non valido'], 403);
}

try {
    $rows = appScheduleAdjustmentLoadUserScheduleRows($pdo, $department, $year, $week, (string) $_SESSION['user']['cf']);
    scheduleResponse(['ok' => true, 'rows' => $rows]);
} catch (Throwable $error) {
    error_log('Orario temporaneamente non disponibile: ' . $error->getMessage());
    scheduleResponse(['ok' => false, 'error' => 'Orario temporaneamente non disponibile'], 500);
}

<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=300, stale-while-revalidate=60');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/bootstrap.php';

if (!isset($_SESSION['user']['cf'])) {
    scheduleJsonResponse(['ok' => false, 'error' => 'Accesso richiesto'], 401);
}

$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT);
$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT);
$department = trim((string) ($_SESSION['user']['reparto'] ?? ''));
$userCf = (string) $_SESSION['user']['cf'];
app_session_write_close_if_active();

if ($year === false || $year === null || $year < 2020 || $year > 2100 || $month === false || $month === null || $month < 1 || $month > 12) {
    scheduleJsonResponse(['ok' => false, 'error' => 'Mese non valido'], 400);
}
if (!appIsValidDepartment($department)) {
    scheduleJsonResponse(['ok' => false, 'error' => 'Reparto non valido'], 403);
}

try {
    $weeks = [];
    $scheduleVersions = [];
    foreach (scheduleVisibleWeeksForMonth($year, $month) as $key => $weekInfo) {
        $weeks[$key] = appScheduleAdjustmentLoadUserScheduleRows(
            $pdo,
            $department,
            (int) $weekInfo['year'],
            (int) $weekInfo['week'],
            $userCf
        );
        $scheduleVersions[$key] = [
            'fingerprint' => appScheduleAdjustmentCurrentScheduleFingerprint(
                $pdo,
                $department,
                (int) $weekInfo['year'],
                (int) $weekInfo['week']
            ),
        ];
    }

    scheduleJsonResponse([
        'ok' => true,
        'year' => $year,
        'month' => $month,
        'weeks' => $weeks,
        'schedule_versions' => $scheduleVersions,
    ]);
} catch (Throwable $error) {
    error_log('Orario mensile temporaneamente non disponibile: ' . $error->getMessage());
    scheduleJsonResponse(['ok' => false, 'error' => 'Orario mensile temporaneamente non disponibile'], 500);
}
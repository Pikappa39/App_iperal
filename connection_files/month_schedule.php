<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=300, stale-while-revalidate=60');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/../session_bootstrap.php';
app_session_start();
require __DIR__ . '/connection.php';
require __DIR__ . '/schedule_adjustment_lib.php';

function monthScheduleResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function monthScheduleVisibleWeeks(int $year, int $month): array
{
    $firstDay = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        sprintf('%04d-%02d-01', $year, $month),
        new DateTimeZone('Europe/Rome')
    );
    $errors = DateTimeImmutable::getLastErrors();
    if ($firstDay === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return [];
    }

    $firstWeekdayIndex = (int) $firstDay->format('N') - 1;
    $daysInMonth = (int) $firstDay->format('t');
    $trailingCells = (7 - (($firstWeekdayIndex + $daysInMonth) % 7)) % 7;
    $visibleCells = $firstWeekdayIndex + $daysInMonth + $trailingCells;
    $visibleStart = $firstDay->modify(sprintf('-%d days', $firstWeekdayIndex));
    $weeks = [];

    for ($offset = 0; $offset < $visibleCells; $offset++) {
        $date = $visibleStart->modify(sprintf('+%d days', $offset));
        $isoYear = (int) $date->format('o');
        $isoWeek = (int) $date->format('W');
        $weeks[$isoYear . ':' . $isoWeek] = [
            'year' => $isoYear,
            'week' => $isoWeek,
        ];
    }

    return $weeks;
}

if (!isset($_SESSION['user']['cf'])) {
    monthScheduleResponse(['ok' => false, 'error' => 'Accesso richiesto'], 401);
}

$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT);
$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT);
$department = trim((string) ($_SESSION['user']['reparto'] ?? ''));
$userCf = (string) $_SESSION['user']['cf'];
app_session_write_close_if_active();

if ($year === false || $year === null || $year < 2020 || $year > 2100 || $month === false || $month === null || $month < 1 || $month > 12) {
    monthScheduleResponse(['ok' => false, 'error' => 'Mese non valido'], 400);
}
if (!appIsValidDepartment($department)) {
    monthScheduleResponse(['ok' => false, 'error' => 'Reparto non valido'], 403);
}

try {
    $weeks = [];
    $scheduleVersions = [];
    foreach (monthScheduleVisibleWeeks($year, $month) as $key => $weekInfo) {
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

    monthScheduleResponse([
        'ok' => true,
        'year' => $year,
        'month' => $month,
        'weeks' => $weeks,
        'schedule_versions' => $scheduleVersions,
    ]);
} catch (Throwable $error) {
    error_log('Orario mensile temporaneamente non disponibile: ' . $error->getMessage());
    monthScheduleResponse(['ok' => false, 'error' => 'Orario mensile temporaneamente non disponibile'], 500);
}

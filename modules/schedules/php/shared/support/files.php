<?php
declare(strict_types=1);

function appScheduleAdjustmentScheduleFile(string $department, int $year, int $week): ?string
{
    $directory = __DIR__ . '/../../../../../turni_json';
    $file = $directory . DIRECTORY_SEPARATOR . $year . '-' . $week . '-' . $department . '.json';
    if (!is_file($file) && $year === (int) (new DateTimeImmutable('now'))->format('o')) {
        $legacyFile = $directory . DIRECTORY_SEPARATOR . $week . '-' . $department . '.json';
        if (is_file($legacyFile)) {
            $file = $legacyFile;
        }
    }

    return is_file($file) ? $file : null;
}

function appScheduleAdjustmentLoadJsonScheduleRows(string $department, int $year, int $week): ?array
{
    $file = appScheduleAdjustmentScheduleFile($department, $year, $week);
    $raw = $file !== null ? file_get_contents($file) : false;
    $rows = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($rows) ? $rows : null;
}

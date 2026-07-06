<?php
declare(strict_types=1);

function appScheduleAdjustmentDateInfo(string $date): ?array
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Europe/Rome'));
    $errors = DateTimeImmutable::getLastErrors();
    if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $parsed->format('Y-m-d') !== $date) {
        return null;
    }

    return [
        'date' => $parsed,
        'year' => (int) $parsed->format('o'),
        'week' => (int) $parsed->format('W'),
        'day' => APP_SCHEDULE_ADJUSTMENT_DAYS[(int) $parsed->format('N')],
    ];
}

function appScheduleAdjustmentParseShift(string $value): ?array
{
    $value = trim((string) preg_replace('/\s+/u', ' ', $value));
    if ($value === '' || mb_strlen($value) > 255) {
        return null;
    }

    $parts = preg_split('/\s*\/\s*/', $value);
    if (!is_array($parts) || count($parts) < 1 || count($parts) > 2) {
        return null;
    }

    $normalized = [];
    $intervals = [];
    $minutes = 0;
    foreach ($parts as $part) {
        if (!preg_match('/^(\d{1,2})\s*[:.]\s*(\d{2})\s*[-–—]\s*(\d{1,2})\s*[:.]\s*(\d{2})$/u', trim($part), $matches)) {
            return null;
        }

        $startHour = (int) $matches[1];
        $startMinute = (int) $matches[2];
        $endHour = (int) $matches[3];
        $endMinute = (int) $matches[4];
        if ($startHour > 23 || $endHour > 23 || $startMinute > 59 || $endMinute > 59) {
            return null;
        }

        $start = $startHour * 60 + $startMinute;
        $end = $endHour * 60 + $endMinute;
        if ($end < $start) {
            $end += 24 * 60;
        }
        $duration = $end - $start;
        if ($duration === 0 || $duration > 16 * 60) {
            return null;
        }

        $minutes += $duration;
        $intervals[] = ['start' => $start, 'end' => $end];
        $normalized[] = sprintf('%02d:%02d-%02d:%02d', $startHour, $startMinute, $endHour, $endMinute);
    }

    if ($minutes > 16 * 60) {
        return null;
    }
    if (count($intervals) === 2 && ($intervals[0]['end'] > $intervals[1]['start'] || $intervals[0]['end'] > 24 * 60 || $intervals[1]['end'] > 24 * 60)) {
        return null;
    }

    return [
        'shift' => implode(' / ', $normalized),
        'minutes' => $minutes,
    ];
}

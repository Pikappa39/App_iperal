<?php
declare(strict_types=1);

function noteNormalizeDateKey($dateValue): ?string
{
    $dateValue = trim((string) $dateValue);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue)) {
        return null;
    }

    $date = DateTime::createFromFormat('Y-m-d', $dateValue);
    if (!$date || $date->format('Y-m-d') !== $dateValue) {
        return null;
    }

    return $dateValue;
}

function noteNormalizeMonthKey($monthValue): ?string
{
    $monthValue = trim((string) $monthValue);
    if (!preg_match('/^\d{4}-\d{2}$/', $monthValue)) {
        return null;
    }

    return $monthValue;
}
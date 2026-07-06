<?php
declare(strict_types=1);

function scheduleVisibleWeeksForMonth(int $year, int $month): array
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
<?php
declare(strict_types=1);

function holidayValidYear(int $year): bool
{
    return $year >= 2020 && $year <= 2100;
}

function holidayValidYearWeek(int $year, int $week): bool
{
    return holidayValidYear($year) && $week >= 1 && $week <= 53;
}
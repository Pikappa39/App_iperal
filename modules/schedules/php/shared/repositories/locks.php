<?php
declare(strict_types=1);

function appScheduleAdjustmentLockWeek(PDO $pdo, string $department, int $year, int $week): void
{
    $statement = $pdo->prepare(
        'INSERT INTO schedule_week_locks (reparto, iso_year, iso_week)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE touched_at = CURRENT_TIMESTAMP'
    );
    $statement->execute([$department, $year, $week]);
}

function appScheduleAdjustmentLockDepartment(PDO $pdo, string $department): void
{
    $statement = $pdo->prepare(
        'INSERT INTO schedule_department_locks (reparto)
         VALUES (?)
         ON DUPLICATE KEY UPDATE touched_at = CURRENT_TIMESTAMP'
    );
    $statement->execute([$department]);
}

function appScheduleAdjustmentLockDay(PDO $pdo, string $userCf, string $date): void
{
    $statement = $pdo->prepare(
        'INSERT INTO schedule_adjustment_day_locks (user_cf, schedule_date)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE touched_at = CURRENT_TIMESTAMP'
    );
    $statement->execute([$userCf, $date]);
}

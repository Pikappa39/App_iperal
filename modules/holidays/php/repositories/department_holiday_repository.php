<?php
declare(strict_types=1);

function holidayEnsureTable(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS department_holidays (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            reparto VARCHAR(20) NOT NULL,
            iso_year SMALLINT UNSIGNED NOT NULL,
            iso_week TINYINT UNSIGNED NOT NULL,
            person_key VARCHAR(220) NOT NULL,
            user_cf VARCHAR(16) NULL DEFAULT NULL,
            schedule_name VARCHAR(191) NOT NULL,
            display_name VARCHAR(220) NOT NULL,
            created_by_cf VARCHAR(16) NOT NULL,
            updated_by_cf VARCHAR(16) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_department_holidays_week_person (reparto, iso_year, iso_week, person_key),
            INDEX idx_department_holidays_week (reparto, iso_year, iso_week),
            INDEX idx_department_holidays_user (user_cf, iso_year, iso_week)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );
}

function holidayFetchPersonal(PDO $pdo, int $year, string $viewerCf): array
{
    $personKey = 'user:' . $viewerCf;
    $statement = $pdo->prepare(
        'SELECT id, reparto, iso_year, iso_week, person_key, user_cf, schedule_name, display_name, created_at, updated_at
         FROM department_holidays
         WHERE iso_year = ?
           AND (BINARY user_cf = BINARY ? OR person_key = ?)
         ORDER BY iso_year, iso_week, reparto, id'
    );
    $statement->execute([$year, $viewerCf, $personKey]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function holidayFetchYearWeeks(PDO $pdo, string $department, int $year): array
{
    $statement = $pdo->prepare(
        'SELECT iso_week, COUNT(*) AS total
         FROM department_holidays
         WHERE reparto = ? AND iso_year = ?
         GROUP BY iso_week'
    );
    $statement->execute([$department, $year]);

    $weeks = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $weeks[(string) ((int) $row['iso_week'])] = (int) $row['total'];
    }
    return $weeks;
}

function holidayFetchWeek(PDO $pdo, string $department, int $year, int $week): array
{
    $statement = $pdo->prepare(
        'SELECT id, person_key, user_cf, schedule_name, display_name, created_at, updated_at
         FROM department_holidays
         WHERE reparto = ? AND iso_year = ? AND iso_week = ?
         ORDER BY display_name, id'
    );
    $statement->execute([$department, $year, $week]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function holidaySaveDepartmentHoliday(PDO $pdo, string $department, int $year, int $week, array $person, string $viewerCf): void
{
    $statement = $pdo->prepare(
        'INSERT INTO department_holidays
            (reparto, iso_year, iso_week, person_key, user_cf, schedule_name, display_name, created_by_cf, updated_by_cf)
         VALUES (?, ?, ?, ?, NULLIF(?, \'\'), ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            user_cf = VALUES(user_cf),
            schedule_name = VALUES(schedule_name),
            display_name = VALUES(display_name),
            updated_by_cf = VALUES(updated_by_cf),
            updated_at = CURRENT_TIMESTAMP'
    );
    $statement->execute([
        $department,
        $year,
        $week,
        (string) $person['person_key'],
        (string) $person['user_cf'],
        (string) $person['schedule_name'],
        (string) $person['display_name'],
        $viewerCf,
        $viewerCf,
    ]);
}

function holidayDeleteDepartmentHoliday(PDO $pdo, int $holidayId, string $department, int $year, int $week): bool
{
    $statement = $pdo->prepare(
        'DELETE FROM department_holidays
         WHERE id = ? AND reparto = ? AND iso_year = ? AND iso_week = ?'
    );
    $statement->execute([$holidayId, $department, $year, $week]);
    return $statement->rowCount() > 0;
}
<?php
declare(strict_types=1);

function holidayDepartmentLabel(string $department): string
{
    return appDepartments()[$department] ?? $department;
}

function holidayDepartmentYearPayload(PDO $pdo, string $department, bool $canManage, int $year): array
{
    return [
        'ok' => true,
        'department' => $department,
        'department_label' => holidayDepartmentLabel($department),
        'can_manage' => $canManage,
        'year' => $year,
        'weeks' => holidayFetchYearWeeks($pdo, $department, $year),
    ];
}

function holidayDepartmentWeekPayload(PDO $pdo, string $department, bool $canManage, int $year, int $week): array
{
    return [
        'ok' => true,
        'department' => $department,
        'department_label' => holidayDepartmentLabel($department),
        'can_manage' => $canManage,
        'year' => $year,
        'week' => $week,
        'people' => $canManage ? holidayPeopleForDepartment($pdo, $department) : [],
        'holidays' => holidayFetchWeek($pdo, $department, $year, $week),
    ];
}

function holidayPersonalPayload(PDO $pdo, int $year, string $viewerCf): array
{
    return [
        'ok' => true,
        'year' => $year,
        'viewer_cf' => $viewerCf,
        'holidays' => holidayFetchPersonal($pdo, $year, $viewerCf),
    ];
}

function holidayAddForPerson(PDO $pdo, string $department, int $year, int $week, string $personKey, string $viewerCf): void
{
    $people = holidayPeopleForDepartment($pdo, $department);
    $person = holidayFindPerson($people, $personKey);
    if (!$person) {
        throw new DomainException('Addetto non trovato nell anagrafica del reparto', 422);
    }

    holidaySaveDepartmentHoliday($pdo, $department, $year, $week, $person, $viewerCf);
}

function holidayDeleteById(PDO $pdo, string $department, int $year, int $week, int $holidayId): bool
{
    if ($holidayId < 1) {
        throw new DomainException('Ferie non valide', 400);
    }

    return holidayDeleteDepartmentHoliday($pdo, $holidayId, $department, $year, $week);
}
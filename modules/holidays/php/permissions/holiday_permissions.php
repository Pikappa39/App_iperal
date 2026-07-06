<?php
declare(strict_types=1);

function holidayTargetDepartment(array $viewer): string
{
    $role = (int) ($viewer['capo'] ?? 0);
    $sessionDepartment = trim((string) ($viewer['reparto'] ?? ''));
    $requestedDepartment = trim((string) ($_REQUEST['reparto'] ?? ''));

    return $role === 3 && appIsValidDepartment($requestedDepartment)
        ? $requestedDepartment
        : $sessionDepartment;
}

function holidayCanManage(array $viewer, string $department): bool
{
    $role = (int) ($viewer['capo'] ?? 0);
    $viewerDepartment = trim((string) ($viewer['reparto'] ?? ''));
    return $role === 3 || ($role === 1 && $viewerDepartment !== '' && $viewerDepartment === $department);
}
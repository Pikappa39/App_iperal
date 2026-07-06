<?php
declare(strict_types=1);

function holidayCampaignCanManage(array $viewer, string $department): bool
{
    $role = (int) ($viewer['capo'] ?? 0);
    $viewerDepartment = trim((string) ($viewer['reparto'] ?? ''));
    return $role === 3 || $role === 4 || ($role === 1 && $viewerDepartment !== '' && $viewerDepartment === $department);
}

function holidayCampaignCanReviewWeeks(array $viewer, string $department): bool
{
    $role = (int) ($viewer['capo'] ?? 0);
    $viewerDepartment = trim((string) ($viewer['reparto'] ?? ''));
    return $role === 3 || ($role === 1 && $viewerDepartment !== '' && $viewerDepartment === $department);
}

function holidayCampaignDepartment(array $viewer): string
{
    $role = (int) ($viewer['capo'] ?? 0);
    $sessionDepartment = trim((string) ($viewer['reparto'] ?? ''));
    $requestedDepartment = trim((string) ($_REQUEST['reparto'] ?? ''));
    return in_array($role, [3, 4], true) && appIsValidDepartment($requestedDepartment) ? $requestedDepartment : $sessionDepartment;
}

function holidayCampaignUserName(array $viewer): string
{
    return trim((string) ($viewer['nome'] ?? '') . ' ' . (string) ($viewer['cognome'] ?? ''));
}
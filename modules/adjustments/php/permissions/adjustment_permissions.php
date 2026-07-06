<?php
declare(strict_types=1);

function scheduleAdjustmentCanApprove(int $viewerRole): bool
{
    return in_array($viewerRole, [1, 3], true);
}

function scheduleExtraHoursCanDecideSide(array $row, int $viewerRole, string $viewerDepartment, string $side): bool
{
    if (($row['request_kind'] ?? '') !== 'department' || ($row['status'] ?? '') !== 'pending') {
        return false;
    }

    $sideStatus = (string) ($row[$side . '_status'] ?? '');
    if ($sideStatus !== 'pending') {
        return false;
    }

    if ($viewerRole === 3) {
        return true;
    }

    $departmentField = $side === 'origin' ? 'origin_reparto' : 'target_reparto';
    return $viewerRole === 1
        && $viewerDepartment !== ''
        && hash_equals($viewerDepartment, (string) ($row[$departmentField] ?? ''));
}

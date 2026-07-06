<?php
declare(strict_types=1);

function appScheduleAdjustmentCanManageDepartment(int $role, string $viewerDepartment, string $targetDepartment): bool
{
    if ($role === 3) {
        return true;
    }

    return $role === 1 && $viewerDepartment !== '' && $viewerDepartment === $targetDepartment;
}

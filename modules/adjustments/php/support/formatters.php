<?php
declare(strict_types=1);

function scheduleAdjustmentRequestData(array $row): array
{
    return [
        'kind' => 'schedule_adjustment',
        'id' => (int) $row['id'],
        'user_cf' => (string) $row['user_cf'],
        'user_name' => trim((string) ($row['user_name'] ?? '')),
        'reparto' => (string) $row['reparto'],
        'schedule_date' => (string) $row['schedule_date'],
        'day_name' => (string) $row['day_name'],
        'original_shift' => (string) $row['original_shift'],
        'current_original_shift' => (string) $row['current_original_shift'],
        'requested_shift' => (string) $row['requested_shift'],
        'request_note' => (string) ($row['request_note'] ?? ''),
        'status' => (string) $row['status'],
        'review_reason' => (string) ($row['review_reason'] ?? ''),
        'decision_note' => (string) ($row['decision_note'] ?? ''),
        'decided_by_name' => trim((string) ($row['decided_by_name'] ?? '')),
        'decided_at' => $row['decided_at'] ?? null,
        'created_at' => (string) $row['created_at'],
    ];
}

function scheduleAdjustmentStatusRank(string $status): int
{
    return [
        'pending' => 0,
        'review' => 1,
        'approved' => 2,
        'recorded' => 2,
        'rejected' => 3,
    ][$status] ?? 4;
}

function scheduleAdjustmentSortUnified(array $requests): array
{
    usort($requests, static function (array $left, array $right): int {
        $statusCompare = scheduleAdjustmentStatusRank((string) ($left['status'] ?? ''))
            <=> scheduleAdjustmentStatusRank((string) ($right['status'] ?? ''));
        if ($statusCompare !== 0) {
            return $statusCompare;
        }

        $dateCompare = strcmp((string) ($right['schedule_date'] ?? ''), (string) ($left['schedule_date'] ?? ''));
        if ($dateCompare !== 0) {
            return $dateCompare;
        }

        return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
    });

    return $requests;
}

function scheduleExtraHoursDurationLabel(int $minutes): string
{
    $hours = intdiv($minutes, 60);
    $remaining = $minutes % 60;
    if ($hours > 0 && $remaining > 0) {
        return $hours . 'h ' . $remaining . 'm';
    }
    if ($hours > 0) {
        return $hours . 'h';
    }
    return $remaining . 'm';
}

function scheduleExtraHoursNormalizeMinutes($value): ?int
{
    $minutes = filter_var($value, FILTER_VALIDATE_INT);
    if ($minutes === false || $minutes < 15 || $minutes > 16 * 60 || $minutes % 15 !== 0) {
        return null;
    }

    return (int) $minutes;
}

function scheduleExtraHoursOverallStatus(array $row): string
{
    if (($row['origin_status'] ?? '') === 'rejected' || ($row['target_status'] ?? '') === 'rejected') {
        return 'rejected';
    }
    if (($row['origin_status'] ?? '') === 'approved' && ($row['target_status'] ?? '') === 'approved') {
        return 'approved';
    }

    return 'pending';
}

function scheduleExtraHourRequestData(array $row, int $viewerRole, string $viewerDepartment): array
{
    $departments = appDepartments();
    $kind = (string) ($row['request_kind'] ?? '') === 'store' ? 'extra_store' : 'extra_department';
    $minutes = (int) ($row['minutes'] ?? 0);
    $originDepartment = (string) ($row['origin_reparto'] ?? '');
    $targetDepartment = (string) ($row['target_reparto'] ?? '');

    return [
        'kind' => $kind,
        'id' => (int) $row['id'],
        'user_cf' => (string) $row['user_cf'],
        'user_name' => trim((string) ($row['user_name'] ?? '')),
        'origin_reparto' => $originDepartment,
        'origin_reparto_label' => $departments[$originDepartment] ?? $originDepartment,
        'target_reparto' => $targetDepartment,
        'target_reparto_label' => $targetDepartment !== '' ? ($departments[$targetDepartment] ?? $targetDepartment) : '',
        'store_name' => (string) ($row['store_name'] ?? ''),
        'schedule_date' => (string) $row['schedule_date'],
        'minutes' => $minutes,
        'duration_label' => scheduleExtraHoursDurationLabel($minutes),
        'request_note' => (string) ($row['request_note'] ?? ''),
        'status' => (string) $row['status'],
        'origin_status' => (string) ($row['origin_status'] ?? ''),
        'target_status' => (string) ($row['target_status'] ?? ''),
        'origin_decision_note' => (string) ($row['origin_decision_note'] ?? ''),
        'target_decision_note' => (string) ($row['target_decision_note'] ?? ''),
        'origin_decided_by_name' => trim((string) ($row['origin_decided_by_name'] ?? '')),
        'target_decided_by_name' => trim((string) ($row['target_decided_by_name'] ?? '')),
        'can_decide_origin' => scheduleExtraHoursCanDecideSide($row, $viewerRole, $viewerDepartment, 'origin'),
        'can_decide_target' => scheduleExtraHoursCanDecideSide($row, $viewerRole, $viewerDepartment, 'target'),
        'created_at' => (string) $row['created_at'],
    ];
}

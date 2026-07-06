<?php
declare(strict_types=1);

function appScheduleAdjustmentFindOriginalShift(PDO $pdo, string $department, int $year, int $week, string $userCf, string $dayName): ?string
{
    $rows = appScheduleAdjustmentLoadCurrentScheduleRows($pdo, $department, $year, $week);
    if ($rows === null) {
        return null;
    }

    foreach ($rows as $row) {
        if (!is_array($row) || !hash_equals((string) ($row['COD_FISCALE'] ?? ''), $userCf)) {
            continue;
        }
        return trim((string) ($row[$dayName] ?? 'RIPOSO')) ?: 'RIPOSO';
    }

    return null;
}

function appScheduleAdjustmentReconcileUpload(PDO $pdo, string $department, int $year, int $week, array $rows): array
{
    $currentShifts = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $userCf = trim((string) ($row['COD_FISCALE'] ?? ''));
        if ($userCf === '') {
            continue;
        }
        foreach (APP_SCHEDULE_ADJUSTMENT_DAYS as $dayName) {
            $currentShifts[$userCf][$dayName] = trim((string) ($row[$dayName] ?? 'RIPOSO')) ?: 'RIPOSO';
        }
    }

    $statement = $pdo->prepare(
        "SELECT id, user_cf, day_name, current_original_shift, status
         FROM schedule_adjustment_requests
         WHERE reparto = ? AND iso_year = ? AND iso_week = ?"
    );
    $statement->execute([$department, $year, $week]);
    $requests = $statement->fetchAll(PDO::FETCH_ASSOC);
    if ($requests === []) {
        return [];
    }

    $update = $pdo->prepare(
        "UPDATE schedule_adjustment_requests
         SET current_original_shift = ?,
             status = CASE WHEN status IN ('pending', 'review', 'approved') THEN 'review' ELSE status END,
             review_reason = CASE WHEN status IN ('pending', 'review', 'approved') THEN ? ELSE review_reason END,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    );
    $affectedUsers = [];
    foreach ($requests as $request) {
        $userCf = (string) $request['user_cf'];
        $dayName = (string) $request['day_name'];
        $newShift = $currentShifts[$userCf][$dayName] ?? '';
        if ($newShift === (string) $request['current_original_shift']) {
            continue;
        }

        $update->execute([
            $newShift,
            'Il turno previsto è stato aggiornato dopo la segnalazione.',
            (int) $request['id'],
        ]);
        if (in_array((string) $request['status'], ['pending', 'review', 'approved'], true)) {
            $affectedUsers[$userCf] = true;
        }
    }

    return array_keys($affectedUsers);
}

function appScheduleAdjustmentApplyApproved(PDO $pdo, string $userCf, int $year, int $week, array $rows): array
{
    $statement = $pdo->prepare(
        "SELECT day_name, requested_shift
         FROM schedule_adjustment_requests
         WHERE user_cf = ? AND iso_year = ? AND iso_week = ? AND status = 'approved'"
    );
    $statement->execute([$userCf, $year, $week]);
    $adjustments = $statement->fetchAll(PDO::FETCH_ASSOC);
    if ($adjustments === []) {
        return $rows;
    }

    $byDay = [];
    foreach ($adjustments as $adjustment) {
        $byDay[(string) $adjustment['day_name']] = (string) $adjustment['requested_shift'];
    }

    $userRowIndex = null;
    foreach ($rows as $index => $row) {
        if (is_array($row) && hash_equals((string) ($row['COD_FISCALE'] ?? ''), $userCf)) {
            $userRowIndex = $index;
            break;
        }
    }

    if ($userRowIndex === null) {
        $rows[] = array_merge(
            ['ADDETTO' => 'Storico orario', 'COD_FISCALE' => $userCf],
            array_fill_keys(array_values(APP_SCHEDULE_ADJUSTMENT_DAYS), 'RIPOSO')
        );
        $userRowIndex = array_key_last($rows);
    }
    foreach ($byDay as $dayName => $shift) {
        $rows[$userRowIndex][$dayName] = $shift;
    }

    return $rows;
}

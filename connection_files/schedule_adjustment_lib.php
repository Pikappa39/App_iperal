<?php
declare(strict_types=1);

const APP_SCHEDULE_ADJUSTMENT_DAYS = [
    1 => 'lunedì',
    2 => 'martedì',
    3 => 'mercoledì',
    4 => 'giovedì',
    5 => 'venerdì',
    6 => 'sabato',
    7 => 'domenica',
];

function appScheduleAdjustmentDateInfo(string $date): ?array
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Europe/Rome'));
    $errors = DateTimeImmutable::getLastErrors();
    if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $parsed->format('Y-m-d') !== $date) {
        return null;
    }

    return [
        'date' => $parsed,
        'year' => (int) $parsed->format('o'),
        'week' => (int) $parsed->format('W'),
        'day' => APP_SCHEDULE_ADJUSTMENT_DAYS[(int) $parsed->format('N')],
    ];
}

function appScheduleAdjustmentParseShift(string $value): ?array
{
    $value = trim((string) preg_replace('/\s+/u', ' ', $value));
    if ($value === '' || mb_strlen($value) > 255) {
        return null;
    }

    $parts = preg_split('/\s*\/\s*/', $value);
    if (!is_array($parts) || count($parts) < 1 || count($parts) > 2) {
        return null;
    }

    $normalized = [];
    $intervals = [];
    $minutes = 0;
    foreach ($parts as $part) {
        if (!preg_match('/^(\d{1,2})\s*[:.]\s*(\d{2})\s*[-–—]\s*(\d{1,2})\s*[:.]\s*(\d{2})$/u', trim($part), $matches)) {
            return null;
        }

        $startHour = (int) $matches[1];
        $startMinute = (int) $matches[2];
        $endHour = (int) $matches[3];
        $endMinute = (int) $matches[4];
        if ($startHour > 23 || $endHour > 23 || $startMinute > 59 || $endMinute > 59) {
            return null;
        }

        $start = $startHour * 60 + $startMinute;
        $end = $endHour * 60 + $endMinute;
        if ($end < $start) {
            $end += 24 * 60;
        }
        $duration = $end - $start;
        if ($duration === 0 || $duration > 16 * 60) {
            return null;
        }

        $minutes += $duration;
        $intervals[] = ['start' => $start, 'end' => $end];
        $normalized[] = sprintf('%02d:%02d-%02d:%02d', $startHour, $startMinute, $endHour, $endMinute);
    }

    if ($minutes > 16 * 60) {
        return null;
    }
    if (count($intervals) === 2 && ($intervals[0]['end'] > $intervals[1]['start'] || $intervals[0]['end'] > 24 * 60 || $intervals[1]['end'] > 24 * 60)) {
        return null;
    }

    return [
        'shift' => implode(' / ', $normalized),
        'minutes' => $minutes,
    ];
}

function appScheduleAdjustmentScheduleFile(string $department, int $year, int $week): ?string
{
    $directory = __DIR__ . '/../turni_json';
    $file = $directory . DIRECTORY_SEPARATOR . $year . '-' . $week . '-' . $department . '.json';
    if (!is_file($file) && $year === (int) (new DateTimeImmutable('now'))->format('o')) {
        $legacyFile = $directory . DIRECTORY_SEPARATOR . $week . '-' . $department . '.json';
        if (is_file($legacyFile)) {
            $file = $legacyFile;
        }
    }

    return is_file($file) ? $file : null;
}

function appScheduleAdjustmentLoadJsonScheduleRows(string $department, int $year, int $week): ?array
{
    $file = appScheduleAdjustmentScheduleFile($department, $year, $week);
    $raw = $file !== null ? file_get_contents($file) : false;
    $rows = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($rows) ? $rows : null;
}

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

function appScheduleAdjustmentLoadActiveScheduleRows(PDO $pdo, string $department, int $year, int $week): ?array
{
    $statement = $pdo->prepare(
        'SELECT v.schedule_snapshot
         FROM schedule_active_versions a
         JOIN schedule_upload_versions v ON v.id = a.version_id
         WHERE a.reparto = ? AND a.iso_year = ? AND a.iso_week = ?
         LIMIT 1'
    );
    $statement->execute([$department, $year, $week]);
    $snapshot = $statement->fetchColumn();
    if ($snapshot === false) {
        return null;
    }

    $rows = json_decode((string) $snapshot, true);
    if (!is_array($rows)) {
        throw new RuntimeException('Versione attiva dell’orario non leggibile');
    }

    return $rows;
}

function appScheduleAdjustmentLoadCurrentScheduleRows(PDO $pdo, string $department, int $year, int $week): ?array
{
    return appScheduleAdjustmentLoadActiveScheduleRows($pdo, $department, $year, $week)
        ?? appScheduleAdjustmentLoadJsonScheduleRows($department, $year, $week);
}

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

function appScheduleAdjustmentCanManageDepartment(int $role, string $viewerDepartment, string $targetDepartment): bool
{
    if ($role === 3) {
        return true;
    }

    return $role === 1 && $viewerDepartment !== '' && $viewerDepartment === $targetDepartment;
}

function appScheduleAdjustmentManagerRecipients(PDO $pdo, string $department): array
{
    $statement = $pdo->prepare(
        'SELECT cod_fiscale
         FROM utenti
         WHERE attivo = 1
           AND (capo = 3 OR (reparto = ? AND capo = 1))'
    );
    $statement->execute([$department]);
    return array_map(static fn (array $row): string => (string) $row['cod_fiscale'], $statement->fetchAll(PDO::FETCH_ASSOC));
}

function appScheduleAdjustmentLatestUploadVersion(PDO $pdo, string $department, int $year, int $week): ?string
{
    $statement = $pdo->prepare(
        'SELECT version_id
         FROM schedule_active_versions
         WHERE reparto = ? AND iso_year = ? AND iso_week = ?
         LIMIT 1'
    );
    $statement->execute([$department, $year, $week]);
    $id = $statement->fetchColumn();
    return is_string($id) && $id !== '' ? $id : null;
}

function appScheduleAdjustmentStoreUploadVersion(
    PDO $pdo,
    string $id,
    string $department,
    int $year,
    int $week,
    string $sourceFile,
    string $uploadedByCf,
    array $rows
): void {
    $snapshot = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($snapshot === false) {
        throw new RuntimeException('Impossibile archiviare la versione dell’orario');
    }

    $statement = $pdo->prepare(
        'INSERT INTO schedule_upload_versions
            (id, reparto, iso_year, iso_week, source_file, uploaded_by_cf, schedule_snapshot)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([$id, $department, $year, $week, $sourceFile, $uploadedByCf, $snapshot]);

    $activate = $pdo->prepare(
        'INSERT INTO schedule_active_versions (reparto, iso_year, iso_week, version_id)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE version_id = VALUES(version_id), updated_at = CURRENT_TIMESTAMP'
    );
    $activate->execute([$department, $year, $week, $id]);
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

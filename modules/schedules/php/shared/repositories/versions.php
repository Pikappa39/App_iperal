<?php
declare(strict_types=1);

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

function appScheduleAdjustmentLoadUserScheduleRows(PDO $pdo, string $department, int $year, int $week, string $userCf): array
{
    $rows = appScheduleAdjustmentLoadCurrentScheduleRows($pdo, $department, $year, $week) ?? [];
    $rows = appScheduleAdjustmentApplyApproved($pdo, $userCf, $year, $week, $rows);
    $normalizedUserCf = strtoupper(trim($userCf));

    return array_values(array_filter($rows, static function ($row) use ($normalizedUserCf): bool {
        if (!is_array($row)) {
            return false;
        }

        return strtoupper(trim((string) ($row['COD_FISCALE'] ?? ''))) === $normalizedUserCf;
    }));
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

function appScheduleAdjustmentCurrentScheduleFingerprint(PDO $pdo, string $department, int $year, int $week): string
{
    $statement = $pdo->prepare(
        'SELECT version_id, updated_at
         FROM schedule_active_versions
         WHERE reparto = ? AND iso_year = ? AND iso_week = ?
         LIMIT 1'
    );
    $statement->execute([$department, $year, $week]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (is_array($row) && (string) ($row['version_id'] ?? '') !== '') {
        return hash('sha256', implode('|', [
            $department,
            (string) $year,
            (string) $week,
            (string) $row['version_id'],
            (string) ($row['updated_at'] ?? ''),
        ]));
    }

    $file = appScheduleAdjustmentScheduleFile($department, $year, $week);
    if ($file === null) {
        return hash('sha256', implode('|', [$department, (string) $year, (string) $week, 'missing']));
    }

    return hash('sha256', implode('|', [
        $department,
        (string) $year,
        (string) $week,
        basename($file),
        (string) (filemtime($file) ?: 0),
        (string) (filesize($file) ?: 0),
    ]));
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

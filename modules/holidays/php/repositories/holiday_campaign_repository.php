<?php
declare(strict_types=1);

function holidayCampaignEnsureColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    $statement = $pdo->prepare(
        "SELECT 1
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1"
    );
    $statement->execute([$table, $column]);
    if ($statement->fetchColumn()) {
        return;
    }

    $pdo->exec(sprintf('ALTER TABLE %s ADD %s', $table, $definition));
}

function holidayCampaignEnsureTables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS holiday_campaigns (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            reparto VARCHAR(20) NOT NULL,
            holiday_year SMALLINT UNSIGNED NOT NULL,
            status ENUM('draft', 'open', 'closed') NOT NULL DEFAULT 'draft',
            opened_by_cf VARCHAR(16) NULL DEFAULT NULL,
            opened_at DATETIME NULL DEFAULT NULL,
            closed_by_cf VARCHAR(16) NULL DEFAULT NULL,
            closed_at DATETIME NULL DEFAULT NULL,
            submitted_to_director TINYINT(1) NOT NULL DEFAULT 0,
            submitted_by_cf VARCHAR(16) NULL DEFAULT NULL,
            submitted_at DATETIME NULL DEFAULT NULL,
            director_approved TINYINT(1) NOT NULL DEFAULT 0,
            director_approval_simulated TINYINT(1) NOT NULL DEFAULT 0,
            director_approved_by_cf VARCHAR(16) NULL DEFAULT NULL,
            director_approved_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_holiday_campaign_department_year (reparto, holiday_year),
            INDEX idx_holiday_campaign_status (reparto, status, holiday_year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS holiday_preferences (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            campaign_id BIGINT UNSIGNED NOT NULL,
            reparto VARCHAR(20) NOT NULL,
            iso_year SMALLINT UNSIGNED NOT NULL,
            iso_week TINYINT UNSIGNED NOT NULL,
            user_cf VARCHAR(16) NOT NULL,
            person_key VARCHAR(220) NOT NULL,
            display_name VARCHAR(220) NOT NULL,
            status ENUM('pending', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
            approved_by_manager TINYINT(1) NOT NULL DEFAULT 0,
            approved_by_admin TINYINT(1) NOT NULL DEFAULT 0,
            approved_by_director TINYINT(1) NOT NULL DEFAULT 1,
            decided_by_cf VARCHAR(16) NULL DEFAULT NULL,
            decided_at DATETIME NULL DEFAULT NULL,
            decision_note VARCHAR(1000) NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_holiday_preferences_user_week (campaign_id, user_cf, iso_year, iso_week),
            INDEX idx_holiday_preferences_week (campaign_id, iso_year, iso_week, status),
            INDEX idx_holiday_preferences_user (user_cf, campaign_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    holidayEnsureTable($pdo);

    holidayCampaignEnsureColumn($pdo, 'holiday_campaigns', 'submitted_to_director', 'submitted_to_director TINYINT(1) NOT NULL DEFAULT 0 AFTER closed_at');
    holidayCampaignEnsureColumn($pdo, 'holiday_campaigns', 'submitted_by_cf', 'submitted_by_cf VARCHAR(16) NULL DEFAULT NULL AFTER submitted_to_director');
    holidayCampaignEnsureColumn($pdo, 'holiday_campaigns', 'submitted_at', 'submitted_at DATETIME NULL DEFAULT NULL AFTER submitted_by_cf');
    holidayCampaignEnsureColumn($pdo, 'holiday_campaigns', 'director_approved', 'director_approved TINYINT(1) NOT NULL DEFAULT 0 AFTER submitted_at');
    holidayCampaignEnsureColumn($pdo, 'holiday_campaigns', 'director_approval_simulated', 'director_approval_simulated TINYINT(1) NOT NULL DEFAULT 0 AFTER director_approved');
    holidayCampaignEnsureColumn($pdo, 'holiday_campaigns', 'director_approved_by_cf', 'director_approved_by_cf VARCHAR(16) NULL DEFAULT NULL AFTER director_approval_simulated');
    holidayCampaignEnsureColumn($pdo, 'holiday_campaigns', 'director_approved_at', 'director_approved_at DATETIME NULL DEFAULT NULL AFTER director_approved_by_cf');

    holidayCampaignEnsureColumn($pdo, 'holiday_preferences', 'approved_by_manager', 'approved_by_manager TINYINT(1) NOT NULL DEFAULT 0 AFTER status');
    holidayCampaignEnsureColumn($pdo, 'holiday_preferences', 'approved_by_admin', 'approved_by_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER approved_by_manager');
    holidayCampaignEnsureColumn($pdo, 'holiday_preferences', 'approved_by_director', 'approved_by_director TINYINT(1) NOT NULL DEFAULT 1 AFTER approved_by_admin');
}

function holidayCampaignFetch(PDO $pdo, string $department, int $year): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, reparto, holiday_year, status, opened_at, closed_at, submitted_to_director, submitted_by_cf, submitted_at,
                director_approved, director_approval_simulated, director_approved_by_cf, director_approved_at, updated_at
         FROM holiday_campaigns
         WHERE reparto = ? AND holiday_year = ?
         LIMIT 1'
    );
    $statement->execute([$department, $year]);
    $campaign = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($campaign) ? $campaign : null;
}

function holidayCampaignFetchPreferences(PDO $pdo, int $campaignId): array
{
    $statement = $pdo->prepare(
        "SELECT id, iso_year, iso_week, user_cf, person_key, display_name, status,
                approved_by_manager, approved_by_admin, approved_by_director,
                decided_by_cf, decided_at, updated_at
         FROM holiday_preferences
         WHERE campaign_id = ?
           AND status <> 'cancelled'
         ORDER BY iso_year, iso_week, display_name"
    );
    $statement->execute([$campaignId]);
    $preferences = $statement->fetchAll(PDO::FETCH_ASSOC);
    foreach ($preferences as &$preference) {
        $preference['is_finally_approved'] = holidayCampaignFinalApproval($preference);
    }
    unset($preference);
    return $preferences;
}

function holidayCampaignLoadPreference(PDO $pdo, int $preferenceId, int $campaignId): ?array
{
    $statement = $pdo->prepare(
        'SELECT *
         FROM holiday_preferences
         WHERE id = ? AND campaign_id = ?
         LIMIT 1'
    );
    $statement->execute([$preferenceId, $campaignId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function holidayCampaignOpen(PDO $pdo, string $department, int $year, string $viewerCf): void
{
    $statement = $pdo->prepare(
        "INSERT INTO holiday_campaigns (
            reparto, holiday_year, status, opened_by_cf, opened_at,
            submitted_to_director, submitted_by_cf, submitted_at,
            director_approved, director_approval_simulated, director_approved_by_cf, director_approved_at,
            closed_by_cf, closed_at
        )
         VALUES (?, ?, 'open', ?, NOW(), 0, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL)
         ON DUPLICATE KEY UPDATE
            status = 'open',
            opened_by_cf = VALUES(opened_by_cf),
            opened_at = NOW(),
            submitted_to_director = 0,
            submitted_by_cf = NULL,
            submitted_at = NULL,
            director_approved = 0,
            director_approval_simulated = 0,
            director_approved_by_cf = NULL,
            director_approved_at = NULL,
            closed_by_cf = NULL,
            closed_at = NULL,
            updated_at = CURRENT_TIMESTAMP"
    );
    $statement->execute([$department, $year, $viewerCf]);
}

function holidayCampaignClose(PDO $pdo, string $department, int $year, string $viewerCf): void
{
    $statement = $pdo->prepare(
        "UPDATE holiday_campaigns
         SET status = 'closed', closed_by_cf = ?, closed_at = NOW(), updated_at = CURRENT_TIMESTAMP
         WHERE reparto = ? AND holiday_year = ?"
    );
    $statement->execute([$viewerCf, $department, $year]);
}

function holidayCampaignMarkSubmitted(PDO $pdo, int $campaignId, string $viewerCf, bool $isDirector): void
{
    $statement = $pdo->prepare(
        "UPDATE holiday_campaigns
         SET status = 'closed',
             closed_by_cf = ?,
             closed_at = NOW(),
             submitted_to_director = 1,
             submitted_by_cf = ?,
             submitted_at = NOW(),
             director_approved = 1,
             director_approval_simulated = ?,
             director_approved_by_cf = ?,
             director_approved_at = NOW(),
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    );
    $statement->execute([$viewerCf, $viewerCf, $isDirector ? 0 : 1, $viewerCf, $campaignId]);
}

function holidayCampaignApplyWeekDecision(PDO $pdo, array $preference, string $decision, array $viewer): void
{
    $role = (int) ($viewer['capo'] ?? 0);
    $viewerCf = (string) ($viewer['cf'] ?? '');

    if ($decision === 'reset') {
        $statement = $pdo->prepare(
            "UPDATE holiday_preferences
             SET status = 'pending',
                 approved_by_manager = 0,
                 approved_by_admin = 0,
                 approved_by_director = 1,
                 decided_by_cf = ?,
                 decided_at = NOW(),
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $statement->execute([$viewerCf, (int) $preference['id']]);
        return;
    }

    if ($decision === 'reject') {
        $statement = $pdo->prepare(
            "UPDATE holiday_preferences
             SET status = 'rejected',
                 decided_by_cf = ?,
                 decided_at = NOW(),
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $statement->execute([$viewerCf, (int) $preference['id']]);
        return;
    }

    $approvedByManager = (int) ($preference['approved_by_manager'] ?? 0);
    $approvedByAdmin = (int) ($preference['approved_by_admin'] ?? 0);
    if ($role === 3) {
        $approvedByAdmin = 1;
    } else {
        $approvedByManager = 1;
    }
    $status = ((int) ($preference['approved_by_director'] ?? 1) === 1 && ($approvedByManager === 1 || $approvedByAdmin === 1))
        ? 'approved'
        : 'pending';

    $statement = $pdo->prepare(
        "UPDATE holiday_preferences
         SET status = ?,
             approved_by_manager = ?,
             approved_by_admin = ?,
             approved_by_director = 1,
             decided_by_cf = ?,
             decided_at = NOW(),
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    );
    $statement->execute([$status, $approvedByManager, $approvedByAdmin, $viewerCf, (int) $preference['id']]);
}

function holidayCampaignPublishApprovedPreferences(PDO $pdo, array $campaign, array $viewer): int
{
    $statement = $pdo->prepare(
        "SELECT user_cf, person_key, display_name, iso_year, iso_week
         FROM holiday_preferences
         WHERE campaign_id = ?
           AND status = 'approved'"
    );
    $statement->execute([(int) $campaign['id']]);
    $approvedRows = $statement->fetchAll(PDO::FETCH_ASSOC);
    if ($approvedRows === []) {
        return 0;
    }

    $viewerCf = (string) ($viewer['cf'] ?? '');
    $save = $pdo->prepare(
        "INSERT INTO department_holidays
            (reparto, iso_year, iso_week, person_key, user_cf, schedule_name, display_name, created_by_cf, updated_by_cf)
         VALUES (?, ?, ?, ?, NULLIF(?, ''), ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            user_cf = VALUES(user_cf),
            schedule_name = VALUES(schedule_name),
            display_name = VALUES(display_name),
            updated_by_cf = VALUES(updated_by_cf),
            updated_at = CURRENT_TIMESTAMP"
    );

    foreach ($approvedRows as $row) {
        $save->execute([
            (string) $campaign['reparto'],
            (int) $row['iso_year'],
            (int) $row['iso_week'],
            (string) $row['person_key'],
            (string) $row['user_cf'],
            '',
            (string) $row['display_name'],
            $viewerCf,
            $viewerCf,
        ]);
    }

    return count($approvedRows);
}

function holidayCampaignLoadOwnPreference(PDO $pdo, int $campaignId, string $viewerCf, int $year, int $week): ?array
{
    $existing = $pdo->prepare(
        "SELECT id, status
         FROM holiday_preferences
         WHERE campaign_id = ? AND user_cf = ? AND iso_year = ? AND iso_week = ?
         LIMIT 1"
    );
    $existing->execute([$campaignId, $viewerCf, $year, $week]);
    $current = $existing->fetch(PDO::FETCH_ASSOC);
    return is_array($current) ? $current : null;
}

function holidayCampaignCancelPreference(PDO $pdo, int $preferenceId, string $viewerCf): void
{
    $update = $pdo->prepare(
        "UPDATE holiday_preferences
         SET status = 'cancelled',
             approved_by_manager = 0,
             approved_by_admin = 0,
             approved_by_director = 1,
             decided_by_cf = ?,
             decided_at = NOW(),
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    );
    $update->execute([$viewerCf, $preferenceId]);
}

function holidayCampaignUpsertPreference(PDO $pdo, int $campaignId, string $department, int $year, int $week, string $viewerCf, string $viewerName): void
{
    $personKey = 'user:' . $viewerCf;
    $insert = $pdo->prepare(
        "INSERT INTO holiday_preferences
            (campaign_id, reparto, iso_year, iso_week, user_cf, person_key, display_name, status, approved_by_manager, approved_by_admin, approved_by_director, decided_by_cf, decided_at, decision_note)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 0, 0, 1, NULL, NULL, NULL)
         ON DUPLICATE KEY UPDATE
            person_key = VALUES(person_key),
            display_name = VALUES(display_name),
            status = 'pending',
            approved_by_manager = 0,
            approved_by_admin = 0,
            approved_by_director = 1,
            decided_by_cf = NULL,
            decided_at = NULL,
            decision_note = NULL,
            updated_at = CURRENT_TIMESTAMP"
    );
    $insert->execute([$campaignId, $department, $year, $week, $viewerCf, $personKey, $viewerName]);
}
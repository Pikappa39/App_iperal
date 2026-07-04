<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/../app_config.php';
require __DIR__ . '/../session_bootstrap.php';
app_session_start();
require __DIR__ . '/connection.php';

function holidayCampaignResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

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

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS department_holidays (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            reparto VARCHAR(20) NOT NULL,
            iso_year SMALLINT UNSIGNED NOT NULL,
            iso_week TINYINT UNSIGNED NOT NULL,
            person_key VARCHAR(220) NOT NULL,
            user_cf VARCHAR(16) NULL DEFAULT NULL,
            schedule_name VARCHAR(191) NOT NULL,
            display_name VARCHAR(220) NOT NULL,
            created_by_cf VARCHAR(16) NOT NULL,
            updated_by_cf VARCHAR(16) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_department_holidays_week_person (reparto, iso_year, iso_week, person_key),
            INDEX idx_department_holidays_week (reparto, iso_year, iso_week),
            INDEX idx_department_holidays_user (user_cf, iso_year, iso_week)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );

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

function holidayCampaignFinalApproval(array $preference): bool
{
    return (int) ($preference['approved_by_director'] ?? 0) === 1
        && (
            (int) ($preference['approved_by_manager'] ?? 0) === 1
            || (int) ($preference['approved_by_admin'] ?? 0) === 1
        );
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

function holidayCampaignSummarizePreferences(array $preferences): array
{
    $summary = [
        'pending' => 0,
        'approved' => 0,
        'rejected' => 0,
        'cancelled' => 0,
        'total' => 0,
    ];
    foreach ($preferences as $preference) {
        $status = (string) ($preference['status'] ?? 'pending');
        if (!array_key_exists($status, $summary)) {
            $status = 'pending';
        }
        $summary[$status]++;
        $summary['total']++;
    }
    return $summary;
}

function holidayCampaignCanSubmitToDirector(?array $campaign, array $preferences): bool
{
    if (!is_array($campaign) || (string) ($campaign['status'] ?? '') !== 'open') {
        return false;
    }
    if ((int) ($campaign['submitted_to_director'] ?? 0) === 1) {
        return false;
    }

    $summary = holidayCampaignSummarizePreferences($preferences);
    return $summary['approved'] > 0 && $summary['pending'] === 0;
}

function holidayCampaignValidateSelection(array $campaign, int $year, int $week): array
{
    $rules = [];

    if ($year !== (int) $campaign['holiday_year']) {
        $rules[] = [
            'severity' => 'block',
            'code' => 'wrong_year',
            'message' => 'Puoi scegliere solo settimane dell\'anno ferie attivo.',
        ];
    }

    if ($week < 1 || $week > 53) {
        $rules[] = [
            'severity' => 'block',
            'code' => 'invalid_week',
            'message' => 'Settimana non valida.',
        ];
    }

    return [
        'ok' => !array_filter($rules, static fn (array $rule): bool => $rule['severity'] === 'block'),
        'rules' => $rules,
    ];
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
        'INSERT INTO department_holidays
            (reparto, iso_year, iso_week, person_key, user_cf, schedule_name, display_name, created_by_cf, updated_by_cf)
         VALUES (?, ?, ?, ?, NULLIF(?, \'\'), ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            user_cf = VALUES(user_cf),
            schedule_name = VALUES(schedule_name),
            display_name = VALUES(display_name),
            updated_by_cf = VALUES(updated_by_cf),
            updated_at = CURRENT_TIMESTAMP'
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

$viewer = $_SESSION['user'] ?? null;
if (!is_array($viewer) || !$connessione || !($pdo instanceof PDO)) {
    holidayCampaignResponse(['ok' => false, 'error' => 'Accesso richiesto'], 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !app_csrf_request_is_valid()) {
    holidayCampaignResponse(['ok' => false, 'error' => 'Richiesta non valida. Ricarica la pagina e riprova.'], 403);
}

$department = holidayCampaignDepartment($viewer);
if (!appIsValidDepartment($department)) {
    holidayCampaignResponse(['ok' => false, 'error' => 'Reparto non valido'], 403);
}

$viewerCf = (string) ($viewer['cf'] ?? '');
$viewerName = holidayCampaignUserName($viewer);
$canManage = holidayCampaignCanManage($viewer, $department);
$canReviewWeeks = holidayCampaignCanReviewWeeks($viewer, $department);
app_session_write_close_if_active();

try {
    holidayCampaignEnsureTables($pdo);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $year = filter_var($_REQUEST['year'] ?? date('o'), FILTER_VALIDATE_INT);
    if ($year === false || $year === null || $year < 2020 || $year > 2100) {
        holidayCampaignResponse(['ok' => false, 'error' => 'Anno non valido'], 400);
    }

    if ($method === 'GET') {
        $campaign = holidayCampaignFetch($pdo, $department, (int) $year);
        $preferences = $campaign ? holidayCampaignFetchPreferences($pdo, (int) $campaign['id']) : [];
        holidayCampaignResponse([
            'ok' => true,
            'department' => $department,
            'department_label' => appDepartments()[$department] ?? $department,
            'year' => (int) $year,
            'can_manage' => $canManage,
            'can_review_weeks' => $canReviewWeeks,
            'active' => is_array($campaign) && (string) $campaign['status'] === 'open',
            'campaign' => $campaign,
            'preferences' => $preferences,
            'summary' => holidayCampaignSummarizePreferences($preferences),
            'can_submit_to_director' => holidayCampaignCanSubmitToDirector($campaign, $preferences),
            'viewer_cf' => $viewerCf,
        ]);
    }

    if ($method !== 'POST') {
        holidayCampaignResponse(['ok' => false, 'error' => 'Metodo non consentito'], 405);
    }

    $action = (string) ($_POST['action'] ?? '');
    if (in_array($action, ['open', 'close', 'submit_to_director'], true) && !$canManage) {
        holidayCampaignResponse(['ok' => false, 'error' => 'Accesso negato'], 403);
    }
    if ($action === 'review_preference' && !$canReviewWeeks) {
        holidayCampaignResponse(['ok' => false, 'error' => 'Accesso negato'], 403);
    }

    if ($action === 'open') {
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
        holidayCampaignResponse(['ok' => true, 'campaign' => holidayCampaignFetch($pdo, $department, (int) $year)]);
    }

    if ($action === 'close') {
        $statement = $pdo->prepare(
            "UPDATE holiday_campaigns
             SET status = 'closed', closed_by_cf = ?, closed_at = NOW(), updated_at = CURRENT_TIMESTAMP
             WHERE reparto = ? AND holiday_year = ?"
        );
        $statement->execute([$viewerCf, $department, $year]);
        holidayCampaignResponse(['ok' => true, 'campaign' => holidayCampaignFetch($pdo, $department, (int) $year)]);
    }

    $campaign = holidayCampaignFetch($pdo, $department, (int) $year);
    if (!is_array($campaign)) {
        holidayCampaignResponse(['ok' => false, 'error' => 'Campagna ferie non trovata'], 404);
    }

    if ($action === 'submit_to_director') {
        $preferences = holidayCampaignFetchPreferences($pdo, (int) $campaign['id']);
        if (!holidayCampaignCanSubmitToDirector($campaign, $preferences)) {
            holidayCampaignResponse(['ok' => false, 'error' => 'Prima completa la revisione di tutte le richieste e approva almeno una settimana.'], 409);
        }

        $isDirector = (int) ($viewer['capo'] ?? 0) === 4;
        $pdo->beginTransaction();
        $published = holidayCampaignPublishApprovedPreferences($pdo, $campaign, $viewer);
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
        $statement->execute([$viewerCf, $viewerCf, $isDirector ? 0 : 1, $viewerCf, (int) $campaign['id']]);
        $pdo->commit();

        holidayCampaignResponse([
            'ok' => true,
            'published' => $published,
            'simulated_director' => !$isDirector,
            'message' => !$isDirector
                ? 'Proposta inviata. Approvazione direttore simulata e ferie ufficiali aggiornate.'
                : 'Proposta approvata dal direttore e ferie ufficiali aggiornate.',
            'campaign' => holidayCampaignFetch($pdo, $department, (int) $year),
        ]);
    }

    if ($action === 'review_preference') {
        if ((string) ($campaign['status'] ?? '') !== 'open') {
            holidayCampaignResponse(['ok' => false, 'error' => 'La campagna non e piu modificabile.'], 409);
        }
        $preferenceId = (int) ($_POST['preference_id'] ?? 0);
        $decision = (string) ($_POST['decision'] ?? '');
        if ($preferenceId < 1 || !in_array($decision, ['approve', 'reject', 'reset'], true)) {
            holidayCampaignResponse(['ok' => false, 'error' => 'Operazione non valida'], 400);
        }

        $preference = holidayCampaignLoadPreference($pdo, $preferenceId, (int) $campaign['id']);
        if (!is_array($preference) || (string) ($preference['status'] ?? '') === 'cancelled') {
            holidayCampaignResponse(['ok' => false, 'error' => 'Richiesta ferie non trovata'], 404);
        }

        holidayCampaignApplyWeekDecision($pdo, $preference, $decision, $viewer);
        holidayCampaignResponse([
            'ok' => true,
            'preferences' => holidayCampaignFetchPreferences($pdo, (int) $campaign['id']),
            'summary' => holidayCampaignSummarizePreferences(holidayCampaignFetchPreferences($pdo, (int) $campaign['id'])),
        ]);
    }

    if ($action !== 'toggle_preference') {
        holidayCampaignResponse(['ok' => false, 'error' => 'Azione non valida'], 400);
    }

    if ((string) ($campaign['status'] ?? '') !== 'open') {
        holidayCampaignResponse(['ok' => false, 'error' => 'Attivita ferie non avviata'], 409);
    }

    $week = filter_var($_POST['week'] ?? null, FILTER_VALIDATE_INT);
    if ($week === false || $week === null) {
        holidayCampaignResponse(['ok' => false, 'error' => 'Settimana non valida'], 400);
    }

    $validation = holidayCampaignValidateSelection($campaign, (int) $year, (int) $week);
    if (!$validation['ok']) {
        holidayCampaignResponse([
            'ok' => false,
            'error' => $validation['rules'][0]['message'] ?? 'Scelta non valida',
            'rules' => $validation['rules'],
        ], 422);
    }

    $campaignId = (int) $campaign['id'];
    $existing = $pdo->prepare(
        "SELECT id, status
         FROM holiday_preferences
         WHERE campaign_id = ? AND user_cf = ? AND iso_year = ? AND iso_week = ?
         LIMIT 1"
    );
    $existing->execute([$campaignId, $viewerCf, $year, $week]);
    $current = $existing->fetch(PDO::FETCH_ASSOC);

    if (is_array($current) && in_array((string) $current['status'], ['pending', 'approved'], true)) {
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
        $update->execute([$viewerCf, (int) $current['id']]);
        holidayCampaignResponse(['ok' => true, 'selected' => false, 'preferences' => holidayCampaignFetchPreferences($pdo, $campaignId)]);
    }

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

    holidayCampaignResponse([
        'ok' => true,
        'selected' => true,
        'preferences' => holidayCampaignFetchPreferences($pdo, $campaignId),
        'rules' => $validation['rules'],
    ]);
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Errore attivita ferie: ' . $error->getMessage());
    holidayCampaignResponse(['ok' => false, 'error' => 'Attivita ferie temporaneamente non disponibile'], 500);
}

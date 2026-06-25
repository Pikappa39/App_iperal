<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/app_config.php';
require __DIR__ . '/session_bootstrap.php';
app_session_start();
require_once __DIR__ . '/connection_files/connection.php';
require_once __DIR__ . '/connection_files/admin_audit_lib.php';
require_once __DIR__ . '/connection_files/invite_lib.php';
require_once __DIR__ . '/connection_files/push_lib.php';

$sessionUser = $_SESSION['user'] ?? null;
if (!is_array($sessionUser) || (int) ($sessionUser['capo'] ?? 0) !== 3) {
    header('Location: index.php', true, 303);
    exit;
}

function appAdminConsoleEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function appAdminConsoleRoleLabel(int $role): string
{
    return match ($role) {
        3 => 'Admin globale',
        2 => 'Vice capo',
        1 => 'Capo reparto',
        default => 'Addetto',
    };
}

function appAdminConsoleDateLabel($value): string
{
    if (!is_string($value) || trim($value) === '') {
        return 'Non disponibile';
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? 'Dato non valido' : date('d/m/Y H:i', $timestamp);
}

function appAdminConsoleLastSeenLabel($value): string
{
    if (!is_string($value) || trim($value) === '') {
        return 'Mai rilevato';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return 'Dato non valido';
    }

    $diff = max(0, time() - $timestamp);
    if ($diff < 120) {
        return 'Attivo adesso';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . ' min fa';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . ' h fa';
    }

    return date('d/m/Y H:i', $timestamp);
}

function appAdminConsoleStatusClass(string $status): string
{
    return match ($status) {
        'accepted' => 'text-bg-success',
        'expired' => 'text-bg-secondary',
        'revoked' => 'text-bg-danger',
        default => 'text-bg-warning',
    };
}

function appAdminConsoleAuditActionLabel(string $action): string
{
    return match ($action) {
        'console_unlock' => 'Console sbloccata',
        'console_unlock_failed' => 'Codice console errato',
        'console_lock' => 'Console bloccata',
        'invite_created' => 'Invito creato',
        'invite_regenerated' => 'Invito rigenerato',
        'invite_manual_link_generated' => 'Link invito generato',
        'invite_revoked' => 'Invito revocato',
        default => $action,
    };
}

function appAdminConsoleAuditDetailsLabel(?string $detailsJson): string
{
    if (!is_string($detailsJson) || trim($detailsJson) === '') {
        return '';
    }

    $details = json_decode($detailsJson, true);
    if (!is_array($details)) {
        return '';
    }

    $parts = [];
    if (!empty($details['email'])) {
        $parts[] = 'Email: ' . (string) $details['email'];
    }
    if (!empty($details['reparto'])) {
        $department = (string) $details['reparto'];
        $parts[] = 'Reparto: ' . (appDepartments()[$department] ?? $department);
    }
    if (!empty($details['status'])) {
        $parts[] = 'Stato: ' . (string) $details['status'];
    }
    if (isset($details['role'])) {
        $parts[] = 'Ruolo: ' . appAdminConsoleRoleLabel((int) $details['role']);
    }
    if (!empty($details['source'])) {
        $parts[] = 'Origine: ' . (string) $details['source'];
    }
    if (!empty($details['reason'])) {
        $parts[] = 'Motivo: ' . (string) $details['reason'];
    }

    return implode(' · ', $parts);
}

function appAdminConsoleBytesLabel($bytes): string
{
    if (!is_numeric($bytes)) {
        return 'Non disponibile';
    }

    $value = (float) $bytes;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }

    return ($index === 0 ? (string) (int) $value : number_format($value, 1, ',', '.')) . ' ' . $units[$index];
}

function appAdminConsoleDiagnosticLabel(string $status): string
{
    return match ($status) {
        'ok' => 'OK',
        'danger' => 'Errore',
        'warning' => 'Attenzione',
        default => 'Info',
    };
}

function appAdminConsoleDiagnosticClass(string $status): string
{
    return match ($status) {
        'ok' => 'text-bg-success',
        'danger' => 'text-bg-danger',
        'warning' => 'text-bg-warning',
        default => 'text-bg-info',
    };
}

function appAdminConsoleAddDiagnostic(
    array &$diagnostics,
    string $area,
    string $name,
    string $status,
    string $value,
    string $detail = ''
): void {
    $diagnostics[] = [
        'area' => $area,
        'name' => $name,
        'status' => in_array($status, ['ok', 'warning', 'danger', 'info'], true) ? $status : 'info',
        'value' => $value,
        'detail' => $detail,
    ];
}

function appAdminConsoleDiagnosticSummary(array $diagnostics): array
{
    $summary = ['ok' => 0, 'warning' => 0, 'danger' => 0, 'info' => 0];
    foreach ($diagnostics as $diagnostic) {
        $status = (string) ($diagnostic['status'] ?? 'info');
        if (!array_key_exists($status, $summary)) {
            $status = 'info';
        }
        $summary[$status]++;
    }

    return $summary;
}

function appAdminConsoleDirectoryDiagnostic(array &$diagnostics, string $label, string $path, bool $mustBeWritable = true): void
{
    if (!is_dir($path)) {
        appAdminConsoleAddDiagnostic($diagnostics, 'Storage', $label, 'danger', 'Cartella assente', $path);
        return;
    }

    if ($mustBeWritable && !is_writable($path)) {
        appAdminConsoleAddDiagnostic($diagnostics, 'Storage', $label, 'danger', 'Non scrivibile', $path);
        return;
    }

    appAdminConsoleAddDiagnostic(
        $diagnostics,
        'Storage',
        $label,
        'ok',
        $mustBeWritable ? 'Scrivibile' : 'Presente',
        $path
    );
}

function appAdminConsoleBackupLogPath(): string
{
    $configured = appEnv('APP_BACKUP_LOG_PATH');
    if ($configured !== '') {
        return $configured;
    }

    foreach ([
        '/home/ubuntu/myorari-backup.log',
        __DIR__ . '/myorari-backup.log',
    ] as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return '';
}

function appAdminConsoleReadTail(string $path, int $bytes = 262144): ?string
{
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }

    $size = @filesize($path);
    if (!is_int($size) || $size <= $bytes) {
        $raw = @file_get_contents($path);
        return is_string($raw) ? $raw : null;
    }

    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return null;
    }

    try {
        fseek($handle, -$bytes, SEEK_END);
        $raw = stream_get_contents($handle);
        return is_string($raw) ? $raw : null;
    } finally {
        fclose($handle);
    }
}

function appAdminConsoleAddBackupDiagnostic(array &$diagnostics): void
{
    $path = appAdminConsoleBackupLogPath();
    if ($path === '') {
        appAdminConsoleAddDiagnostic(
            $diagnostics,
            'Backup',
            'Log backup',
            'warning',
            'Non configurato',
            'Imposta APP_BACKUP_LOG_PATH se vuoi vedere l’ultimo backup dalla console.'
        );
        return;
    }

    $raw = appAdminConsoleReadTail($path);
    if (!is_string($raw) || trim($raw) === '') {
        appAdminConsoleAddDiagnostic($diagnostics, 'Backup', 'Log backup', 'warning', 'Non leggibile', $path);
        return;
    }

    preg_match_all('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) Backup completato.*$/m', $raw, $successes, PREG_OFFSET_CAPTURE);
    preg_match_all('/(^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} ERRORE:.*$|^tar: .*failure.*$|^tar: .*Cannot open.*$)/m', $raw, $errors, PREG_OFFSET_CAPTURE);

    $lastSuccess = $successes[1] ? end($successes[1]) : null;
    $lastSuccessLine = $successes[0] ? end($successes[0]) : null;
    $lastError = $errors[0] ? end($errors[0]) : null;
    $lastSuccessOffset = is_array($lastSuccessLine) ? (int) $lastSuccessLine[1] : -1;
    $lastErrorOffset = is_array($lastError) ? (int) $lastError[1] : -1;

    if ($lastErrorOffset > $lastSuccessOffset) {
        appAdminConsoleAddDiagnostic(
            $diagnostics,
            'Backup',
            'Ultimo backup',
            'danger',
            'Ultimo esito non riuscito',
            is_array($lastError) ? (string) $lastError[0] : $path
        );
        return;
    }

    if (!is_array($lastSuccess) || !is_array($lastSuccessLine)) {
        appAdminConsoleAddDiagnostic($diagnostics, 'Backup', 'Ultimo backup', 'warning', 'Nessun successo nel log', $path);
        return;
    }

    $timestamp = strtotime((string) $lastSuccess[0]);
    if ($timestamp === false) {
        appAdminConsoleAddDiagnostic($diagnostics, 'Backup', 'Ultimo backup', 'warning', 'Data non leggibile', (string) $lastSuccessLine[0]);
        return;
    }

    $ageHours = (int) floor(max(0, time() - $timestamp) / 3600);
    $status = $ageHours <= 30 ? 'ok' : ($ageHours <= 48 ? 'warning' : 'danger');
    appAdminConsoleAddDiagnostic(
        $diagnostics,
        'Backup',
        'Ultimo backup',
        $status,
        $ageHours . ' ore fa',
        (string) $lastSuccessLine[0]
    );
}

function appAdminConsoleBuildDiagnostics(PDO $pdo, bool $consoleConfigured): array
{
    $diagnostics = [];

    appAdminConsoleAddDiagnostic($diagnostics, 'App', 'Versione', 'ok', APP_VERSION, 'Codice applicazione attualmente caricato.');
    appAdminConsoleAddDiagnostic($diagnostics, 'App', 'URL pubblico', appPublicUrl() !== '' ? 'ok' : 'warning', appPublicUrl() ?: 'Non configurato');
    appAdminConsoleAddDiagnostic($diagnostics, 'App', 'PHP', version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'warning', PHP_VERSION);
    $timezone = date_default_timezone_get();
    appAdminConsoleAddDiagnostic($diagnostics, 'App', 'Timezone PHP', $timezone !== '' ? 'info' : 'warning', $timezone !== '' ? $timezone : 'Non disponibile');

    $total = @disk_total_space(__DIR__);
    $free = @disk_free_space(__DIR__);
    if (is_numeric($total) && is_numeric($free) && (float) $total > 0) {
        $usedPercent = (int) round((1 - ((float) $free / (float) $total)) * 100);
        $status = $usedPercent >= 95 || (float) $free < 250 * 1024 * 1024
            ? 'danger'
            : ($usedPercent >= 85 || (float) $free < 1024 * 1024 * 1024 ? 'warning' : 'ok');
        appAdminConsoleAddDiagnostic(
            $diagnostics,
            'Sistema',
            'Spazio disco',
            $status,
            $usedPercent . '% usato',
            appAdminConsoleBytesLabel($free) . ' liberi su ' . appAdminConsoleBytesLabel($total)
        );
    } else {
        appAdminConsoleAddDiagnostic($diagnostics, 'Sistema', 'Spazio disco', 'warning', 'Non leggibile');
    }

    appAdminConsoleAddDiagnostic($diagnostics, 'Database', 'Connessione', 'ok', 'Attiva');
    try {
        $mysqlVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        appAdminConsoleAddDiagnostic($diagnostics, 'Database', 'Versione MySQL', $mysqlVersion !== '' ? 'ok' : 'warning', $mysqlVersion ?: 'Non disponibile');
    } catch (Throwable $error) {
        appAdminConsoleAddDiagnostic($diagnostics, 'Database', 'Versione MySQL', 'warning', 'Non leggibile');
    }

    foreach ([
        'utenti' => 'Tabella utenti',
        'user_invites' => 'Tabella inviti',
        'schedule_name_mappings' => 'Tabella associazioni',
        'admin_audit_log' => 'Tabella audit',
    ] as $table => $label) {
        try {
            $statement = $pdo->prepare('SHOW TABLES LIKE ?');
            $statement->execute([$table]);
            $exists = (bool) $statement->fetchColumn();
            appAdminConsoleAddDiagnostic($diagnostics, 'Database', $label, $exists ? 'ok' : 'danger', $exists ? 'Presente' : 'Assente');
        } catch (Throwable $error) {
            appAdminConsoleAddDiagnostic($diagnostics, 'Database', $label, 'warning', 'Non verificabile');
        }
    }

    try {
        $activeUsers = (int) $pdo->query('SELECT COUNT(*) FROM utenti WHERE attivo = 1')->fetchColumn();
        $inactiveUsers = (int) $pdo->query('SELECT COUNT(*) FROM utenti WHERE attivo = 0')->fetchColumn();
        $pendingInvites = (int) $pdo->query(
            'SELECT COUNT(*)
             FROM user_invites
             WHERE accepted_at IS NULL
               AND revoked_at IS NULL
               AND expires_at >= NOW()'
        )->fetchColumn();
        appAdminConsoleAddDiagnostic($diagnostics, 'Dati', 'Utenti attivi', 'ok', (string) $activeUsers);
        appAdminConsoleAddDiagnostic($diagnostics, 'Dati', 'Utenti disattivati', $inactiveUsers > 0 ? 'info' : 'ok', (string) $inactiveUsers);
        appAdminConsoleAddDiagnostic($diagnostics, 'Dati', 'Inviti in attesa', $pendingInvites > 0 ? 'info' : 'ok', (string) $pendingInvites);
        $adjustmentsToManage = (int) $pdo->query("SELECT COUNT(*) FROM schedule_adjustment_requests WHERE status IN ('pending', 'review')")->fetchColumn();
        appAdminConsoleAddDiagnostic($diagnostics, 'Dati', 'Richieste ore aperte', $adjustmentsToManage > 0 ? 'info' : 'ok', (string) $adjustmentsToManage);
    } catch (Throwable $error) {
        appAdminConsoleAddDiagnostic($diagnostics, 'Dati', 'Conteggi operativi', 'warning', 'Non leggibili');
    }

    appAdminConsoleAddDiagnostic($diagnostics, 'Sicurezza', 'Codice console', $consoleConfigured ? 'ok' : 'danger', $consoleConfigured ? 'Configurato' : 'Mancante');
    appAdminConsoleAddDiagnostic($diagnostics, 'Sicurezza', 'Scadenza console', 'ok', appAdminConsoleTimeoutSeconds() . ' secondi');
    appAdminConsoleAddDiagnostic($diagnostics, 'Sicurezza', 'Registrazione libera', appSelfRegistrationEnabled() ? 'warning' : 'ok', appSelfRegistrationEnabled() ? 'Attiva' : 'Disattivata');
    appAdminConsoleAddDiagnostic($diagnostics, 'Sicurezza', 'Turnstile', appTurnstileEnabled() ? 'ok' : 'info', appTurnstileEnabled() ? 'Configurato' : 'Non configurato');

    $smtpConfigured = appSmtpHost() !== '' && appSmtpUsername() !== '' && appSmtpPassword() !== '';
    appAdminConsoleAddDiagnostic(
        $diagnostics,
        'Mail',
        'SMTP inviti',
        $smtpConfigured ? 'ok' : 'danger',
        $smtpConfigured ? 'Configurato' : 'Incompleto',
        appSmtpUsername() . '@' . appSmtpHost() . ':' . appSmtpPort()
    );

    $pushPath = appPushConfigPath();
    $pushConfigOk = false;
    if (is_file($pushPath) && is_readable($pushPath)) {
        $decoded = json_decode((string) file_get_contents($pushPath), true);
        $pushConfigOk = is_array($decoded) && !empty($decoded['publicKey']) && !empty($decoded['privateKey']);
    }
    appAdminConsoleAddDiagnostic($diagnostics, 'Push', 'Chiavi VAPID', $pushConfigOk ? 'ok' : 'warning', $pushConfigOk ? 'Presenti' : 'Da verificare', $pushPath);
    try {
        $activePush = (int) $pdo->query('SELECT COUNT(*) FROM push_subscriptions WHERE active = 1')->fetchColumn();
        appAdminConsoleAddDiagnostic($diagnostics, 'Push', 'Dispositivi push attivi', $activePush > 0 ? 'ok' : 'info', (string) $activePush);
    } catch (Throwable $error) {
        appAdminConsoleAddDiagnostic($diagnostics, 'Push', 'Dispositivi push attivi', 'warning', 'Non leggibili');
    }

    appAdminConsoleDirectoryDiagnostic($diagnostics, 'storage', __DIR__ . '/storage');
    appAdminConsoleDirectoryDiagnostic($diagnostics, 'sessioni', app_session_storage_path());
    appAdminConsoleDirectoryDiagnostic($diagnostics, 'turni_json', __DIR__ . '/turni_json');
    appAdminConsoleDirectoryDiagnostic($diagnostics, 'note_json', __DIR__ . '/note_json');
    appAdminConsoleDirectoryDiagnostic($diagnostics, 'xlms', __DIR__ . '/xlms', false);

    appAdminConsoleAddBackupDiagnostic($diagnostics);

    return $diagnostics;
}

function appAdminConsoleIsUnlocked(array $sessionUser): bool
{
    $until = (int) ($_SESSION['admin_console_until'] ?? 0);
    $cf = (string) ($_SESSION['admin_console_cf'] ?? '');
    return $until > time() && $cf !== '' && hash_equals((string) ($sessionUser['cf'] ?? ''), $cf);
}

function appAdminConsoleLock(): void
{
    unset($_SESSION['admin_console_until'], $_SESSION['admin_console_cf']);
}

function appAdminConsoleRedirect(array $params = []): void
{
    $query = $params === [] ? '' : '?' . http_build_query($params);
    header('Location: admin_console.php' . $query, true, 303);
    exit;
}

function appAdminConsoleFlash(string $type, string $message, string $link = ''): void
{
    $_SESSION['admin_console_flash'] = [
        'type' => $type,
        'message' => $message,
        'link' => $link,
    ];
}

$codeHash = appAdminConsoleCodeHash();
$consoleConfigured = $codeHash !== '';
$unlocked = appAdminConsoleIsUnlocked($sessionUser);
$appCsrfToken = app_csrf_token();
$flash = $_SESSION['admin_console_flash'] ?? null;
unset($_SESSION['admin_console_flash']);
$departmentFilter = trim((string) ($_GET['reparto'] ?? ''));
if ($departmentFilter !== '' && !appIsValidDepartment($departmentFilter)) {
    $departmentFilter = '';
}
$inviteStatusFilter = trim((string) ($_GET['stato'] ?? ''));
if (!in_array($inviteStatusFilter, ['', 'pending', 'accepted', 'expired', 'revoked'], true)) {
    $inviteStatusFilter = '';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!app_csrf_request_is_valid()) {
        appAdminConsoleFlash('danger', 'Richiesta non valida. Ricarica la pagina e riprova.');
        appAdminConsoleRedirect();
    }

    if ($action === 'unlock') {
        if (!$consoleConfigured) {
            appAdminConsoleFlash('danger', 'Codice console non configurato sul server.');
            appAdminConsoleRedirect();
        }

        $code = (string) ($_POST['console_code'] ?? '');
        if (!password_verify($code, $codeHash)) {
            appAdminConsoleLock();
            appAdminAuditLog($pdo, $sessionUser, 'console_unlock_failed', 'admin_console', null, [
                'reason' => 'invalid_code',
            ]);
            appAdminConsoleFlash('danger', 'Codice console non corretto.');
            appAdminConsoleRedirect();
        }

        session_regenerate_id(true);
        $_SESSION['admin_console_until'] = time() + appAdminConsoleTimeoutSeconds();
        $_SESSION['admin_console_cf'] = (string) ($sessionUser['cf'] ?? '');
        appAdminAuditLog($pdo, $sessionUser, 'console_unlock', 'admin_console', null, [
            'expires_at' => date('c', (int) $_SESSION['admin_console_until']),
        ]);
        appAdminConsoleFlash('success', 'Console admin sbloccata.');
        appAdminConsoleRedirect();
    }

    if ($action === 'lock') {
        appAdminConsoleLock();
        appAdminAuditLog($pdo, $sessionUser, 'console_lock', 'admin_console');
        appAdminConsoleFlash('success', 'Console admin bloccata.');
        appAdminConsoleRedirect();
    }

    if (!$unlocked) {
        appAdminConsoleFlash('danger', 'Sblocca la console prima di eseguire questa azione.');
        appAdminConsoleRedirect();
    }
    if (!$connessione || !($pdo instanceof PDO)) {
        appAdminConsoleFlash('danger', 'Database non disponibile. Riprova più tardi.');
        appAdminConsoleRedirect();
    }

    $redirectParams = [];
    $postedDepartment = trim((string) ($_POST['reparto'] ?? ''));
    if (appIsValidDepartment($postedDepartment)) {
        $redirectParams['reparto'] = $postedDepartment;
    }
    $postedStatus = trim((string) ($_POST['stato'] ?? ''));
    if (in_array($postedStatus, ['pending', 'accepted', 'expired', 'revoked'], true)) {
        $redirectParams['stato'] = $postedStatus;
    }

    $inviteId = (int) ($_POST['invite_id'] ?? 0);
    try {
        if ($inviteId <= 0 || !in_array($action, ['manual_invite_link', 'revoke_invite'], true)) {
            throw new RuntimeException('Operazione non valida.');
        }

        $pdo->beginTransaction();
        if ($action === 'revoke_invite') {
            $invite = appInviteRevokeLocked($pdo, $inviteId, $sessionUser);
            $pdo->commit();
            appAdminAuditLog($pdo, $sessionUser, 'invite_revoked', 'user_invite', (string) $inviteId, [
                'email' => (string) ($invite['invited_email'] ?? ''),
                'reparto' => (string) ($invite['reparto'] ?? ''),
                'status' => appInviteStatus($invite),
            ]);
            appAdminConsoleFlash('success', 'Invito revocato.');
            appAdminConsoleRedirect($redirectParams);
        }

        $regenerated = appInviteRegenerateLocked($pdo, $inviteId, $sessionUser);
        $pdo->commit();
        $invite = $regenerated['invite'];
        appAdminAuditLog($pdo, $sessionUser, 'invite_manual_link_generated', 'user_invite', (string) $inviteId, [
            'email' => (string) ($invite['invited_email'] ?? ''),
            'reparto' => (string) ($invite['reparto'] ?? ''),
            'status' => appInviteStatus($invite),
        ]);

        appAdminConsoleFlash(
            'success',
            'Nuovo link manuale generato. Questo link non verrà mostrato di nuovo.',
            (string) $regenerated['link']
        );
        appAdminConsoleRedirect($redirectParams);
    } catch (Throwable $error) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Console admin: azione invito non riuscita: ' . $error->getMessage());
        appAdminConsoleFlash('danger', $error->getMessage());
        appAdminConsoleRedirect($redirectParams);
    }
}

$users = [];
$mappings = [];
$invites = [];
$auditLogs = [];
$auditLogError = false;
$diagnostics = [];
$diagnosticSummary = ['ok' => 0, 'warning' => 0, 'danger' => 0, 'info' => 0];
$namesByUser = [];
$stats = [
    'active_users' => 0,
    'mappings' => 0,
    'pending_invites' => 0,
];
$databaseError = !$connessione || !($pdo instanceof PDO);

if ($unlocked && !$databaseError) {
    $userSql =
        'SELECT cod_fiscale, nome, cognome, email, reparto, capo, last_seen
         FROM utenti
         WHERE attivo = 1';
    $userParams = [];
    if ($departmentFilter !== '') {
        $userSql .= ' AND reparto = ?';
        $userParams[] = $departmentFilter;
    }
    $userSql .= ' ORDER BY reparto, cognome, nome, cod_fiscale';
    $userStatement = $pdo->prepare($userSql);
    $userStatement->execute($userParams);
    $users = $userStatement->fetchAll(PDO::FETCH_ASSOC);

    $mappingSql =
        "SELECT m.reparto, m.schedule_name, m.user_cf, m.updated_at,
                u.nome, u.cognome, u.email, u.attivo
         FROM schedule_name_mappings m
         LEFT JOIN utenti u ON u.cod_fiscale = m.user_cf
         WHERE 1 = 1";
    $mappingParams = [];
    if ($departmentFilter !== '') {
        $mappingSql .= ' AND m.reparto = ?';
        $mappingParams[] = $departmentFilter;
    }
    $mappingSql .= ' ORDER BY m.reparto, m.schedule_name';
    $mappingStatement = $pdo->prepare($mappingSql);
    $mappingStatement->execute($mappingParams);
    $mappings = $mappingStatement->fetchAll(PDO::FETCH_ASSOC);

    foreach ($mappings as $mapping) {
        $userCf = (string) ($mapping['user_cf'] ?? '');
        if ($userCf !== '' && !in_array($userCf, [APP_SCHEDULE_MAPPING_IGNORED_VALUE, '__UNREGISTERED__'], true)) {
            $namesByUser[$userCf][] = (string) ($mapping['schedule_name'] ?? '');
        }
    }

    $inviteSql =
        "SELECT i.*,
                TRIM(CONCAT(COALESCE(author.nome, ''), ' ', COALESCE(author.cognome, ''))) AS author_name,
                TRIM(CONCAT(COALESCE(accepted.nome, ''), ' ', COALESCE(accepted.cognome, ''))) AS accepted_name
         FROM user_invites i
         LEFT JOIN utenti author ON author.cod_fiscale = i.invited_by_cf
         LEFT JOIN utenti accepted ON accepted.cod_fiscale = i.accepted_user_cf";
    $inviteParams = [];
    if ($departmentFilter !== '') {
        $inviteSql .= ' WHERE i.reparto = ?';
        $inviteParams[] = $departmentFilter;
    }
    $inviteSql .= ' ORDER BY i.created_at DESC LIMIT 200';
    $inviteStatement = $pdo->prepare($inviteSql);
    $inviteStatement->execute($inviteParams);
    $inviteRows = $inviteStatement->fetchAll(PDO::FETCH_ASSOC);
    foreach ($inviteRows as $invite) {
        $status = appInviteStatus($invite);
        if ($inviteStatusFilter !== '' && $status !== $inviteStatusFilter) {
            continue;
        }
        $invite['computed_status'] = $status;
        $invites[] = $invite;
    }

    $stats['active_users'] = count($users);
    $stats['mappings'] = count($mappings);
    $stats['pending_invites'] = count(array_filter($inviteRows, static fn (array $invite): bool => appInviteStatus($invite) === 'pending'));
    $diagnostics = appAdminConsoleBuildDiagnostics($pdo, $consoleConfigured);
    $diagnosticSummary = appAdminConsoleDiagnosticSummary($diagnostics);

    try {
        $auditStatement = $pdo->query(
            "SELECT l.id, l.actor_cf, l.action, l.target_type, l.target_id, l.details_json, l.created_at,
                    TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS actor_name
             FROM admin_audit_log l
             LEFT JOIN utenti u ON u.cod_fiscale = l.actor_cf
             ORDER BY l.created_at DESC
             LIMIT 80"
        );
        $auditLogs = $auditStatement ? $auditStatement->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $auditError) {
        $auditLogError = true;
        error_log('Console admin: audit non leggibile: ' . $auditError->getMessage());
    }
}

$expiresAt = (int) ($_SESSION['admin_console_until'] ?? 0);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Console admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="sfera.css?v=<?php echo rawurlencode(APP_VERSION); ?>">
</head>
<body>
<main class="app-shell admin-console">
    <header class="admin-console-hero">
        <div>
            <p class="admin-console-eyebrow">Area riservata</p>
            <h1>Console admin</h1>
            <p class="admin-console-subtitle">Supervisione tecnica di utenti, associazioni e inviti.</p>
        </div>
        <div class="admin-console-hero__actions">
            <?php if ($unlocked): ?>
                <div class="admin-console-timer" aria-label="Tempo rimanente nella console">
                    <span class="admin-console-timer__icon" aria-hidden="true">Time</span>
                    <span data-admin-console-countdown data-expires-at="<?php echo (int) $expiresAt; ?>">--:--</span>
                </div>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo appAdminConsoleEscape($appCsrfToken); ?>">
                    <input type="hidden" name="action" value="lock">
                    <button type="submit" class="btn btn-outline-dark">Blocca</button>
                </form>
            <?php endif; ?>
            <a class="btn btn-outline-dark" href="index.php">Home</a>
        </div>
    </header>

    <?php if (is_array($flash) && isset($flash['message'], $flash['type'])): ?>
        <div class="alert alert-<?php echo appAdminConsoleEscape((string) $flash['type']); ?>">
            <div><?php echo appAdminConsoleEscape((string) $flash['message']); ?></div>
            <?php if (!empty($flash['link'])): ?>
                <div class="mt-3">
                    <label for="adminConsoleInviteLink" class="form-label">Link invito manuale</label>
                    <div class="input-group">
                        <input id="adminConsoleInviteLink" class="form-control" type="text" readonly value="<?php echo appAdminConsoleEscape((string) $flash['link']); ?>">
                        <button type="button" class="btn btn-outline-dark" data-copy-target="#adminConsoleInviteLink">Copia</button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!$unlocked): ?>
        <section class="admin-console-lock">
            <div class="admin-console-lock__panel">
                <h2>Codice console</h2>
                <p>Inserisci il codice riservato per aprire la sezione amministrativa.</p>
                <?php if (!$consoleConfigured): ?>
                    <div class="alert alert-warning">
                        Configura <code>APP_ADMIN_CONSOLE_CODE_HASH</code> in <code>app_local_env.php</code> prima di usare la console.
                    </div>
                <?php endif; ?>
                <form method="post" class="admin-console-lock__form">
                    <input type="hidden" name="csrf_token" value="<?php echo appAdminConsoleEscape($appCsrfToken); ?>">
                    <input type="hidden" name="action" value="unlock">
                    <label class="form-label" for="consoleCode">Codice</label>
                    <input class="form-control form-control-lg" type="password" id="consoleCode" name="console_code" autocomplete="one-time-code" required <?php echo $consoleConfigured ? '' : 'disabled'; ?>>
                    <button type="submit" class="btn btn-primary btn-lg" <?php echo $consoleConfigured ? '' : 'disabled'; ?>>Entra</button>
                </form>
            </div>
        </section>
    <?php elseif ($databaseError): ?>
        <div class="alert alert-danger">Database non disponibile. Riprova più tardi.</div>
    <?php else: ?>
        <section class="admin-console-stats" aria-label="Riepilogo console">
            <article>
                <span>Utenti attivi</span>
                <strong><?php echo (int) $stats['active_users']; ?></strong>
            </article>
            <article>
                <span>Associazioni</span>
                <strong><?php echo (int) $stats['mappings']; ?></strong>
            </article>
            <article>
                <span>Inviti in attesa</span>
                <strong><?php echo (int) $stats['pending_invites']; ?></strong>
            </article>
        </section>

        <section class="admin-console-section">
            <div class="admin-console-section__header">
                <h2>Diagnostica sistema</h2>
                <span><?php echo count($diagnostics); ?> controlli</span>
            </div>
            <div class="admin-console-health-summary" aria-label="Riepilogo diagnostica">
                <article class="admin-console-health-summary__ok">
                    <span>OK</span>
                    <strong><?php echo (int) $diagnosticSummary['ok']; ?></strong>
                </article>
                <article class="admin-console-health-summary__warning">
                    <span>Attenzioni</span>
                    <strong><?php echo (int) $diagnosticSummary['warning']; ?></strong>
                </article>
                <article class="admin-console-health-summary__danger">
                    <span>Errori</span>
                    <strong><?php echo (int) $diagnosticSummary['danger']; ?></strong>
                </article>
                <article class="admin-console-health-summary__info">
                    <span>Info</span>
                    <strong><?php echo (int) $diagnosticSummary['info']; ?></strong>
                </article>
            </div>
            <div class="table-responsive">
                <table class="table align-middle admin-console-table">
                    <thead>
                    <tr>
                        <th>Area</th>
                        <th>Controllo</th>
                        <th>Stato</th>
                        <th>Valore</th>
                        <th>Dettaglio</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($diagnostics as $diagnostic): ?>
                        <?php $status = (string) ($diagnostic['status'] ?? 'info'); ?>
                        <tr>
                            <td><?php echo appAdminConsoleEscape((string) $diagnostic['area']); ?></td>
                            <td><strong><?php echo appAdminConsoleEscape((string) $diagnostic['name']); ?></strong></td>
                            <td><span class="badge <?php echo appAdminConsoleEscape(appAdminConsoleDiagnosticClass($status)); ?>"><?php echo appAdminConsoleEscape(appAdminConsoleDiagnosticLabel($status)); ?></span></td>
                            <td><?php echo appAdminConsoleEscape((string) $diagnostic['value']); ?></td>
                            <td><?php echo appAdminConsoleEscape((string) ($diagnostic['detail'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($diagnostics === []): ?>
                        <tr><td colspan="5" class="text-muted">Diagnostica non disponibile.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="admin-console-filters">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-5 col-lg-4">
                    <label class="form-label" for="reparto">Reparto</label>
                    <select class="form-select" id="reparto" name="reparto">
                        <option value="">Tutti i reparti</option>
                        <?php foreach (appDepartments() as $code => $label): ?>
                            <option value="<?php echo appAdminConsoleEscape($code); ?>"<?php echo $code === $departmentFilter ? ' selected' : ''; ?>>
                                <?php echo appAdminConsoleEscape($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5 col-lg-4">
                    <label class="form-label" for="stato">Stato inviti</label>
                    <select class="form-select" id="stato" name="stato">
                        <option value="">Tutti gli stati</option>
                        <?php foreach (['pending' => 'In attesa', 'accepted' => 'Attivato', 'expired' => 'Scaduto', 'revoked' => 'Revocato'] as $status => $label): ?>
                            <option value="<?php echo appAdminConsoleEscape($status); ?>"<?php echo $status === $inviteStatusFilter ? ' selected' : ''; ?>>
                                <?php echo appAdminConsoleEscape($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-lg-2">
                    <button type="submit" class="btn btn-outline-dark w-100">Filtra</button>
                </div>
            </form>
        </section>

        <section class="admin-console-section">
            <div class="admin-console-section__header">
                <h2>Utenti attivi</h2>
                <span><?php echo count($users); ?> utenti</span>
            </div>
            <div class="table-responsive">
                <table class="table align-middle admin-console-table">
                    <thead>
                    <tr>
                        <th>Utente</th>
                        <th>Reparto</th>
                        <th>Ruolo</th>
                        <th>Ultima attività</th>
                        <th>Nominativi associati</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $user): ?>
                        <?php $userCf = (string) $user['cod_fiscale']; ?>
                        <tr>
                            <td>
                                <strong><?php echo appAdminConsoleEscape(trim((string) $user['nome'] . ' ' . (string) $user['cognome'])); ?></strong><br>
                                <span class="text-muted"><?php echo appAdminConsoleEscape((string) $user['email']); ?></span>
                            </td>
                            <td><?php echo appAdminConsoleEscape(appDepartments()[(string) $user['reparto']] ?? (string) $user['reparto']); ?></td>
                            <td><?php echo appAdminConsoleEscape(appAdminConsoleRoleLabel((int) $user['capo'])); ?></td>
                            <td><?php echo appAdminConsoleEscape(appAdminConsoleLastSeenLabel($user['last_seen'] ?? null)); ?></td>
                            <td>
                                <?php $scheduleNames = $namesByUser[$userCf] ?? []; ?>
                                <?php if ($scheduleNames === []): ?>
                                    <span class="text-muted">Nessuno</span>
                                <?php else: ?>
                                    <div class="admin-console-tags">
                                        <?php foreach (array_slice($scheduleNames, 0, 4) as $name): ?>
                                            <span><?php echo appAdminConsoleEscape($name); ?></span>
                                        <?php endforeach; ?>
                                        <?php if (count($scheduleNames) > 4): ?>
                                            <span>+<?php echo count($scheduleNames) - 4; ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($users === []): ?>
                        <tr><td colspan="5" class="text-muted">Nessun utente attivo trovato.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="admin-console-section">
            <div class="admin-console-section__header">
                <h2>Associazioni orari</h2>
                <span><?php echo count($mappings); ?> associazioni</span>
            </div>
            <div class="table-responsive">
                <table class="table align-middle admin-console-table">
                    <thead>
                    <tr>
                        <th>Nome negli orari</th>
                        <th>Reparto</th>
                        <th>Account collegato</th>
                        <th>Aggiornata</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($mappings as $mapping): ?>
                        <?php
                        $userCf = (string) ($mapping['user_cf'] ?? '');
                        $isIgnored = $userCf === APP_SCHEDULE_MAPPING_IGNORED_VALUE;
                        $isUnregistered = $userCf === '__UNREGISTERED__';
                        $linkedName = trim((string) ($mapping['nome'] ?? '') . ' ' . (string) ($mapping['cognome'] ?? ''));
                        ?>
                        <tr>
                            <td><strong><?php echo appAdminConsoleEscape((string) $mapping['schedule_name']); ?></strong></td>
                            <td><?php echo appAdminConsoleEscape(appDepartments()[(string) $mapping['reparto']] ?? (string) $mapping['reparto']); ?></td>
                            <td>
                                <?php if ($isIgnored): ?>
                                    <span class="badge text-bg-secondary">Ignorato</span>
                                <?php elseif ($isUnregistered): ?>
                                    <span class="badge text-bg-info">Non registrato</span>
                                <?php elseif ($linkedName !== ''): ?>
                                    <?php echo appAdminConsoleEscape($linkedName); ?><br>
                                    <span class="text-muted"><?php echo appAdminConsoleEscape((string) $mapping['email']); ?></span>
                                <?php else: ?>
                                    <span class="badge text-bg-danger">Da verificare</span>
                                    <span class="text-muted"><?php echo appAdminConsoleEscape($userCf); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo appAdminConsoleEscape(appAdminConsoleDateLabel($mapping['updated_at'] ?? null)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($mappings === []): ?>
                        <tr><td colspan="4" class="text-muted">Nessuna associazione trovata.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="admin-console-section">
            <div class="admin-console-section__header">
                <h2>Inviti</h2>
                <span><?php echo count($invites); ?> inviti</span>
            </div>
            <div class="table-responsive">
                <table class="table align-middle admin-console-table">
                    <thead>
                    <tr>
                        <th>Invitato</th>
                        <th>Reparto</th>
                        <th>Ruolo</th>
                        <th>Creato da</th>
                        <th>Scadenza</th>
                        <th>Stato</th>
                        <th>Link</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($invites as $invite): ?>
                        <?php $status = (string) ($invite['computed_status'] ?? appInviteStatus($invite)); ?>
                        <tr>
                            <td>
                                <strong><?php echo appAdminConsoleEscape(trim((string) $invite['invited_nome'] . ' ' . (string) $invite['invited_cognome'])); ?></strong><br>
                                <span class="text-muted"><?php echo appAdminConsoleEscape((string) $invite['invited_email']); ?></span>
                            </td>
                            <td><?php echo appAdminConsoleEscape(appDepartments()[(string) $invite['reparto']] ?? (string) $invite['reparto']); ?></td>
                            <td><?php echo appAdminConsoleEscape(appAdminConsoleRoleLabel((int) ($invite['invited_capo'] ?? 0))); ?></td>
                            <td><?php echo appAdminConsoleEscape((string) ($invite['author_name'] ?: $invite['invited_by_cf'])); ?></td>
                            <td><?php echo appAdminConsoleEscape(appAdminConsoleDateLabel($invite['expires_at'] ?? null)); ?></td>
                            <td><span class="badge <?php echo appAdminConsoleEscape(appAdminConsoleStatusClass($status)); ?>"><?php echo appAdminConsoleEscape(appInviteStatusLabel($invite)); ?></span></td>
                            <td>
                                <?php if ($status === 'accepted'): ?>
                                    <span class="text-muted">Account attivato</span>
                                <?php else: ?>
                                    <div class="d-flex flex-wrap gap-2">
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?php echo appAdminConsoleEscape($appCsrfToken); ?>">
                                            <input type="hidden" name="action" value="manual_invite_link">
                                            <input type="hidden" name="invite_id" value="<?php echo (int) $invite['id']; ?>">
                                            <input type="hidden" name="reparto" value="<?php echo appAdminConsoleEscape($departmentFilter); ?>">
                                            <input type="hidden" name="stato" value="<?php echo appAdminConsoleEscape($inviteStatusFilter); ?>">
                                            <button type="submit" class="btn btn-outline-dark btn-sm">Genera link</button>
                                        </form>
                                        <?php if ($status === 'pending'): ?>
                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?php echo appAdminConsoleEscape($appCsrfToken); ?>">
                                                <input type="hidden" name="action" value="revoke_invite">
                                                <input type="hidden" name="invite_id" value="<?php echo (int) $invite['id']; ?>">
                                                <input type="hidden" name="reparto" value="<?php echo appAdminConsoleEscape($departmentFilter); ?>">
                                                <input type="hidden" name="stato" value="<?php echo appAdminConsoleEscape($inviteStatusFilter); ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm">Revoca</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($invites === []): ?>
                        <tr><td colspan="7" class="text-muted">Nessun invito trovato.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="admin-console-section">
            <div class="admin-console-section__header">
                <h2>Audit recente</h2>
                <span><?php echo count($auditLogs); ?> eventi</span>
            </div>
            <?php if ($auditLogError): ?>
                <div class="alert alert-warning mb-0">Audit non ancora disponibile. Esegui la migrazione database al prossimo rilascio.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle admin-console-table">
                        <thead>
                        <tr>
                            <th>Quando</th>
                            <th>Admin</th>
                            <th>Azione</th>
                            <th>Oggetto</th>
                            <th>Dettagli</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($auditLogs as $event): ?>
                            <tr>
                                <td><?php echo appAdminConsoleEscape(appAdminConsoleDateLabel($event['created_at'] ?? null)); ?></td>
                                <td><?php echo appAdminConsoleEscape((string) ($event['actor_name'] ?: $event['actor_cf'])); ?></td>
                                <td><?php echo appAdminConsoleEscape(appAdminConsoleAuditActionLabel((string) $event['action'])); ?></td>
                                <td>
                                    <?php if (!empty($event['target_id'])): ?>
                                        <span class="admin-console-mono"><?php echo appAdminConsoleEscape((string) $event['target_type'] . ' #' . (string) $event['target_id']); ?></span>
                                    <?php elseif (!empty($event['target_type'])): ?>
                                        <span class="admin-console-mono"><?php echo appAdminConsoleEscape((string) $event['target_type']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">Console</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo appAdminConsoleEscape(appAdminConsoleAuditDetailsLabel($event['details_json'] ?? null)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($auditLogs === []): ?>
                            <tr><td colspan="5" class="text-muted">Nessun evento registrato.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
<script src="admin_console.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
</body>
</html>

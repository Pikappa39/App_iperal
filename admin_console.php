<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/app_config.php';
require __DIR__ . '/session_bootstrap.php';
app_session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
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
        'invite_email_sent' => 'Email invito inviata',
        'invite_regenerated' => 'Invito rigenerato',
        'invite_manual_link_generated' => 'Link invito generato',
        'invite_revoked' => 'Invito revocato',
        'revoked_invites_deleted' => 'Inviti revocati eliminati',
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
    if (!empty($details['transport'])) {
        $parts[] = 'Canale: ' . (string) $details['transport'];
    }
    if (!empty($details['reason'])) {
        $parts[] = 'Motivo: ' . (string) $details['reason'];
    }
    if (isset($details['deleted_count'])) {
        $parts[] = 'Eliminati: ' . (int) $details['deleted_count'];
    }
    if (!empty($details['message_id'])) {
        $parts[] = 'Message-ID: ' . (string) $details['message_id'];
    }
    if (!empty($details['smtp_code'])) {
        $parts[] = 'SMTP: ' . (int) $details['smtp_code'];
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

function appAdminConsoleMsLabel($value): string
{
    if (!is_numeric($value)) {
        return 'Non disponibile';
    }

    return number_format((float) $value, 1, ',', '.') . ' ms';
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

function appAdminConsoleSearchText(array $values): string
{
    $text = implode(' ', array_map(static fn ($value): string => is_scalar($value) ? (string) $value : '', $values));
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);

    return trim(is_string($text) ? $text : '');
}

function appAdminConsoleBuildAssociationIssues(array $mappings): array
{
    $issues = [];
    $byUser = [];

    foreach ($mappings as $mapping) {
        $userCf = (string) ($mapping['user_cf'] ?? '');
        if ($userCf === APP_SCHEDULE_MAPPING_IGNORED_VALUE) {
            $issues[] = [
                'severity' => 'info',
                'type' => 'Ignorato',
                'schedule_name' => (string) ($mapping['schedule_name'] ?? ''),
                'reparto' => (string) ($mapping['reparto'] ?? ''),
                'detail' => 'Questo nominativo e stato marcato come da ignorare.',
            ];
            continue;
        }
        if ($userCf === '__UNREGISTERED__') {
            $issues[] = [
                'severity' => 'warning',
                'type' => 'Non registrato',
                'schedule_name' => (string) ($mapping['schedule_name'] ?? ''),
                'reparto' => (string) ($mapping['reparto'] ?? ''),
                'detail' => 'Il nominativo esiste negli orari, ma non ha ancora un account collegato.',
            ];
            continue;
        }

        $linkedName = trim((string) ($mapping['nome'] ?? '') . ' ' . (string) ($mapping['cognome'] ?? ''));
        if ($linkedName === '') {
            $issues[] = [
                'severity' => 'danger',
                'type' => 'Da verificare',
                'schedule_name' => (string) ($mapping['schedule_name'] ?? ''),
                'reparto' => (string) ($mapping['reparto'] ?? ''),
                'detail' => 'L’associazione punta a un account non trovato o non piu coerente.',
            ];
            continue;
        }
        if ((int) ($mapping['attivo'] ?? 1) !== 1) {
            $issues[] = [
                'severity' => 'warning',
                'type' => 'Utente disattivato',
                'schedule_name' => (string) ($mapping['schedule_name'] ?? ''),
                'reparto' => (string) ($mapping['reparto'] ?? ''),
                'detail' => 'L’associazione punta a ' . $linkedName . ', che risulta disattivato.',
            ];
        }

        if ($userCf !== '') {
            $byUser[$userCf][] = [
                'schedule_name' => (string) ($mapping['schedule_name'] ?? ''),
                'reparto' => (string) ($mapping['reparto'] ?? ''),
                'linked_name' => $linkedName,
            ];
        }
    }

    foreach ($byUser as $rows) {
        if (count($rows) <= 1) {
            continue;
        }

        $names = array_map(static fn (array $row): string => $row['schedule_name'], $rows);
        $first = $rows[0];
        $issues[] = [
            'severity' => 'info',
            'type' => 'Multipla associazione',
            'schedule_name' => implode(', ', $names),
            'reparto' => (string) ($first['reparto'] ?? ''),
            'detail' => (string) ($first['linked_name'] ?? 'Account') . ' ha piu nominativi collegati.',
        ];
    }

    usort($issues, static function (array $left, array $right): int {
        $weight = ['danger' => 0, 'warning' => 1, 'info' => 2, 'ok' => 3];
        return ($weight[(string) ($left['severity'] ?? 'info')] ?? 2) <=> ($weight[(string) ($right['severity'] ?? 'info')] ?? 2)
            ?: strnatcasecmp((string) ($left['schedule_name'] ?? ''), (string) ($right['schedule_name'] ?? ''));
    });

    return $issues;
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

function appAdminConsolePercentile(array $values, float $percentile): float
{
    $values = array_values(array_filter($values, 'is_numeric'));
    if ($values === []) {
        return 0.0;
    }

    sort($values, SORT_NUMERIC);
    $index = (int) ceil(($percentile / 100) * count($values)) - 1;
    $index = max(0, min(count($values) - 1, $index));

    return (float) $values[$index];
}

function appAdminConsoleBuildPerformanceReport(int $hours = 24): array
{
    $path = app_performance_log_path();
    $report = [
        'available' => false,
        'path' => $path,
        'hours' => $hours,
        'requests' => 0,
        'error_count' => 0,
        'endpoints' => [],
        'slow_requests' => [],
    ];
    if ($path === '') {
        return $report;
    }

    $rawParts = [];
    foreach ([$path . '.1', $path] as $candidate) {
        $tail = appAdminConsoleReadTail($candidate, 1024 * 1024);
        if (is_string($tail) && trim($tail) !== '') {
            $rawParts[] = $tail;
        }
    }
    if ($rawParts === []) {
        return $report;
    }

    $cutoff = time() - ($hours * 3600);
    $groups = [];
    $slowRequests = [];
    foreach (preg_split('/\R/', implode("\n", $rawParts)) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $entry = json_decode($line, true);
        if (!is_array($entry)) {
            continue;
        }

        $timestamp = strtotime((string) ($entry['ts'] ?? ''));
        if ($timestamp === false || $timestamp < $cutoff) {
            continue;
        }

        $route = basename((string) ($entry['route'] ?? ''));
        if ($route === '') {
            continue;
        }

        $duration = (float) ($entry['duration_ms'] ?? 0);
        $status = (int) ($entry['status'] ?? 0);
        $method = (string) ($entry['method'] ?? '');
        $memoryPeak = (int) ($entry['memory_peak_kb'] ?? 0);
        $report['requests']++;
        if ($status >= 400) {
            $report['error_count']++;
        }

        if (!isset($groups[$route])) {
            $groups[$route] = [
                'route' => $route,
                'count' => 0,
                'errors' => 0,
                'durations' => [],
                'memory_peak_kb' => 0,
                'posts' => 0,
            ];
        }
        $groups[$route]['count']++;
        $groups[$route]['durations'][] = $duration;
        $groups[$route]['memory_peak_kb'] = max((int) $groups[$route]['memory_peak_kb'], $memoryPeak);
        if ($status >= 400) {
            $groups[$route]['errors']++;
        }
        if ($method === 'POST') {
            $groups[$route]['posts']++;
        }

        if ($duration >= 500 || $status >= 500) {
            $slowRequests[] = [
                'ts' => (string) ($entry['ts'] ?? ''),
                'route' => $route,
                'method' => $method,
                'status' => $status,
                'duration_ms' => $duration,
                'memory_peak_kb' => $memoryPeak,
            ];
        }
    }

    $endpoints = [];
    foreach ($groups as $group) {
        $durations = $group['durations'];
        $endpoints[] = [
            'route' => (string) $group['route'],
            'count' => (int) $group['count'],
            'posts' => (int) $group['posts'],
            'errors' => (int) $group['errors'],
            'avg_ms' => array_sum($durations) / max(1, count($durations)),
            'p95_ms' => appAdminConsolePercentile($durations, 95),
            'max_ms' => max($durations),
            'memory_peak_kb' => (int) $group['memory_peak_kb'],
        ];
    }

    usort($endpoints, static fn (array $left, array $right): int => ((float) $right['p95_ms'] <=> (float) $left['p95_ms']) ?: ((int) $right['count'] <=> (int) $left['count']));
    usort($slowRequests, static fn (array $left, array $right): int => ((float) $right['duration_ms'] <=> (float) $left['duration_ms']));

    $report['available'] = $report['requests'] > 0;
    $report['endpoints'] = array_slice($endpoints, 0, 12);
    $report['slow_requests'] = array_slice($slowRequests, 0, 20);

    return $report;
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
        if ($action === 'delete_revoked_invites') {
            $deleteSql = 'DELETE FROM user_invites WHERE revoked_at IS NOT NULL';
            $deleteParams = [];
            if ($postedDepartment !== '') {
                $deleteSql .= ' AND reparto = ?';
                $deleteParams[] = $postedDepartment;
            }

            $pdo->beginTransaction();
            $deleteStatement = $pdo->prepare($deleteSql);
            $deleteStatement->execute($deleteParams);
            $deletedCount = $deleteStatement->rowCount();
            $pdo->commit();

            appAdminAuditLog($pdo, $sessionUser, 'revoked_invites_deleted', 'user_invite', null, [
                'reparto' => $postedDepartment,
                'deleted_count' => $deletedCount,
            ]);
            appAdminConsoleFlash(
                $deletedCount > 0 ? 'success' : 'info',
                $deletedCount === 1
                    ? '1 invito revocato eliminato.'
                    : $deletedCount . ' inviti revocati eliminati.'
            );
            appAdminConsoleRedirect($redirectParams);
        }

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
$revokedInvitesCount = 0;
$diagnostics = [];
$diagnosticSummary = ['ok' => 0, 'warning' => 0, 'danger' => 0, 'info' => 0];
$performanceReport = [
    'available' => false,
    'path' => app_performance_log_path(),
    'hours' => 24,
    'requests' => 0,
    'error_count' => 0,
    'endpoints' => [],
    'slow_requests' => [],
];
$associationIssues = [];
$associationIssueSummary = ['danger' => 0, 'warning' => 0, 'info' => 0];
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

    $revokedCountSql = 'SELECT COUNT(*) FROM user_invites WHERE revoked_at IS NOT NULL';
    $revokedCountParams = [];
    if ($departmentFilter !== '') {
        $revokedCountSql .= ' AND reparto = ?';
        $revokedCountParams[] = $departmentFilter;
    }
    $revokedCountStatement = $pdo->prepare($revokedCountSql);
    $revokedCountStatement->execute($revokedCountParams);
    $revokedInvitesCount = (int) $revokedCountStatement->fetchColumn();

    $stats['active_users'] = count($users);
    $stats['mappings'] = count($mappings);
    $stats['pending_invites'] = count(array_filter($inviteRows, static fn (array $invite): bool => appInviteStatus($invite) === 'pending'));
    $associationIssues = appAdminConsoleBuildAssociationIssues($mappings);
    foreach ($associationIssues as $issue) {
        $severity = (string) ($issue['severity'] ?? 'info');
        if (array_key_exists($severity, $associationIssueSummary)) {
            $associationIssueSummary[$severity]++;
        }
    }
    $diagnostics = appAdminConsoleBuildDiagnostics($pdo, $consoleConfigured);
    $diagnosticSummary = appAdminConsoleDiagnosticSummary($diagnostics);
    $performanceReport = appAdminConsoleBuildPerformanceReport(24);

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

        <details class="admin-console-section" data-admin-console-panel open>
            <summary class="admin-console-section__summary">
                <span>
                    <strong>Performance server</strong>
                    <small>
                        ultime <?php echo (int) $performanceReport['hours']; ?>h
                        · <?php echo (int) $performanceReport['requests']; ?> richieste
                        · <?php echo (int) $performanceReport['error_count']; ?> errori
                    </small>
                </span>
            </summary>
            <div class="admin-console-section__body">
                <?php if (!$performanceReport['available']): ?>
                    <div class="alert alert-info mb-0">
                        Non ci sono ancora dati performance sufficienti. Le nuove richieste PHP verranno registrate in <code><?php echo appAdminConsoleEscape((string) $performanceReport['path']); ?></code>.
                    </div>
                <?php else: ?>
                    <div class="table-responsive mb-4">
                        <table class="table align-middle admin-console-table">
                            <thead>
                            <tr>
                                <th>Endpoint</th>
                                <th>Richieste</th>
                                <th>POST</th>
                                <th>Media</th>
                                <th>P95</th>
                                <th>Max</th>
                                <th>Errori</th>
                                <th>Memoria max</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($performanceReport['endpoints'] as $endpoint): ?>
                                <tr data-admin-console-row data-search-text="<?php echo appAdminConsoleEscape(appAdminConsoleSearchText([
                                    $endpoint['route'] ?? '',
                                    'performance server',
                                ])); ?>">
                                    <td><strong><?php echo appAdminConsoleEscape((string) $endpoint['route']); ?></strong></td>
                                    <td><?php echo (int) $endpoint['count']; ?></td>
                                    <td><?php echo (int) $endpoint['posts']; ?></td>
                                    <td><?php echo appAdminConsoleEscape(appAdminConsoleMsLabel($endpoint['avg_ms'] ?? 0)); ?></td>
                                    <td><?php echo appAdminConsoleEscape(appAdminConsoleMsLabel($endpoint['p95_ms'] ?? 0)); ?></td>
                                    <td><?php echo appAdminConsoleEscape(appAdminConsoleMsLabel($endpoint['max_ms'] ?? 0)); ?></td>
                                    <td><?php echo (int) $endpoint['errors']; ?></td>
                                    <td><?php echo appAdminConsoleEscape(appAdminConsoleBytesLabel(((int) ($endpoint['memory_peak_kb'] ?? 0)) * 1024)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <h3 class="h6">Richieste lente recenti</h3>
                    <div class="table-responsive">
                        <table class="table align-middle admin-console-table">
                            <thead>
                            <tr>
                                <th>Quando</th>
                                <th>Endpoint</th>
                                <th>Metodo</th>
                                <th>HTTP</th>
                                <th>Durata</th>
                                <th>Memoria</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($performanceReport['slow_requests'] as $request): ?>
                                <tr data-admin-console-row data-search-text="<?php echo appAdminConsoleEscape(appAdminConsoleSearchText([
                                    $request['route'] ?? '',
                                    $request['method'] ?? '',
                                    $request['status'] ?? '',
                                    'richiesta lenta performance',
                                ])); ?>">
                                    <td><?php echo appAdminConsoleEscape(appAdminConsoleDateLabel($request['ts'] ?? null)); ?></td>
                                    <td><strong><?php echo appAdminConsoleEscape((string) $request['route']); ?></strong></td>
                                    <td><?php echo appAdminConsoleEscape((string) $request['method']); ?></td>
                                    <td><?php echo (int) $request['status']; ?></td>
                                    <td><?php echo appAdminConsoleEscape(appAdminConsoleMsLabel($request['duration_ms'] ?? 0)); ?></td>
                                    <td><?php echo appAdminConsoleEscape(appAdminConsoleBytesLabel(((int) ($request['memory_peak_kb'] ?? 0)) * 1024)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($performanceReport['slow_requests'] === []): ?>
                                <tr><td colspan="6" class="text-muted">Nessuna richiesta oltre 500 ms nelle ultime ore analizzate.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </details>

        <section class="admin-console-search">
            <label class="form-label" for="adminConsoleSearch">Ricerca globale</label>
            <input class="form-control form-control-lg" id="adminConsoleSearch" type="search" placeholder="Cerca utenti, email, nominativi Excel, inviti, audit..." data-admin-console-search autocomplete="off">
            <p class="admin-console-search__status" data-admin-console-search-status></p>
        </section>

        <details class="admin-console-section" data-admin-console-panel>
            <summary class="admin-console-section__summary">
                <span>
                    <strong>Diagnostica sistema</strong>
                    <small><?php echo count($diagnostics); ?> controlli</small>
                </span>
            </summary>
            <div class="admin-console-section__body">
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
                        <tr data-admin-console-row data-search-text="<?php echo appAdminConsoleEscape(appAdminConsoleSearchText($diagnostic)); ?>">
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
            </div>
        </details>

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

        <details class="admin-console-section" data-admin-console-panel>
            <summary class="admin-console-section__summary">
                <span>
                    <strong>Utenti attivi</strong>
                    <small><?php echo count($users); ?> utenti</small>
                </span>
            </summary>
            <div class="admin-console-section__body">
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
                        <?php $scheduleNames = $namesByUser[$userCf] ?? []; ?>
                        <tr data-admin-console-row data-search-text="<?php echo appAdminConsoleEscape(appAdminConsoleSearchText([
                            $user['nome'] ?? '',
                            $user['cognome'] ?? '',
                            $user['email'] ?? '',
                            $user['reparto'] ?? '',
                            appAdminConsoleRoleLabel((int) $user['capo']),
                            implode(' ', $scheduleNames),
                        ])); ?>">
                            <td>
                                <strong><?php echo appAdminConsoleEscape(trim((string) $user['nome'] . ' ' . (string) $user['cognome'])); ?></strong><br>
                                <span class="text-muted"><?php echo appAdminConsoleEscape((string) $user['email']); ?></span>
                            </td>
                            <td><?php echo appAdminConsoleEscape(appDepartments()[(string) $user['reparto']] ?? (string) $user['reparto']); ?></td>
                            <td><?php echo appAdminConsoleEscape(appAdminConsoleRoleLabel((int) $user['capo'])); ?></td>
                            <td><?php echo appAdminConsoleEscape(appAdminConsoleLastSeenLabel($user['last_seen'] ?? null)); ?></td>
                            <td>
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
            </div>
        </details>

        <details class="admin-console-section" data-admin-console-panel>
            <summary class="admin-console-section__summary">
                <span>
                    <strong>Problemi associazioni</strong>
                    <small>
                        <?php echo count($associationIssues); ?> segnalazioni
                        <?php if ($associationIssues !== []): ?>
                            · <?php echo (int) $associationIssueSummary['danger']; ?> critiche
                            · <?php echo (int) $associationIssueSummary['warning']; ?> attenzioni
                        <?php endif; ?>
                    </small>
                </span>
            </summary>
            <div class="admin-console-section__body">
            <div class="table-responsive">
                <table class="table align-middle admin-console-table">
                    <thead>
                    <tr>
                        <th>Tipo</th>
                        <th>Nominativo</th>
                        <th>Reparto</th>
                        <th>Dettaglio</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($associationIssues as $issue): ?>
                        <?php $severity = (string) ($issue['severity'] ?? 'info'); ?>
                        <tr data-admin-console-row data-search-text="<?php echo appAdminConsoleEscape(appAdminConsoleSearchText($issue)); ?>">
                            <td><span class="badge <?php echo appAdminConsoleEscape(appAdminConsoleDiagnosticClass($severity)); ?>"><?php echo appAdminConsoleEscape((string) $issue['type']); ?></span></td>
                            <td><strong><?php echo appAdminConsoleEscape((string) $issue['schedule_name']); ?></strong></td>
                            <td><?php echo appAdminConsoleEscape(appDepartments()[(string) $issue['reparto']] ?? (string) $issue['reparto']); ?></td>
                            <td><?php echo appAdminConsoleEscape((string) $issue['detail']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($associationIssues === []): ?>
                        <tr><td colspan="4" class="text-muted">Nessun problema associazioni rilevato nei filtri attuali.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            </div>
        </details>

        <details class="admin-console-section" data-admin-console-panel>
            <summary class="admin-console-section__summary">
                <span>
                    <strong>Associazioni orari</strong>
                    <small><?php echo count($mappings); ?> associazioni</small>
                </span>
            </summary>
            <div class="admin-console-section__body">
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
                        <tr data-admin-console-row data-search-text="<?php echo appAdminConsoleEscape(appAdminConsoleSearchText([
                            $mapping['schedule_name'] ?? '',
                            $mapping['reparto'] ?? '',
                            $mapping['user_cf'] ?? '',
                            $linkedName,
                            $mapping['email'] ?? '',
                            $isIgnored ? 'ignorato' : '',
                            $isUnregistered ? 'non registrato' : '',
                        ])); ?>">
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
            </div>
        </details>

        <details class="admin-console-section" data-admin-console-panel>
            <summary class="admin-console-section__summary">
                <span>
                    <strong>Inviti</strong>
                    <small><?php echo count($invites); ?> inviti</small>
                </span>
            </summary>
            <div class="admin-console-section__body">
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-3">
                <div class="text-muted">
                    <?php if ($departmentFilter !== ''): ?>
                        Revocati nel reparto selezionato: <?php echo (int) $revokedInvitesCount; ?>.
                    <?php else: ?>
                        Inviti revocati totali: <?php echo (int) $revokedInvitesCount; ?>.
                    <?php endif; ?>
                </div>
                <form method="post" data-admin-console-confirm="Eliminare definitivamente gli inviti revocati? Questa operazione non può essere annullata.">
                    <input type="hidden" name="csrf_token" value="<?php echo appAdminConsoleEscape($appCsrfToken); ?>">
                    <input type="hidden" name="action" value="delete_revoked_invites">
                    <input type="hidden" name="reparto" value="<?php echo appAdminConsoleEscape($departmentFilter); ?>">
                    <input type="hidden" name="stato" value="<?php echo appAdminConsoleEscape($inviteStatusFilter); ?>">
                    <button type="submit" class="btn btn-outline-danger btn-sm" <?php echo $revokedInvitesCount > 0 ? '' : 'disabled'; ?>>
                        Elimina inviti revocati
                    </button>
                </form>
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
                        <tr data-admin-console-row data-search-text="<?php echo appAdminConsoleEscape(appAdminConsoleSearchText([
                            $invite['invited_nome'] ?? '',
                            $invite['invited_cognome'] ?? '',
                            $invite['invited_email'] ?? '',
                            $invite['reparto'] ?? '',
                            appAdminConsoleRoleLabel((int) ($invite['invited_capo'] ?? 0)),
                            $invite['author_name'] ?? '',
                            appInviteStatusLabel($invite),
                        ])); ?>">
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
            </div>
        </details>

        <details class="admin-console-section" data-admin-console-panel>
            <summary class="admin-console-section__summary">
                <span>
                    <strong>Audit recente</strong>
                    <small><?php echo count($auditLogs); ?> eventi</small>
                </span>
            </summary>
            <div class="admin-console-section__body">
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
                            <tr data-admin-console-row data-search-text="<?php echo appAdminConsoleEscape(appAdminConsoleSearchText([
                                $event['created_at'] ?? '',
                                $event['actor_name'] ?? '',
                                $event['actor_cf'] ?? '',
                                appAdminConsoleAuditActionLabel((string) $event['action']),
                                $event['target_type'] ?? '',
                                $event['target_id'] ?? '',
                                appAdminConsoleAuditDetailsLabel($event['details_json'] ?? null),
                            ])); ?>">
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
            </div>
        </details>
    <?php endif; ?>
</main>
<script src="admin_console.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
</body>
</html>

<?php
declare(strict_types=1);

if (!defined('ADMIN_CONSOLE_APP_ROOT')) {
    define('ADMIN_CONSOLE_APP_ROOT', dirname(__DIR__, 4));
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
        ADMIN_CONSOLE_APP_ROOT . '/myorari-backup.log',
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

    $total = @disk_total_space(ADMIN_CONSOLE_APP_ROOT);
    $free = @disk_free_space(ADMIN_CONSOLE_APP_ROOT);
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

    appAdminConsoleDirectoryDiagnostic($diagnostics, 'storage', ADMIN_CONSOLE_APP_ROOT . '/storage');
    appAdminConsoleDirectoryDiagnostic($diagnostics, 'sessioni', app_session_storage_path());
    appAdminConsoleDirectoryDiagnostic($diagnostics, 'turni_json', ADMIN_CONSOLE_APP_ROOT . '/turni_json');
    appAdminConsoleDirectoryDiagnostic($diagnostics, 'note_json', ADMIN_CONSOLE_APP_ROOT . '/note_json');
    appAdminConsoleDirectoryDiagnostic($diagnostics, 'xlms', ADMIN_CONSOLE_APP_ROOT . '/xlms', false);

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


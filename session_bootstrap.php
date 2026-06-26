<?php

require_once __DIR__ . '/php_runtime.php';

const APP_SESSION_LIFETIME = 60 * 60 * 24 * 7;
const APP_LAST_SEEN_UPDATE_INTERVAL = 300;
const APP_PERFORMANCE_LOG_MAX_BYTES = 2 * 1024 * 1024;

function app_session_storage_path(): string
{
    $preferredPath = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions';
    if (app_session_ensure_storage_directory($preferredPath)) {
        return $preferredPath;
    }

    // In locale XAMPP può eseguire PHP con un utente diverso dal proprietario
    // del progetto. In quel caso storage/ non è scrivibile: usiamo una
    // directory privata del server, senza interrompere il login.
    $temporaryRoot = DIRECTORY_SEPARATOR === '/' ? '/tmp' : sys_get_temp_dir();
    $fallbackPath = rtrim($temporaryRoot, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'myorari-sessions';
    if (app_session_ensure_storage_directory($fallbackPath)) {
        return $fallbackPath;
    }

    throw new RuntimeException('Impossibile creare la cartella delle sessioni');
}

function app_session_ensure_storage_directory(string $path): bool
{
    if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
        return false;
    }

    return is_writable($path);
}

function app_session_configure_storage(): void
{
    $path = app_session_storage_path();

    ini_set('session.gc_maxlifetime', (string) APP_SESSION_LIFETIME);
    session_save_path($path);
}

function app_session_refresh_cookie(): void
{
    if (!ini_get('session.use_cookies')) {
        return;
    }

    $params = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires' => time() + APP_SESSION_LIFETIME,
        'path' => $params['path'] ?: '/',
        'domain' => $params['domain'] ?? '',
        'secure' => (bool) ($params['secure'] ?? false),
        'httponly' => (bool) ($params['httponly'] ?? true),
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}

function app_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    app_session_configure_storage();

    session_set_cookie_params([
        'lifetime' => APP_SESSION_LIFETIME,
        'path' => '/',
        'domain' => '',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        throw new RuntimeException('Impossibile avviare la sessione');
    }
    app_session_refresh_cookie();
    app_session_validate_user();

    // Se una sessione autenticata non è più valida (ad esempio un account è
    // stato rimosso), app_session_validate_user la chiude. Nella stessa
    // richiesta serve però una nuova sessione anonima, altrimenti i moduli
    // pubblici con CSRF, come l'attivazione di un invito, mostrano un token
    // che non può essere verificato al POST successivo.
    if (session_status() !== PHP_SESSION_ACTIVE) {
        app_session_configure_storage();
        if (!session_start()) {
            throw new RuntimeException('Impossibile riavviare la sessione');
        }
        app_session_refresh_cookie();
    }
}

function app_session_destroy_current(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
    session_id('');
}

function app_csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function app_csrf_request_is_valid(): bool
{
    $provided = (string) ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $expected = (string) ($_SESSION['csrf_token'] ?? '');

    return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
}

function app_session_write_close_if_active(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

function app_performance_log_path(): string
{
    $storageDir = __DIR__ . DIRECTORY_SEPARATOR . 'storage';
    if (!is_dir($storageDir) && !@mkdir($storageDir, 0750, true) && !is_dir($storageDir)) {
        return '';
    }
    if (!is_writable($storageDir)) {
        return '';
    }

    return $storageDir . DIRECTORY_SEPARATOR . 'performance.log';
}

function app_performance_log_rotate(string $path): void
{
    $size = @filesize($path);
    if (!is_int($size) || $size < APP_PERFORMANCE_LOG_MAX_BYTES) {
        return;
    }

    @rename($path, $path . '.1');
}

function app_performance_log_shutdown(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $path = app_performance_log_path();
    if ($path === '') {
        return;
    }

    app_performance_log_rotate($path);
    $startedAt = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
    $status = (int) http_response_code();
    if ($status < 100) {
        $status = 200;
    }

    $lastError = error_get_last();
    if (is_array($lastError) && in_array((int) ($lastError['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $status = max($status, 500);
    }

    $sessionUser = $_SESSION['user'] ?? null;
    $entry = [
        'ts' => date(DATE_ATOM),
        'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
        'route' => basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')),
        'status' => $status,
        'duration_ms' => round((microtime(true) - $startedAt) * 1000, 1),
        'memory_peak_kb' => (int) ceil(memory_get_peak_usage(true) / 1024),
        'role' => is_array($sessionUser) ? (int) ($sessionUser['capo'] ?? 0) : null,
        'authenticated' => is_array($sessionUser),
    ];

    $json = json_encode($entry, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return;
    }

    @file_put_contents($path, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function app_performance_log_register(): void
{
    static $registered = false;
    if ($registered || PHP_SAPI === 'cli') {
        return;
    }

    $registered = true;
    register_shutdown_function('app_performance_log_shutdown');
}

function app_session_validate_user(): void
{
    global $connessione, $pdo;

    $sessionUser = $_SESSION['user'] ?? null;
    $cf = is_array($sessionUser) ? (string) ($sessionUser['cf'] ?? '') : '';
    if ($cf === '') {
        return;
    }

    require_once __DIR__ . '/connection_files/connection.php';
    if (!$connessione || !($pdo instanceof PDO)) {
        // In caso di database non disponibile, non mantenere una sessione che non può essere verificata.
        app_session_destroy_current();
        return;
    }

    try {
        $statement = $pdo->prepare(
            'SELECT session_version, nome, cognome, avatar, capo, reparto, attivo
             FROM utenti
             WHERE cod_fiscale = ?
             LIMIT 1'
        );
        $statement->execute([$cf]);
        $currentUser = $statement->fetch(PDO::FETCH_ASSOC);
        $sessionVersion = (int) ($sessionUser['session_version'] ?? 0);

        if (!$currentUser || (int) ($currentUser['attivo'] ?? 1) !== 1 || (int) $currentUser['session_version'] !== $sessionVersion) {
            app_session_destroy_current();
            return;
        }

        $_SESSION['user'] = array_merge($sessionUser, [
            'nome' => (string) $currentUser['nome'],
            'cognome' => (string) $currentUser['cognome'],
            'avatar' => (string) ($currentUser['avatar'] ?? 'default'),
            'capo' => (int) ($currentUser['capo'] ?? 0),
            'reparto' => (string) ($currentUser['reparto'] ?? ''),
        ]);

        app_session_touch_user($pdo, $cf);
    } catch (Throwable $e) {
        error_log('Verifica sessione non riuscita: ' . $e->getMessage());
        app_session_destroy_current();
    }
}

function app_session_touch_user(PDO $pdo, string $cf, bool $force = false): void
{
    if ($cf === '') {
        return;
    }

    $lastTouch = (int) ($_SESSION['last_seen_touch'] ?? 0);
    $now = time();
    if (!$force && $lastTouch > 0 && ($now - $lastTouch) < APP_LAST_SEEN_UPDATE_INTERVAL) {
        return;
    }

    $statement = $pdo->prepare('UPDATE utenti SET last_seen = NOW() WHERE cod_fiscale = ?');
    $statement->execute([$cf]);
    $_SESSION['last_seen_touch'] = $now;
}

app_performance_log_register();

<?php

require_once __DIR__ . '/php_runtime.php';

const APP_SESSION_LIFETIME = 60 * 60 * 24 * 7;
const APP_LAST_SEEN_UPDATE_INTERVAL = 300;

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
}

function app_session_destroy_current(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
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
        $statement = $pdo->prepare('SELECT session_version FROM utenti WHERE cod_fiscale = ? LIMIT 1');
        $statement->execute([$cf]);
        $currentVersion = $statement->fetchColumn();
        $sessionVersion = (int) ($sessionUser['session_version'] ?? 0);

        if ($currentVersion === false || (int) $currentVersion !== $sessionVersion) {
            app_session_destroy_current();
            return;
        }

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

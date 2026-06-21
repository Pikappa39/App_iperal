<?php

require_once __DIR__ . '/php_runtime.php';

const APP_SESSION_LIFETIME = 60 * 60 * 24 * 30;

function app_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => APP_SESSION_LIFETIME,
        'path' => '/',
        'domain' => '',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
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

function app_session_validate_user(): void
{
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
        }
    } catch (Throwable $e) {
        error_log('Verifica sessione non riuscita: ' . $e->getMessage());
        app_session_destroy_current();
    }
}

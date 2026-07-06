<?php
declare(strict_types=1);

function notificationsModuleBootstrap(array $extraRequires = []): string
{
    global $connessione, $pdo;

    $appRoot = dirname(__DIR__, 3);
    if (!defined('NOTIFICATIONS_MODULE_APP_ROOT')) {
        define('NOTIFICATIONS_MODULE_APP_ROOT', $appRoot);
    }

    require_once $appRoot . '/app_config.php';
    require_once $appRoot . '/session_bootstrap.php';
    app_session_start();
    require_once $appRoot . '/connection_files/connection.php';

    foreach ($extraRequires as $relativePath) {
        require_once $appRoot . '/' . ltrim((string) $relativePath, '/\\');
    }

    return $appRoot;
}

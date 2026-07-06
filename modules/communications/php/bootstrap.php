<?php
declare(strict_types=1);

function communicationModuleBootstrap(array $extraRequires = []): string
{
    header('Content-Type: application/json; charset=utf-8');

    $appRoot = dirname(__DIR__, 3);
    if (!defined('COMMUNICATION_MODULE_APP_ROOT')) {
        define('COMMUNICATION_MODULE_APP_ROOT', $appRoot);
    }

    global $connessione, $pdo;

    require_once $appRoot . '/session_bootstrap.php';
    app_session_start();
    require_once $appRoot . '/connection_files/connection.php';

    foreach ($extraRequires as $relativePath) {
        require_once $appRoot . '/' . ltrim((string) $relativePath, '/\\');
    }

    return $appRoot;
}

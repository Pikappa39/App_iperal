<?php
declare(strict_types=1);

function customerOrdersModuleBootstrap(array $extraRequires = []): string
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');

    $appRoot = dirname(__DIR__, 3);
    if (!defined('CUSTOMER_ORDERS_MODULE_APP_ROOT')) {
        define('CUSTOMER_ORDERS_MODULE_APP_ROOT', $appRoot);
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

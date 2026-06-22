<?php
require_once __DIR__ . '/../app_config.php';

$connessione = false;
$pdo = null;

try {
    $dbHost = appEnv('APP_DB_HOST');
    $dbName = appEnv('APP_DB_NAME');
    $dbUser = appEnv('APP_DB_USER');
    $dbPassword = appEnv('APP_DB_PASSWORD');

    if ($dbHost === '' || $dbName === '' || $dbUser === '' || !appHasEnv('APP_DB_PASSWORD')) {
        throw new RuntimeException('Configurazione database incompleta');
    }

    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPassword,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $connessione = true;
} catch (Throwable $e) {
    $connessione = false;
    error_log('Connessione database non disponibile: ' . $e->getMessage());
}

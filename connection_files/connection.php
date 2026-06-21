<?php
$connessione = false;
$pdo = null;

try {
    $dbHost = trim((string) getenv('APP_DB_HOST'));
    $dbName = trim((string) getenv('APP_DB_NAME'));
    $dbUser = trim((string) getenv('APP_DB_USER'));
    $dbPassword = (string) getenv('APP_DB_PASSWORD');

    if ($dbHost === '' || $dbName === '' || $dbUser === '' || $dbPassword === '') {
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
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS utenti (
            cod_fiscale VARCHAR(16) PRIMARY KEY,
            nome VARCHAR(100),
            cognome VARCHAR(100),
            badge VARCHAR(20) UNIQUE,
            password VARCHAR(255),
            email VARCHAR(255) UNIQUE,
            avatar VARCHAR(50) NOT NULL DEFAULT 'default',
            capo TINYINT(1) NOT NULL DEFAULT 0
            
        )
    ");

    $columns = $pdo->query("SHOW COLUMNS FROM utenti")->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array("avatar", $columns, true)) {
        $pdo->exec("ALTER TABLE utenti ADD COLUMN avatar VARCHAR(50) NOT NULL DEFAULT 'default'");
    }
    if (!in_array("capo", $columns, true)) {
        $pdo->exec("ALTER TABLE utenti ADD COLUMN capo TINYINT(1) NOT NULL DEFAULT 0");
    }

    $connessione = true;
} catch (Throwable $e) {
    $connessione = false;
    error_log('Connessione database non disponibile: ' . $e->getMessage());
}

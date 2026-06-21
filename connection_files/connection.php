<?php
require_once __DIR__ . '/../app_config.php';

$connessione = false;
$pdo = null;

try {
    $dbHost = appEnv('APP_DB_HOST');
    $dbName = appEnv('APP_DB_NAME');
    $dbUser = appEnv('APP_DB_USER');
    $dbPassword = appEnv('APP_DB_PASSWORD');

    if (
        $dbHost === '' ||
        $dbName === '' ||
        $dbUser === '' ||
        !appHasEnv('APP_DB_PASSWORD')
    ) {
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
            capo TINYINT(1) NOT NULL DEFAULT 0,
            reparto VARCHAR(20) NULL DEFAULT NULL
            
        )
    ");

    $columns = $pdo->query("SHOW COLUMNS FROM utenti")->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array("avatar", $columns, true)) {
        $pdo->exec("ALTER TABLE utenti ADD COLUMN avatar VARCHAR(50) NOT NULL DEFAULT 'default'");
    }
    if (!in_array("capo", $columns, true)) {
        $pdo->exec("ALTER TABLE utenti ADD COLUMN capo TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!in_array("session_version", $columns, true)) {
        $pdo->exec("ALTER TABLE utenti ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 0");
    }
    if (!in_array("reparto", $columns, true)) {
        $pdo->exec("ALTER TABLE utenti ADD COLUMN reparto VARCHAR(20) NULL DEFAULT NULL");
    }

    // Il file Excel contiene solo il nominativo. Questa tabella conserva la
    // scelta fatta dal capo tra quel nominativo e l'utente reale del reparto.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS schedule_name_mappings (
            reparto VARCHAR(20) NOT NULL,
            schedule_name VARCHAR(191) NOT NULL,
            user_cf VARCHAR(16) NOT NULL,
            created_by_cf VARCHAR(16) NULL DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (reparto, schedule_name),
            INDEX idx_schedule_name_mappings_user (user_cf)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_cf VARCHAR(16) NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL DEFAULT NULL,
            requested_ip VARCHAR(45) NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_password_reset_user_created (user_cf, created_at),
            INDEX idx_password_reset_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $resetTableCollation = $pdo->query(
        "SELECT TABLE_COLLATION
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_reset_tokens'"
    )->fetchColumn();
    if (is_string($resetTableCollation) && strcasecmp($resetTableCollation, 'utf8mb4_general_ci') !== 0) {
        $pdo->exec('ALTER TABLE password_reset_tokens CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS communications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            author_cf VARCHAR(16) NOT NULL,
            title VARCHAR(150) NOT NULL,
            message TEXT NOT NULL,
            priority ENUM('normal', 'important') NOT NULL DEFAULT 'normal',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_communications_author_created (author_cf, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS communication_recipients (
            communication_id BIGINT UNSIGNED NOT NULL,
            recipient_cf VARCHAR(16) NOT NULL,
            read_at DATETIME NULL DEFAULT NULL,
            acknowledged_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (communication_id, recipient_cf),
            INDEX idx_communication_recipients_inbox (recipient_cf, acknowledged_at, communication_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $connessione = true;
} catch (Throwable $e) {
    $connessione = false;
    error_log('Connessione database non disponibile: ' . $e->getMessage());
}

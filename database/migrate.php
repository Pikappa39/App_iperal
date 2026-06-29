<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo da riga di comando\n");
}

require __DIR__ . '/../app_config.php';

$host = appEnv('APP_DB_HOST');
$name = appEnv('APP_DB_NAME');
$user = appEnv('APP_DB_USER');
$password = appEnv('APP_DB_PASSWORD');
if ($host === '' || $name === '' || $user === '' || !appHasEnv('APP_DB_PASSWORD')) {
    throw new RuntimeException('Configurazione database incompleta');
}

$schema = file_get_contents(__DIR__ . '/schema.sql');
if (!is_string($schema)) {
    throw new RuntimeException('Schema database non leggibile');
}

$pdo = new PDO(
    "mysql:host={$host};dbname={$name};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);
$pdo->exec($schema);

// Alcune installazioni storiche conservano la data fittizia 0000-00-00.
// MySQL moderno non la accetta mentre ricostruisce la tabella durante un
// ALTER; la convertiamo quindi nel valore semantico corretto: data assente.
$hireDateColumn = $pdo->query(
    "SELECT DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'utenti'
       AND COLUMN_NAME = 'assunzione'
     LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
if ($hireDateColumn) {
    $sqlMode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    $pdo->exec("SET SESSION sql_mode = ''");
    try {
        $pdo->exec("UPDATE utenti SET assunzione = NULL WHERE assunzione = '0000-00-00'");
    } finally {
        $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote($sqlMode));
    }
    if (
        strtolower((string) $hireDateColumn['DATA_TYPE']) !== 'date'
        || (string) $hireDateColumn['IS_NULLABLE'] !== 'YES'
        || $hireDateColumn['COLUMN_DEFAULT'] !== null
    ) {
        $pdo->exec('ALTER TABLE utenti MODIFY assunzione DATE NULL DEFAULT NULL');
    }
}

// Le installazioni precedenti usavano un badge numerico. I nuovi account
// ricevono un identificativo tecnico alfanumerico, quindi adeguiamo la
// colonna senza modificare i valori già presenti.
$badgeColumn = $pdo->query(
    "SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'utenti'
       AND COLUMN_NAME = 'badge'
     LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if (!$badgeColumn) {
    throw new RuntimeException('Colonna utenti.badge non trovata');
}

$badgeType = strtolower((string) $badgeColumn['DATA_TYPE']);
$badgeLength = (int) ($badgeColumn['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
if (!in_array($badgeType, ['char', 'varchar'], true) || $badgeLength < 20) {
    $pdo->exec('ALTER TABLE utenti MODIFY badge VARCHAR(20) NOT NULL');
    echo "Colonna utenti.badge aggiornata.\n";
}

// Gli identificativi tecnici dei nuovi account sono lunghi 16 caratteri.
// Le installazioni precedenti possono avere ancora colonne da 15 caratteri.
foreach ([
    ['table' => 'utenti', 'column' => 'cod_fiscale'],
    ['table' => 'user_invites', 'column' => 'invited_cf'],
] as $identityColumn) {
    $statement = $pdo->prepare(
        "SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1"
    );
    $statement->execute([$identityColumn['table'], $identityColumn['column']]);
    $column = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$column) {
        throw new RuntimeException('Colonna ' . $identityColumn['table'] . '.' . $identityColumn['column'] . ' non trovata');
    }

    $columnType = strtolower((string) $column['DATA_TYPE']);
    $columnLength = (int) ($column['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
    if (!in_array($columnType, ['char', 'varchar'], true) || $columnLength < 16) {
        $pdo->exec('ALTER TABLE ' . $identityColumn['table'] . ' MODIFY ' . $identityColumn['column'] . ' VARCHAR(16) NOT NULL');
        echo 'Colonna ' . $identityColumn['table'] . '.' . $identityColumn['column'] . " aggiornata.\n";
    }
}

$inviteRoleColumn = $pdo->query(
    "SELECT 1
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'user_invites'
       AND COLUMN_NAME = 'invited_capo'
     LIMIT 1"
)->fetchColumn();
if (!$inviteRoleColumn) {
    $pdo->exec('ALTER TABLE user_invites ADD invited_capo TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER invited_cognome');
    echo "Colonna user_invites.invited_capo aggiunta.\n";
}

$inviteBoxInfoColumn = $pdo->query(
    "SELECT 1
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'user_invites'
       AND COLUMN_NAME = 'invited_box_info'
     LIMIT 1"
)->fetchColumn();
if (!$inviteBoxInfoColumn) {
    $pdo->exec('ALTER TABLE user_invites ADD invited_box_info TINYINT(1) NOT NULL DEFAULT 0 AFTER invited_capo');
    echo "Colonna user_invites.invited_box_info aggiunta.\n";
}

$activeUserColumn = $pdo->query(
    "SELECT 1
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'utenti'
       AND COLUMN_NAME = 'attivo'
     LIMIT 1"
)->fetchColumn();
if (!$activeUserColumn) {
    $pdo->exec('ALTER TABLE utenti ADD attivo TINYINT(1) NOT NULL DEFAULT 1 AFTER reparto');
    echo "Colonna utenti.attivo aggiunta.\n";
}

$boxInfoColumn = $pdo->query(
    "SELECT 1
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'utenti'
       AND COLUMN_NAME = 'box_info'
     LIMIT 1"
)->fetchColumn();
if (!$boxInfoColumn) {
    $pdo->exec('ALTER TABLE utenti ADD box_info TINYINT(1) NOT NULL DEFAULT 0 AFTER reparto');
    echo "Colonna utenti.box_info aggiunta.\n";
}

$sessionVersionColumn = $pdo->query(
    "SELECT 1
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'utenti'
       AND COLUMN_NAME = 'session_version'
     LIMIT 1"
)->fetchColumn();
if (!$sessionVersionColumn) {
    $pdo->exec('ALTER TABLE utenti ADD session_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER attivo');
    echo "Colonna utenti.session_version aggiunta.\n";
}

$lastSeenColumn = $pdo->query(
    "SELECT 1
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'utenti'
       AND COLUMN_NAME = 'last_seen'
     LIMIT 1"
)->fetchColumn();
if (!$lastSeenColumn) {
    $pdo->exec('ALTER TABLE utenti ADD last_seen DATETIME NULL DEFAULT NULL AFTER session_version');
    echo "Colonna utenti.last_seen aggiunta.\n";
}

$itemPriceColumn = $pdo->query(
    "SELECT 1
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'customer_order_items'
       AND COLUMN_NAME = 'price_at_order'
     LIMIT 1"
)->fetchColumn();
if (!$itemPriceColumn) {
    $pdo->exec('ALTER TABLE customer_order_items ADD price_at_order DECIMAL(10,2) NULL DEFAULT NULL AFTER quantity');
    echo "Colonna customer_order_items.price_at_order aggiunta.\n";
}

$auditCreatedIndex = $pdo->query(
    "SELECT 1
     FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'admin_audit_log'
       AND INDEX_NAME = 'idx_admin_audit_created'
     LIMIT 1"
)->fetchColumn();
if (!$auditCreatedIndex) {
    $pdo->exec('ALTER TABLE admin_audit_log ADD INDEX idx_admin_audit_created (created_at)');
    echo "Indice admin_audit_log.idx_admin_audit_created aggiunto.\n";
}

echo "Migrazione database completata.\n";

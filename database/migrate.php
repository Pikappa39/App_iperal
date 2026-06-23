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
echo "Migrazione database completata.\n";

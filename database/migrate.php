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
echo "Migrazione database completata.\n";

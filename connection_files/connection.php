<?php
$connessione = false;

try {
    $pdo = new PDO("mysql:host=localhost;dbname=iperal_01", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
} catch (PDOException $e) {
    $connessione = false;

}

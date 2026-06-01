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
            email VARCHAR(255) UNIQUE
            
        )
    ");
    $connessione = true;
} catch (PDOException $e) {
    $connessione = false;

}

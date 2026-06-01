<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require 'connection.php';
$nome=$_POST['nome'];
$cognome=$_POST['cognome'];
$cf=$_POST['cf'];
$email=$_POST['email'];
$password=$_POST['password'];
$badge=$_POST['badge'];
try{
$stmt=$pdo->prepare("INSERT INTO utenti (cod_fiscale, nome, cognome, badge, password, email) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->execute([$cf, $nome, $cognome, $badge, password_hash($password, PASSWORD_DEFAULT), $email]);
}
catch(PDOException $e){
    echo "Errore durante la registrazione: " . $e->getMessage();
    exit;
}
?>
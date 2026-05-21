<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require 'conn.php';
$nome=$_POST['nome'];
$email=$_POST['email'];
$password=password_hash($_POST['password'], PASSWORD_DEFAULT);
$stmt= $pdo->prepare ("INSERT INTO utenti (nome,email,password) VALUES (?,?,?)");
$stmt->execute ([$nome,$email,$password]);
?>
<?php


session_start();
require "connection.php";

$stmt = $pdo->prepare("SELECT * FROM utenti WHERE email = ?");
$stmt->execute([$_POST['email']]);
$user = $stmt->fetch();
//var_dump($_POST);
if ($user && password_verify($_POST['password'], $user['password'])) {
        $_SESSION['user'] = [
        "id" => $user["id"],
        "nome" => $user["nome"]
    ];
    echo json_encode([
        "logged" => true,
        "nome" => $_SESSION['user']['nome']
    ]);
} else {
    echo json_encode([
        "logged" => false
    ]);
}
?>

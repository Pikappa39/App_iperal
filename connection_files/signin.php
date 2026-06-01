<?php
header("Content-Type: application/json; charset=utf-8");

session_start();
require "connection.php";
try {
    $stmt = $pdo->prepare("SELECT * FROM utenti WHERE email = ?");
    $stmt->execute([$_POST["email"] ?? ""]);
    $user = $stmt->fetch();

    if ($user && password_verify($_POST["password"] ?? "", $user["password"])) {
        $_SESSION["user"] = [
            "cf" => $user["cod_fiscale"],
            "nome" => $user["nome"],
        ];
        echo json_encode([
            "logged" => true,
            "nome" => $_SESSION["user"]["nome"],
            "cf" => $_SESSION["user"]["cf"],
        ]);
    } else {
        echo json_encode([
            "logged" => false,
        ]);
    }
} catch (PDOException $e) {
    echo json_encode([
        "logged" => false,
        "error_code" => "errore_query" . $e->getCode(),
        "error" => "Errore durante l'accesso al database: " . $e->getMessage(),
    ]);
}

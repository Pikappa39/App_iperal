<?php
require __DIR__ . '/../session_bootstrap.php';
app_session_start();

if (isset($_SESSION['user'])) {
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

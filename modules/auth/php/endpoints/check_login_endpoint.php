<?php
require __DIR__ . '/../bootstrap.php';

authModuleBootstrap();

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

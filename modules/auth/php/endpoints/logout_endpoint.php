<?php
require __DIR__ . '/../bootstrap.php';

authModuleBootstrap();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !app_csrf_request_is_valid()) {
    http_response_code(403);
    exit('Richiesta non valida');
}

app_session_destroy_current();

header("Location: ../index.php");
exit;

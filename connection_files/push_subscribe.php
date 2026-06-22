<?php
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../session_bootstrap.php';
app_session_start();
require __DIR__ . '/connection.php';
require __DIR__ . '/push_lib.php';

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if (!isset($_SESSION['user'])) {
    jsonResponse([
        'ok' => false,
        'error' => 'Accesso richiesto',
    ], 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonResponse([
        'ok' => false,
        'error' => 'Metodo non consentito',
    ], 405);
}

if (!app_csrf_request_is_valid()) {
    jsonResponse(['ok' => false, 'error' => 'Richiesta non valida. Ricarica la pagina e riprova.'], 403);
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody ?: '', true);

if (!is_array($payload)) {
    jsonResponse([
        'ok' => false,
        'error' => 'Payload non valido',
    ], 400);
}

try {
    appPushStoreSubscription(
        $pdo,
        (string) ($_SESSION['user']['cf'] ?? ''),
        $payload,
        (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
    );

    jsonResponse([
        'ok' => true,
    ]);
} catch (Throwable $e) {
    jsonResponse([
        'ok' => false,
        'error' => 'Impossibile salvare la subscription',
    ], 500);
}

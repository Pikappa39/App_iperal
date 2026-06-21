<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../session_bootstrap.php';
app_session_start();
require __DIR__ . '/connection.php';
require __DIR__ . '/push_lib.php';

function pushStatusResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    pushStatusResponse(['ok' => false, 'error' => 'Metodo non consentito'], 405);
}
if (!isset($_SESSION['user']['cf']) || !$connessione || !($pdo instanceof PDO)) {
    pushStatusResponse(['ok' => false, 'error' => 'Accesso richiesto'], 401);
}

$payload = json_decode((string) file_get_contents('php://input'), true);
$endpoint = is_array($payload) ? appPushSubscriptionEndpoint($payload) : '';
if ($endpoint === '') {
    pushStatusResponse(['ok' => true, 'enabled' => false]);
}

try {
    pushStatusResponse([
        'ok' => true,
        'enabled' => appPushSubscriptionIsActiveForUser($pdo, (string) $_SESSION['user']['cf'], $endpoint),
    ]);
} catch (Throwable $error) {
    error_log('Controllo subscription push non riuscito: ' . $error->getMessage());
    pushStatusResponse(['ok' => false, 'error' => 'Impossibile verificare le notifiche'], 500);
}

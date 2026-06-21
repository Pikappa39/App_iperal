<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../session_bootstrap.php';
app_session_start();
require __DIR__ . '/connection.php';
require __DIR__ . '/push_lib.php';

function pushUnsubscribeResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    pushUnsubscribeResponse(['ok' => false, 'error' => 'Metodo non consentito'], 405);
}
if (!isset($_SESSION['user']['cf']) || !$connessione || !($pdo instanceof PDO)) {
    pushUnsubscribeResponse(['ok' => false, 'error' => 'Accesso richiesto'], 401);
}

$payload = json_decode((string) file_get_contents('php://input'), true);
$endpoint = is_array($payload) ? appPushSubscriptionEndpoint($payload) : '';
if ($endpoint === '') {
    pushUnsubscribeResponse(['ok' => false, 'error' => 'Subscription non valida'], 400);
}

try {
    appPushDeactivateSubscription($pdo, $endpoint);
    pushUnsubscribeResponse(['ok' => true]);
} catch (Throwable $error) {
    error_log('Disattivazione subscription push non riuscita: ' . $error->getMessage());
    pushUnsubscribeResponse(['ok' => false, 'error' => 'Impossibile disattivare le notifiche'], 500);
}

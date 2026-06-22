<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require __DIR__ . '/../session_bootstrap.php';
app_session_start();
require __DIR__ . '/connection.php';
require __DIR__ . '/push_lib.php';

function pushDeliveryResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    pushDeliveryResponse(['ok' => false, 'allowed' => false], 405);
}
if (!isset($_SESSION['user']['cf']) || !$connessione || !($pdo instanceof PDO)) {
    pushDeliveryResponse(['ok' => true, 'allowed' => false]);
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    pushDeliveryResponse(['ok' => false, 'allowed' => false], 400);
}

$endpoint = appPushSubscriptionEndpoint($payload);
$recipientCf = trim((string) ($payload['recipient_cf'] ?? ''));
$currentUserCf = (string) $_SESSION['user']['cf'];

try {
    $subscriptionActive = appPushSubscriptionIsActiveForUser($pdo, $currentUserCf, $endpoint);
    $recipientMatches = $recipientCf === '' || hash_equals($currentUserCf, $recipientCf);

    pushDeliveryResponse([
        'ok' => true,
        'allowed' => $subscriptionActive && $recipientMatches,
    ]);
} catch (Throwable $error) {
    error_log('Verifica consegna push non riuscita: ' . $error->getMessage());
    pushDeliveryResponse(['ok' => false, 'allowed' => false], 500);
}

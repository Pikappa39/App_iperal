<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../session_bootstrap.php';
app_session_start();
require __DIR__ . '/../app_config.php';
require __DIR__ . '/connection.php';

function avatarResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    avatarResponse(['ok' => false, 'error' => 'Metodo non consentito'], 405);
}

if (!app_csrf_request_is_valid()) {
    avatarResponse(['ok' => false, 'error' => 'Richiesta non valida. Ricarica la pagina e riprova.'], 403);
}

$sessionUser = $_SESSION['user'] ?? null;
$userCf = is_array($sessionUser) ? trim((string) ($sessionUser['cf'] ?? '')) : '';
if ($userCf === '') {
    avatarResponse(['ok' => false, 'error' => 'Accesso richiesto'], 401);
}

if (!$connessione || !($pdo instanceof PDO)) {
    avatarResponse(['ok' => false, 'error' => 'Servizio profilo temporaneamente non disponibile'], 503);
}

$availableAvatars = appAvailableAvatars();
$avatar = trim((string) ($_POST['avatar'] ?? ''));
if (!in_array($avatar, $availableAvatars, true)) {
    avatarResponse(['ok' => false, 'error' => 'Avatar non valido'], 422);
}

$statement = $pdo->prepare('UPDATE utenti SET avatar = ? WHERE cod_fiscale = ? LIMIT 1');
$statement->execute([$avatar, $userCf]);

$_SESSION['user']['avatar'] = $avatar;

avatarResponse([
    'ok' => true,
    'avatar' => $avatar,
    'avatar_url' => 'img/' . rawurlencode($avatar) . '.png',
]);

<?php
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/connection.php';

function confirmResetResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    confirmResetResponse(['ok' => false, 'error' => 'Metodo non consentito'], 405);
}
if (!$connessione || !($pdo instanceof PDO)) {
    confirmResetResponse(['ok' => false, 'error' => 'Servizio temporaneamente non disponibile'], 503);
}

$token = trim((string) ($_POST['token'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$confirmation = (string) ($_POST['confirmation'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    confirmResetResponse(['ok' => false, 'error' => 'Il link non è valido o è scaduto'], 400);
}
if (strlen($password) < 12) {
    confirmResetResponse(['ok' => false, 'error' => 'La password deve contenere almeno 12 caratteri'], 400);
}
if (!hash_equals($password, $confirmation)) {
    confirmResetResponse(['ok' => false, 'error' => 'Le password non coincidono'], 400);
}

try {
    $pdo->beginTransaction();
    $lookup = $pdo->prepare(
        'SELECT id, user_cf FROM password_reset_tokens
         WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() FOR UPDATE'
    );
    $lookup->execute([hash('sha256', $token)]);
    $reset = $lookup->fetch();
    if (!$reset) {
        $pdo->rollBack();
        confirmResetResponse(['ok' => false, 'error' => 'Il link non è valido o è scaduto'], 400);
    }

    $pdo->prepare('UPDATE utenti SET password = ?, session_version = session_version + 1 WHERE cod_fiscale = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $reset['user_cf']]);
    $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_cf = ?')
        ->execute([$reset['user_cf']]);
    $pdo->commit();
    confirmResetResponse(['ok' => true, 'message' => 'Password aggiornata. Ora puoi accedere.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Errore conferma recupero password: ' . $e->getMessage());
    confirmResetResponse(['ok' => false, 'error' => 'Impossibile aggiornare la password. Riprova più tardi.'], 500);
}

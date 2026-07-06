<?php
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../bootstrap.php';

authModuleBootstrap([
    'modules/auth/php/mail/password_reset_mail.php',
]);

function passwordResetTurnstileValidate(string $token): bool
{
    $payload = http_build_query([
        'secret' => appTurnstileSecretKey(),
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
    $endpoint = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    if (function_exists('curl_init')) {
        $curl = curl_init($endpoint);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if (!is_string($body) || $status >= 400) {
            error_log('Verifica Turnstile reset password non riuscita: HTTP ' . $status);
            return false;
        }
    } else {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 10,
        ]]);
        $body = @file_get_contents($endpoint, false, $context);
        if (!is_string($body)) {
            error_log('Verifica Turnstile reset password non riuscita: risposta vuota');
            return false;
        }
    }

    $result = json_decode($body, true);
    return is_array($result) && !empty($result['success']);
}

function passwordResetResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    passwordResetResponse(['ok' => false, 'error' => 'Metodo non consentito'], 405);
}

if (!$connessione || !($pdo instanceof PDO)) {
    passwordResetResponse(['ok' => false, 'error' => 'Servizio temporaneamente non disponibile'], 503);
}

if (appTurnstileEnabled()) {
    $turnstileToken = trim((string) ($_POST['cf-turnstile-response'] ?? ''));
    if ($turnstileToken === '' || !passwordResetTurnstileValidate($turnstileToken)) {
        passwordResetResponse(['ok' => false, 'error' => 'Completa correttamente il controllo di sicurezza'], 403);
    }
}

$email = strtolower(trim((string) ($_POST['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    passwordResetResponse(['ok' => false, 'error' => 'Inserisci un indirizzo email valido'], 400);
}

$genericMessage = 'Se l’indirizzo è registrato, riceverai a breve le istruzioni per reimpostare la password.';
$ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

try {
    $rateLimit = $pdo->prepare(
        'SELECT COUNT(*) FROM password_reset_tokens t JOIN utenti u ON u.cod_fiscale = t.user_cf
         WHERE t.created_at >= (NOW() - INTERVAL 15 MINUTE)
           AND (u.email = ? OR (t.requested_ip IS NOT NULL AND t.requested_ip = ?))'
    );
    $rateLimit->execute([$email, $ip]);
    if ((int) $rateLimit->fetchColumn() >= 3) {
        passwordResetResponse(['ok' => true, 'message' => $genericMessage]);
    }

    $userQuery = $pdo->prepare('SELECT cod_fiscale, email FROM utenti WHERE email = ? AND attivo = 1 LIMIT 1');
    $userQuery->execute([$email]);
    $user = $userQuery->fetch();
    if (!$user) {
        passwordResetResponse(['ok' => true, 'message' => $genericMessage]);
    }

    $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_cf = ? OR expires_at < NOW()')
        ->execute([$user['cod_fiscale']]);

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $insert = $pdo->prepare(
        'INSERT INTO password_reset_tokens (user_cf, token_hash, expires_at, requested_ip)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 60 MINUTE), ?)'
    );
    $insert->execute([$user['cod_fiscale'], $tokenHash, $ip ?: null]);

    $resetUrl = appPublicUrl() . '/reset_password.php?token=' . rawurlencode($token);
    try {
        sendPasswordResetEmail($user['email'], $resetUrl);
    } catch (Throwable $mailError) {
        $pdo->prepare('DELETE FROM password_reset_tokens WHERE token_hash = ?')->execute([$tokenHash]);
        throw $mailError;
    }

    passwordResetResponse(['ok' => true, 'message' => $genericMessage]);
} catch (Throwable $e) {
    error_log('Errore recupero password: ' . $e->getMessage());
    passwordResetResponse(['ok' => false, 'error' => 'Impossibile inviare l’email in questo momento. Riprova più tardi.'], 503);
}

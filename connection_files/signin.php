<?php
header("Content-Type: application/json; charset=utf-8");

require __DIR__ . '/../app_config.php';
require __DIR__ . '/../session_bootstrap.php';
app_session_start();
require "connection.php";

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

const APP_LOGIN_MAX_FAILURES = 5;
const APP_LOGIN_RATE_LIMIT_MINUTES = 15;
const APP_LOGIN_DUMMY_PASSWORD_HASH = '$2y$12$dxNSk/jDu3k988PqNPMP1eZvwCNiFIkYMILJkMz4obuLKK9InLViS';

function appLoginEmailHash(string $email): string
{
    return hash('sha256', strtolower(trim($email)));
}

function appLoginRetryAfterSeconds(PDO $pdo, string $emailHash): int
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) AS failures, MIN(attempted_at) AS first_attempt
         FROM login_attempts
         WHERE email_hash = ?
           AND attempted_at >= (NOW() - INTERVAL ' . APP_LOGIN_RATE_LIMIT_MINUTES . ' MINUTE)'
    );
    $statement->execute([$emailHash]);
    $result = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

    if ((int) ($result['failures'] ?? 0) < APP_LOGIN_MAX_FAILURES) {
        return 0;
    }

    $firstAttempt = strtotime((string) ($result['first_attempt'] ?? ''));
    if ($firstAttempt === false) {
        return APP_LOGIN_RATE_LIMIT_MINUTES * 60;
    }

    return max(1, ($firstAttempt + (APP_LOGIN_RATE_LIMIT_MINUTES * 60)) - time());
}

function appLoginRecordFailure(PDO $pdo, string $emailHash): void
{
    $pdo->prepare('INSERT INTO login_attempts (email_hash) VALUES (?)')->execute([$emailHash]);
    // Mantiene la tabella piccola anche su installazioni molto usate.
    $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
}

function appLoginClearFailures(PDO $pdo, string $emailHash): void
{
    $pdo->prepare('DELETE FROM login_attempts WHERE email_hash = ?')->execute([$emailHash]);
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
    jsonResponse([
        "logged" => false,
        "error_code" => "metodo_non_consentito",
        "error" => "Metodo non consentito",
    ], 405);
}

if (!app_csrf_request_is_valid()) {
    jsonResponse([
        'logged' => false,
        'error_code' => 'richiesta_non_valida',
        'error' => 'Richiesta non valida. Ricarica la pagina e riprova.',
    ], 403);
}

if (!$connessione || !($pdo instanceof PDO)) {
    jsonResponse([
        "logged" => false,
        "error_code" => "db_non_disponibile",
        "error" => "Connessione al database non disponibile",
    ], 500);
}

$email = trim((string) ($_POST['email'] ?? ''));
$emailHash = appLoginEmailHash($email);
$retryAfterSeconds = appLoginRetryAfterSeconds($pdo, $emailHash);
if ($retryAfterSeconds > 0) {
    $retryAfterMinutes = max(1, (int) ceil($retryAfterSeconds / 60));
    jsonResponse([
        'logged' => false,
        'error_code' => 'troppi_tentativi',
        'error' => "Per sicurezza attendi circa {$retryAfterMinutes} minuti prima di riprovare.",
    ], 429);
}

function appTurnstileValidateToken(string $token): array
{
    $secret = appTurnstileSecretKey();
    if ($secret === '') {
        return [
            'success' => false,
            'error-codes' => ['missing-secret'],
        ];
    }

    $payload = http_build_query([
        'secret' => $secret,
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    $endpoint = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($body) || $body === '') {
            throw new RuntimeException('Verifica Turnstile non riuscita: ' . ($curlError !== '' ? $curlError : 'risposta vuota'));
        }

        if ($statusCode >= 400) {
            throw new RuntimeException('Verifica Turnstile non riuscita: HTTP ' . $statusCode);
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $payload,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($endpoint, false, $context);
        if (!is_string($body) || $body === '') {
            throw new RuntimeException('Verifica Turnstile non riuscita: risposta vuota');
        }
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Verifica Turnstile non riuscita: risposta non valida');
    }

    return $decoded;
}

if (appTurnstileEnabled()) {
    $turnstileToken = trim((string) ($_POST['cf-turnstile-response'] ?? ''));
    if ($turnstileToken === '') {
        jsonResponse([
            'logged' => false,
            'error_code' => 'captcha_mancante',
            'error' => 'Completa il controllo di sicurezza',
        ], 400);
    }

    $turnstileResult = appTurnstileValidateToken($turnstileToken);
    if (empty($turnstileResult['success'])) {
        jsonResponse([
            'logged' => false,
            'error_code' => 'captcha_non_valido',
            'error' => 'Verifica di sicurezza non valida, riprova',
            'details' => $turnstileResult['error-codes'] ?? [],
        ], 403);
    }
}

try {
    $stmt = $pdo->prepare("SELECT * FROM utenti WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    // Usiamo comunque un hash bcrypt quando l'email non esiste, così il
    // tempo di risposta non rivela se un indirizzo è registrato.
    $passwordMatches = password_verify(
        (string) ($_POST['password'] ?? ''),
        (string) ($user['password'] ?? APP_LOGIN_DUMMY_PASSWORD_HASH)
    );

    if ($user && $passwordMatches && (int) ($user['attivo'] ?? 1) === 1) {
        appLoginClearFailures($pdo, $emailHash);
        session_regenerate_id(true);
        $_SESSION["user"] = [
            "cf" => $user["cod_fiscale"],
            "nome" => $user["nome"],
            "cognome" => $user["cognome"],
            "avatar" => $user["avatar"] ?? "default",
            "capo" => $user["capo"] ?? 0,
            "reparto"=> $user["reparto"],
            "session_version" => (int) ($user["session_version"] ?? 0),
        ];
        echo json_encode([
            "logged" => true,
            "nome" => $_SESSION["user"]["nome"],
            "cf" => $_SESSION["user"]["cf"],
            "avatar" => $_SESSION["user"]["avatar"],
            "cognome" => $_SESSION["user"]["cognome"],
            "capo" => $_SESSION["user"]["capo"],
            "reparto"=> $_SESSION["user"]["reparto"]
        ]);
        app_session_touch_user($pdo, (string) $user["cod_fiscale"], true);
    } else {
        appLoginRecordFailure($pdo, $emailHash);
        jsonResponse([
            'logged' => false,
            'error_code' => 'credenziali_non_valide',
            'error' => 'Email o password non corretti',
        ], 401);
    }
} catch (Throwable $e) {
    error_log('Errore login: ' . $e->getMessage());
    jsonResponse([
        "logged" => false,
        "error_code" => "errore_login_" . $e->getCode(),
        "error" => "Errore durante l'accesso al database",
    ], 500);
}

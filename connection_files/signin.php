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
    $stmt->execute([$_POST["email"] ?? ""]);
    $user = $stmt->fetch();
    if ($user && password_verify($_POST["password"] ?? "", $user["password"])) {
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
        echo json_encode([
            "logged" => false,
        ]);
    }
} catch (Throwable $e) {
    error_log('Errore login: ' . $e->getMessage());
    jsonResponse([
        "logged" => false,
        "error_code" => "errore_login_" . $e->getCode(),
        "error" => "Errore durante l'accesso al database",
    ], 500);
}

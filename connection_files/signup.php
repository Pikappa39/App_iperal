<?php
header("Content-Type: application/json; charset=utf-8");

require __DIR__ . '/../session_bootstrap.php';
app_session_start();
require __DIR__ . '/connection.php';

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
    jsonResponse([
        "ok" => false,
        "error" => "Metodo non consentito",
    ], 405);
}

if (!appSelfRegistrationEnabled()) {
    jsonResponse([
        'ok' => false,
        'error' => 'La registrazione autonoma non è disponibile. Contatta il tuo responsabile.',
    ], 403);
}

if (!app_csrf_request_is_valid()) {
    jsonResponse(['ok' => false, 'error' => 'Richiesta non valida. Ricarica la pagina e riprova.'], 403);
}

if (!$connessione || !isset($pdo)) {
    jsonResponse([
        "ok" => false,
        "error" => "Connessione al database non disponibile",
    ], 500);
}

$nome = trim((string) ($_POST["nome"] ?? ""));
$cognome = trim((string) ($_POST["cognome"] ?? ""));
$cf = strtoupper(trim((string) ($_POST["cf"] ?? "")));
$email = strtolower(trim((string) ($_POST["email"] ?? "")));
$password = (string) ($_POST["password"] ?? "");
$badge = trim((string) ($_POST["badge"] ?? ""));
$reparto = trim((string) ($_POST["reparto"] ?? ""));

if ($nome === "" || $cognome === "" || $cf === "" || $email === "" || $password === "" || $badge === "" || $reparto === "") {
    jsonResponse([
        "ok" => false,
        "error" => "Compila tutti i campi obbligatori",
    ], 400);
}

if (!appIsValidDepartment($reparto)) {
    jsonResponse([
        "ok" => false,
        "error" => "Reparto non valido",
    ], 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse([
        "ok" => false,
        "error" => "Email non valida",
    ], 400);
}

if (strlen($cf) < 11 || strlen($cf) > 16) {
    jsonResponse([
        "ok" => false,
        "error" => "Codice fiscale non valido",
    ], 400);
}

if (strlen($password) < 12) {
    jsonResponse([
        'ok' => false,
        'error' => 'La password deve contenere almeno 12 caratteri',
    ], 400);
}

try {
    $checks = [
        ["email", $email, "Email già registrata"],
        ["badge", $badge, "Badge già registrato"],
        ["cod_fiscale", $cf, "Codice fiscale già registrato"],
    ];

    foreach ($checks as [$field, $value, $message]) {
        $stmt = $pdo->prepare("SELECT 1 FROM utenti WHERE {$field} = ? LIMIT 1");
        $stmt->execute([$value]);
        if ($stmt->fetchColumn()) {
            jsonResponse([
                "ok" => false,
                "error" => $message,
            ], 409);
        }
    }

    $stmt = $pdo->prepare(
        "INSERT INTO utenti (cod_fiscale, nome, cognome, badge, password, email, avatar, capo, reparto)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $stmt->execute([
        $cf,
        $nome,
        $cognome,
        $badge,
        password_hash($password, PASSWORD_DEFAULT),
        $email,
        "default",
        0,
        $reparto,
    ]);

    jsonResponse([
        "ok" => true,
        "registered" => true,
    ]);
} catch (PDOException $e) {
    jsonResponse([
        "ok" => false,
        "error" => "Errore durante la registrazione",
    ], 500);
}

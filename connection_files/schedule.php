<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/../session_bootstrap.php';
app_session_start();

function scheduleResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user']['cf'])) {
    scheduleResponse(['ok' => false, 'error' => 'Accesso richiesto'], 401);
}

$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT);
$week = filter_input(INPUT_GET, 'week', FILTER_VALIDATE_INT);
$department = trim((string) ($_SESSION['user']['reparto'] ?? ''));

if ($year === false || $year === null || $year < 2020 || $year > 2100 || $week === false || $week === null || $week < 1 || $week > 53) {
    scheduleResponse(['ok' => false, 'error' => 'Settimana non valida'], 400);
}
if (!appIsValidDepartment($department)) {
    scheduleResponse(['ok' => false, 'error' => 'Reparto non valido'], 403);
}

$directory = __DIR__ . '/../turni_json';
$file = $directory . DIRECTORY_SEPARATOR . $year . '-' . $week . '-' . $department . '.json';

// Compatibilità temporanea con gli orari caricati prima dell'introduzione
// dell'anno nel nome file: non li riutilizziamo mai per anni diversi.
if (!is_file($file) && $year === (int) (new DateTimeImmutable('now'))->format('o')) {
    $legacyFile = $directory . DIRECTORY_SEPARATOR . $week . '-' . $department . '.json';
    if (is_file($legacyFile)) {
        $file = $legacyFile;
    }
}

if (!is_file($file)) {
    scheduleResponse(['ok' => true, 'rows' => []]);
}

$raw = file_get_contents($file);
$rows = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($rows)) {
    error_log('File turni non valido: ' . basename($file));
    scheduleResponse(['ok' => false, 'error' => 'Orario temporaneamente non disponibile'], 500);
}

scheduleResponse(['ok' => true, 'rows' => $rows]);

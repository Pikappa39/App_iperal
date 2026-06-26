<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../session_bootstrap.php';
app_session_start();
require __DIR__ . '/connection.php';

function scheduleChangesResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user']['cf'])) {
    scheduleChangesResponse(['ok' => false, 'error' => 'Accesso richiesto'], 401);
}

$userCf = (string) $_SESSION['user']['cf'];
$batchId = trim((string) ($_GET['batch'] ?? ''));
app_session_write_close_if_active();

if ($batchId !== '' && !preg_match('/^[a-f0-9]{32}$/', $batchId)) {
    scheduleChangesResponse(['ok' => false, 'error' => 'Gruppo modifiche non valido'], 400);
}

$query = '
    SELECT c.id, c.batch_id, c.iso_year, c.iso_week, c.schedule_date, c.day_name,
           c.previous_shift, c.new_shift, c.source_file, c.read_at, c.created_at,
           TRIM(CONCAT(COALESCE(u.nome, \'\'), \' \', COALESCE(u.cognome, \'\'))) AS changed_by_name
    FROM schedule_change_log c
    LEFT JOIN utenti u ON BINARY u.cod_fiscale = BINARY c.changed_by_cf
    WHERE BINARY c.user_cf = BINARY ?';
$params = [$userCf];

if ($batchId !== '') {
    $query .= ' AND c.batch_id = ?';
    $params[] = $batchId;
}

$query .= ' ORDER BY c.schedule_date DESC, c.id DESC LIMIT 100';

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $changes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($changes !== []) {
        $markRead = 'UPDATE schedule_change_log SET read_at = CURRENT_TIMESTAMP WHERE BINARY user_cf = BINARY ? AND read_at IS NULL';
        $markReadParams = [$userCf];
        if ($batchId !== '') {
            $markRead .= ' AND batch_id = ?';
            $markReadParams[] = $batchId;
        }
        $pdo->prepare($markRead)->execute($markReadParams);
    }

    scheduleChangesResponse([
        'ok' => true,
        'changes' => $changes,
    ]);
} catch (Throwable $e) {
    error_log('Storico modifiche non disponibile: ' . $e->getMessage());
    scheduleChangesResponse([
        'ok' => false,
        'error' => 'Impossibile caricare lo storico modifiche',
    ], 500);
}

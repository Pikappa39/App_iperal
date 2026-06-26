<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../session_bootstrap.php';
app_session_start();
require __DIR__ . '/connection.php';
require __DIR__ . '/push_lib.php';

function communicationResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user']['cf']) || !$connessione || !($pdo instanceof PDO)) {
    communicationResponse(['ok' => false, 'error' => 'Accesso richiesto'], 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !app_csrf_request_is_valid()) {
    communicationResponse(['ok' => false, 'error' => 'Richiesta non valida. Ricarica la pagina e riprova.'], 403);
}

$viewer = $_SESSION['user'];
$viewerCf = (string) $viewer['cf'];
$viewerRole = (int) ($viewer['capo'] ?? 0);
$viewerDepartment = (string) ($viewer['reparto'] ?? '');
$isManager = in_array($viewerRole, [1, 2, 3], true);
app_session_write_close_if_active();

function communicationCanManageUser(PDO $pdo, string $userCf, int $viewerRole, string $viewerDepartment): bool
{
    if ($viewerRole === 3) {
        return true;
    }
    if (!in_array($viewerRole, [1, 2], true) || $viewerDepartment === '') {
        return false;
    }

    $stmt = $pdo->prepare('SELECT reparto FROM utenti WHERE cod_fiscale = ? AND attivo = 1 LIMIT 1');
    $stmt->execute([$userCf]);
    return (string) $stmt->fetchColumn() === $viewerDepartment;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $view = (string) ($_GET['view'] ?? 'inbox');
        if ($view === 'inbox') {
            $stmt = $pdo->prepare(
                "SELECT c.id, c.title, c.message, c.priority, c.created_at, r.read_at, r.acknowledged_at,
                        TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS author_name
                 FROM communication_recipients r
                 JOIN communications c ON c.id = r.communication_id
                 LEFT JOIN utenti u ON u.cod_fiscale = c.author_cf
                 WHERE r.recipient_cf = ?
                 ORDER BY (r.acknowledged_at IS NULL) DESC, c.created_at DESC
                 LIMIT 100"
            );
            $stmt->execute([$viewerCf]);
            $communications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $pdo->prepare('UPDATE communication_recipients SET read_at = NOW() WHERE recipient_cf = ? AND read_at IS NULL')
                ->execute([$viewerCf]);
            communicationResponse(['ok' => true, 'communications' => $communications]);
        }

        if (!$isManager) {
            communicationResponse(['ok' => false, 'error' => 'Accesso negato'], 403);
        }

        if ($view === 'users') {
            $query = 'SELECT cod_fiscale, nome, cognome, reparto FROM utenti WHERE attivo = 1';
            $params = [];
            if (in_array($viewerRole, [1, 2], true)) {
                $query .= ' AND reparto = ?';
                $params[] = $viewerDepartment;
            }
            $query .= ' ORDER BY cognome, nome';
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            communicationResponse(['ok' => true, 'users' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }

        if ($view === 'sent') {
            $query =
                "SELECT c.id, c.title, c.message, c.priority, c.created_at,
                        COUNT(r.recipient_cf) AS recipients,
                        SUM(r.read_at IS NOT NULL) AS read_count,
                        SUM(r.acknowledged_at IS NOT NULL) AS acknowledged_count
                 FROM communications c
                 JOIN communication_recipients r ON r.communication_id = c.id";
            $params = [];
            if (in_array($viewerRole, [1, 2], true)) {
                $query .= ' WHERE c.author_cf = ?';
                $params[] = $viewerCf;
            }
            $query .= ' GROUP BY c.id ORDER BY c.created_at DESC LIMIT 100';
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            communicationResponse(['ok' => true, 'communications' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }

        communicationResponse(['ok' => false, 'error' => 'Vista non valida'], 400);
    }

    if ($method !== 'POST') {
        communicationResponse(['ok' => false, 'error' => 'Metodo non consentito'], 405);
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'acknowledge') {
        $communicationId = (int) ($_POST['communication_id'] ?? 0);
        if ($communicationId < 1) {
            communicationResponse(['ok' => false, 'error' => 'Comunicazione non valida'], 400);
        }
        $stmt = $pdo->prepare(
            'UPDATE communication_recipients SET read_at = COALESCE(read_at, NOW()), acknowledged_at = NOW()
             WHERE communication_id = ? AND recipient_cf = ?'
        );
        $stmt->execute([$communicationId, $viewerCf]);
        communicationResponse(['ok' => true, 'acknowledged' => $stmt->rowCount() > 0]);
    }

    if ($action !== 'send' || !$isManager) {
        communicationResponse(['ok' => false, 'error' => 'Accesso negato'], 403);
    }

    $title = trim((string) ($_POST['title'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));
    $priority = (string) ($_POST['priority'] ?? 'normal');
    $targetType = (string) ($_POST['target_type'] ?? 'department');
    $targetUserCf = trim((string) ($_POST['recipient_cf'] ?? ''));
    $targetDepartment = trim((string) ($_POST['department'] ?? ''));

    if ($title === '' || mb_strlen($title) > 150 || $message === '' || mb_strlen($message) > 3000) {
        communicationResponse(['ok' => false, 'error' => 'Titolo o testo non validi'], 400);
    }
    if (!in_array($priority, ['normal', 'important'], true)) {
        communicationResponse(['ok' => false, 'error' => 'Priorità non valida'], 400);
    }

    if ($targetType === 'user') {
        if ($targetUserCf === '' || !communicationCanManageUser($pdo, $targetUserCf, $viewerRole, $viewerDepartment)) {
            communicationResponse(['ok' => false, 'error' => 'Destinatario non autorizzato'], 403);
        }
        $recipients = [$targetUserCf];
    } elseif ($targetType === 'department') {
        if (in_array($viewerRole, [1, 2], true)) {
            $targetDepartment = $viewerDepartment;
        }
        if (!appIsValidDepartment($targetDepartment)) {
            communicationResponse(['ok' => false, 'error' => 'Reparto non valido'], 400);
        }
        $stmt = $pdo->prepare('SELECT cod_fiscale FROM utenti WHERE reparto = ? AND attivo = 1');
        $stmt->execute([$targetDepartment]);
        $recipients = array_map(static fn (array $row): string => (string) $row['cod_fiscale'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    } else {
        communicationResponse(['ok' => false, 'error' => 'Destinazione non valida'], 400);
    }

    if ($recipients === []) {
        communicationResponse(['ok' => false, 'error' => 'Nessun destinatario trovato'], 400);
    }

    $pdo->beginTransaction();
    $insertCommunication = $pdo->prepare('INSERT INTO communications (author_cf, title, message, priority) VALUES (?, ?, ?, ?)');
    $insertCommunication->execute([$viewerCf, $title, $message, $priority]);
    $communicationId = (int) $pdo->lastInsertId();
    $insertRecipient = $pdo->prepare('INSERT INTO communication_recipients (communication_id, recipient_cf) VALUES (?, ?)');
    foreach ($recipients as $recipientCf) {
        $insertRecipient->execute([$communicationId, $recipientCf]);
    }
    $pdo->commit();

    foreach ($recipients as $recipientCf) {
        try {
            appPushSendPayload($pdo, [
                'title' => $priority === 'important' ? 'Comunicazione importante' : 'Nuova comunicazione',
                'body' => $title,
                'url' => './index.php?communications=1',
                'recipient_cf' => $recipientCf,
            ], $recipientCf);
        } catch (Throwable $pushError) {
            error_log('Push comunicazione non inviata: ' . $pushError->getMessage());
        }
    }

    communicationResponse(['ok' => true, 'communication_id' => $communicationId, 'recipients' => count($recipients)]);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Errore comunicazioni: ' . $e->getMessage());
    communicationResponse(['ok' => false, 'error' => 'Operazione non riuscita'], 500);
}

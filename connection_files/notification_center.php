<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

require __DIR__ . '/../session_bootstrap.php';
app_session_start();
require __DIR__ . '/connection.php';

function notificationCenterResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user']['cf']) || !$connessione || !($pdo instanceof PDO)) {
    notificationCenterResponse(['ok' => false, 'error' => 'Accesso richiesto'], 401);
}

$viewerCf = (string) $_SESSION['user']['cf'];
$viewerRole = (int) ($_SESSION['user']['capo'] ?? 0);
$viewerDepartment = trim((string) ($_SESSION['user']['reparto'] ?? ''));
app_session_write_close_if_active();

try {
    $items = [];

    $scheduleStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM schedule_change_log
         WHERE BINARY user_cf = BINARY ?
           AND read_at IS NULL'
    );
    $scheduleStmt->execute([$viewerCf]);
    $scheduleCount = (int) $scheduleStmt->fetchColumn();
    if ($scheduleCount > 0) {
        $items[] = [
            'type' => 'schedule_changes',
            'title' => 'Aggiornamenti orari',
            'body' => $scheduleCount === 1
                ? 'Hai 1 modifica orario da vedere.'
                : 'Hai ' . $scheduleCount . ' modifiche orario da vedere.',
            'count' => $scheduleCount,
            'url' => 'index.php?changes=1',
        ];
    }

    $communicationStmt = $pdo->prepare(
        'SELECT
            SUM(read_at IS NULL) AS unread_count,
            SUM(acknowledged_at IS NULL) AS pending_count
         FROM communication_recipients
         WHERE BINARY recipient_cf = BINARY ?'
    );
    $communicationStmt->execute([$viewerCf]);
    $communicationCounts = $communicationStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $unreadCommunications = (int) ($communicationCounts['unread_count'] ?? 0);
    $pendingCommunications = (int) ($communicationCounts['pending_count'] ?? 0);
    $communicationCount = max($unreadCommunications, $pendingCommunications);
    if ($communicationCount > 0) {
        $items[] = [
            'type' => 'communications',
            'title' => 'Comunicazioni',
            'body' => $communicationCount === 1
                ? 'Hai 1 comunicazione da aprire o confermare.'
                : 'Hai ' . $communicationCount . ' comunicazioni da aprire o confermare.',
            'count' => $communicationCount,
            'url' => 'index.php?communications=1',
        ];
    }

    if (in_array($viewerRole, [1, 3], true)) {
        $query = "SELECT COUNT(*)
                  FROM schedule_adjustment_requests
                  WHERE status IN ('pending', 'review')";
        $params = [];
        if ($viewerRole !== 3) {
            $query .= ' AND reparto = ?';
            $params[] = $viewerDepartment;
        }
        $extraQuery = "SELECT COUNT(*)
                       FROM extra_hour_requests
                       WHERE request_kind = 'department'
                         AND status = 'pending'";
        $extraParams = [];
        if ($viewerRole !== 3) {
            $extraQuery .= " AND (
                (origin_reparto = ? AND origin_status = 'pending')
                OR (target_reparto = ? AND target_status = 'pending')
            )";
            $extraParams[] = $viewerDepartment;
            $extraParams[] = $viewerDepartment;
        }
        $extraStmt = $pdo->prepare($extraQuery);
        $extraStmt->execute($extraParams);
        $extraCount = (int) $extraStmt->fetchColumn();

        $adjustmentStmt = $pdo->prepare($query);
        $adjustmentStmt->execute($params);
        $adjustmentCount = (int) $adjustmentStmt->fetchColumn();
        $totalAdjustmentCount = $adjustmentCount + $extraCount;
        if ($totalAdjustmentCount > 0) {
            $items[] = [
                'type' => 'adjustments_manage',
                'title' => 'Richieste ore',
                'body' => $totalAdjustmentCount === 1
                    ? 'Hai 1 richiesta da approvare.'
                    : 'Hai ' . $totalAdjustmentCount . ' richieste da approvare.',
                'count' => $totalAdjustmentCount,
                'url' => 'index.php?adjustments=1',
            ];
        }
    } else {
        $reviewStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM schedule_adjustment_requests
             WHERE BINARY user_cf = BINARY ?
               AND status = 'review'"
        );
        $reviewStmt->execute([$viewerCf]);
        $reviewCount = (int) $reviewStmt->fetchColumn();
        if ($reviewCount > 0) {
            $items[] = [
                'type' => 'adjustments_review',
                'title' => 'Richieste ore',
                'body' => $reviewCount === 1
                    ? 'Una tua richiesta deve essere ricontrollata.'
                    : $reviewCount . ' tue richieste devono essere ricontrollate.',
                'count' => $reviewCount,
                'url' => 'index.php?adjustments=1',
            ];
        }
    }

    $total = array_sum(array_map(static fn (array $item): int => (int) ($item['count'] ?? 0), $items));
    notificationCenterResponse([
        'ok' => true,
        'total' => $total,
        'items' => $items,
    ]);
} catch (Throwable $error) {
    error_log('Centro notifiche non disponibile: ' . $error->getMessage());
    notificationCenterResponse(['ok' => false, 'error' => 'Centro notifiche non disponibile'], 500);
}

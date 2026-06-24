<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

require __DIR__ . '/../session_bootstrap.php';
app_session_start();
require __DIR__ . '/connection.php';
require __DIR__ . '/push_lib.php';
require __DIR__ . '/schedule_adjustment_lib.php';

function scheduleAdjustmentResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user']['cf']) || !$connessione || !($pdo instanceof PDO)) {
    scheduleAdjustmentResponse(['ok' => false, 'error' => 'Accesso richiesto'], 401);
}

$viewer = $_SESSION['user'];
$viewerCf = (string) $viewer['cf'];
$viewerRole = (int) ($viewer['capo'] ?? 0);
$viewerDepartment = trim((string) ($viewer['reparto'] ?? ''));
$canApprove = in_array($viewerRole, [1, 3], true);

function scheduleAdjustmentRequestData(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'user_cf' => (string) $row['user_cf'],
        'user_name' => trim((string) ($row['user_name'] ?? '')),
        'reparto' => (string) $row['reparto'],
        'schedule_date' => (string) $row['schedule_date'],
        'day_name' => (string) $row['day_name'],
        'original_shift' => (string) $row['original_shift'],
        'current_original_shift' => (string) $row['current_original_shift'],
        'requested_shift' => (string) $row['requested_shift'],
        'request_note' => (string) ($row['request_note'] ?? ''),
        'status' => (string) $row['status'],
        'review_reason' => (string) ($row['review_reason'] ?? ''),
        'decision_note' => (string) ($row['decision_note'] ?? ''),
        'decided_by_name' => trim((string) ($row['decided_by_name'] ?? '')),
        'decided_at' => $row['decided_at'] ?? null,
        'created_at' => (string) $row['created_at'],
    ];
}

function scheduleAdjustmentLoad(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(
        "SELECT r.*, TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS user_name,
                TRIM(CONCAT(COALESCE(d.nome, ''), ' ', COALESCE(d.cognome, ''))) AS decided_by_name
         FROM schedule_adjustment_requests r
         LEFT JOIN utenti u ON BINARY u.cod_fiscale = BINARY r.user_cf
         LEFT JOIN utenti d ON BINARY d.cod_fiscale = BINARY r.decided_by_cf
         WHERE r.id = ?
         LIMIT 1"
    );
    $statement->execute([$id]);
    $request = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($request) ? $request : null;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        $view = (string) ($_GET['view'] ?? 'day');
        if ($view === 'day') {
            $date = trim((string) ($_GET['date'] ?? ''));
            $dateInfo = appScheduleAdjustmentDateInfo($date);
            if ($dateInfo === null) {
                scheduleAdjustmentResponse(['ok' => false, 'error' => 'Data non valida'], 400);
            }

            $statement = $pdo->prepare(
                "SELECT r.*, TRIM(CONCAT(COALESCE(d.nome, ''), ' ', COALESCE(d.cognome, ''))) AS decided_by_name
                 FROM schedule_adjustment_requests r
                 LEFT JOIN utenti d ON BINARY d.cod_fiscale = BINARY r.decided_by_cf
                 WHERE r.user_cf = ? AND r.schedule_date = ?
                 ORDER BY r.created_at DESC, r.id DESC"
            );
            $statement->execute([$viewerCf, $date]);
            $requests = array_map('scheduleAdjustmentRequestData', $statement->fetchAll(PDO::FETCH_ASSOC));
            scheduleAdjustmentResponse([
                'ok' => true,
                'requests' => $requests,
                'can_create' => true,
            ]);
        }

        if ($view === 'mine') {
            $statement = $pdo->prepare(
                "SELECT r.*, TRIM(CONCAT(COALESCE(d.nome, ''), ' ', COALESCE(d.cognome, ''))) AS decided_by_name
                 FROM schedule_adjustment_requests r
                 LEFT JOIN utenti d ON BINARY d.cod_fiscale = BINARY r.decided_by_cf
                 WHERE r.user_cf = ?
                 ORDER BY FIELD(r.status, 'pending', 'review', 'approved', 'rejected'), r.schedule_date DESC, r.created_at DESC
                 LIMIT 100"
            );
            $statement->execute([$viewerCf]);
            scheduleAdjustmentResponse([
                'ok' => true,
                'requests' => array_map('scheduleAdjustmentRequestData', $statement->fetchAll(PDO::FETCH_ASSOC)),
            ]);
        }

        if ($view !== 'manage' || !$canApprove) {
            scheduleAdjustmentResponse(['ok' => false, 'error' => 'Accesso negato'], 403);
        }

        $query =
            "SELECT r.*, TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS user_name,
                    TRIM(CONCAT(COALESCE(d.nome, ''), ' ', COALESCE(d.cognome, ''))) AS decided_by_name
             FROM schedule_adjustment_requests r
             LEFT JOIN utenti u ON BINARY u.cod_fiscale = BINARY r.user_cf
             LEFT JOIN utenti d ON BINARY d.cod_fiscale = BINARY r.decided_by_cf";
        $params = [];
        if ($viewerRole !== 3) {
            $query .= ' WHERE r.reparto = ?';
            $params[] = $viewerDepartment;
        }
        $query .= " ORDER BY FIELD(r.status, 'pending', 'review', 'approved', 'rejected'), r.schedule_date DESC, r.created_at DESC LIMIT 150";
        $statement = $pdo->prepare($query);
        $statement->execute($params);
        scheduleAdjustmentResponse([
            'ok' => true,
            'requests' => array_map('scheduleAdjustmentRequestData', $statement->fetchAll(PDO::FETCH_ASSOC)),
        ]);
    }

    if ($method !== 'POST' || !app_csrf_request_is_valid()) {
        scheduleAdjustmentResponse(['ok' => false, 'error' => 'Richiesta non valida. Ricarica la pagina e riprova.'], 403);
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        if (!appIsValidDepartment($viewerDepartment)) {
            scheduleAdjustmentResponse(['ok' => false, 'error' => 'Reparto utente non valido'], 403);
        }

        $date = trim((string) ($_POST['date'] ?? ''));
        $dateInfo = appScheduleAdjustmentDateInfo($date);
        if ($dateInfo === null) {
            scheduleAdjustmentResponse(['ok' => false, 'error' => 'Data non valida'], 422);
        }

        $requested = appScheduleAdjustmentParseShift((string) ($_POST['requested_shift'] ?? ''));
        if ($requested === null) {
            scheduleAdjustmentResponse(['ok' => false, 'error' => 'Inserisci un orario valido, ad esempio 05:00-10:00 / 10:30-12:00'], 422);
        }
        $note = trim((string) ($_POST['request_note'] ?? ''));
        if (mb_strlen($note) > 1000) {
            scheduleAdjustmentResponse(['ok' => false, 'error' => 'La nota può contenere al massimo 1000 caratteri'], 422);
        }

        try {
            $pdo->beginTransaction();
            appScheduleAdjustmentLockWeek($pdo, $viewerDepartment, $dateInfo['year'], $dateInfo['week']);
            appScheduleAdjustmentLockDay($pdo, $viewerCf, $date);

            $originalShift = appScheduleAdjustmentFindOriginalShift(
                $pdo,
                $viewerDepartment,
                $dateInfo['year'],
                $dateInfo['week'],
                $viewerCf,
                $dateInfo['day']
            );
            if ($originalShift === null) {
                throw new DomainException('Non è disponibile un orario caricato per questo giorno');
            }

            $existing = $pdo->prepare(
                "SELECT status FROM schedule_adjustment_requests
                 WHERE user_cf = ? AND schedule_date = ?
                 ORDER BY created_at DESC, id DESC LIMIT 1 FOR UPDATE"
            );
            $existing->execute([$viewerCf, $date]);
            $existingStatus = $existing->fetchColumn();
            if (in_array($existingStatus, ['pending', 'review'], true)) {
                throw new DomainException('Hai già una segnalazione in attesa per questo giorno', 409);
            }
            if ($existingStatus === 'approved') {
                throw new DomainException('Per questo giorno esiste già un orario effettivo approvato', 409);
            }

            $insert = $pdo->prepare(
                'INSERT INTO schedule_adjustment_requests
                    (user_cf, reparto, iso_year, iso_week, schedule_date, day_name, base_upload_id, original_shift, current_original_shift, requested_shift, request_note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $viewerCf,
                $viewerDepartment,
                $dateInfo['year'],
                $dateInfo['week'],
                $date,
                $dateInfo['day'],
                appScheduleAdjustmentLatestUploadVersion($pdo, $viewerDepartment, $dateInfo['year'], $dateInfo['week']),
                $originalShift,
                $originalShift,
                $requested['shift'],
                $note !== '' ? $note : null,
            ]);
            $requestId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (DomainException $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            scheduleAdjustmentResponse(['ok' => false, 'error' => $error->getMessage()], $error->getCode() === 409 ? 409 : 422);
        }
        $request = scheduleAdjustmentLoad($pdo, $requestId);

        foreach (appScheduleAdjustmentManagerRecipients($pdo, $viewerDepartment) as $recipientCf) {
            try {
                appPushSendPayload($pdo, [
                    'title' => 'Nuova segnalazione ore',
                    'body' => 'Un addetto ha segnalato una variazione di orario da approvare.',
                    'url' => './index.php',
                    'recipient_cf' => $recipientCf,
                ], $recipientCf);
            } catch (Throwable $pushError) {
                error_log('Push richiesta ore non inviata: ' . $pushError->getMessage());
            }
        }

        scheduleAdjustmentResponse(['ok' => true, 'request' => scheduleAdjustmentRequestData($request ?: [])]);
    }

    if (!in_array($action, ['approve', 'reject'], true) || !$canApprove) {
        scheduleAdjustmentResponse(['ok' => false, 'error' => 'Operazione non consentita'], 403);
    }

    $requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
    $request = $requestId ? scheduleAdjustmentLoad($pdo, $requestId) : null;
    if ($request === null || !appScheduleAdjustmentCanManageDepartment($viewerRole, $viewerDepartment, (string) $request['reparto'])) {
        scheduleAdjustmentResponse(['ok' => false, 'error' => 'Segnalazione non trovata o non autorizzata'], 404);
    }
    if (hash_equals((string) $request['user_cf'], $viewerCf)) {
        scheduleAdjustmentResponse(['ok' => false, 'error' => 'Non puoi approvare una tua segnalazione'], 403);
    }
    if (!in_array((string) $request['status'], ['pending', 'review'], true)) {
        scheduleAdjustmentResponse(['ok' => false, 'error' => 'Questa segnalazione è già stata gestita'], 409);
    }

    $decisionNote = trim((string) ($_POST['decision_note'] ?? ''));
    if (mb_strlen($decisionNote) > 1000) {
        scheduleAdjustmentResponse(['ok' => false, 'error' => 'La nota decisionale può contenere al massimo 1000 caratteri'], 422);
    }
    $expectedCurrentShift = trim((string) ($_POST['expected_current_original_shift'] ?? ''));
    if ($expectedCurrentShift !== (string) $request['current_original_shift']) {
        scheduleAdjustmentResponse(['ok' => false, 'error' => 'Il turno previsto è cambiato. Ricarica la richiesta e riesaminala.'], 409);
    }
    $status = $action === 'approve' ? 'approved' : 'rejected';
    $pdo->beginTransaction();
    $update = $pdo->prepare(
        "UPDATE schedule_adjustment_requests
         SET status = ?, decision_note = ?, decided_by_cf = ?, decided_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
         WHERE id = ?
           AND status IN ('pending', 'review')
           AND current_original_shift = ?"
    );
    $update->execute([
        $status,
        $decisionNote !== '' ? $decisionNote : null,
        $viewerCf,
        (int) $request['id'],
        $expectedCurrentShift,
    ]);
    if ($update->rowCount() !== 1) {
        $pdo->rollBack();
        scheduleAdjustmentResponse(['ok' => false, 'error' => 'Questa segnalazione è già stata gestita da un altro responsabile'], 409);
    }
    $pdo->commit();
    $request = scheduleAdjustmentLoad($pdo, (int) $request['id']);

    try {
        appPushSendPayload($pdo, [
            'title' => $status === 'approved' ? 'Variazione ore approvata' : 'Variazione ore rifiutata',
            'body' => $status === 'approved'
                ? 'Il tuo orario effettivo è stato approvato.'
                : 'Il capo ha rifiutato la tua segnalazione ore.',
            'url' => './index.php',
            'recipient_cf' => (string) $request['user_cf'],
        ], (string) $request['user_cf']);
    } catch (Throwable $pushError) {
        error_log('Push esito richiesta ore non inviata: ' . $pushError->getMessage());
    }

    scheduleAdjustmentResponse(['ok' => true, 'request' => scheduleAdjustmentRequestData($request ?: [])]);
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Errore richieste variazione orario: ' . $error->getMessage());
    scheduleAdjustmentResponse(['ok' => false, 'error' => 'Impossibile completare l’operazione. Riprova più tardi.'], 500);
}

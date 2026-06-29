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
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST' || app_csrf_request_is_valid()) {
    app_session_write_close_if_active();
}

function scheduleAdjustmentRequestData(array $row): array
{
    return [
        'kind' => 'schedule_adjustment',
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

function scheduleAdjustmentStatusRank(string $status): int
{
    return [
        'pending' => 0,
        'review' => 1,
        'approved' => 2,
        'recorded' => 2,
        'rejected' => 3,
    ][$status] ?? 4;
}

function scheduleAdjustmentSortUnified(array $requests): array
{
    usort($requests, static function (array $left, array $right): int {
        $statusCompare = scheduleAdjustmentStatusRank((string) ($left['status'] ?? ''))
            <=> scheduleAdjustmentStatusRank((string) ($right['status'] ?? ''));
        if ($statusCompare !== 0) {
            return $statusCompare;
        }

        $dateCompare = strcmp((string) ($right['schedule_date'] ?? ''), (string) ($left['schedule_date'] ?? ''));
        if ($dateCompare !== 0) {
            return $dateCompare;
        }

        return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
    });

    return $requests;
}

function scheduleExtraHoursDurationLabel(int $minutes): string
{
    $hours = intdiv($minutes, 60);
    $remaining = $minutes % 60;
    if ($hours > 0 && $remaining > 0) {
        return $hours . 'h ' . $remaining . 'm';
    }
    if ($hours > 0) {
        return $hours . 'h';
    }
    return $remaining . 'm';
}

function scheduleExtraHoursCanDecideSide(array $row, int $viewerRole, string $viewerDepartment, string $side): bool
{
    if (($row['request_kind'] ?? '') !== 'department' || ($row['status'] ?? '') !== 'pending') {
        return false;
    }

    $sideStatus = (string) ($row[$side . '_status'] ?? '');
    if ($sideStatus !== 'pending') {
        return false;
    }

    if ($viewerRole === 3) {
        return true;
    }

    $departmentField = $side === 'origin' ? 'origin_reparto' : 'target_reparto';
    return $viewerRole === 1
        && $viewerDepartment !== ''
        && hash_equals($viewerDepartment, (string) ($row[$departmentField] ?? ''));
}

function scheduleExtraHourRequestData(array $row, int $viewerRole, string $viewerDepartment): array
{
    $departments = appDepartments();
    $kind = (string) ($row['request_kind'] ?? '') === 'store' ? 'extra_store' : 'extra_department';
    $minutes = (int) ($row['minutes'] ?? 0);
    $originDepartment = (string) ($row['origin_reparto'] ?? '');
    $targetDepartment = (string) ($row['target_reparto'] ?? '');

    return [
        'kind' => $kind,
        'id' => (int) $row['id'],
        'user_cf' => (string) $row['user_cf'],
        'user_name' => trim((string) ($row['user_name'] ?? '')),
        'origin_reparto' => $originDepartment,
        'origin_reparto_label' => $departments[$originDepartment] ?? $originDepartment,
        'target_reparto' => $targetDepartment,
        'target_reparto_label' => $targetDepartment !== '' ? ($departments[$targetDepartment] ?? $targetDepartment) : '',
        'store_name' => (string) ($row['store_name'] ?? ''),
        'schedule_date' => (string) $row['schedule_date'],
        'minutes' => $minutes,
        'duration_label' => scheduleExtraHoursDurationLabel($minutes),
        'request_note' => (string) ($row['request_note'] ?? ''),
        'status' => (string) $row['status'],
        'origin_status' => (string) ($row['origin_status'] ?? ''),
        'target_status' => (string) ($row['target_status'] ?? ''),
        'origin_decision_note' => (string) ($row['origin_decision_note'] ?? ''),
        'target_decision_note' => (string) ($row['target_decision_note'] ?? ''),
        'origin_decided_by_name' => trim((string) ($row['origin_decided_by_name'] ?? '')),
        'target_decided_by_name' => trim((string) ($row['target_decided_by_name'] ?? '')),
        'can_decide_origin' => scheduleExtraHoursCanDecideSide($row, $viewerRole, $viewerDepartment, 'origin'),
        'can_decide_target' => scheduleExtraHoursCanDecideSide($row, $viewerRole, $viewerDepartment, 'target'),
        'created_at' => (string) $row['created_at'],
    ];
}

function scheduleExtraHoursLoad(PDO $pdo, int $id, bool $forUpdate = false): ?array
{
    $sql =
        "SELECT e.*, TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS user_name,
                TRIM(CONCAT(COALESCE(od.nome, ''), ' ', COALESCE(od.cognome, ''))) AS origin_decided_by_name,
                TRIM(CONCAT(COALESCE(td.nome, ''), ' ', COALESCE(td.cognome, ''))) AS target_decided_by_name
         FROM extra_hour_requests e
         LEFT JOIN utenti u ON BINARY u.cod_fiscale = BINARY e.user_cf
         LEFT JOIN utenti od ON BINARY od.cod_fiscale = BINARY e.origin_decided_by_cf
         LEFT JOIN utenti td ON BINARY td.cod_fiscale = BINARY e.target_decided_by_cf
         WHERE e.id = ?
         LIMIT 1";
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $statement = $pdo->prepare($sql);
    $statement->execute([$id]);
    $request = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($request) ? $request : null;
}

function scheduleExtraHoursQueryData(PDO $pdo, string $where, array $params, int $viewerRole, string $viewerDepartment): array
{
    $statement = $pdo->prepare(
        "SELECT e.*, TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS user_name,
                TRIM(CONCAT(COALESCE(od.nome, ''), ' ', COALESCE(od.cognome, ''))) AS origin_decided_by_name,
                TRIM(CONCAT(COALESCE(td.nome, ''), ' ', COALESCE(td.cognome, ''))) AS target_decided_by_name
         FROM extra_hour_requests e
         LEFT JOIN utenti u ON BINARY u.cod_fiscale = BINARY e.user_cf
         LEFT JOIN utenti od ON BINARY od.cod_fiscale = BINARY e.origin_decided_by_cf
         LEFT JOIN utenti td ON BINARY td.cod_fiscale = BINARY e.target_decided_by_cf
         WHERE {$where}
         ORDER BY FIELD(e.status, 'pending', 'recorded', 'approved', 'rejected'), e.schedule_date DESC, e.created_at DESC
         LIMIT 150"
    );
    $statement->execute($params);

    return array_map(
        static fn (array $row): array => scheduleExtraHourRequestData($row, $viewerRole, $viewerDepartment),
        $statement->fetchAll(PDO::FETCH_ASSOC)
    );
}

function scheduleExtraHoursNormalizeMinutes($value): ?int
{
    $minutes = filter_var($value, FILTER_VALIDATE_INT);
    if ($minutes === false || $minutes < 15 || $minutes > 16 * 60 || $minutes % 15 !== 0) {
        return null;
    }

    return (int) $minutes;
}

function scheduleExtraHoursOverallStatus(array $row): string
{
    if (($row['origin_status'] ?? '') === 'rejected' || ($row['target_status'] ?? '') === 'rejected') {
        return 'rejected';
    }
    if (($row['origin_status'] ?? '') === 'approved' && ($row['target_status'] ?? '') === 'approved') {
        return 'approved';
    }

    return 'pending';
}

try {
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
            $extraRequests = scheduleExtraHoursQueryData(
                $pdo,
                'BINARY e.user_cf = BINARY ? AND e.schedule_date = ?',
                [$viewerCf, $date],
                $viewerRole,
                $viewerDepartment
            );
            scheduleAdjustmentResponse([
                'ok' => true,
                'requests' => scheduleAdjustmentSortUnified(array_merge($requests, $extraRequests)),
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
            $requests = array_map('scheduleAdjustmentRequestData', $statement->fetchAll(PDO::FETCH_ASSOC));
            $extraRequests = scheduleExtraHoursQueryData(
                $pdo,
                'BINARY e.user_cf = BINARY ?',
                [$viewerCf],
                $viewerRole,
                $viewerDepartment
            );
            scheduleAdjustmentResponse([
                'ok' => true,
                'requests' => scheduleAdjustmentSortUnified(array_merge($requests, $extraRequests)),
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
        $requests = array_map('scheduleAdjustmentRequestData', $statement->fetchAll(PDO::FETCH_ASSOC));
        if ($viewerRole === 3) {
            $extraRequests = scheduleExtraHoursQueryData(
                $pdo,
                '1 = 1',
                [],
                $viewerRole,
                $viewerDepartment
            );
        } else {
            $extraRequests = scheduleExtraHoursQueryData(
                $pdo,
                '(e.origin_reparto = ? OR e.target_reparto = ?)',
                [$viewerDepartment, $viewerDepartment],
                $viewerRole,
                $viewerDepartment
            );
        }
        scheduleAdjustmentResponse([
            'ok' => true,
            'requests' => scheduleAdjustmentSortUnified(array_merge($requests, $extraRequests)),
        ]);
    }

    if ($method !== 'POST' || !app_csrf_request_is_valid()) {
        scheduleAdjustmentResponse(['ok' => false, 'error' => 'Richiesta non valida. Ricarica la pagina e riprova.'], 403);
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create_extra_department' || $action === 'create_extra_store') {
        if (!appIsValidDepartment($viewerDepartment)) {
            scheduleAdjustmentResponse(['ok' => false, 'error' => 'Reparto utente non valido'], 403);
        }

        $date = trim((string) ($_POST['date'] ?? ''));
        $dateInfo = appScheduleAdjustmentDateInfo($date);
        if ($dateInfo === null) {
            scheduleAdjustmentResponse(['ok' => false, 'error' => 'Data non valida'], 422);
        }

        $minutes = scheduleExtraHoursNormalizeMinutes($_POST['minutes'] ?? null);
        if ($minutes === null) {
            scheduleAdjustmentResponse(['ok' => false, 'error' => 'Inserisci una durata valida a step di 15 minuti'], 422);
        }

        $note = trim((string) ($_POST['request_note'] ?? ''));
        if (mb_strlen($note) > 1000) {
            scheduleAdjustmentResponse(['ok' => false, 'error' => 'La nota può contenere al massimo 1000 caratteri'], 422);
        }

        $requestKind = $action === 'create_extra_department' ? 'department' : 'store';
        $targetDepartment = null;
        $storeName = null;
        $status = $requestKind === 'store' ? 'recorded' : 'pending';
        $originStatus = $requestKind === 'department' ? 'pending' : null;
        $targetStatus = $requestKind === 'department' ? 'pending' : null;

        if ($requestKind === 'department') {
            $targetDepartment = trim((string) ($_POST['target_reparto'] ?? ''));
            if (!appIsValidDepartment($targetDepartment) || hash_equals($viewerDepartment, $targetDepartment)) {
                scheduleAdjustmentResponse(['ok' => false, 'error' => 'Scegli un reparto diverso dal tuo'], 422);
            }
        } else {
            $storeName = trim((string) ($_POST['store_name'] ?? ''));
            if ($storeName === '' || mb_strlen($storeName) > 120) {
                scheduleAdjustmentResponse(['ok' => false, 'error' => 'Inserisci il negozio o ipermercato'], 422);
            }
        }

        $insert = $pdo->prepare(
            'INSERT INTO extra_hour_requests
                (request_kind, user_cf, origin_reparto, target_reparto, store_name, schedule_date, minutes, request_note, status, origin_status, target_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $requestKind,
            $viewerCf,
            $viewerDepartment,
            $targetDepartment,
            $storeName,
            $date,
            $minutes,
            $note !== '' ? $note : null,
            $status,
            $originStatus,
            $targetStatus,
        ]);
        $requestId = (int) $pdo->lastInsertId();
        $request = scheduleExtraHoursLoad($pdo, $requestId) ?: [];

        if ($requestKind === 'department') {
            $recipients = array_values(array_unique(array_merge(
                appScheduleAdjustmentManagerRecipients($pdo, $viewerDepartment),
                appScheduleAdjustmentManagerRecipients($pdo, (string) $targetDepartment)
            )));
            $departmentLabel = appDepartments()[(string) $targetDepartment] ?? (string) $targetDepartment;
            foreach ($recipients as $recipientCf) {
                if (hash_equals((string) $recipientCf, $viewerCf)) {
                    continue;
                }
                try {
                    appPushSendPayload($pdo, [
                        'type' => 'extra_department_hours_created',
                        'title' => 'Ore in altro reparto da approvare',
                        'body' => 'Un addetto ha segnalato ' . scheduleExtraHoursDurationLabel($minutes) . ' nel reparto ' . $departmentLabel . '.',
                        'url' => './index.php?adjustments=1',
                        'recipient_cf' => (string) $recipientCf,
                        'tag' => 'extra-department-hours-' . $requestId,
                        'request_id' => $requestId,
                    ], (string) $recipientCf);
                } catch (Throwable $pushError) {
                    error_log('Push ore extra reparto non inviata: ' . $pushError->getMessage());
                }
            }
        }

        scheduleAdjustmentResponse([
            'ok' => true,
            'request' => scheduleExtraHourRequestData($request, $viewerRole, $viewerDepartment),
        ]);
    }

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
                    'type' => 'adjustment_created',
                    'title' => 'Nuova segnalazione ore',
                    'body' => 'Un addetto ha segnalato una variazione di orario da approvare.',
                    'url' => './index.php?adjustments=1',
                    'recipient_cf' => $recipientCf,
                    'tag' => 'adjustment-created-' . $requestId,
                    'request_id' => $requestId,
                ], $recipientCf);
            } catch (Throwable $pushError) {
                error_log('Push richiesta ore non inviata: ' . $pushError->getMessage());
            }
        }

        scheduleAdjustmentResponse(['ok' => true, 'request' => scheduleAdjustmentRequestData($request ?: [])]);
    }

    if (in_array($action, ['approve_extra', 'reject_extra'], true)) {
        if (!$canApprove) {
            scheduleAdjustmentResponse(['ok' => false, 'error' => 'Operazione non consentita'], 403);
        }

        $requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
        $decisionNote = trim((string) ($_POST['decision_note'] ?? ''));
        if ($requestId === false || $requestId === null || $requestId < 1) {
            scheduleAdjustmentResponse(['ok' => false, 'error' => 'Richiesta non valida'], 400);
        }
        if (mb_strlen($decisionNote) > 1000) {
            scheduleAdjustmentResponse(['ok' => false, 'error' => 'La nota decisionale può contenere al massimo 1000 caratteri'], 422);
        }

        $pdo->beginTransaction();
        $request = scheduleExtraHoursLoad($pdo, (int) $requestId, true);
        if ($request === null || ($request['request_kind'] ?? '') !== 'department') {
            $pdo->rollBack();
            scheduleAdjustmentResponse(['ok' => false, 'error' => 'Richiesta non trovata'], 404);
        }
        if (hash_equals((string) $request['user_cf'], $viewerCf)) {
            $pdo->rollBack();
            scheduleAdjustmentResponse(['ok' => false, 'error' => 'Non puoi approvare una tua richiesta'], 403);
        }
        if ((string) $request['status'] !== 'pending') {
            $pdo->rollBack();
            scheduleAdjustmentResponse(['ok' => false, 'error' => 'Questa richiesta è già stata completata'], 409);
        }

        $requestedSide = (string) ($_POST['decision_side'] ?? '');
        $availableSides = [];
        foreach (['origin', 'target'] as $side) {
            if (scheduleExtraHoursCanDecideSide($request, $viewerRole, $viewerDepartment, $side)) {
                $availableSides[] = $side;
            }
        }
        $side = in_array($requestedSide, $availableSides, true)
            ? $requestedSide
            : ($availableSides[0] ?? '');
        if ($side === '') {
            $pdo->rollBack();
            scheduleAdjustmentResponse(['ok' => false, 'error' => 'Non puoi gestire questa richiesta'], 403);
        }

        $sideStatusField = $side . '_status';
        if ((string) ($request[$sideStatusField] ?? '') !== 'pending') {
            $pdo->rollBack();
            scheduleAdjustmentResponse(['ok' => false, 'error' => 'Questa approvazione è già stata gestita'], 409);
        }

        $sideDecision = $action === 'approve_extra' ? 'approved' : 'rejected';
        $request[$sideStatusField] = $sideDecision;
        $overallStatus = scheduleExtraHoursOverallStatus($request);
        $update = $pdo->prepare(
            'UPDATE extra_hour_requests
             SET status = ?,
                 ' . $side . '_status = ?,
                 ' . $side . '_decided_by_cf = ?,
                 ' . $side . '_decision_note = ?,
                 ' . $side . '_decided_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status = ? AND ' . $side . '_status = ?'
        );
        $update->execute([
            $overallStatus,
            $sideDecision,
            $viewerCf,
            $decisionNote !== '' ? $decisionNote : null,
            (int) $request['id'],
            'pending',
            'pending',
        ]);
        if ($update->rowCount() !== 1) {
            $pdo->rollBack();
            scheduleAdjustmentResponse(['ok' => false, 'error' => 'Questa richiesta è stata aggiornata da un altro responsabile'], 409);
        }
        $pdo->commit();

        $request = scheduleExtraHoursLoad($pdo, (int) $requestId) ?: [];
        if (in_array($overallStatus, ['approved', 'rejected'], true)) {
            try {
                appPushSendPayload($pdo, [
                    'type' => 'extra_department_hours_decision',
                    'title' => $overallStatus === 'approved' ? 'Ore in altro reparto approvate' : 'Ore in altro reparto rifiutate',
                    'body' => $overallStatus === 'approved'
                        ? 'La tua richiesta ore in altro reparto è stata approvata.'
                        : 'La tua richiesta ore in altro reparto è stata rifiutata.',
                    'url' => './index.php?adjustments=1',
                    'recipient_cf' => (string) ($request['user_cf'] ?? ''),
                    'tag' => 'extra-department-hours-decision-' . (int) $requestId,
                    'request_id' => (int) $requestId,
                ], (string) ($request['user_cf'] ?? ''));
            } catch (Throwable $pushError) {
                error_log('Push esito ore extra reparto non inviata: ' . $pushError->getMessage());
            }
        }

        scheduleAdjustmentResponse([
            'ok' => true,
            'request' => scheduleExtraHourRequestData($request, $viewerRole, $viewerDepartment),
        ]);
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
            'type' => 'adjustment_decision',
            'title' => $status === 'approved' ? 'Variazione ore approvata' : 'Variazione ore rifiutata',
            'body' => $status === 'approved'
                ? 'Il tuo orario effettivo è stato approvato.'
                : 'Il capo ha rifiutato la tua segnalazione ore.',
            'url' => './index.php?adjustments=1',
            'recipient_cf' => (string) $request['user_cf'],
            'tag' => 'adjustment-decision-' . (int) $request['id'],
            'request_id' => (int) $request['id'],
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

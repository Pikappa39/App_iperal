<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

ob_start();
require_once __DIR__ . '/../../../session_bootstrap.php';
app_session_start();
require_once __DIR__ . '/support/response.php';
require_once __DIR__ . '/support/validation.php';
require_once __DIR__ . '/support/storage.php';
require_once __DIR__ . '/../../../connection_files/connection.php';
require_once __DIR__ . '/../../../connection_files/schedule_adjustment_lib.php';
require_once __DIR__ . '/permissions/note_permissions.php';
require_once __DIR__ . '/services/note_service.php';

set_error_handler(static function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $storageDir = noteStorageDir();
    noteEnsureStorage($storageDir);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $sessionUser = $_SESSION['user'] ?? null;
    if (!is_array($sessionUser) || trim((string) ($sessionUser['cf'] ?? '')) === '') {
        noteResponse([
            'ok' => false,
            'error' => 'Accesso richiesto',
        ], 401);
    }

    if ($method === 'POST' && !app_csrf_request_is_valid()) {
        noteResponse(['ok' => false, 'error' => 'Richiesta non valida. Ricarica la pagina e riprova.'], 403);
    }
    app_session_write_close_if_active();

    if (!$connessione || !($pdo instanceof PDO)) {
        noteResponse([
            'ok' => false,
            'error' => 'Servizio note temporaneamente non disponibile',
        ], 503);
    }

    $viewer = noteViewer($sessionUser, $pdo);
    $capo = (int) $viewer['capo'];

    if ($method === 'GET') {
        if (isset($_GET['all']) && $_GET['all'] === '1') {
            if (!in_array($capo, [1, 2, 3], true)) {
                noteResponse([
                    'ok' => false,
                    'error' => 'Accesso negato',
                ], 403);
            }

            noteResponse(noteAllMonthsPayload($storageDir, $viewer));
        }

        $monthKey = noteNormalizeMonthKey($_GET['month'] ?? '');
        $dateKey = noteNormalizeDateKey($_GET['date'] ?? '');

        if ($monthKey === null && $dateKey !== null) {
            $monthKey = substr($dateKey, 0, 7);
        }

        if ($monthKey === null) {
            noteResponse([
                'ok' => false,
                'error' => 'Parametro month o date non valido',
            ], 400);
        }

        noteResponse(noteMonthPayload($storageDir, $monthKey, $dateKey, $viewer));
    }

    if ($method !== 'POST') {
        noteResponse([
            'ok' => false,
            'error' => 'Metodo non consentito',
        ], 405);
    }

    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'delete_admin') {
        if (!in_array($capo, [1, 2, 3], true)) {
            noteResponse(['ok' => false, 'error' => 'Accesso negato'], 403);
        }

        $dateKey = noteNormalizeDateKey($_POST['date'] ?? '');
        $entryId = trim((string) ($_POST['entry_id'] ?? ''));
        if ($dateKey === null || !preg_match('/^[a-f0-9]{64}$/', $entryId)) {
            noteResponse(['ok' => false, 'error' => 'Nota non valida'], 400);
        }

        noteResponse(noteDeleteAdminPayload($storageDir, $dateKey, $entryId, $viewer));
    }

    if ($action !== 'save') {
        noteResponse(['ok' => false, 'error' => 'Operazione non valida'], 400);
    }

    $dateKey = noteNormalizeDateKey($_POST['date'] ?? '');
    $note = trim((string) ($_POST['note'] ?? ''));
    $clientScheduleVersion = trim((string) ($_POST['schedule_version'] ?? ''));
    $clientScheduleYear = filter_var($_POST['schedule_year'] ?? null, FILTER_VALIDATE_INT);
    $clientScheduleWeek = filter_var($_POST['schedule_week'] ?? null, FILTER_VALIDATE_INT);

    if ($dateKey === null) {
        noteResponse([
            'ok' => false,
            'error' => 'Data non valida',
        ], 400);
    }
    if (mb_strlen($note) > 2000) {
        noteResponse([
            'ok' => false,
            'error' => 'La nota può contenere al massimo 2000 caratteri',
        ], 400);
    }

    noteAssertScheduleVersion(
        $pdo,
        $dateKey,
        $clientScheduleVersion,
        $clientScheduleYear,
        $clientScheduleWeek,
        (string) $viewer['department']
    );

    noteResponse(noteSavePayload($storageDir, $dateKey, $note, $viewer));
} catch (Throwable $error) {
    noteResponse([
        'ok' => false,
        'error' => 'Errore interno nel salvataggio note',
    ], 500);
} finally {
    restore_error_handler();
}

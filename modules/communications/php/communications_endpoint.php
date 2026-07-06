<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

communicationModuleBootstrap([
    'connection_files/push_lib.php',
    'modules/communications/php/support/response.php',
    'modules/communications/php/permissions/communication_permissions.php',
    'modules/communications/php/repositories/communication_repository.php',
    'modules/communications/php/services/communication_service.php',
]);

$viewer = $_SESSION['user'] ?? null;
if (!is_array($viewer) || !$connessione || !($pdo instanceof PDO) || !isset($viewer['cf'])) {
    communicationResponse(['ok' => false, 'error' => 'Accesso richiesto'], 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !app_csrf_request_is_valid()) {
    communicationResponse(['ok' => false, 'error' => 'Richiesta non valida. Ricarica la pagina e riprova.'], 403);
}

$viewerCf = (string) $viewer['cf'];
$viewerRole = (int) ($viewer['capo'] ?? 0);
$viewerDepartment = (string) ($viewer['reparto'] ?? '');
$isManager = communicationIsManager($viewerRole);
app_session_write_close_if_active();

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $view = (string) ($_GET['view'] ?? 'inbox');

        if ($view === 'inbox') {
            communicationResponse(communicationInboxPayload($pdo, $viewerCf));
        }

        if (!$isManager) {
            communicationResponse(['ok' => false, 'error' => 'Accesso negato'], 403);
        }

        if ($view === 'users') {
            communicationResponse(communicationUsersPayload($pdo, $viewerRole, $viewerDepartment));
        }

        if ($view === 'sent') {
            communicationResponse(communicationSentPayload($pdo, $viewerRole, $viewerCf));
        }

        communicationResponse(['ok' => false, 'error' => 'Vista non valida'], 400);
    }

    if ($method !== 'POST') {
        communicationResponse(['ok' => false, 'error' => 'Metodo non consentito'], 405);
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'acknowledge') {
        communicationResponse(communicationAcknowledgePayload($pdo, (int) ($_POST['communication_id'] ?? 0), $viewerCf));
    }

    if ($action !== 'send' || !$isManager) {
        communicationResponse(['ok' => false, 'error' => 'Accesso negato'], 403);
    }

    communicationResponse(communicationSendPayload($pdo, $_POST, $viewerCf, $viewerRole, $viewerDepartment));
} catch (DomainException $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $status = $error->getCode() >= 400 && $error->getCode() <= 499 ? $error->getCode() : 400;
    communicationResponse(['ok' => false, 'error' => $error->getMessage()], $status);
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Errore comunicazioni: ' . $error->getMessage());
    communicationResponse(['ok' => false, 'error' => 'Operazione non riuscita'], 500);
}
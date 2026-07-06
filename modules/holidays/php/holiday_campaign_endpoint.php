<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

holidayModuleBootstrap([
    'modules/holidays/php/support/response.php',
    'modules/holidays/php/support/week.php',
    'modules/holidays/php/permissions/holiday_campaign_permissions.php',
    'modules/holidays/php/repositories/department_holiday_repository.php',
    'modules/holidays/php/repositories/holiday_campaign_repository.php',
    'modules/holidays/php/services/holiday_campaign_service.php',
]);

$viewer = $_SESSION['user'] ?? null;
if (!is_array($viewer) || !$connessione || !($pdo instanceof PDO)) {
    holidayResponse(['ok' => false, 'error' => 'Accesso richiesto'], 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !app_csrf_request_is_valid()) {
    holidayResponse(['ok' => false, 'error' => 'Richiesta non valida. Ricarica la pagina e riprova.'], 403);
}

$department = holidayCampaignDepartment($viewer);
if (!appIsValidDepartment($department)) {
    holidayResponse(['ok' => false, 'error' => 'Reparto non valido'], 403);
}

$viewerCf = (string) ($viewer['cf'] ?? '');
$viewerName = holidayCampaignUserName($viewer);
$canManage = holidayCampaignCanManage($viewer, $department);
$canReviewWeeks = holidayCampaignCanReviewWeeks($viewer, $department);
app_session_write_close_if_active();

try {
    holidayCampaignEnsureTables($pdo);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $year = filter_var($_REQUEST['year'] ?? date('o'), FILTER_VALIDATE_INT);
    if ($year === false || $year === null || !holidayValidYear((int) $year)) {
        holidayResponse(['ok' => false, 'error' => 'Anno non valido'], 400);
    }
    $year = (int) $year;

    if ($method === 'GET') {
        holidayResponse(holidayCampaignPayload($pdo, $department, $year, $canManage, $canReviewWeeks, $viewerCf));
    }

    if ($method !== 'POST') {
        holidayResponse(['ok' => false, 'error' => 'Metodo non consentito'], 405);
    }

    $action = (string) ($_POST['action'] ?? '');
    if (in_array($action, ['open', 'close', 'submit_to_director'], true) && !$canManage) {
        holidayResponse(['ok' => false, 'error' => 'Accesso negato'], 403);
    }
    if ($action === 'review_preference' && !$canReviewWeeks) {
        holidayResponse(['ok' => false, 'error' => 'Accesso negato'], 403);
    }

    if ($action === 'open') {
        holidayResponse(holidayCampaignOpenPayload($pdo, $department, $year, $viewerCf));
    }

    if ($action === 'close') {
        holidayResponse(holidayCampaignClosePayload($pdo, $department, $year, $viewerCf));
    }

    $campaign = holidayCampaignFetch($pdo, $department, $year);
    if (!is_array($campaign)) {
        holidayResponse(['ok' => false, 'error' => 'Campagna ferie non trovata'], 404);
    }

    if ($action === 'submit_to_director') {
        holidayResponse(holidayCampaignSubmitToDirectorPayload($pdo, $campaign, $viewer, $department, $year));
    }

    if ($action === 'review_preference') {
        $payload = holidayCampaignReviewPreferencePayload(
            $pdo,
            $campaign,
            (int) ($_POST['preference_id'] ?? 0),
            (string) ($_POST['decision'] ?? ''),
            $viewer
        );
        holidayResponse($payload);
    }

    if ($action !== 'toggle_preference') {
        holidayResponse(['ok' => false, 'error' => 'Azione non valida'], 400);
    }

    $week = filter_var($_POST['week'] ?? null, FILTER_VALIDATE_INT);
    if ($week === false || $week === null) {
        holidayResponse(['ok' => false, 'error' => 'Settimana non valida'], 400);
    }

    $validation = holidayCampaignValidateSelection($campaign, $year, (int) $week);
    if (!$validation['ok']) {
        holidayResponse([
            'ok' => false,
            'error' => $validation['rules'][0]['message'] ?? 'Scelta non valida',
            'rules' => $validation['rules'],
        ], 422);
    }

    holidayResponse(holidayCampaignTogglePreferencePayload($pdo, $campaign, $department, $year, (int) $week, $viewerCf, $viewerName));
} catch (DomainException $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $status = $error->getCode() >= 400 && $error->getCode() <= 499 ? $error->getCode() : 422;
    holidayResponse(['ok' => false, 'error' => $error->getMessage()], $status);
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Errore attivita ferie: ' . $error->getMessage());
    holidayResponse(['ok' => false, 'error' => 'Attivita ferie temporaneamente non disponibile'], 500);
}
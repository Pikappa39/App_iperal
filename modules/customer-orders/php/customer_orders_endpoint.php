<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

customerOrdersModuleBootstrap([
    'modules/customer-orders/php/support/response.php',
    'modules/customer-orders/php/support/validation.php',
    'modules/customer-orders/php/permissions/customer_order_permissions.php',
    'modules/customer-orders/php/repositories/customer_order_repository.php',
    'modules/customer-orders/php/services/customer_order_service.php',
]);

if (!isset($_SESSION['user']['cf']) || !$connessione || !($pdo instanceof PDO)) {
    customerOrdersResponse(['ok' => false, 'error' => 'Accesso richiesto'], 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !app_csrf_request_is_valid()) {
    customerOrdersResponse(['ok' => false, 'error' => 'Richiesta non valida. Ricarica la pagina e riprova.'], 403);
}

$viewerCf = (string) $_SESSION['user']['cf'];
app_session_write_close_if_active();

try {
    $viewer = customerOrdersViewer($pdo, $viewerCf);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $view = (string) ($_GET['view'] ?? 'list');
        if ($view === 'meta') {
            customerOrdersResponse(customerOrdersMetaPayload($viewer));
        }
        if ($view === 'list') {
            customerOrdersResponse(customerOrdersListPayload($pdo, $viewer, $_GET));
        }

        customerOrdersResponse(['ok' => false, 'error' => 'Vista non valida'], 400);
    }

    if ($method !== 'POST') {
        customerOrdersResponse(['ok' => false, 'error' => 'Metodo non consentito'], 405);
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        customerOrdersResponse(customerOrdersCreatePayload($pdo, $viewer, $_POST));
    }
    if ($action === 'update_order') {
        customerOrdersResponse(customerOrdersUpdateOrderPayload($pdo, $viewer, $_POST));
    }
    if ($action === 'add_item') {
        customerOrdersResponse(customerOrdersAddItemPayload($pdo, $viewer, $_POST));
    }
    if ($action === 'update_item') {
        customerOrdersResponse(customerOrdersUpdateItemPayload($pdo, $viewer, $_POST));
    }

    customerOrdersResponse(['ok' => false, 'error' => 'Azione non valida'], 400);
} catch (DomainException $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $status = $error->getCode() >= 400 && $error->getCode() <= 499 ? $error->getCode() : 422;
    customerOrdersResponse(['ok' => false, 'error' => $error->getMessage()], $status);
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Ordini clienti non disponibili: ' . $error->getMessage());
    customerOrdersResponse(['ok' => false, 'error' => 'Ordini clienti temporaneamente non disponibili'], 500);
}

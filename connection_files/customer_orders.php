<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/../session_bootstrap.php';
app_session_start();
require __DIR__ . '/connection.php';

function customerOrdersResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user']['cf']) || !$connessione || !($pdo instanceof PDO)) {
    customerOrdersResponse(['ok' => false, 'error' => 'Accesso richiesto'], 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !app_csrf_request_is_valid()) {
    customerOrdersResponse(['ok' => false, 'error' => 'Richiesta non valida. Ricarica la pagina e riprova.'], 403);
}

$sessionUser = $_SESSION['user'];
$viewerCf = (string) $sessionUser['cf'];
app_session_write_close_if_active();

function customerOrdersViewer(PDO $pdo, string $viewerCf): array
{
    $statement = $pdo->prepare(
        'SELECT cod_fiscale, nome, cognome, capo, reparto, box_info
         FROM utenti
         WHERE cod_fiscale = ? AND attivo = 1
         LIMIT 1'
    );
    $statement->execute([$viewerCf]);
    $viewer = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($viewer)) {
        throw new RuntimeException('Utente non disponibile.');
    }

    return $viewer;
}

function customerOrdersActorName(array $viewer): string
{
    return trim((string) ($viewer['nome'] ?? '') . ' ' . (string) ($viewer['cognome'] ?? '')) ?: (string) ($viewer['cod_fiscale'] ?? '');
}

function customerOrdersStatusLabel(string $status): string
{
    return [
        'registered' => 'Registrato',
        'ordered' => 'Ordinato',
        'arrived' => 'Arrivato',
        'arrived_to_call' => 'Arrivato / da chiamare',
        'called' => 'Chiamato',
        'delivered' => 'Consegnato',
        'partial' => 'Parziale',
        'cancelled' => 'Annullato',
        'unavailable' => 'Non disponibile',
    ][$status] ?? $status;
}

function customerOrdersAllowedItemStatuses(): array
{
    return ['registered', 'ordered', 'arrived', 'called', 'delivered', 'cancelled', 'unavailable'];
}

function customerOrdersTrimField(string $value, int $maxLength): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?: $value);
    return mb_substr($value, 0, $maxLength);
}

function customerOrdersCanChooseDepartment(array $viewer): bool
{
    return appUserHasBoxInfo($viewer);
}

function customerOrdersCanAccess(array $order, array $viewer): bool
{
    if (appUserHasBoxInfo($viewer)) {
        return true;
    }

    $department = trim((string) ($viewer['reparto'] ?? ''));
    $viewerCf = (string) ($viewer['cod_fiscale'] ?? '');

    return ($department !== '' && (string) ($order['target_reparto'] ?? '') === $department)
        || ($viewerCf !== '' && hash_equals($viewerCf, (string) ($order['taken_by_cf'] ?? '')));
}

function customerOrdersNormalizeCustomer(array $source): array
{
    $name = customerOrdersTrimField((string) ($source['customer_name'] ?? ''), 100);
    $surname = customerOrdersTrimField((string) ($source['customer_surname'] ?? ''), 100);
    $phone = customerOrdersTrimField((string) ($source['customer_phone'] ?? ''), 40);
    $note = trim((string) ($source['general_note'] ?? ''));

    if ($name === '' || $surname === '' || $phone === '') {
        throw new DomainException('Nome, cognome e telefono cliente sono obbligatori.', 422);
    }
    if (mb_strlen($note) > 2000) {
        throw new DomainException('Le note ordine possono contenere al massimo 2000 caratteri.', 422);
    }

    return [
        'customer_name' => $name,
        'customer_surname' => $surname,
        'customer_phone' => $phone,
        'general_note' => $note !== '' ? $note : null,
    ];
}

function customerOrdersNormalizeItem(array $source, ?array $current = null): array
{
    $articleName = customerOrdersTrimField((string) ($source['article_name'] ?? ($current['article_name'] ?? '')), 255);
    $quantity = customerOrdersTrimField((string) ($source['quantity'] ?? ($current['quantity'] ?? '')), 80);
    $ean = customerOrdersTrimField((string) ($source['ean'] ?? ($current['ean'] ?? '')), 64);
    $internalCode = customerOrdersTrimField((string) ($source['internal_code'] ?? ($current['internal_code'] ?? '')), 64);
    $rawPrice = array_key_exists('price_at_order', $source)
        ? trim((string) $source['price_at_order'])
        : trim((string) ($current['price_at_order'] ?? ''));
    $note = trim((string) ($source['item_note'] ?? ($current['item_note'] ?? '')));
    $status = (string) ($source['status'] ?? ($current['status'] ?? 'registered'));

    if ($articleName === '' || $quantity === '') {
        throw new DomainException('Ogni articolo deve avere descrizione e quantità.', 422);
    }
    if (mb_strlen($note) > 1000) {
        throw new DomainException('La nota articolo può contenere al massimo 1000 caratteri.', 422);
    }
    if (!in_array($status, customerOrdersAllowedItemStatuses(), true)) {
        throw new DomainException('Stato articolo non valido.', 422);
    }
    $price = null;
    if ($rawPrice !== '') {
        $normalizedPrice = str_replace(',', '.', $rawPrice);
        if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $normalizedPrice)) {
            throw new DomainException('Prezzo articolo non valido. Usa ad esempio 1,99.', 422);
        }
        $price = number_format((float) $normalizedPrice, 2, '.', '');
    }

    return [
        'article_name' => $articleName,
        'ean' => $ean !== '' ? $ean : null,
        'internal_code' => $internalCode !== '' ? $internalCode : null,
        'quantity' => $quantity,
        'price_at_order' => $price,
        'item_note' => $note !== '' ? $note : null,
        'status' => $status,
    ];
}

function customerOrdersDecodeItems(string $raw): array
{
    $items = json_decode($raw, true);
    if (!is_array($items)) {
        throw new DomainException('Articoli ordine non validi.', 422);
    }

    $normalized = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $normalized[] = customerOrdersNormalizeItem($item);
    }
    if ($normalized === []) {
        throw new DomainException('Inserisci almeno un articolo.', 422);
    }
    if (count($normalized) > 40) {
        throw new DomainException('Troppi articoli nello stesso ordine.', 422);
    }

    return $normalized;
}

function customerOrdersEvent(PDO $pdo, int $orderId, ?int $itemId, array $viewer, string $eventType, ?string $fromStatus = null, ?string $toStatus = null, array $details = []): void
{
    $encoded = $details !== [] ? json_encode($details, JSON_UNESCAPED_UNICODE) : null;
    $statement = $pdo->prepare(
        'INSERT INTO customer_order_events
            (order_id, item_id, actor_cf, actor_name, event_type, from_status, to_status, details_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([
        $orderId,
        $itemId,
        (string) ($viewer['cod_fiscale'] ?? ''),
        customerOrdersActorName($viewer),
        $eventType,
        $fromStatus,
        $toStatus,
        $encoded,
    ]);
}

function customerOrdersRecalculateStatus(PDO $pdo, int $orderId): string
{
    $statement = $pdo->prepare('SELECT status FROM customer_order_items WHERE order_id = ?');
    $statement->execute([$orderId]);
    $statuses = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    if ($statuses === []) {
        $nextStatus = 'cancelled';
    } else {
        $unique = array_values(array_unique($statuses));
        $closedWithoutDelivery = ['cancelled', 'unavailable'];
        $closedWithoutDeliveryCount = count(array_filter($statuses, static fn (string $status): bool => in_array($status, $closedWithoutDelivery, true)));
        if (count(array_filter($statuses, static fn (string $status): bool => !in_array($status, $closedWithoutDelivery, true))) === 0) {
            $nextStatus = 'cancelled';
        } elseif (count($unique) === 1) {
            $only = $unique[0];
            $nextStatus = $only === 'arrived' ? 'arrived_to_call' : $only;
        } elseif ($closedWithoutDeliveryCount > 0) {
            $nextStatus = 'partial';
        } elseif (in_array('delivered', $statuses, true) || in_array('called', $statuses, true) || in_array('arrived', $statuses, true)) {
            $nextStatus = 'partial';
        } elseif (in_array('ordered', $statuses, true)) {
            $nextStatus = 'ordered';
        } else {
            $nextStatus = 'registered';
        }
    }

    $pdo->prepare('UPDATE customer_orders SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
        ->execute([$nextStatus, $orderId]);

    return $nextStatus;
}

function customerOrdersLoad(PDO $pdo, int $orderId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM customer_orders WHERE id = ? LIMIT 1');
    $statement->execute([$orderId]);
    $order = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($order) ? $order : null;
}

function customerOrdersLoadForUpdate(PDO $pdo, int $orderId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM customer_orders WHERE id = ? LIMIT 1 FOR UPDATE');
    $statement->execute([$orderId]);
    $order = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($order) ? $order : null;
}

function customerOrdersItemForUpdate(PDO $pdo, int $itemId): ?array
{
    $statement = $pdo->prepare(
        'SELECT i.*, o.target_reparto, o.taken_by_cf, o.status AS order_status
         FROM customer_order_items i
         JOIN customer_orders o ON o.id = i.order_id
         WHERE i.id = ?
         LIMIT 1 FOR UPDATE'
    );
    $statement->execute([$itemId]);
    $item = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($item) ? $item : null;
}

function customerOrdersRecipients(PDO $pdo, string $targetDepartment, string $actorCf): array
{
    $statement = $pdo->prepare(
        "SELECT cod_fiscale
         FROM utenti
         WHERE attivo = 1
           AND (
                reparto = ?
                OR box_info = 1
                OR reparto = 'box'
                OR capo = 3
                OR (capo = 1 AND reparto = 'cs')
           )"
    );
    $statement->execute([$targetDepartment]);
    $recipients = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cf = (string) ($row['cod_fiscale'] ?? '');
        if ($cf !== '' && !hash_equals($cf, $actorCf)) {
            $recipients[$cf] = true;
        }
    }

    return array_keys($recipients);
}

function customerOrdersNotifyInApp(PDO $pdo, array $order, string $type, string $title, string $body, string $actorCf): void
{
    $statement = $pdo->prepare(
        'INSERT INTO customer_order_notifications (order_id, recipient_cf, event_type, title, body)
         VALUES (?, ?, ?, ?, ?)'
    );
    foreach (customerOrdersRecipients($pdo, (string) $order['target_reparto'], $actorCf) as $recipientCf) {
        try {
            $statement->execute([(int) $order['id'], $recipientCf, $type, $title, $body]);
        } catch (Throwable $notificationError) {
            error_log('Notifica in-app ordine cliente non salvata: ' . $notificationError->getMessage());
        }
    }
}

function customerOrdersFormat(array $order, array $items, array $viewer): array
{
    $departments = appDepartments();
    $target = (string) $order['target_reparto'];
    $source = (string) $order['source_reparto'];

    return [
        'id' => (int) $order['id'],
        'target_reparto' => $target,
        'target_reparto_label' => $departments[$target] ?? $target,
        'source_type' => (string) $order['source_type'],
        'source_reparto' => $source,
        'source_reparto_label' => $departments[$source] ?? $source,
        'customer_name' => (string) $order['customer_name'],
        'customer_surname' => (string) $order['customer_surname'],
        'customer_phone' => (string) $order['customer_phone'],
        'general_note' => (string) ($order['general_note'] ?? ''),
        'status' => (string) $order['status'],
        'status_label' => customerOrdersStatusLabel((string) $order['status']),
        'taken_by_cf' => (string) ($order['taken_by_cf'] ?? ''),
        'taken_by_name' => (string) $order['taken_by_name'],
        'taken_at' => (string) $order['taken_at'],
        'updated_at' => (string) $order['updated_at'],
        'can_edit' => customerOrdersCanAccess($order, $viewer),
        'items' => array_values(array_map(static function (array $item): array {
            return [
                'id' => (int) $item['id'],
                'order_id' => (int) $item['order_id'],
                'article_name' => (string) $item['article_name'],
                'ean' => (string) ($item['ean'] ?? ''),
                'internal_code' => (string) ($item['internal_code'] ?? ''),
                'quantity' => (string) $item['quantity'],
                'price_at_order' => $item['price_at_order'] !== null ? number_format((float) $item['price_at_order'], 2, '.', '') : '',
                'item_note' => (string) ($item['item_note'] ?? ''),
                'status' => (string) $item['status'],
                'status_label' => customerOrdersStatusLabel((string) $item['status']),
            ];
        }, $items)),
    ];
}

try {
    $viewer = customerOrdersViewer($pdo, $viewerCf);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $view = (string) ($_GET['view'] ?? 'list');
        if ($view === 'meta') {
            customerOrdersResponse([
                'ok' => true,
                'departments' => appCustomerOrderDepartments(),
                'can_choose_department' => customerOrdersCanChooseDepartment($viewer),
                'is_box_operator' => appUserHasBoxInfo($viewer),
                'default_department' => (string) ($viewer['reparto'] ?? ''),
                'item_statuses' => array_map(static fn (string $status): array => [
                    'value' => $status,
                    'label' => customerOrdersStatusLabel($status),
                ], customerOrdersAllowedItemStatuses()),
            ]);
        }

        if ($view !== 'list') {
            customerOrdersResponse(['ok' => false, 'error' => 'Vista non valida'], 400);
        }

        $where = [];
        $params = [];
        $status = (string) ($_GET['status'] ?? 'open');
        if (customerOrdersCanChooseDepartment($viewer)) {
            $departmentFilter = trim((string) ($_GET['department'] ?? ''));
            if ($departmentFilter !== '' && appIsValidCustomerOrderDepartment($departmentFilter)) {
                $where[] = 'o.target_reparto = ?';
                $params[] = $departmentFilter;
            }
        } else {
            $where[] = '(o.target_reparto = ? OR BINARY o.taken_by_cf = BINARY ?)';
            $params[] = (string) ($viewer['reparto'] ?? '');
            $params[] = (string) $viewer['cod_fiscale'];
        }

        if ($status === 'open') {
            $where[] = "o.status NOT IN ('delivered', 'cancelled')";
        } elseif ($status === 'closed') {
            $where[] = "o.status IN ('delivered', 'cancelled')";
        } elseif ($status !== 'all') {
            $where[] = 'o.status = ?';
            $params[] = $status;
        }

        $sql = 'SELECT o.* FROM customer_orders o'
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
            . " ORDER BY FIELD(o.status, 'registered', 'ordered', 'arrived_to_call', 'called', 'partial', 'delivered', 'cancelled'), o.taken_at DESC, o.id DESC
                LIMIT 150";
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $orders = $statement->fetchAll(PDO::FETCH_ASSOC);
        $itemsByOrder = [];
        if ($orders !== []) {
            $ids = array_map(static fn (array $order): int => (int) $order['id'], $orders);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $itemsStatement = $pdo->prepare(
                'SELECT *
                 FROM customer_order_items
                 WHERE order_id IN (' . $placeholders . ')
                 ORDER BY id'
            );
            $itemsStatement->execute($ids);
            foreach ($itemsStatement->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $itemsByOrder[(int) $item['order_id']][] = $item;
            }
        }

        $pdo->prepare('UPDATE customer_order_notifications SET read_at = CURRENT_TIMESTAMP WHERE BINARY recipient_cf = BINARY ? AND read_at IS NULL')
            ->execute([(string) $viewer['cod_fiscale']]);
        customerOrdersResponse([
            'ok' => true,
            'orders' => array_map(
                static fn (array $order): array => customerOrdersFormat($order, $itemsByOrder[(int) $order['id']] ?? [], $viewer),
                $orders
            ),
        ]);
    }

    if ($method !== 'POST') {
        customerOrdersResponse(['ok' => false, 'error' => 'Metodo non consentito'], 405);
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        $customer = customerOrdersNormalizeCustomer($_POST);
        $items = customerOrdersDecodeItems((string) ($_POST['items_json'] ?? ''));
        $sourceDepartment = trim((string) ($viewer['reparto'] ?? ''));
        $targetDepartment = customerOrdersCanChooseDepartment($viewer)
            ? trim((string) ($_POST['target_reparto'] ?? ''))
            : $sourceDepartment;

        if (!appIsValidCustomerOrderDepartment($targetDepartment)) {
            customerOrdersResponse(['ok' => false, 'error' => 'Reparto destinatario non valido'], 422);
        }
        if (!appIsValidDepartment($sourceDepartment)) {
            $sourceDepartment = customerOrdersCanChooseDepartment($viewer) ? 'box' : $targetDepartment;
        }

        $pdo->beginTransaction();
        $insertOrder = $pdo->prepare(
            'INSERT INTO customer_orders
                (target_reparto, source_type, source_reparto, customer_name, customer_surname, customer_phone, general_note, taken_by_cf, taken_by_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertOrder->execute([
            $targetDepartment,
            customerOrdersCanChooseDepartment($viewer) ? 'box' : 'department',
            $sourceDepartment,
            $customer['customer_name'],
            $customer['customer_surname'],
            $customer['customer_phone'],
            $customer['general_note'],
            (string) $viewer['cod_fiscale'],
            customerOrdersActorName($viewer),
        ]);
        $orderId = (int) $pdo->lastInsertId();

        $insertItem = $pdo->prepare(
            'INSERT INTO customer_order_items
                (order_id, article_name, ean, internal_code, quantity, price_at_order, item_note, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($items as $item) {
            $insertItem->execute([
                $orderId,
                $item['article_name'],
                $item['ean'],
                $item['internal_code'],
                $item['quantity'],
                $item['price_at_order'],
                $item['item_note'],
                $item['status'],
            ]);
        }
        customerOrdersRecalculateStatus($pdo, $orderId);
        customerOrdersEvent($pdo, $orderId, null, $viewer, 'order_created', null, 'registered', [
            'items' => count($items),
            'target_reparto' => $targetDepartment,
        ]);
        $pdo->commit();

        $order = customerOrdersLoad($pdo, $orderId) ?: [];
        customerOrdersNotifyInApp(
            $pdo,
            $order,
            'customer_order_created',
            'Nuovo ordine cliente',
            'Ordine per ' . ((appDepartments()[$targetDepartment] ?? $targetDepartment)) . ' inserito da ' . customerOrdersActorName($viewer) . '.',
            (string) $viewer['cod_fiscale']
        );
        customerOrdersResponse(['ok' => true, 'order_id' => $orderId]);
    }

    if ($action === 'update_order') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $customer = customerOrdersNormalizeCustomer($_POST);
        $pdo->beginTransaction();
        $order = customerOrdersLoadForUpdate($pdo, $orderId);
        if ($order === null || !customerOrdersCanAccess($order, $viewer)) {
            $pdo->rollBack();
            customerOrdersResponse(['ok' => false, 'error' => 'Ordine non trovato o non autorizzato'], 404);
        }
        $pdo->prepare(
            'UPDATE customer_orders
             SET customer_name = ?, customer_surname = ?, customer_phone = ?, general_note = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        )->execute([
            $customer['customer_name'],
            $customer['customer_surname'],
            $customer['customer_phone'],
            $customer['general_note'],
            $orderId,
        ]);
        customerOrdersEvent($pdo, $orderId, null, $viewer, 'order_updated', null, null);
        $pdo->commit();
        customerOrdersResponse(['ok' => true]);
    }

    if ($action === 'add_item') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $item = customerOrdersNormalizeItem($_POST);
        $pdo->beginTransaction();
        $order = customerOrdersLoadForUpdate($pdo, $orderId);
        if ($order === null || !customerOrdersCanAccess($order, $viewer)) {
            $pdo->rollBack();
            customerOrdersResponse(['ok' => false, 'error' => 'Ordine non trovato o non autorizzato'], 404);
        }
        if (in_array((string) $order['status'], ['delivered', 'cancelled'], true)) {
            $pdo->rollBack();
            customerOrdersResponse(['ok' => false, 'error' => 'Ordine già chiuso. Riapertura non disponibile in questa bozza.'], 409);
        }
        $insertItem = $pdo->prepare(
            'INSERT INTO customer_order_items
                (order_id, article_name, ean, internal_code, quantity, price_at_order, item_note, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertItem->execute([
            $orderId,
            $item['article_name'],
            $item['ean'],
            $item['internal_code'],
            $item['quantity'],
            $item['price_at_order'],
            $item['item_note'],
            $item['status'],
        ]);
        $itemId = (int) $pdo->lastInsertId();
        $nextStatus = customerOrdersRecalculateStatus($pdo, $orderId);
        customerOrdersEvent($pdo, $orderId, $itemId, $viewer, 'item_added', null, $item['status'], [
            'article_name' => $item['article_name'],
        ]);
        $pdo->commit();
        $updatedOrder = customerOrdersLoad($pdo, $orderId);
        if ($updatedOrder !== null) {
            customerOrdersNotifyInApp(
                $pdo,
                $updatedOrder,
                'customer_order_updated',
                'Ordine cliente aggiornato',
                'Aggiunto articolo: ' . $item['article_name'] . '.',
                (string) $viewer['cod_fiscale']
            );
        }
        customerOrdersResponse(['ok' => true, 'status' => $nextStatus]);
    }

    if ($action === 'update_item') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $pdo->beginTransaction();
        $current = customerOrdersItemForUpdate($pdo, $itemId);
        if ($current === null || !customerOrdersCanAccess($current, $viewer)) {
            $pdo->rollBack();
            customerOrdersResponse(['ok' => false, 'error' => 'Articolo non trovato o non autorizzato'], 404);
        }
        $next = customerOrdersNormalizeItem($_POST, $current);
        $pdo->prepare(
            'UPDATE customer_order_items
             SET article_name = ?, ean = ?, internal_code = ?, quantity = ?, price_at_order = ?, item_note = ?, status = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        )->execute([
            $next['article_name'],
            $next['ean'],
            $next['internal_code'],
            $next['quantity'],
            $next['price_at_order'],
            $next['item_note'],
            $next['status'],
            $itemId,
        ]);
        $orderStatus = customerOrdersRecalculateStatus($pdo, (int) $current['order_id']);
        customerOrdersEvent(
            $pdo,
            (int) $current['order_id'],
            $itemId,
            $viewer,
            'item_updated',
            (string) $current['status'],
            $next['status'],
            [
                'previous_article_name' => (string) $current['article_name'],
                'article_name' => $next['article_name'],
                'previous_quantity' => (string) $current['quantity'],
                'quantity' => $next['quantity'],
                'previous_price_at_order' => $current['price_at_order'] !== null ? (string) $current['price_at_order'] : null,
                'price_at_order' => $next['price_at_order'],
            ]
        );
        $pdo->commit();
        $updatedOrder = customerOrdersLoad($pdo, (int) $current['order_id']);
        if ($updatedOrder !== null) {
            customerOrdersNotifyInApp(
                $pdo,
                $updatedOrder,
                'customer_order_updated',
                'Ordine cliente aggiornato',
                $next['article_name'] . ': ' . customerOrdersStatusLabel($next['status']) . '.',
                (string) $viewer['cod_fiscale']
            );
        }
        customerOrdersResponse(['ok' => true, 'status' => $orderStatus]);
    }

    customerOrdersResponse(['ok' => false, 'error' => 'Azione non valida'], 400);
} catch (DomainException $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    customerOrdersResponse(['ok' => false, 'error' => $error->getMessage()], $error->getCode() ?: 422);
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Ordini clienti non disponibili: ' . $error->getMessage());
    customerOrdersResponse(['ok' => false, 'error' => 'Ordini clienti temporaneamente non disponibili'], 500);
}

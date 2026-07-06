<?php
declare(strict_types=1);

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

function customerOrdersMetaPayload(array $viewer): array
{
    return [
        'ok' => true,
        'departments' => appCustomerOrderDepartments(),
        'can_choose_department' => customerOrdersCanChooseDepartment($viewer),
        'is_box_operator' => appUserHasBoxInfo($viewer),
        'default_department' => (string) ($viewer['reparto'] ?? ''),
        'item_statuses' => array_map(static fn (string $status): array => [
            'value' => $status,
            'label' => customerOrdersStatusLabel($status),
        ], customerOrdersAllowedItemStatuses()),
    ];
}

function customerOrdersListPayload(PDO $pdo, array $viewer, array $source): array
{
    $status = (string) ($source['status'] ?? 'open');
    $departmentFilter = trim((string) ($source['department'] ?? ''));

    [$orders, $itemsByOrder] = customerOrdersFetchWithItems($pdo, $viewer, $status, $departmentFilter);
    customerOrdersMarkNotificationsRead($pdo, (string) $viewer['cod_fiscale']);

    return [
        'ok' => true,
        'orders' => array_map(
            static fn (array $order): array => customerOrdersFormat($order, $itemsByOrder[(int) $order['id']] ?? [], $viewer),
            $orders
        ),
    ];
}

function customerOrdersCreatePayload(PDO $pdo, array $viewer, array $source): array
{
    $customer = customerOrdersNormalizeCustomer($source);
    $items = customerOrdersDecodeItems((string) ($source['items_json'] ?? ''));
    $sourceDepartment = trim((string) ($viewer['reparto'] ?? ''));
    $targetDepartment = customerOrdersCanChooseDepartment($viewer)
        ? trim((string) ($source['target_reparto'] ?? ''))
        : $sourceDepartment;

    if (!appIsValidCustomerOrderDepartment($targetDepartment)) {
        throw new DomainException('Reparto destinatario non valido', 422);
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

    $order = customerOrdersLoad($pdo, $orderId);
    if ($order !== null) {
        customerOrdersNotifyInApp(
            $pdo,
            $order,
            'customer_order_created',
            'Nuovo ordine cliente',
            'Ordine per ' . ((appDepartments()[$targetDepartment] ?? $targetDepartment)) . ' inserito da ' . customerOrdersActorName($viewer) . '.',
            (string) $viewer['cod_fiscale']
        );
    }

    return ['ok' => true, 'order_id' => $orderId];
}

function customerOrdersUpdateOrderPayload(PDO $pdo, array $viewer, array $source): array
{
    $orderId = (int) ($source['order_id'] ?? 0);
    $customer = customerOrdersNormalizeCustomer($source);

    $pdo->beginTransaction();
    $order = customerOrdersLoadForUpdate($pdo, $orderId);
    if ($order === null || !customerOrdersCanAccess($order, $viewer)) {
        $pdo->rollBack();
        throw new DomainException('Ordine non trovato o non autorizzato', 404);
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

    return ['ok' => true];
}

function customerOrdersAddItemPayload(PDO $pdo, array $viewer, array $source): array
{
    $orderId = (int) ($source['order_id'] ?? 0);
    $item = customerOrdersNormalizeItem($source);

    $pdo->beginTransaction();
    $order = customerOrdersLoadForUpdate($pdo, $orderId);
    if ($order === null || !customerOrdersCanAccess($order, $viewer)) {
        $pdo->rollBack();
        throw new DomainException('Ordine non trovato o non autorizzato', 404);
    }
    if (in_array((string) $order['status'], ['delivered', 'cancelled'], true)) {
        $pdo->rollBack();
        throw new DomainException('Ordine già chiuso. Riapertura non disponibile in questa bozza.', 409);
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

    return ['ok' => true, 'status' => $nextStatus];
}

function customerOrdersUpdateItemPayload(PDO $pdo, array $viewer, array $source): array
{
    $itemId = (int) ($source['item_id'] ?? 0);

    $pdo->beginTransaction();
    $current = customerOrdersItemForUpdate($pdo, $itemId);
    if ($current === null || !customerOrdersCanAccess($current, $viewer)) {
        $pdo->rollBack();
        throw new DomainException('Articolo non trovato o non autorizzato', 404);
    }
    $next = customerOrdersNormalizeItem($source, $current);
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

    return ['ok' => true, 'status' => $orderStatus];
}

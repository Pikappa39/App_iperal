<?php
declare(strict_types=1);

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

function customerOrdersFetchWithItems(PDO $pdo, array $viewer, string $status, string $departmentFilter): array
{
    $where = [];
    $params = [];
    if (customerOrdersCanChooseDepartment($viewer)) {
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

    return [$orders, $itemsByOrder];
}

function customerOrdersMarkNotificationsRead(PDO $pdo, string $viewerCf): void
{
    $pdo->prepare('UPDATE customer_order_notifications SET read_at = CURRENT_TIMESTAMP WHERE BINARY recipient_cf = BINARY ? AND read_at IS NULL')
        ->execute([$viewerCf]);
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

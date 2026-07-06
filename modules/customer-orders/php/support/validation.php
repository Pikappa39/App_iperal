<?php
declare(strict_types=1);

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

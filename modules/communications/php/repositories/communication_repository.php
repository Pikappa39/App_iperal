<?php
declare(strict_types=1);

function communicationFetchInbox(PDO $pdo, string $viewerCf): array
{
    $stmt = $pdo->prepare(
        "SELECT c.id, c.title, c.message, c.priority, c.created_at, r.read_at, r.acknowledged_at,
                TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS author_name
         FROM communication_recipients r
         JOIN communications c ON c.id = r.communication_id
         LEFT JOIN utenti u ON u.cod_fiscale = c.author_cf
         WHERE r.recipient_cf = ?
         ORDER BY (r.acknowledged_at IS NULL) DESC, c.created_at DESC
         LIMIT 100"
    );
    $stmt->execute([$viewerCf]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function communicationMarkInboxRead(PDO $pdo, string $viewerCf): void
{
    $pdo->prepare('UPDATE communication_recipients SET read_at = NOW() WHERE recipient_cf = ? AND read_at IS NULL')
        ->execute([$viewerCf]);
}

function communicationFetchManageableUsers(PDO $pdo, int $viewerRole, string $viewerDepartment): array
{
    $query = 'SELECT cod_fiscale, nome, cognome, reparto FROM utenti WHERE attivo = 1';
    $params = [];
    if (in_array($viewerRole, [1, 2], true)) {
        $query .= ' AND reparto = ?';
        $params[] = $viewerDepartment;
    }
    $query .= ' ORDER BY cognome, nome';

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function communicationFetchSent(PDO $pdo, int $viewerRole, string $viewerCf): array
{
    $query =
        "SELECT c.id, c.title, c.message, c.priority, c.created_at,
                COUNT(r.recipient_cf) AS recipients,
                SUM(r.read_at IS NOT NULL) AS read_count,
                SUM(r.acknowledged_at IS NOT NULL) AS acknowledged_count
         FROM communications c
         JOIN communication_recipients r ON r.communication_id = c.id";
    $params = [];
    if (in_array($viewerRole, [1, 2], true)) {
        $query .= ' WHERE c.author_cf = ?';
        $params[] = $viewerCf;
    }
    $query .= ' GROUP BY c.id ORDER BY c.created_at DESC LIMIT 100';

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function communicationAcknowledge(PDO $pdo, int $communicationId, string $viewerCf): bool
{
    $stmt = $pdo->prepare(
        'UPDATE communication_recipients SET read_at = COALESCE(read_at, NOW()), acknowledged_at = NOW()
         WHERE communication_id = ? AND recipient_cf = ?'
    );
    $stmt->execute([$communicationId, $viewerCf]);
    return $stmt->rowCount() > 0;
}

function communicationFetchDepartmentRecipients(PDO $pdo, string $department): array
{
    $stmt = $pdo->prepare('SELECT cod_fiscale FROM utenti WHERE reparto = ? AND attivo = 1');
    $stmt->execute([$department]);
    return array_map(static fn (array $row): string => (string) $row['cod_fiscale'], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function communicationCreate(PDO $pdo, string $authorCf, string $title, string $message, string $priority, array $recipients): int
{
    $pdo->beginTransaction();
    $insertCommunication = $pdo->prepare('INSERT INTO communications (author_cf, title, message, priority) VALUES (?, ?, ?, ?)');
    $insertCommunication->execute([$authorCf, $title, $message, $priority]);
    $communicationId = (int) $pdo->lastInsertId();

    $insertRecipient = $pdo->prepare('INSERT INTO communication_recipients (communication_id, recipient_cf) VALUES (?, ?)');
    foreach ($recipients as $recipientCf) {
        $insertRecipient->execute([$communicationId, (string) $recipientCf]);
    }
    $pdo->commit();

    return $communicationId;
}
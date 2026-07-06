<?php
declare(strict_types=1);

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

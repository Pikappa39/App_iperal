<?php
declare(strict_types=1);

function scheduleExtraHoursLoad(PDO $pdo, int $id, bool $forUpdate = false): ?array
{
    $sql =
        "SELECT e.*, TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS user_name,
                TRIM(CONCAT(COALESCE(od.nome, ''), ' ', COALESCE(od.cognome, ''))) AS origin_decided_by_name,
                TRIM(CONCAT(COALESCE(td.nome, ''), ' ', COALESCE(td.cognome, ''))) AS target_decided_by_name
         FROM extra_hour_requests e
         LEFT JOIN utenti u ON BINARY u.cod_fiscale = BINARY e.user_cf
         LEFT JOIN utenti od ON BINARY od.cod_fiscale = BINARY e.origin_decided_by_cf
         LEFT JOIN utenti td ON BINARY td.cod_fiscale = BINARY e.target_decided_by_cf
         WHERE e.id = ?
         LIMIT 1";
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $statement = $pdo->prepare($sql);
    $statement->execute([$id]);
    $request = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($request) ? $request : null;
}

function scheduleExtraHoursQueryData(PDO $pdo, string $where, array $params, int $viewerRole, string $viewerDepartment): array
{
    $statement = $pdo->prepare(
        "SELECT e.*, TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS user_name,
                TRIM(CONCAT(COALESCE(od.nome, ''), ' ', COALESCE(od.cognome, ''))) AS origin_decided_by_name,
                TRIM(CONCAT(COALESCE(td.nome, ''), ' ', COALESCE(td.cognome, ''))) AS target_decided_by_name
         FROM extra_hour_requests e
         LEFT JOIN utenti u ON BINARY u.cod_fiscale = BINARY e.user_cf
         LEFT JOIN utenti od ON BINARY od.cod_fiscale = BINARY e.origin_decided_by_cf
         LEFT JOIN utenti td ON BINARY td.cod_fiscale = BINARY e.target_decided_by_cf
         WHERE {$where}
         ORDER BY FIELD(e.status, 'pending', 'recorded', 'approved', 'rejected'), e.schedule_date DESC, e.created_at DESC
         LIMIT 150"
    );
    $statement->execute($params);

    return array_map(
        static fn (array $row): array => scheduleExtraHourRequestData($row, $viewerRole, $viewerDepartment),
        $statement->fetchAll(PDO::FETCH_ASSOC)
    );
}

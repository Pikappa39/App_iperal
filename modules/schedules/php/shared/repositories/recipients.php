<?php
declare(strict_types=1);

function appScheduleAdjustmentManagerRecipients(PDO $pdo, string $department): array
{
    $statement = $pdo->prepare(
        'SELECT cod_fiscale
         FROM utenti
         WHERE attivo = 1
           AND (capo = 3 OR (reparto = ? AND capo = 1))'
    );
    $statement->execute([$department]);
    return array_map(static fn (array $row): string => (string) $row['cod_fiscale'], $statement->fetchAll(PDO::FETCH_ASSOC));
}

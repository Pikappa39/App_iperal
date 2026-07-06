<?php
declare(strict_types=1);

function noteViewer(array $sessionUser, PDO $pdo): array
{
    $userName = trim(((string) ($sessionUser['nome'] ?? '')) . ' ' . ((string) ($sessionUser['cognome'] ?? '')));
    $userKey = trim((string) ($sessionUser['cf'] ?? ''));
    $capo = (int) ($sessionUser['capo'] ?? 0);
    $viewerDepartment = trim((string) ($sessionUser['reparto'] ?? ''));
    $userDepartments = [];

    if (in_array($capo, [1, 2], true)) {
        $departmentQuery = $pdo->query('SELECT cod_fiscale, reparto FROM utenti WHERE attivo = 1');
        foreach ($departmentQuery->fetchAll(PDO::FETCH_ASSOC) as $userDepartment) {
            $userDepartments[(string) $userDepartment['cod_fiscale']] = (string) ($userDepartment['reparto'] ?? '');
        }
    }

    return [
        'userName' => $userName,
        'userKey' => $userKey !== '' ? $userKey : ($userName !== '' ? $userName : session_id()),
        'capo' => $capo,
        'department' => $viewerDepartment,
        'userDepartments' => $userDepartments,
    ];
}

function noteCanViewEntry(array $entry, array $viewer): bool
{
    $entryUserKey = (string) ($entry['userKey'] ?? '');
    if ($entryUserKey === (string) $viewer['userKey'] || (int) $viewer['capo'] === 3) {
        return true;
    }

    return in_array((int) $viewer['capo'], [1, 2], true)
        && (string) $viewer['department'] !== ''
        && (($viewer['userDepartments'][$entryUserKey] ?? '') === (string) $viewer['department']);
}

function noteFilterForViewer(array $notes, array $viewer): array
{
    foreach ($notes as $dateKey => $entries) {
        if (!is_array($entries)) {
            $notes[$dateKey] = [];
            continue;
        }

        $notes[$dateKey] = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => noteCanViewEntry($entry, $viewer)
        ));
        if ($notes[$dateKey] === []) {
            unset($notes[$dateKey]);
        }
    }

    return $notes;
}
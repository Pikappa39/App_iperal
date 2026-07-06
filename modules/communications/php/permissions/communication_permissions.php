<?php
declare(strict_types=1);

function communicationIsManager(int $viewerRole): bool
{
    return in_array($viewerRole, [1, 2, 3], true);
}

function communicationCanManageUser(PDO $pdo, string $userCf, int $viewerRole, string $viewerDepartment): bool
{
    if ($viewerRole === 3) {
        return true;
    }
    if (!in_array($viewerRole, [1, 2], true) || $viewerDepartment === '') {
        return false;
    }

    $stmt = $pdo->prepare('SELECT reparto FROM utenti WHERE cod_fiscale = ? AND attivo = 1 LIMIT 1');
    $stmt->execute([$userCf]);
    return (string) $stmt->fetchColumn() === $viewerDepartment;
}

function communicationCanUseDepartment(int $viewerRole, string $viewerDepartment, string $targetDepartment): bool
{
    if ($viewerRole === 3) {
        return appIsValidDepartment($targetDepartment);
    }

    return in_array($viewerRole, [1, 2], true)
        && $viewerDepartment !== ''
        && $viewerDepartment === $targetDepartment;
}
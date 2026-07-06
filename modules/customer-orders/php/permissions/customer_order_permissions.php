<?php
declare(strict_types=1);

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

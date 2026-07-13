<?php
require dirname(__DIR__) . '/app_config.php';
require dirname(__DIR__) . '/session_bootstrap.php';
app_session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
require_once dirname(__DIR__) . '/connection_files/connection.php';
require_once dirname(__DIR__) . '/connection_files/invite_lib.php';
require dirname(__DIR__) . '/gestore_ods/orario_converter_lib.php';
require_once dirname(__DIR__) . '/modules/users/php/addetti/page_context.php';

$capo = (int) ($_SESSION['user']['capo'] ?? 0);
if (!isset($_SESSION['user']) || !in_array($capo, [1, 2, 3], true)) {
    header('Location: index.php');
    exit;
}
$addettiContext = appAddettiBuildPageContext($pdo instanceof PDO ? $pdo : null, (bool) $connessione, $_SESSION['user'], $_GET);
extract($addettiContext, EXTR_OVERWRITE);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione addetti</title>
    <script>
    (function () {
        try {
            document.documentElement.dataset.theme = localStorage.getItem("app-iperal-theme") === "dark" ? "dark" : "light";
        } catch (error) {
            document.documentElement.dataset.theme = "light";
        }
    })();
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="sfera.css?v=<?php echo rawurlencode(APP_VERSION); ?>">
</head>
<body>
<main class="app-shell">
    <header class="d-flex align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Addetti</h1>
            <p class="text-muted mb-0">
                <?php echo $isGlobalAdmin ? 'Gestisci gli addetti di tutti i reparti.' : 'Reparto: ' . appAddettiEscape($repartoLabel); ?>
            </p>
        </div>
        <a class="btn btn-outline-dark" href="index.php">Indietro</a>
    </header>

    <?php if (isset($_GET['updated'])): ?>
        <div class="alert alert-success">Associazione salvata. Aggiornate <?php echo (int) $_GET['updated']; ?> righe negli orari già caricati.</div>
    <?php elseif (isset($_GET['deleted'])): ?>
        <div class="alert alert-success">Associazione eliminata. Ripulite <?php echo (int) $_GET['deleted']; ?> righe negli orari già caricati.</div>
    <?php elseif (isset($_GET['error'])): ?>
        <div class="alert alert-danger">Non è stato possibile salvare l'associazione. Riprova.</div>
    <?php endif; ?>

    <?php if ($databaseError): ?>
        <div class="alert alert-danger">Impossibile caricare gli addetti. Riprova più tardi.</div>
    <?php else: ?>
        <?php if ($isGlobalAdmin): ?>
            <section class="card shadow-sm mb-4">
                <div class="card-body">
                    <form method="get" class="row g-3 align-items-end">
                        <div class="col-md-6 col-lg-4">
                            <label class="form-label" for="reparto">Filtra per reparto</label>
                            <select class="form-select" id="reparto" name="reparto" onchange="this.form.submit()">
                                <?php foreach (appDepartments() as $code => $label): ?>
                                    <option value="<?php echo appAddettiEscape($code); ?>"<?php echo $code === $reparto ? ' selected' : ''; ?>>
                                        <?php echo appAddettiEscape($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 col-lg-4">
                            <button type="submit" class="btn btn-outline-dark">Mostra reparto</button>
                        </div>
                    </form>
                </div>
            </section>
        <?php endif; ?>

        <?php if (is_array($inviteFlash) && isset($inviteFlash['message'], $inviteFlash['type'])): ?>
            <div class="alert alert-<?php echo appAddettiEscape((string) $inviteFlash['type']); ?>">
                <div><?php echo appAddettiEscape((string) $inviteFlash['message']); ?></div>
                <?php if (!empty($inviteFlash['link'])): ?>
                    <div class="mt-2 d-flex flex-column gap-2">
                        <label for="inviteLinkField" class="form-label mb-0">Link di invito</label>
                        <div class="input-group">
                            <input id="inviteLinkField" class="form-control" type="text" readonly value="<?php echo appAddettiEscape((string) $inviteFlash['link']); ?>">
                            <button type="button" class="btn btn-outline-dark" data-copy-target="#inviteLinkField">Copia</button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (is_array($userManagementFlash) && isset($userManagementFlash['message'], $userManagementFlash['type'])): ?>
            <div class="alert alert-<?php echo appAddettiEscape((string) $userManagementFlash['type']); ?>">
                <?php echo appAddettiEscape((string) $userManagementFlash['message']); ?>
            </div>
        <?php endif; ?>

        <?php if ($canInvite): ?>
        <section class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3 mb-3">
                    <div>
                        <h2 class="h5 mb-1">Invita un dipendente</h2>
                        <p class="text-muted mb-0">Invia un link personale via email. Il dipendente completerà da solo la password.</p>
                    </div>
                </div>
                <form action="connection_files/manage_invites.php" method="post" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?php echo appAddettiEscape($appCsrfToken); ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="col-md-6">
                        <label class="form-label" for="inviteNome">Nome</label>
                        <input class="form-control" type="text" id="inviteNome" name="nome" maxlength="100" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="inviteCognome">Cognome</label>
                        <input class="form-control" type="text" id="inviteCognome" name="cognome" maxlength="100" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="inviteEmail">Email</label>
                        <input class="form-control" type="email" id="inviteEmail" name="email" maxlength="255" autocomplete="email" required>
                    </div>
                    <div class="col-md-4">
                        <p class="form-text mt-4 mb-0">Il dipendente dovrà solo scegliere la password aprendo il link.</p>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="inviteCapo">Ruolo</label>
                        <select class="form-select" id="inviteCapo" name="capo" required>
                            <?php foreach (appInviteAllowedRoles($_SESSION['user']) as $role): ?>
                                <option value="<?php echo (int) $role; ?>"><?php echo appAddettiEscape(appInviteRoleLabel((int) $role)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($capo === 3): ?>
                        <div class="col-md-6">
                            <label class="form-label" for="inviteReparto">Reparto</label>
                            <select class="form-select" id="inviteReparto" name="reparto" required>
                                <option value="" selected disabled>Seleziona reparto</option>
                                <?php foreach (appDepartments() as $code => $label): ?>
                                    <option value="<?php echo appAddettiEscape($code); ?>"><?php echo appAddettiEscape($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <div class="col-md-6">
                            <label class="form-label" for="inviteRepartoLabel">Reparto</label>
                            <input class="form-control" type="text" id="inviteRepartoLabel" value="<?php echo appAddettiEscape($repartoLabel); ?>" readonly>
                            <input type="hidden" name="reparto" value="<?php echo appAddettiEscape($reparto); ?>">
                        </div>
                    <?php endif; ?>
                    <div class="col-md-6<?php echo appInviteCanAssignBoxInfo($_SESSION['user'], $capo === 3 ? '' : $reparto) ? '' : ' d-none'; ?>" id="inviteBoxInfoField">
                        <div class="form-check mt-4">
                            <input class="form-check-input" type="checkbox" id="inviteBoxInfo" name="box_info" value="1">
                            <label class="form-check-label" for="inviteBoxInfo">
                                Abilita anche al box informazioni
                            </label>
                            <div class="form-text">Da usare per addette box/casse. Una cassiera semplice va lasciata senza questa spunta.</div>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">Invia invito</button>
                    </div>
                </form>

                <?php if ($isGlobalAdmin): ?>
                    <hr class="my-4">

                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3 mb-3">
                        <div>
                            <h3 class="h6 mb-1">Inviti di test</h3>
                            <p class="text-muted mb-0">Invia una mail di prova usando lo stesso trasporto reale, senza creare record in <code>user_invites</code>.</p>
                        </div>
                    </div>
                    <form action="connection_files/manage_invites.php" method="post" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?php echo appAddettiEscape($appCsrfToken); ?>">
                        <input type="hidden" name="action" value="send_test">
                        <div class="col-md-5">
                            <label class="form-label" for="testInviteEmail">Email destinataria</label>
                            <input class="form-control" type="email" id="testInviteEmail" name="email" maxlength="255" autocomplete="email" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="testInviteNome">Nome visualizzato</label>
                            <input class="form-control" type="text" id="testInviteNome" name="nome" maxlength="100" placeholder="Facoltativo">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="testInviteReparto">Reparto simulato</label>
                            <select class="form-select" id="testInviteReparto" name="reparto">
                                <option value="">Nessun reparto</option>
                                <?php foreach (appDepartments() as $code => $label): ?>
                                    <option value="<?php echo appAddettiEscape($code); ?>"<?php echo $code === $reparto ? ' selected' : ''; ?>>
                                        <?php echo appAddettiEscape($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-outline-primary">Invia email di test</button>
                        </div>
                    </form>
                <?php endif; ?>

                <hr class="my-4">

                <h3 class="h6">Inviti recenti</h3>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Dipendente</th>
                            <th>Reparto</th>
                            <th>Ruolo</th>
                            <th>Creato</th>
                            <th>Scadenza</th>
                            <th>Stato</th>
                            <th>Azioni</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($invites as $invite): ?>
                            <?php $status = appInviteStatus($invite); ?>
                            <tr>
                                <td>
                                    <strong><?php echo appAddettiEscape(trim((string) $invite['invited_nome'] . ' ' . (string) $invite['invited_cognome'])); ?></strong><br>
                                    <span class="text-muted"><?php echo appAddettiEscape((string) $invite['invited_email']); ?></span>
                                </td>
                                <td><?php echo appAddettiEscape(appDepartments()[(string) $invite['reparto']] ?? (string) $invite['reparto']); ?></td>
                                <td>
                                    <?php echo appAddettiEscape(appInviteRoleLabel((int) ($invite['invited_capo'] ?? 0))); ?>
                                    <?php if (appInviteHasBoxInfoPrivilege($invite)): ?>
                                        <br><span class="badge text-bg-info">Box info</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo appAddettiEscape(date('d/m/Y H:i', strtotime((string) $invite['created_at']))); ?></td>
                                <td><?php echo appAddettiEscape(date('d/m/Y H:i', strtotime((string) $invite['expires_at']))); ?></td>
                                <td><?php echo appAddettiEscape(appInviteStatusLabel($invite)); ?></td>
                                <td>
                                    <?php if ($status === 'pending'): ?>
                                        <div class="d-flex flex-wrap gap-2">
                                            <form action="connection_files/manage_invites.php" method="post">
                                                <input type="hidden" name="csrf_token" value="<?php echo appAddettiEscape($appCsrfToken); ?>">
                                                <input type="hidden" name="action" value="regenerate">
                                                <input type="hidden" name="invite_id" value="<?php echo (int) $invite['id']; ?>">
                                                <button type="submit" class="btn btn-outline-dark btn-sm">Reinvia invito</button>
                                            </form>
                                            <form action="connection_files/manage_invites.php" method="post">
                                                <input type="hidden" name="csrf_token" value="<?php echo appAddettiEscape($appCsrfToken); ?>">
                                                <input type="hidden" name="action" value="revoke">
                                                <input type="hidden" name="invite_id" value="<?php echo (int) $invite['id']; ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm">Revoca</button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">Nessuna azione</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($invites === []): ?>
                            <tr><td colspan="7" class="text-muted">Non ci sono ancora inviti creati.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <section class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5">Utenti registrati</h2>
                <p class="text-muted">Sono inclusi gli utenti del reparto selezionato, anche se non hanno ancora un nominativo negli orari.</p>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Utente</th>
                            <?php if ($isGlobalAdmin): ?>
                                <th>Reparto</th>
                            <?php endif; ?>
                            <?php if ($canViewLastSeen): ?>
                                <th>Ultima attività</th>
                            <?php endif; ?>
                            <?php if ($isGroceryDepartment): ?>
                                <th>Gruppo Grocery</th>
                            <?php endif; ?>
                            <?php if ($isGlobalAdmin): ?>
                                <th>Stato</th>
                                <th>Box info</th>
                            <?php endif; ?>
                            <th>Nominativi negli orari</th>
                            <?php if ($isGlobalAdmin): ?>
                                <th>Gestione account</th>
                            <?php endif; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($users as $user): ?>
                            <?php
                            $userCf = (string) $user['cod_fiscale'];
                            $scheduleUserNames = $namesByUser[$userCf] ?? [];
                            $userHasImplicitBox = (string) ($user['reparto'] ?? '') === 'box'
                                || ((int) ($user['capo'] ?? 0) === 1 && (string) ($user['reparto'] ?? '') === 'cs');
                            $userHasBoxInfo = appUserHasBoxInfo($user);
                            ?>
                            <tr>
                                <td><?php echo appAddettiEscape(trim((string) $user['nome'] . ' ' . (string) $user['cognome'])); ?></td>
                                <?php if ($isGlobalAdmin): ?>
                                    <td><?php echo appAddettiEscape(appDepartments()[(string) $user['reparto']] ?? (string) $user['reparto']); ?></td>
                                <?php endif; ?>
                                <?php if ($canViewLastSeen): ?>
                                    <td><?php echo appAddettiEscape(appAddettiLastSeenLabel($user['last_seen'] ?? null)); ?></td>
                                <?php endif; ?>
                                <?php if ($isGroceryDepartment): ?>
                                    <td>
                                        <form action="connection_files/manage_users.php" method="post" class="d-flex gap-2 align-items-center">
                                            <input type="hidden" name="csrf_token" value="<?php echo appAddettiEscape($appCsrfToken); ?>">
                                            <input type="hidden" name="reparto" value="<?php echo appAddettiEscape($reparto); ?>">
                                            <input type="hidden" name="action" value="set_department_group">
                                            <input type="hidden" name="user_cf" value="<?php echo appAddettiEscape($userCf); ?>">
                                            <?php echo appAddettiGroceryGroupSelect('department_group', (string) ($user['department_group'] ?? ''), 'user-group-' . $userCf); ?>
                                            <button type="submit" class="btn btn-outline-dark btn-sm">Salva</button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                                <?php if ($isGlobalAdmin): ?>
                                    <td>
                                        <?php if ((int) ($user['attivo'] ?? 1) === 1): ?>
                                            <span class="badge text-bg-success">Attivo</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">Disattivato</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($userHasImplicitBox): ?>
                                            <span class="badge text-bg-info">Automatico</span>
                                        <?php elseif ($userHasBoxInfo): ?>
                                            <span class="badge text-bg-info">Abilitato</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">No</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td><?php echo $scheduleUserNames === [] ? '<span class="text-muted">Nessuno</span>' : appAddettiEscape(implode(', ', $scheduleUserNames)); ?></td>
                                <?php if ($isGlobalAdmin): ?>
                                    <td>
                                        <?php if ((int) ($user['capo'] ?? 0) === 3): ?>
                                            <span class="text-muted">Admin protetto</span>
                                        <?php elseif ((int) ($user['attivo'] ?? 1) === 1): ?>
                                            <div class="d-grid gap-2">
                                                <?php if (!$userHasImplicitBox): ?>
                                                    <form action="connection_files/manage_users.php" method="post">
                                                        <input type="hidden" name="csrf_token" value="<?php echo appAddettiEscape($appCsrfToken); ?>">
                                                        <input type="hidden" name="reparto" value="<?php echo appAddettiEscape($reparto); ?>">
                                                        <input type="hidden" name="action" value="set_box_info">
                                                        <input type="hidden" name="user_cf" value="<?php echo appAddettiEscape($userCf); ?>">
                                                        <input type="hidden" name="box_info" value="<?php echo $userHasBoxInfo ? '0' : '1'; ?>">
                                                        <button type="submit" class="btn btn-outline-info btn-sm">
                                                            <?php echo $userHasBoxInfo ? 'Disabilita box' : 'Abilita box'; ?>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <form action="connection_files/manage_users.php" method="post" onsubmit="return confirm('Disattivare questo account? L’utente verrà scollegato e non riceverà notifiche.');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo appAddettiEscape($appCsrfToken); ?>">
                                                    <input type="hidden" name="reparto" value="<?php echo appAddettiEscape($reparto); ?>">
                                                    <input type="hidden" name="action" value="deactivate">
                                                    <input type="hidden" name="user_cf" value="<?php echo appAddettiEscape($userCf); ?>">
                                                    <button type="submit" class="btn btn-outline-warning btn-sm">Disattiva</button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <div class="d-grid gap-2">
                                                <form action="connection_files/manage_users.php" method="post">
                                                    <input type="hidden" name="csrf_token" value="<?php echo appAddettiEscape($appCsrfToken); ?>">
                                                    <input type="hidden" name="reparto" value="<?php echo appAddettiEscape($reparto); ?>">
                                                    <input type="hidden" name="action" value="reactivate">
                                                    <input type="hidden" name="user_cf" value="<?php echo appAddettiEscape($userCf); ?>">
                                                    <button type="submit" class="btn btn-outline-success btn-sm">Riattiva</button>
                                                </form>
                                                <details>
                                                    <summary class="text-danger small">Elimina definitivamente</summary>
                                                    <form action="connection_files/manage_users.php" method="post" class="mt-2" onsubmit="return confirm('Eliminazione definitiva: dati personali, note, associazioni e notifiche saranno rimossi.');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo appAddettiEscape($appCsrfToken); ?>">
                                                        <input type="hidden" name="reparto" value="<?php echo appAddettiEscape($reparto); ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="user_cf" value="<?php echo appAddettiEscape($userCf); ?>">
                                                        <label class="form-label small" for="deleteConfirm-<?php echo appAddettiEscape($userCf); ?>">Digita: ELIMINA <?php echo appAddettiEscape($userCf); ?></label>
                                                        <input class="form-control form-control-sm mb-2" id="deleteConfirm-<?php echo appAddettiEscape($userCf); ?>" type="text" name="confirmation" autocomplete="off" required>
                                                        <button type="submit" class="btn btn-danger btn-sm">Conferma eliminazione</button>
                                                    </form>
                                                </details>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($users === []): ?>
                            <tr><td colspan="<?php echo 2 + ($isGlobalAdmin ? 4 : 0) + ($canViewLastSeen ? 1 : 0) + ($isGroceryDepartment ? 1 : 0); ?>" class="text-muted">Non ci sono utenti registrati in questo reparto.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5">Associazioni esistenti</h2>
                <p class="text-muted">Puoi correggere l'utente associato a un nominativo oppure eliminare l'associazione se è storica o buggata. Uno stesso addetto può avere più varianti del nome negli orari.</p>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Nominativo nell'orario</th><th>Associato a</th><?php if ($isGroceryDepartment): ?><th>Gruppo Grocery</th><?php endif; ?><th>Modifica associazione</th><th>Elimina</th></tr></thead>
                        <tbody>
                        <?php foreach ($mappedScheduleRows as $row): ?>
                            <?php $mappedUser = $usersByCf[$row['user_cf']]; ?>
                            <tr>
                                <td><?php echo appAddettiEscape($row['name']); ?></td>
                                <td><?php echo appAddettiEscape(trim((string) $mappedUser['nome'] . ' ' . (string) $mappedUser['cognome'])); ?></td>
                                <?php if ($isGroceryDepartment): ?>
                                    <td><?php echo appAddettiEscape(appAddettiDepartmentGroupLabel((string) ($row['department_group'] ?? ''))); ?></td>
                                <?php endif; ?>
                                <td>
                                    <form action="connection_files/save_schedule_mapping.php" method="post" class="d-flex gap-2 align-items-center">
                                        <input type="hidden" name="action" value="save">
                                        <input type="hidden" name="csrf_token" value="<?php echo appAddettiEscape($csrfToken); ?>">
                                        <input type="hidden" name="reparto" value="<?php echo appAddettiEscape($reparto); ?>">
                                        <input type="hidden" name="schedule_name" value="<?php echo appAddettiEscape($row['key']); ?>">
                                        <select class="form-select form-select-sm" name="user_cf" required aria-label="Nuovo utente per <?php echo appAddettiEscape($row['name']); ?>">
                                            <?php foreach ($assignableUsers as $user): ?>
                                                <?php $userCf = (string) $user['cod_fiscale']; ?>
                                                <option value="<?php echo appAddettiEscape($userCf); ?>"<?php echo $userCf === $row['user_cf'] ? ' selected' : ''; ?>><?php echo appAddettiEscape(trim((string) $user['nome'] . ' ' . (string) $user['cognome'])); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($isGroceryDepartment): ?>
                                            <?php echo appAddettiGroceryGroupSelect('department_group', (string) ($row['department_group'] ?? ''), 'mapped-group-' . $row['key']); ?>
                                        <?php endif; ?>
                                        <button type="submit" class="btn btn-outline-dark btn-sm">Aggiorna</button>
                                    </form>
                                </td>
                                <td>
                                    <form action="connection_files/save_schedule_mapping.php" method="post" onsubmit="return confirm('Eliminare questa associazione? Il nominativo tornerà tra quelli da gestire e verrà sganciato anche dagli orari già caricati.');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="csrf_token" value="<?php echo appAddettiEscape($csrfToken); ?>">
                                        <input type="hidden" name="reparto" value="<?php echo appAddettiEscape($reparto); ?>">
                                        <input type="hidden" name="schedule_name" value="<?php echo appAddettiEscape($row['key']); ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">Elimina</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($mappedScheduleRows === []): ?>
                            <tr><td colspan="<?php echo $isGroceryDepartment ? 5 : 4; ?>" class="text-muted">Non ci sono ancora associazioni salvate.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Nominativi degli orari da gestire</h2>
                <p class="text-muted">Qui compaiono i nominativi non collegati a un utente registrato, inclusi quelli segnati come non registrati.</p>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Nominativo nell'orario</th><th>Stato</th><?php if ($isGroceryDepartment): ?><th>Gruppo Grocery</th><?php endif; ?><th>Associa a</th><th>Escludi</th></tr></thead>
                        <tbody>
                        <?php foreach ($scheduleOnlyRows as $row): ?>
                            <?php $assignFormId = 'schedule-assign-' . hash('sha256', (string) $row['key']); ?>
                            <tr>
                                <td><?php echo appAddettiEscape($row['name']); ?></td>
                                <td><?php echo appAddettiEscape($row['status']); ?></td>
                                <?php if ($isGroceryDepartment): ?>
                                    <td><?php echo appAddettiGroceryGroupSelect('department_group', (string) ($row['department_group'] ?? ''), 'schedule-group-' . hash('sha256', (string) $row['key']), ['form' => $assignFormId]); ?></td>
                                <?php endif; ?>
                                <td>
                                    <form id="<?php echo appAddettiEscape($assignFormId); ?>" action="connection_files/save_schedule_mapping.php" method="post" class="d-flex gap-2 align-items-center">
                                        <input type="hidden" name="action" value="save">
                                        <input type="hidden" name="csrf_token" value="<?php echo appAddettiEscape($csrfToken); ?>">
                                        <input type="hidden" name="reparto" value="<?php echo appAddettiEscape($reparto); ?>">
                                        <input type="hidden" name="schedule_name" value="<?php echo appAddettiEscape($row['key']); ?>">
                                        <select class="form-select form-select-sm" name="user_cf" required aria-label="Utente da associare a <?php echo appAddettiEscape($row['name']); ?>">
                                            <option value="">Seleziona utente…</option>
                                            <?php foreach ($assignableUsers as $user): ?>
                                                <?php $userCf = (string) $user['cod_fiscale']; ?>
                                                <option value="<?php echo appAddettiEscape($userCf); ?>"><?php echo appAddettiEscape(trim((string) $user['nome'] . ' ' . (string) $user['cognome'])); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-primary btn-sm">Associa</button>
                                    </form>
                                </td>
                                <td>
                                    <form action="connection_files/save_schedule_mapping.php" method="post" onsubmit="return confirm('Escludere questo nominativo dalla lista? Verrà nascosto da questa schermata finché non ricomparirà in un nuovo caricamento orario.');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="csrf_token" value="<?php echo appAddettiEscape($csrfToken); ?>">
                                        <input type="hidden" name="reparto" value="<?php echo appAddettiEscape($reparto); ?>">
                                        <input type="hidden" name="schedule_name" value="<?php echo appAddettiEscape($row['key']); ?>">
                                        <button type="submit" class="btn btn-outline-secondary btn-sm">Escludi</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($scheduleOnlyRows === []): ?>
                            <tr><td colspan="<?php echo $isGroceryDepartment ? 5 : 4; ?>" class="text-muted">Non ci sono nominativi in attesa di associazione.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    <?php endif; ?>
</main>
<script src="assets/js/modules/users/addetti.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
</body>
</html>

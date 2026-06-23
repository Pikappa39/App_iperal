<?php
require __DIR__ . '/app_config.php';
require __DIR__ . '/session_bootstrap.php';
app_session_start();
require_once __DIR__ . '/connection_files/connection.php';
require_once __DIR__ . '/connection_files/invite_lib.php';
require __DIR__ . '/gestore_ods/orario_converter_lib.php';

$capo = (int) ($_SESSION['user']['capo'] ?? 0);
if (!isset($_SESSION['user']) || !in_array($capo, [1, 2, 3], true)) {
    header('Location: index.php');
    exit;
}
$canViewLastSeen = $capo === 3;
$isGlobalAdmin = $capo === 3;
$canInvite = appInviteCanManage($_SESSION['user']);

function appAddettiEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function appAddettiLastSeenLabel($value): string
{
    if (!is_string($value) || trim($value) === '') {
        return 'Mai rilevato';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return 'Dato non valido';
    }

    $diff = time() - $timestamp;
    if ($diff < 0) {
        $diff = 0;
    }

    if ($diff < 120) {
        return 'Attivo adesso';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . ' min fa';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . ' h fa';
    }

    return date('d/m/Y H:i', $timestamp);
}

if (empty($_SESSION['schedule_mapping_csrf'])) {
    $_SESSION['schedule_mapping_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['schedule_mapping_csrf'];
$appCsrfToken = app_csrf_token();

$sessionReparto = trim((string) ($_SESSION['user']['reparto'] ?? ''));
$requestedReparto = trim((string) ($_GET['reparto'] ?? ''));
$reparto = $isGlobalAdmin && appIsValidDepartment($requestedReparto)
    ? $requestedReparto
    : $sessionReparto;
$repartoLabel = appDepartments()[$reparto] ?? 'non assegnato';
$users = [];
$mappings = [];
$invites = [];
$databaseError = !$connessione || !($pdo instanceof PDO);
$inviteFlash = $_SESSION['invite_flash'] ?? null;
unset($_SESSION['invite_flash']);

if (!$databaseError) {
    $userStatement = $pdo->prepare(
        'SELECT cod_fiscale, nome, cognome, reparto, last_seen
         FROM utenti
         WHERE reparto = ?
         ORDER BY cognome, nome, cod_fiscale'
    );
    $userStatement->execute([$reparto]);
    $users = $userStatement->fetchAll(PDO::FETCH_ASSOC);

    $mappingStatement = $pdo->prepare(
        'SELECT schedule_name, user_cf, updated_at
         FROM schedule_name_mappings
         WHERE reparto = ?
         ORDER BY schedule_name'
    );
    $mappingStatement->execute([$reparto]);
    $mappings = $mappingStatement->fetchAll(PDO::FETCH_ASSOC);

    $inviteQuery = null;
    if ($canInvite) {
        if ($capo === 3) {
            $inviteQuery = $pdo->query(
                'SELECT *
                 FROM user_invites
                 ORDER BY created_at DESC
                 LIMIT 30'
            );
        } else {
            $inviteQuery = $pdo->prepare(
                'SELECT *
                 FROM user_invites
                 WHERE reparto = ?
                   AND invited_by_cf = ?
                 ORDER BY created_at DESC
                 LIMIT 30'
            );
            $inviteQuery->execute([$reparto, (string) ($_SESSION['user']['cf'] ?? '')]);
        }
    }
    $invites = $inviteQuery ? $inviteQuery->fetchAll(PDO::FETCH_ASSOC) : [];
}

$usersByCf = [];
foreach ($users as $user) {
    $usersByCf[(string) $user['cod_fiscale']] = $user;
}

// Include anche i nominativi presenti negli orari storici, caricati prima
// dell'introduzione della tabella delle associazioni.
$scheduleNames = [];
$jsonFiles = glob(__DIR__ . '/turni_json/*-' . $reparto . '.json') ?: [];
foreach ($jsonFiles as $jsonFile) {
    $decoded = json_decode((string) @file_get_contents($jsonFile), true);
    if (!is_array($decoded)) {
        continue;
    }
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $displayName = trim((string) ($row['ADDETTO'] ?? ''));
        $key = normalizzaChiaveAddetto($displayName);
        if ($key !== '' && !isset($scheduleNames[$key])) {
            $scheduleNames[$key] = $displayName;
        }
    }
}

$namesByUser = [];
$mappedScheduleRows = [];
$scheduleOnlyRows = [];
$mappedKeys = [];
foreach ($mappings as $mapping) {
    $key = (string) $mapping['schedule_name'];
    $userCf = (string) $mapping['user_cf'];
    $mappedKeys[$key] = true;
    $scheduleName = $scheduleNames[$key] ?? $key;

    if (isset($usersByCf[$userCf])) {
        $namesByUser[$userCf][] = $scheduleName;
        $mappedScheduleRows[] = [
            'key' => $key,
            'name' => $scheduleName,
            'user_cf' => $userCf,
        ];
        continue;
    }

    $scheduleOnlyRows[] = [
        'key' => $key,
        'name' => $scheduleName,
        'status' => $userCf === '__UNREGISTERED__' ? 'Utente non registrato' : 'Associazione da verificare',
    ];
}

foreach ($scheduleNames as $key => $scheduleName) {
    if (isset($mappedKeys[$key])) {
        continue;
    }
    $scheduleOnlyRows[] = [
        'key' => $key,
        'name' => $scheduleName,
        'status' => 'Da associare',
    ];
}

usort($scheduleOnlyRows, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
usort($mappedScheduleRows, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione addetti</title>
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
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">Invia invito</button>
                    </div>
                </form>

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
                                <td><?php echo appAddettiEscape(appInviteRoleLabel((int) ($invite['invited_capo'] ?? 0))); ?></td>
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
                <p class="text-muted">Sono inclusi tutti gli utenti del reparto selezionato, anche se non hanno ancora un nominativo negli orari.</p>
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
                            <th>Nominativi negli orari</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($users as $user): ?>
                            <?php $userCf = (string) $user['cod_fiscale']; $scheduleUserNames = $namesByUser[$userCf] ?? []; ?>
                            <tr>
                                <td><?php echo appAddettiEscape(trim((string) $user['nome'] . ' ' . (string) $user['cognome'])); ?></td>
                                <?php if ($isGlobalAdmin): ?>
                                    <td><?php echo appAddettiEscape(appDepartments()[(string) $user['reparto']] ?? (string) $user['reparto']); ?></td>
                                <?php endif; ?>
                                <?php if ($canViewLastSeen): ?>
                                    <td><?php echo appAddettiEscape(appAddettiLastSeenLabel($user['last_seen'] ?? null)); ?></td>
                                <?php endif; ?>
                                <td><?php echo $scheduleUserNames === [] ? '<span class="text-muted">Nessuno</span>' : appAddettiEscape(implode(', ', $scheduleUserNames)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($users === []): ?>
                            <tr><td colspan="<?php echo $isGlobalAdmin ? '4' : '2'; ?>" class="text-muted">Non ci sono utenti registrati in questo reparto.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5">Associazioni esistenti</h2>
                <p class="text-muted">Puoi correggere l'utente associato a un nominativo. Uno stesso addetto può avere più varianti del nome negli orari.</p>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Nominativo nell'orario</th><th>Associato a</th><th>Modifica associazione</th></tr></thead>
                        <tbody>
                        <?php foreach ($mappedScheduleRows as $row): ?>
                            <?php $mappedUser = $usersByCf[$row['user_cf']]; ?>
                            <tr>
                                <td><?php echo appAddettiEscape($row['name']); ?></td>
                                <td><?php echo appAddettiEscape(trim((string) $mappedUser['nome'] . ' ' . (string) $mappedUser['cognome'])); ?></td>
                                <td>
                                    <form action="connection_files/save_schedule_mapping.php" method="post" class="d-flex gap-2 align-items-center">
                                        <input type="hidden" name="csrf_token" value="<?php echo appAddettiEscape($csrfToken); ?>">
                                        <input type="hidden" name="reparto" value="<?php echo appAddettiEscape($reparto); ?>">
                                        <input type="hidden" name="schedule_name" value="<?php echo appAddettiEscape($row['key']); ?>">
                                        <select class="form-select form-select-sm" name="user_cf" required aria-label="Nuovo utente per <?php echo appAddettiEscape($row['name']); ?>">
                                            <?php foreach ($users as $user): ?>
                                                <?php $userCf = (string) $user['cod_fiscale']; ?>
                                                <option value="<?php echo appAddettiEscape($userCf); ?>"<?php echo $userCf === $row['user_cf'] ? ' selected' : ''; ?>><?php echo appAddettiEscape(trim((string) $user['nome'] . ' ' . (string) $user['cognome'])); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-outline-dark btn-sm">Aggiorna</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($mappedScheduleRows === []): ?>
                            <tr><td colspan="3" class="text-muted">Non ci sono ancora associazioni salvate.</td></tr>
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
                        <thead><tr><th>Nominativo nell'orario</th><th>Stato</th><th>Associa a</th></tr></thead>
                        <tbody>
                        <?php foreach ($scheduleOnlyRows as $row): ?>
                            <tr>
                                <td><?php echo appAddettiEscape($row['name']); ?></td>
                                <td><?php echo appAddettiEscape($row['status']); ?></td>
                                <td>
                                    <form action="connection_files/save_schedule_mapping.php" method="post" class="d-flex gap-2 align-items-center">
                                        <input type="hidden" name="csrf_token" value="<?php echo appAddettiEscape($csrfToken); ?>">
                                        <input type="hidden" name="reparto" value="<?php echo appAddettiEscape($reparto); ?>">
                                        <input type="hidden" name="schedule_name" value="<?php echo appAddettiEscape($row['key']); ?>">
                                        <select class="form-select form-select-sm" name="user_cf" required aria-label="Utente da associare a <?php echo appAddettiEscape($row['name']); ?>">
                                            <option value="">Seleziona utente…</option>
                                            <?php foreach ($users as $user): ?>
                                                <?php $userCf = (string) $user['cod_fiscale']; ?>
                                                <option value="<?php echo appAddettiEscape($userCf); ?>"><?php echo appAddettiEscape(trim((string) $user['nome'] . ' ' . (string) $user['cognome'])); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-primary btn-sm">Associa</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($scheduleOnlyRows === []): ?>
                            <tr><td colspan="3" class="text-muted">Non ci sono nominativi in attesa di associazione.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    <?php endif; ?>
</main>
<script>
document.querySelectorAll("[data-copy-target]").forEach(function (button) {
    button.addEventListener("click", async function () {
        const field = document.querySelector(button.getAttribute("data-copy-target"));
        if (!field) {
            return;
        }

        try {
            await navigator.clipboard.writeText(field.value);
            button.textContent = "Copiato";
            window.setTimeout(function () {
                button.textContent = "Copia";
            }, 1500);
        } catch (error) {
            field.focus();
            field.select();
        }
    });
});
</script>
</body>
</html>

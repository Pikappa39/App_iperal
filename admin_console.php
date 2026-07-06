<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/app_config.php';
require __DIR__ . '/session_bootstrap.php';
app_session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/connection_files/connection.php';
require_once __DIR__ . '/connection_files/admin_audit_lib.php';
require_once __DIR__ . '/connection_files/invite_lib.php';
require_once __DIR__ . '/connection_files/push_lib.php';

$sessionUser = $_SESSION['user'] ?? null;
if (!is_array($sessionUser) || (int) ($sessionUser['capo'] ?? 0) !== 3) {
    header('Location: index.php', true, 303);
    exit;
}

require_once __DIR__ . '/modules/users/php/admin/console_helpers.php';
$codeHash = appAdminConsoleCodeHash();
$consoleConfigured = $codeHash !== '';
$unlocked = appAdminConsoleIsUnlocked($sessionUser);
$appCsrfToken = app_csrf_token();
$flash = $_SESSION['admin_console_flash'] ?? null;
unset($_SESSION['admin_console_flash']);
$departmentFilter = trim((string) ($_GET['reparto'] ?? ''));
if ($departmentFilter !== '' && !appIsValidDepartment($departmentFilter)) {
    $departmentFilter = '';
}
$inviteStatusFilter = trim((string) ($_GET['stato'] ?? ''));
if (!in_array($inviteStatusFilter, ['', 'pending', 'accepted', 'expired', 'revoked'], true)) {
    $inviteStatusFilter = '';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!app_csrf_request_is_valid()) {
        appAdminConsoleFlash('danger', 'Richiesta non valida. Ricarica la pagina e riprova.');
        appAdminConsoleRedirect();
    }

    if ($action === 'unlock') {
        if (!$consoleConfigured) {
            appAdminConsoleFlash('danger', 'Codice console non configurato sul server.');
            appAdminConsoleRedirect();
        }

        $code = (string) ($_POST['console_code'] ?? '');
        if (!password_verify($code, $codeHash)) {
            appAdminConsoleLock();
            appAdminAuditLog($pdo, $sessionUser, 'console_unlock_failed', 'admin_console', null, [
                'reason' => 'invalid_code',
            ]);
            appAdminConsoleFlash('danger', 'Codice console non corretto.');
            appAdminConsoleRedirect();
        }

        session_regenerate_id(true);
        $_SESSION['admin_console_until'] = time() + appAdminConsoleTimeoutSeconds();
        $_SESSION['admin_console_cf'] = (string) ($sessionUser['cf'] ?? '');
        appAdminAuditLog($pdo, $sessionUser, 'console_unlock', 'admin_console', null, [
            'expires_at' => date('c', (int) $_SESSION['admin_console_until']),
        ]);
        appAdminConsoleFlash('success', 'Console admin sbloccata.');
        appAdminConsoleRedirect();
    }

    if ($action === 'lock') {
        appAdminConsoleLock();
        appAdminAuditLog($pdo, $sessionUser, 'console_lock', 'admin_console');
        appAdminConsoleFlash('success', 'Console admin bloccata.');
        appAdminConsoleRedirect();
    }

    if (!$unlocked) {
        appAdminConsoleFlash('danger', 'Sblocca la console prima di eseguire questa azione.');
        appAdminConsoleRedirect();
    }
    if (!$connessione || !($pdo instanceof PDO)) {
        appAdminConsoleFlash('danger', 'Database non disponibile. Riprova più tardi.');
        appAdminConsoleRedirect();
    }

    $redirectParams = [];
    $postedDepartment = trim((string) ($_POST['reparto'] ?? ''));
    if (appIsValidDepartment($postedDepartment)) {
        $redirectParams['reparto'] = $postedDepartment;
    }
    $postedStatus = trim((string) ($_POST['stato'] ?? ''));
    if (in_array($postedStatus, ['pending', 'accepted', 'expired', 'revoked'], true)) {
        $redirectParams['stato'] = $postedStatus;
    }

    $inviteId = (int) ($_POST['invite_id'] ?? 0);
    try {
        if ($action === 'delete_revoked_invites') {
            $deleteSql = 'DELETE FROM user_invites WHERE revoked_at IS NOT NULL';
            $deleteParams = [];
            if ($postedDepartment !== '') {
                $deleteSql .= ' AND reparto = ?';
                $deleteParams[] = $postedDepartment;
            }

            $pdo->beginTransaction();
            $deleteStatement = $pdo->prepare($deleteSql);
            $deleteStatement->execute($deleteParams);
            $deletedCount = $deleteStatement->rowCount();
            $pdo->commit();

            appAdminAuditLog($pdo, $sessionUser, 'revoked_invites_deleted', 'user_invite', null, [
                'reparto' => $postedDepartment,
                'deleted_count' => $deletedCount,
            ]);
            appAdminConsoleFlash(
                $deletedCount > 0 ? 'success' : 'info',
                $deletedCount === 1
                    ? '1 invito revocato eliminato.'
                    : $deletedCount . ' inviti revocati eliminati.'
            );
            appAdminConsoleRedirect($redirectParams);
        }

        if ($inviteId <= 0 || !in_array($action, ['manual_invite_link', 'revoke_invite'], true)) {
            throw new RuntimeException('Operazione non valida.');
        }

        $pdo->beginTransaction();
        if ($action === 'revoke_invite') {
            $invite = appInviteRevokeLocked($pdo, $inviteId, $sessionUser);
            $pdo->commit();
            appAdminAuditLog($pdo, $sessionUser, 'invite_revoked', 'user_invite', (string) $inviteId, [
                'email' => (string) ($invite['invited_email'] ?? ''),
                'reparto' => (string) ($invite['reparto'] ?? ''),
                'status' => appInviteStatus($invite),
            ]);
            appAdminConsoleFlash('success', 'Invito revocato.');
            appAdminConsoleRedirect($redirectParams);
        }

        $regenerated = appInviteRegenerateLocked($pdo, $inviteId, $sessionUser);
        $pdo->commit();
        $invite = $regenerated['invite'];
        appAdminAuditLog($pdo, $sessionUser, 'invite_manual_link_generated', 'user_invite', (string) $inviteId, [
            'email' => (string) ($invite['invited_email'] ?? ''),
            'reparto' => (string) ($invite['reparto'] ?? ''),
            'status' => appInviteStatus($invite),
        ]);

        appAdminConsoleFlash(
            'success',
            'Nuovo link manuale generato. Questo link non verrà mostrato di nuovo.',
            (string) $regenerated['link']
        );
        appAdminConsoleRedirect($redirectParams);
    } catch (Throwable $error) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Console admin: azione invito non riuscita: ' . $error->getMessage());
        appAdminConsoleFlash('danger', $error->getMessage());
        appAdminConsoleRedirect($redirectParams);
    }
}

$users = [];
$mappings = [];
$invites = [];
$auditLogs = [];
$auditLogError = false;
$revokedInvitesCount = 0;
$diagnostics = [];
$diagnosticSummary = ['ok' => 0, 'warning' => 0, 'danger' => 0, 'info' => 0];
$performanceReport = [
    'available' => false,
    'path' => app_performance_log_path(),
    'hours' => 24,
    'requests' => 0,
    'error_count' => 0,
    'endpoints' => [],
    'slow_requests' => [],
];
$associationIssues = [];
$associationIssueSummary = ['danger' => 0, 'warning' => 0, 'info' => 0];
$namesByUser = [];
$stats = [
    'active_users' => 0,
    'mappings' => 0,
    'pending_invites' => 0,
];
$databaseError = !$connessione || !($pdo instanceof PDO);

if ($unlocked && !$databaseError) {
    $userSql =
        'SELECT cod_fiscale, nome, cognome, email, reparto, capo, last_seen
         FROM utenti
         WHERE attivo = 1';
    $userParams = [];
    if ($departmentFilter !== '') {
        $userSql .= ' AND reparto = ?';
        $userParams[] = $departmentFilter;
    }
    $userSql .= ' ORDER BY reparto, cognome, nome, cod_fiscale';
    $userStatement = $pdo->prepare($userSql);
    $userStatement->execute($userParams);
    $users = $userStatement->fetchAll(PDO::FETCH_ASSOC);

    $mappingSql =
        "SELECT m.reparto, m.schedule_name, m.user_cf, m.updated_at,
                u.nome, u.cognome, u.email, u.attivo
         FROM schedule_name_mappings m
         LEFT JOIN utenti u ON u.cod_fiscale = m.user_cf
         WHERE 1 = 1";
    $mappingParams = [];
    if ($departmentFilter !== '') {
        $mappingSql .= ' AND m.reparto = ?';
        $mappingParams[] = $departmentFilter;
    }
    $mappingSql .= ' ORDER BY m.reparto, m.schedule_name';
    $mappingStatement = $pdo->prepare($mappingSql);
    $mappingStatement->execute($mappingParams);
    $mappings = $mappingStatement->fetchAll(PDO::FETCH_ASSOC);

    foreach ($mappings as $mapping) {
        $userCf = (string) ($mapping['user_cf'] ?? '');
        if ($userCf !== '' && !in_array($userCf, [APP_SCHEDULE_MAPPING_IGNORED_VALUE, '__UNREGISTERED__'], true)) {
            $namesByUser[$userCf][] = (string) ($mapping['schedule_name'] ?? '');
        }
    }

    $inviteSql =
        "SELECT i.*,
                TRIM(CONCAT(COALESCE(author.nome, ''), ' ', COALESCE(author.cognome, ''))) AS author_name,
                TRIM(CONCAT(COALESCE(accepted.nome, ''), ' ', COALESCE(accepted.cognome, ''))) AS accepted_name
         FROM user_invites i
         LEFT JOIN utenti author ON author.cod_fiscale = i.invited_by_cf
         LEFT JOIN utenti accepted ON accepted.cod_fiscale = i.accepted_user_cf";
    $inviteParams = [];
    if ($departmentFilter !== '') {
        $inviteSql .= ' WHERE i.reparto = ?';
        $inviteParams[] = $departmentFilter;
    }
    $inviteSql .= ' ORDER BY i.created_at DESC LIMIT 200';
    $inviteStatement = $pdo->prepare($inviteSql);
    $inviteStatement->execute($inviteParams);
    $inviteRows = $inviteStatement->fetchAll(PDO::FETCH_ASSOC);
    foreach ($inviteRows as $invite) {
        $status = appInviteStatus($invite);
        if ($inviteStatusFilter !== '' && $status !== $inviteStatusFilter) {
            continue;
        }
        $invite['computed_status'] = $status;
        $invites[] = $invite;
    }

    $revokedCountSql = 'SELECT COUNT(*) FROM user_invites WHERE revoked_at IS NOT NULL';
    $revokedCountParams = [];
    if ($departmentFilter !== '') {
        $revokedCountSql .= ' AND reparto = ?';
        $revokedCountParams[] = $departmentFilter;
    }
    $revokedCountStatement = $pdo->prepare($revokedCountSql);
    $revokedCountStatement->execute($revokedCountParams);
    $revokedInvitesCount = (int) $revokedCountStatement->fetchColumn();

    $stats['active_users'] = count($users);
    $stats['mappings'] = count($mappings);
    $stats['pending_invites'] = count(array_filter($inviteRows, static fn (array $invite): bool => appInviteStatus($invite) === 'pending'));
    $associationIssues = appAdminConsoleBuildAssociationIssues($mappings);
    foreach ($associationIssues as $issue) {
        $severity = (string) ($issue['severity'] ?? 'info');
        if (array_key_exists($severity, $associationIssueSummary)) {
            $associationIssueSummary[$severity]++;
        }
    }
    $diagnostics = appAdminConsoleBuildDiagnostics($pdo, $consoleConfigured);
    $diagnosticSummary = appAdminConsoleDiagnosticSummary($diagnostics);
    $performanceReport = appAdminConsoleBuildPerformanceReport(24);

    try {
        $auditStatement = $pdo->query(
            "SELECT l.id, l.actor_cf, l.action, l.target_type, l.target_id, l.details_json, l.created_at,
                    TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS actor_name
             FROM admin_audit_log l
             LEFT JOIN utenti u ON u.cod_fiscale = l.actor_cf
             ORDER BY l.created_at DESC
             LIMIT 80"
        );
        $auditLogs = $auditStatement ? $auditStatement->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $auditError) {
        $auditLogError = true;
        error_log('Console admin: audit non leggibile: ' . $auditError->getMessage());
    }
}

$expiresAt = (int) ($_SESSION['admin_console_until'] ?? 0);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Console admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="sfera.css?v=<?php echo rawurlencode(APP_VERSION); ?>">
    <link rel="stylesheet" href="assets/css/modules/admin-console.css?v=<?php echo rawurlencode(APP_VERSION); ?>">
</head>
<body>
<main class="app-shell admin-console">
    <header class="admin-console-hero">
        <div>
            <p class="admin-console-eyebrow">Area riservata</p>
            <h1>Console admin</h1>
            <p class="admin-console-subtitle">Supervisione tecnica di utenti, associazioni e inviti.</p>
        </div>
        <div class="admin-console-hero__actions">
            <?php if ($unlocked): ?>
                <div class="admin-console-timer" aria-label="Tempo rimanente nella console">
                    <span class="admin-console-timer__icon" aria-hidden="true">Time</span>
                    <span data-admin-console-countdown data-expires-at="<?php echo (int) $expiresAt; ?>">--:--</span>
                </div>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo appAdminConsoleEscape($appCsrfToken); ?>">
                    <input type="hidden" name="action" value="lock">
                    <button type="submit" class="btn btn-outline-dark">Blocca</button>
                </form>
            <?php endif; ?>
            <a class="btn btn-outline-dark" href="index.php">Home</a>
        </div>
    </header>

    <?php if (is_array($flash) && isset($flash['message'], $flash['type'])): ?>
        <div class="alert alert-<?php echo appAdminConsoleEscape((string) $flash['type']); ?>">
            <div><?php echo appAdminConsoleEscape((string) $flash['message']); ?></div>
            <?php if (!empty($flash['link'])): ?>
                <div class="mt-3">
                    <label for="adminConsoleInviteLink" class="form-label">Link invito manuale</label>
                    <div class="input-group">
                        <input id="adminConsoleInviteLink" class="form-control" type="text" readonly value="<?php echo appAdminConsoleEscape((string) $flash['link']); ?>">
                        <button type="button" class="btn btn-outline-dark" data-copy-target="#adminConsoleInviteLink">Copia</button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!$unlocked): ?>
        <section class="admin-console-lock">
            <div class="admin-console-lock__panel">
                <h2>Codice console</h2>
                <p>Inserisci il codice riservato per aprire la sezione amministrativa.</p>
                <?php if (!$consoleConfigured): ?>
                    <div class="alert alert-warning">
                        Configura <code>APP_ADMIN_CONSOLE_CODE_HASH</code> in <code>app_local_env.php</code> prima di usare la console.
                    </div>
                <?php endif; ?>
                <form method="post" class="admin-console-lock__form">
                    <input type="hidden" name="csrf_token" value="<?php echo appAdminConsoleEscape($appCsrfToken); ?>">
                    <input type="hidden" name="action" value="unlock">
                    <label class="form-label" for="consoleCode">Codice</label>
                    <input class="form-control form-control-lg" type="password" id="consoleCode" name="console_code" autocomplete="one-time-code" required <?php echo $consoleConfigured ? '' : 'disabled'; ?>>
                    <button type="submit" class="btn btn-primary btn-lg" <?php echo $consoleConfigured ? '' : 'disabled'; ?>>Entra</button>
                </form>
            </div>
        </section>
    <?php elseif ($databaseError): ?>
        <div class="alert alert-danger">Database non disponibile. Riprova più tardi.</div>
    <?php else: ?>
        <section class="admin-console-stats" aria-label="Riepilogo console">
            <article>
                <span>Utenti attivi</span>
                <strong><?php echo (int) $stats['active_users']; ?></strong>
            </article>
            <article>
                <span>Associazioni</span>
                <strong><?php echo (int) $stats['mappings']; ?></strong>
            </article>
            <article>
                <span>Inviti in attesa</span>
                <strong><?php echo (int) $stats['pending_invites']; ?></strong>
            </article>
        </section>

        <details class="admin-console-section" data-admin-console-panel open>
            <summary class="admin-console-section__summary">
                <span>
                    <strong>Performance server</strong>
                    <small>
                        ultime <?php echo (int) $performanceReport['hours']; ?>h
                        · <?php echo (int) $performanceReport['requests']; ?> richieste
                        · <?php echo (int) $performanceReport['error_count']; ?> errori
                    </small>
                </span>
            </summary>
            <div class="admin-console-section__body">
                <?php if (!$performanceReport['available']): ?>
                    <div class="alert alert-info mb-0">
                        Non ci sono ancora dati performance sufficienti. Le nuove richieste PHP verranno registrate in <code><?php echo appAdminConsoleEscape((string) $performanceReport['path']); ?></code>.
                    </div>
                <?php else: ?>
                    <div class="table-responsive mb-4">
                        <table class="table align-middle admin-console-table">
                            <thead>
                            <tr>
                                <th>Endpoint</th>
                                <th>Richieste</th>
                                <th>POST</th>
                                <th>Media</th>
                                <th>P95</th>
                                <th>Max</th>
                                <th>Errori</th>
                                <th>Memoria max</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($performanceReport['endpoints'] as $endpoint): ?>
                                <tr data-admin-console-row data-search-text="<?php echo appAdminConsoleEscape(appAdminConsoleSearchText([
                                    $endpoint['route'] ?? '',
                                    'performance server',
                                ])); ?>">
                                    <td><strong><?php echo appAdminConsoleEscape((string) $endpoint['route']); ?></strong></td>
                                    <td><?php echo (int) $endpoint['count']; ?></td>
                                    <td><?php echo (int) $endpoint['posts']; ?></td>
                                    <td><?php echo appAdminConsoleEscape(appAdminConsoleMsLabel($endpoint['avg_ms'] ?? 0)); ?></td>
                                    <td><?php echo appAdminConsoleEscape(appAdminConsoleMsLabel($endpoint['p95_ms'] ?? 0)); ?></td>
                                    <td><?php echo appAdminConsoleEscape(appAdminConsoleMsLabel($endpoint['max_ms'] ?? 0)); ?></td>
                                    <td><?php echo (int) $endpoint['errors']; ?></td>
                                    <td><?php echo appAdminConsoleEscape(appAdminConsoleBytesLabel(((int) ($endpoint['memory_peak_kb'] ?? 0)) * 1024)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <h3 class="h6">Richieste lente recenti</h3>
                    <div class="table-responsive">
                        <table class="table align-middle admin-console-table">
                            <thead>
                            <tr>
                                <th>Quando</th>
                                <th>Endpoint</th>
                                <th>Metodo</th>
                                <th>HTTP</th>
                                <th>Durata</th>
                                <th>Memoria</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($performanceReport['slow_requests'] as $request): ?>
                                <tr data-admin-console-row data-search-text="<?php echo appAdminConsoleEscape(appAdminConsoleSearchText([
                                    $request['route'] ?? '',
                                    $request['method'] ?? '',
                                    $request['status'] ?? '',
                                    'richiesta lenta performance',
                                ])); ?>">
                                    <td><?php echo appAdminConsoleEscape(appAdminConsoleDateLabel($request['ts'] ?? null)); ?></td>
                                    <td><strong><?php echo appAdminConsoleEscape((string) $request['route']); ?></strong></td>
                                    <td><?php echo appAdminConsoleEscape((string) $request['method']); ?></td>
                                    <td><?php echo (int) $request['status']; ?></td>
                                    <td><?php echo appAdminConsoleEscape(appAdminConsoleMsLabel($request['duration_ms'] ?? 0)); ?></td>
                                    <td><?php echo appAdminConsoleEscape(appAdminConsoleBytesLabel(((int) ($request['memory_peak_kb'] ?? 0)) * 1024)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($performanceReport['slow_requests'] === []): ?>
                                <tr><td colspan="6" class="text-muted">Nessuna richiesta oltre 500 ms nelle ultime ore analizzate.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </details>

        <section class="admin-console-search">
            <label class="form-label" for="adminConsoleSearch">Ricerca globale</label>
            <input class="form-control form-control-lg" id="adminConsoleSearch" type="search" placeholder="Cerca utenti, email, nominativi Excel, inviti, audit..." data-admin-console-search autocomplete="off">
            <p class="admin-console-search__status" data-admin-console-search-status></p>
        </section>

        <details class="admin-console-section" data-admin-console-panel>
            <summary class="admin-console-section__summary">
                <span>
                    <strong>Diagnostica sistema</strong>
                    <small><?php echo count($diagnostics); ?> controlli</small>
                </span>
            </summary>
            <div class="admin-console-section__body">
            <div class="admin-console-health-summary" aria-label="Riepilogo diagnostica">
                <article class="admin-console-health-summary__ok">
                    <span>OK</span>
                    <strong><?php echo (int) $diagnosticSummary['ok']; ?></strong>
                </article>
                <article class="admin-console-health-summary__warning">
                    <span>Attenzioni</span>
                    <strong><?php echo (int) $diagnosticSummary['warning']; ?></strong>
                </article>
                <article class="admin-console-health-summary__danger">
                    <span>Errori</span>
                    <strong><?php echo (int) $diagnosticSummary['danger']; ?></strong>
                </article>
                <article class="admin-console-health-summary__info">
                    <span>Info</span>
                    <strong><?php echo (int) $diagnosticSummary['info']; ?></strong>
                </article>
            </div>
            <div class="table-responsive">
                <table class="table align-middle admin-console-table">
                    <thead>
                    <tr>
                        <th>Area</th>
                        <th>Controllo</th>
                        <th>Stato</th>
                        <th>Valore</th>
                        <th>Dettaglio</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($diagnostics as $diagnostic): ?>
                        <?php $status = (string) ($diagnostic['status'] ?? 'info'); ?>
                        <tr data-admin-console-row data-search-text="<?php echo appAdminConsoleEscape(appAdminConsoleSearchText($diagnostic)); ?>">
                            <td><?php echo appAdminConsoleEscape((string) $diagnostic['area']); ?></td>
                            <td><strong><?php echo appAdminConsoleEscape((string) $diagnostic['name']); ?></strong></td>
                            <td><span class="badge <?php echo appAdminConsoleEscape(appAdminConsoleDiagnosticClass($status)); ?>"><?php echo appAdminConsoleEscape(appAdminConsoleDiagnosticLabel($status)); ?></span></td>
                            <td><?php echo appAdminConsoleEscape((string) $diagnostic['value']); ?></td>
                            <td><?php echo appAdminConsoleEscape((string) ($diagnostic['detail'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($diagnostics === []): ?>
                        <tr><td colspan="5" class="text-muted">Diagnostica non disponibile.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            </div>
        </details>

        <section class="admin-console-filters">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-5 col-lg-4">
                    <label class="form-label" for="reparto">Reparto</label>
                    <select class="form-select" id="reparto" name="reparto">
                        <option value="">Tutti i reparti</option>
                        <?php foreach (appDepartments() as $code => $label): ?>
                            <option value="<?php echo appAdminConsoleEscape($code); ?>"<?php echo $code === $departmentFilter ? ' selected' : ''; ?>>
                                <?php echo appAdminConsoleEscape($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5 col-lg-4">
                    <label class="form-label" for="stato">Stato inviti</label>
                    <select class="form-select" id="stato" name="stato">
                        <option value="">Tutti gli stati</option>
                        <?php foreach (['pending' => 'In attesa', 'accepted' => 'Attivato', 'expired' => 'Scaduto', 'revoked' => 'Revocato'] as $status => $label): ?>
                            <option value="<?php echo appAdminConsoleEscape($status); ?>"<?php echo $status === $inviteStatusFilter ? ' selected' : ''; ?>>
                                <?php echo appAdminConsoleEscape($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-lg-2">
                    <button type="submit" class="btn btn-outline-dark w-100">Filtra</button>
                </div>
            </form>
        </section>

        <details class="admin-console-section" data-admin-console-panel>
            <summary class="admin-console-section__summary">
                <span>
                    <strong>Utenti attivi</strong>
                    <small><?php echo count($users); ?> utenti</small>
                </span>
            </summary>
            <div class="admin-console-section__body">
            <div class="table-responsive">
                <table class="table align-middle admin-console-table">
                    <thead>
                    <tr>
                        <th>Utente</th>
                        <th>Reparto</th>
                        <th>Ruolo</th>
                        <th>Ultima attività</th>
                        <th>Nominativi associati</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $user): ?>
                        <?php $userCf = (string) $user['cod_fiscale']; ?>
                        <?php $scheduleNames = $namesByUser[$userCf] ?? []; ?>
                        <tr data-admin-console-row data-search-text="<?php echo appAdminConsoleEscape(appAdminConsoleSearchText([
                            $user['nome'] ?? '',
                            $user['cognome'] ?? '',
                            $user['email'] ?? '',
                            $user['reparto'] ?? '',
                            appAdminConsoleRoleLabel((int) $user['capo']),
                            implode(' ', $scheduleNames),
                        ])); ?>">
                            <td>
                                <strong><?php echo appAdminConsoleEscape(trim((string) $user['nome'] . ' ' . (string) $user['cognome'])); ?></strong><br>
                                <span class="text-muted"><?php echo appAdminConsoleEscape((string) $user['email']); ?></span>
                            </td>
                            <td><?php echo appAdminConsoleEscape(appDepartments()[(string) $user['reparto']] ?? (string) $user['reparto']); ?></td>
                            <td><?php echo appAdminConsoleEscape(appAdminConsoleRoleLabel((int) $user['capo'])); ?></td>
                            <td><?php echo appAdminConsoleEscape(appAdminConsoleLastSeenLabel($user['last_seen'] ?? null)); ?></td>
                            <td>
                                <?php if ($scheduleNames === []): ?>
                                    <span class="text-muted">Nessuno</span>
                                <?php else: ?>
                                    <div class="admin-console-tags">
                                        <?php foreach (array_slice($scheduleNames, 0, 4) as $name): ?>
                                            <span><?php echo appAdminConsoleEscape($name); ?></span>
                                        <?php endforeach; ?>
                                        <?php if (count($scheduleNames) > 4): ?>
                                            <span>+<?php echo count($scheduleNames) - 4; ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($users === []): ?>
                        <tr><td colspan="5" class="text-muted">Nessun utente attivo trovato.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            </div>
        </details>

        <details class="admin-console-section" data-admin-console-panel>
            <summary class="admin-console-section__summary">
                <span>
                    <strong>Problemi associazioni</strong>
                    <small>
                        <?php echo count($associationIssues); ?> segnalazioni
                        <?php if ($associationIssues !== []): ?>
                            · <?php echo (int) $associationIssueSummary['danger']; ?> critiche
                            · <?php echo (int) $associationIssueSummary['warning']; ?> attenzioni
                        <?php endif; ?>
                    </small>
                </span>
            </summary>
            <div class="admin-console-section__body">
            <div class="table-responsive">
                <table class="table align-middle admin-console-table">
                    <thead>
                    <tr>
                        <th>Tipo</th>
                        <th>Nominativo</th>
                        <th>Reparto</th>
                        <th>Dettaglio</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($associationIssues as $issue): ?>
                        <?php $severity = (string) ($issue['severity'] ?? 'info'); ?>
                        <tr data-admin-console-row data-search-text="<?php echo appAdminConsoleEscape(appAdminConsoleSearchText($issue)); ?>">
                            <td><span class="badge <?php echo appAdminConsoleEscape(appAdminConsoleDiagnosticClass($severity)); ?>"><?php echo appAdminConsoleEscape((string) $issue['type']); ?></span></td>
                            <td><strong><?php echo appAdminConsoleEscape((string) $issue['schedule_name']); ?></strong></td>
                            <td><?php echo appAdminConsoleEscape(appDepartments()[(string) $issue['reparto']] ?? (string) $issue['reparto']); ?></td>
                            <td><?php echo appAdminConsoleEscape((string) $issue['detail']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($associationIssues === []): ?>
                        <tr><td colspan="4" class="text-muted">Nessun problema associazioni rilevato nei filtri attuali.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            </div>
        </details>

        <details class="admin-console-section" data-admin-console-panel>
            <summary class="admin-console-section__summary">
                <span>
                    <strong>Associazioni orari</strong>
                    <small><?php echo count($mappings); ?> associazioni</small>
                </span>
            </summary>
            <div class="admin-console-section__body">
            <div class="table-responsive">
                <table class="table align-middle admin-console-table">
                    <thead>
                    <tr>
                        <th>Nome negli orari</th>
                        <th>Reparto</th>
                        <th>Account collegato</th>
                        <th>Aggiornata</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($mappings as $mapping): ?>
                        <?php
                        $userCf = (string) ($mapping['user_cf'] ?? '');
                        $isIgnored = $userCf === APP_SCHEDULE_MAPPING_IGNORED_VALUE;
                        $isUnregistered = $userCf === '__UNREGISTERED__';
                        $linkedName = trim((string) ($mapping['nome'] ?? '') . ' ' . (string) ($mapping['cognome'] ?? ''));
                        ?>
                        <tr data-admin-console-row data-search-text="<?php echo appAdminConsoleEscape(appAdminConsoleSearchText([
                            $mapping['schedule_name'] ?? '',
                            $mapping['reparto'] ?? '',
                            $mapping['user_cf'] ?? '',
                            $linkedName,
                            $mapping['email'] ?? '',
                            $isIgnored ? 'ignorato' : '',
                            $isUnregistered ? 'non registrato' : '',
                        ])); ?>">
                            <td><strong><?php echo appAdminConsoleEscape((string) $mapping['schedule_name']); ?></strong></td>
                            <td><?php echo appAdminConsoleEscape(appDepartments()[(string) $mapping['reparto']] ?? (string) $mapping['reparto']); ?></td>
                            <td>
                                <?php if ($isIgnored): ?>
                                    <span class="badge text-bg-secondary">Ignorato</span>
                                <?php elseif ($isUnregistered): ?>
                                    <span class="badge text-bg-info">Non registrato</span>
                                <?php elseif ($linkedName !== ''): ?>
                                    <?php echo appAdminConsoleEscape($linkedName); ?><br>
                                    <span class="text-muted"><?php echo appAdminConsoleEscape((string) $mapping['email']); ?></span>
                                <?php else: ?>
                                    <span class="badge text-bg-danger">Da verificare</span>
                                    <span class="text-muted"><?php echo appAdminConsoleEscape($userCf); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo appAdminConsoleEscape(appAdminConsoleDateLabel($mapping['updated_at'] ?? null)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($mappings === []): ?>
                        <tr><td colspan="4" class="text-muted">Nessuna associazione trovata.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            </div>
        </details>

        <details class="admin-console-section" data-admin-console-panel>
            <summary class="admin-console-section__summary">
                <span>
                    <strong>Inviti</strong>
                    <small><?php echo count($invites); ?> inviti</small>
                </span>
            </summary>
            <div class="admin-console-section__body">
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-3">
                <div class="text-muted">
                    <?php if ($departmentFilter !== ''): ?>
                        Revocati nel reparto selezionato: <?php echo (int) $revokedInvitesCount; ?>.
                    <?php else: ?>
                        Inviti revocati totali: <?php echo (int) $revokedInvitesCount; ?>.
                    <?php endif; ?>
                </div>
                <form method="post" data-admin-console-confirm="Eliminare definitivamente gli inviti revocati? Questa operazione non può essere annullata.">
                    <input type="hidden" name="csrf_token" value="<?php echo appAdminConsoleEscape($appCsrfToken); ?>">
                    <input type="hidden" name="action" value="delete_revoked_invites">
                    <input type="hidden" name="reparto" value="<?php echo appAdminConsoleEscape($departmentFilter); ?>">
                    <input type="hidden" name="stato" value="<?php echo appAdminConsoleEscape($inviteStatusFilter); ?>">
                    <button type="submit" class="btn btn-outline-danger btn-sm" <?php echo $revokedInvitesCount > 0 ? '' : 'disabled'; ?>>
                        Elimina inviti revocati
                    </button>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table align-middle admin-console-table">
                    <thead>
                    <tr>
                        <th>Invitato</th>
                        <th>Reparto</th>
                        <th>Ruolo</th>
                        <th>Creato da</th>
                        <th>Scadenza</th>
                        <th>Stato</th>
                        <th>Link</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($invites as $invite): ?>
                        <?php $status = (string) ($invite['computed_status'] ?? appInviteStatus($invite)); ?>
                        <tr data-admin-console-row data-search-text="<?php echo appAdminConsoleEscape(appAdminConsoleSearchText([
                            $invite['invited_nome'] ?? '',
                            $invite['invited_cognome'] ?? '',
                            $invite['invited_email'] ?? '',
                            $invite['reparto'] ?? '',
                            appAdminConsoleRoleLabel((int) ($invite['invited_capo'] ?? 0)),
                            $invite['author_name'] ?? '',
                            appInviteStatusLabel($invite),
                        ])); ?>">
                            <td>
                                <strong><?php echo appAdminConsoleEscape(trim((string) $invite['invited_nome'] . ' ' . (string) $invite['invited_cognome'])); ?></strong><br>
                                <span class="text-muted"><?php echo appAdminConsoleEscape((string) $invite['invited_email']); ?></span>
                            </td>
                            <td><?php echo appAdminConsoleEscape(appDepartments()[(string) $invite['reparto']] ?? (string) $invite['reparto']); ?></td>
                            <td><?php echo appAdminConsoleEscape(appAdminConsoleRoleLabel((int) ($invite['invited_capo'] ?? 0))); ?></td>
                            <td><?php echo appAdminConsoleEscape((string) ($invite['author_name'] ?: $invite['invited_by_cf'])); ?></td>
                            <td><?php echo appAdminConsoleEscape(appAdminConsoleDateLabel($invite['expires_at'] ?? null)); ?></td>
                            <td><span class="badge <?php echo appAdminConsoleEscape(appAdminConsoleStatusClass($status)); ?>"><?php echo appAdminConsoleEscape(appInviteStatusLabel($invite)); ?></span></td>
                            <td>
                                <?php if ($status === 'accepted'): ?>
                                    <span class="text-muted">Account attivato</span>
                                <?php else: ?>
                                    <div class="d-flex flex-wrap gap-2">
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?php echo appAdminConsoleEscape($appCsrfToken); ?>">
                                            <input type="hidden" name="action" value="manual_invite_link">
                                            <input type="hidden" name="invite_id" value="<?php echo (int) $invite['id']; ?>">
                                            <input type="hidden" name="reparto" value="<?php echo appAdminConsoleEscape($departmentFilter); ?>">
                                            <input type="hidden" name="stato" value="<?php echo appAdminConsoleEscape($inviteStatusFilter); ?>">
                                            <button type="submit" class="btn btn-outline-dark btn-sm">Genera link</button>
                                        </form>
                                        <?php if ($status === 'pending'): ?>
                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?php echo appAdminConsoleEscape($appCsrfToken); ?>">
                                                <input type="hidden" name="action" value="revoke_invite">
                                                <input type="hidden" name="invite_id" value="<?php echo (int) $invite['id']; ?>">
                                                <input type="hidden" name="reparto" value="<?php echo appAdminConsoleEscape($departmentFilter); ?>">
                                                <input type="hidden" name="stato" value="<?php echo appAdminConsoleEscape($inviteStatusFilter); ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm">Revoca</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($invites === []): ?>
                        <tr><td colspan="7" class="text-muted">Nessun invito trovato.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            </div>
        </details>

        <details class="admin-console-section" data-admin-console-panel>
            <summary class="admin-console-section__summary">
                <span>
                    <strong>Audit recente</strong>
                    <small><?php echo count($auditLogs); ?> eventi</small>
                </span>
            </summary>
            <div class="admin-console-section__body">
            <?php if ($auditLogError): ?>
                <div class="alert alert-warning mb-0">Audit non ancora disponibile. Esegui la migrazione database al prossimo rilascio.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle admin-console-table">
                        <thead>
                        <tr>
                            <th>Quando</th>
                            <th>Admin</th>
                            <th>Azione</th>
                            <th>Oggetto</th>
                            <th>Dettagli</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($auditLogs as $event): ?>
                            <tr data-admin-console-row data-search-text="<?php echo appAdminConsoleEscape(appAdminConsoleSearchText([
                                $event['created_at'] ?? '',
                                $event['actor_name'] ?? '',
                                $event['actor_cf'] ?? '',
                                appAdminConsoleAuditActionLabel((string) $event['action']),
                                $event['target_type'] ?? '',
                                $event['target_id'] ?? '',
                                appAdminConsoleAuditDetailsLabel($event['details_json'] ?? null),
                            ])); ?>">
                                <td><?php echo appAdminConsoleEscape(appAdminConsoleDateLabel($event['created_at'] ?? null)); ?></td>
                                <td><?php echo appAdminConsoleEscape((string) ($event['actor_name'] ?: $event['actor_cf'])); ?></td>
                                <td><?php echo appAdminConsoleEscape(appAdminConsoleAuditActionLabel((string) $event['action'])); ?></td>
                                <td>
                                    <?php if (!empty($event['target_id'])): ?>
                                        <span class="admin-console-mono"><?php echo appAdminConsoleEscape((string) $event['target_type'] . ' #' . (string) $event['target_id']); ?></span>
                                    <?php elseif (!empty($event['target_type'])): ?>
                                        <span class="admin-console-mono"><?php echo appAdminConsoleEscape((string) $event['target_type']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">Console</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo appAdminConsoleEscape(appAdminConsoleAuditDetailsLabel($event['details_json'] ?? null)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($auditLogs === []): ?>
                            <tr><td colspan="5" class="text-muted">Nessun evento registrato.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            </div>
        </details>
    <?php endif; ?>
</main>
<script src="assets/js/pages/admin-console.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
</body>
</html>

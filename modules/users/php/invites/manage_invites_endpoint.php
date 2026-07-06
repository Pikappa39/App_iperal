<?php
require __DIR__ . '/../bootstrap.php';

usersModuleBootstrap([
    'modules/users/php/admin/audit.php',
    'modules/users/php/invites/invite_lib.php',
    'connection_files/password_reset_mail.php',
]);

function appInviteRedirect(): void
{
    header('Location: ../addetti.php', true, 303);
    exit;
}

function appInviteSetFlash(string $type, string $message, ?string $link = null): void
{
    $_SESSION['invite_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
    if ($link !== null) {
        $_SESSION['invite_flash']['link'] = $link;
    }
}

function appInviteAuditEmailSent(PDO $pdo, array $sessionUser, int $inviteId, string $email, string $reparto, string $source, array $mailInfo): void
{
    appAdminAuditLog($pdo, $sessionUser, 'invite_email_sent', 'user_invite', $inviteId > 0 ? (string) $inviteId : null, [
        'email' => $email,
        'reparto' => $reparto,
        'source' => $source,
        'message_id' => (string) ($mailInfo['message_id'] ?? ''),
        'smtp_code' => (int) ($mailInfo['smtp_code'] ?? 0),
        'smtp_response' => mb_substr((string) ($mailInfo['smtp_response'] ?? ''), 0, 255, 'UTF-8'),
        'transport' => (string) ($mailInfo['transport'] ?? ''),
    ]);
}

function appInviteAuditTestEmailSent(PDO $pdo, array $sessionUser, string $email, string $reparto, array $mailInfo): void
{
    appAdminAuditLog($pdo, $sessionUser, 'invite_test_email_sent', 'email_test', null, [
        'email' => $email,
        'reparto' => $reparto,
        'message_id' => (string) ($mailInfo['message_id'] ?? ''),
        'smtp_code' => (int) ($mailInfo['smtp_code'] ?? 0),
        'smtp_response' => mb_substr((string) ($mailInfo['smtp_response'] ?? ''), 0, 255, 'UTF-8'),
        'transport' => (string) ($mailInfo['transport'] ?? ''),
        'source' => 'addetti_test',
    ]);
}

$sessionUser = $_SESSION['user'] ?? null;
if (!is_array($sessionUser) || !appInviteCanManage($sessionUser) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    appInviteRedirect();
}

if (!app_csrf_request_is_valid()) {
    appInviteSetFlash('danger', 'Richiesta non valida. Ricarica la pagina e riprova.');
    appInviteRedirect();
}

if (!$connessione || !($pdo instanceof PDO)) {
    appInviteSetFlash('danger', 'Database non disponibile. Riprova più tardi.');
    appInviteRedirect();
}

$action = (string) ($_POST['action'] ?? 'create');
$inviteId = (int) ($_POST['invite_id'] ?? 0);
$managerCf = (string) ($sessionUser['cf'] ?? '');

try {
    if ($action === 'send_test') {
        if ((int) ($sessionUser['capo'] ?? 0) !== 3) {
            throw new RuntimeException('Solo un admin può inviare email di test.');
        }

        $email = appInviteNormalizeEmail((string) ($_POST['email'] ?? ''));
        $nome = appInviteNormalizeName((string) ($_POST['nome'] ?? ''));
        $reparto = trim((string) ($_POST['reparto'] ?? ''));
        $departmentLabel = appDepartments()[$reparto] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Inserisci un indirizzo email valido per il test.');
        }
        if ($nome !== '' && mb_strlen($nome) > 100) {
            throw new RuntimeException('Il nome del destinatario di test è troppo lungo.');
        }

        $requestedBy = trim((string) (($sessionUser['nome'] ?? '') . ' ' . ($sessionUser['cognome'] ?? '')));
        if ($requestedBy === '') {
            $requestedBy = (string) ($sessionUser['email'] ?? $managerCf);
        }

        $testUrl = appPublicUrl() . '/login_reg.php?test_invite=1';
        $mailInfo = sendTestInvitationEmail($email, $nome, $departmentLabel, $testUrl, $requestedBy);
        appInviteAuditTestEmailSent($pdo, $sessionUser, $email, $reparto, $mailInfo);
        appInviteSetFlash('success', 'Email di test inviata a ' . $email . '. Nessun invito operativo è stato creato.');
        appInviteRedirect();
    }

    if ($action === 'revoke' || $action === 'regenerate') {
        $pdo->beginTransaction();
        if ($action === 'revoke') {
            $invite = appInviteRevokeLocked($pdo, $inviteId, $sessionUser);
            $pdo->commit();
            appAdminAuditLog($pdo, $sessionUser, 'invite_revoked', 'user_invite', (string) $inviteId, [
                'email' => (string) ($invite['invited_email'] ?? ''),
                'reparto' => (string) ($invite['reparto'] ?? ''),
                'status' => appInviteStatus($invite),
                'source' => 'addetti',
            ]);
            appInviteSetFlash('success', 'Invito revocato.');
            appInviteRedirect();
        }

        $regenerated = appInviteRegenerateLocked($pdo, $inviteId, $sessionUser);
        $pdo->commit();
        $invite = $regenerated['invite'];
        $link = (string) $regenerated['link'];
        appAdminAuditLog($pdo, $sessionUser, 'invite_regenerated', 'user_invite', (string) $inviteId, [
            'email' => (string) ($invite['invited_email'] ?? ''),
            'reparto' => (string) ($invite['reparto'] ?? ''),
            'status' => appInviteStatus($invite),
            'source' => 'addetti',
        ]);
        $departmentLabel = appDepartments()[(string) $invite['reparto']] ?? (string) $invite['reparto'];
        try {
            $mailInfo = sendInvitationEmail(
                (string) $invite['invited_email'],
                trim((string) $invite['invited_nome'] . ' ' . (string) $invite['invited_cognome']),
                $departmentLabel,
                $link,
                (string) $regenerated['expires_at']
            );
            appInviteAuditEmailSent($pdo, $sessionUser, $inviteId, (string) $invite['invited_email'], (string) $invite['reparto'], 'addetti', $mailInfo);
            appInviteSetFlash('success', 'Nuovo invito inviato via email a ' . (string) $invite['invited_email'] . '.');
        } catch (Throwable $mailError) {
            error_log('Invio email invito non riuscito: ' . $mailError->getMessage());
            appInviteSetFlash('warning', 'Nuovo invito creato, ma l’email non è stata inviata. Copia e condividi il link manualmente.', $link);
        }
        appInviteRedirect();
    }

    $email = appInviteNormalizeEmail((string) ($_POST['email'] ?? ''));
    $nome = appInviteNormalizeName((string) ($_POST['nome'] ?? ''));
    $cognome = appInviteNormalizeName((string) ($_POST['cognome'] ?? ''));
    $reparto = appInviteDepartmentForManager($sessionUser, trim((string) ($_POST['reparto'] ?? '')));
    $invitedRole = appInviteRoleForManager($sessionUser, (int) ($_POST['capo'] ?? -1));
    $invitedBoxInfo = (int) ($_POST['box_info'] ?? 0) === 1 ? 1 : 0;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Inserisci un indirizzo email valido.');
    }
    if ($nome === '' || mb_strlen($nome) > 100 || $cognome === '' || mb_strlen($cognome) > 100) {
        throw new RuntimeException('Nome e cognome sono obbligatori.');
    }
    if ($reparto === '') {
        throw new RuntimeException('Reparto non valido.');
    }
    if ($invitedRole === null) {
        throw new RuntimeException('Non puoi creare un invito con questo ruolo.');
    }
    if ($invitedBoxInfo === 1 && !appInviteCanAssignBoxInfo($sessionUser, $reparto)) {
        throw new RuntimeException('Non puoi assegnare privilegi box per questo reparto.');
    }

    $userExists = $pdo->prepare(
        'SELECT 1 FROM utenti WHERE email = ? LIMIT 1'
    );
    $userExists->execute([$email]);
    if ($userExists->fetchColumn()) {
        throw new RuntimeException('Esiste già un account con l’email indicata.');
    }

    $activeInvite = $pdo->prepare(
        'SELECT 1
         FROM user_invites
         WHERE invited_email = ?
           AND accepted_at IS NULL
           AND revoked_at IS NULL
           AND expires_at >= NOW()
         LIMIT 1'
    );
    $activeInvite->execute([$email]);
    if ($activeInvite->fetchColumn()) {
        throw new RuntimeException('Esiste già un invito attivo per questa email.');
    }

    $badge = appGenerateUniqueInviteBadge($pdo);
    $cf = appGenerateUniqueInvitePlaceholderCf($pdo);

    $token = appInviteGenerateToken();
    $tokenHash = appInviteHashToken($token);
    $insert = $pdo->prepare(
        'INSERT INTO user_invites (
            invited_by_cf, invited_email, invited_badge, invited_cf,
            invited_nome, invited_cognome, invited_capo, invited_box_info, reparto, token_hash, expires_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))'
    );
    $insert->execute([$managerCf, $email, $badge, $cf, $nome, $cognome, $invitedRole, $invitedBoxInfo, $reparto, $tokenHash]);
    $newInviteId = (int) $pdo->lastInsertId();
    appAdminAuditLog($pdo, $sessionUser, 'invite_created', 'user_invite', $newInviteId > 0 ? (string) $newInviteId : null, [
        'email' => $email,
        'reparto' => $reparto,
        'role' => $invitedRole,
        'box_info' => $invitedBoxInfo,
        'source' => 'addetti',
    ]);

    $link = appInviteBuildUrl($token);
    $departmentLabel = appDepartments()[$reparto] ?? $reparto;
    try {
        $mailInfo = sendInvitationEmail($email, trim($nome . ' ' . $cognome), $departmentLabel, $link, date('Y-m-d H:i:s', strtotime('+7 days')));
        appInviteAuditEmailSent($pdo, $sessionUser, $newInviteId, $email, $reparto, 'addetti', $mailInfo);
        appInviteSetFlash('success', 'Invito inviato via email a ' . $email . '.');
    } catch (Throwable $mailError) {
        error_log('Invio email invito non riuscito: ' . $mailError->getMessage());
        appInviteSetFlash('warning', 'Invito creato, ma l’email non è stata inviata. Copia e condividi il link manualmente.', $link);
    }
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Gestione inviti non riuscita: ' . $e->getMessage());
    appInviteSetFlash('danger', $e->getMessage());
}

appInviteRedirect();

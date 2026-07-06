<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/app_config.php';
require_once dirname(__DIR__) . '/identity/account_identity.php';
require_once dirname(__DIR__, 4) . '/session_bootstrap.php';
require_once dirname(__DIR__, 4) . '/connection_files/connection.php';
require_once dirname(__DIR__, 4) . '/connection_files/invite_lib.php';
require_once __DIR__ . '/page_helpers.php';

function appAuthAcceptInviteLoad(PDO $pdo, string $token): ?array
{
    if ($token === '') {
        return null;
    }

    $query = $pdo->prepare('SELECT * FROM user_invites WHERE token_hash = ? LIMIT 1');
    $query->execute([appInviteHashToken($token)]);
    $invite = $query->fetch(PDO::FETCH_ASSOC);
    return $invite ?: null;
}

function appAuthAcceptInvitePageContext(): array
{
    global $connessione, $pdo;

    app_session_start();

    $token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
    $errorMessage = '';

    if (!$connessione || !($pdo instanceof PDO)) {
        http_response_code(503);
        $errorMessage = 'Servizio temporaneamente non disponibile.';
    }

    $invite = ($errorMessage === '' && $token !== '') ? appAuthAcceptInviteLoad($pdo, $token) : null;
    if ($errorMessage === '' && !$invite) {
        http_response_code(404);
        $errorMessage = 'Invito non valido o non piu disponibile.';
    }

    if ($errorMessage === '' && appInviteStatus($invite) !== 'pending') {
        http_response_code(410);
        $errorMessage = match (appInviteStatus($invite)) {
            'accepted' => 'Questo invito e gia stato usato.',
            'revoked' => 'Questo invito e stato revocato.',
            default => 'Questo invito e scaduto. Chiedi un nuovo link al responsabile.',
        };
    }

    if ($errorMessage === '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $errorMessage = appAuthAcceptInviteHandlePost($pdo, $token, $invite);
    }

    $departments = appDepartments();
    $inviteDepartment = $invite ? ($departments[(string) ($invite['reparto'] ?? '')] ?? (string) ($invite['reparto'] ?? '')) : '';

    return [
        'errorMessage' => $errorMessage,
        'invite' => $invite,
        'inviteDepartment' => $inviteDepartment,
        'token' => $token,
    ];
}

function appAuthAcceptInviteHandlePost(PDO $pdo, string $token, array $invite): string
{
    if (!app_csrf_request_is_valid()) {
        return 'Richiesta non valida. Ricarica la pagina e riprova.';
    }

    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if (strlen($password) < 12) {
        return 'La password deve contenere almeno 12 caratteri.';
    }
    if (!hash_equals($password, $confirmPassword)) {
        return 'Le password non corrispondono.';
    }

    try {
        $alreadyExists = $pdo->prepare('SELECT 1 FROM utenti WHERE email = ? LIMIT 1');
        $alreadyExists->execute([(string) $invite['invited_email']]);
        if ($alreadyExists->fetchColumn()) {
            throw new RuntimeException('Esiste gia un account con questa email. Contatta il responsabile.');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $generatedBadge = appGenerateUniqueUserBadge($pdo);
        $pdo->beginTransaction();

        $freshInvite = appAuthAcceptInviteLoad($pdo, $token);
        if (!$freshInvite || appInviteStatus($freshInvite) !== 'pending') {
            throw new RuntimeException("L'invito non e piu disponibile.");
        }

        $technicalId = (string) $freshInvite['invited_cf'];
        $invitedRole = (int) ($freshInvite['invited_capo'] ?? 0);
        if (!in_array($invitedRole, [0, 1, 2], true)) {
            throw new RuntimeException("Il ruolo dell'invito non e valido. Contatta l'amministratore.");
        }

        $insert = $pdo->prepare(
            'INSERT INTO utenti (cod_fiscale, nome, cognome, badge, password, email, avatar, capo, reparto, box_info)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $technicalId,
            (string) $freshInvite['invited_nome'],
            (string) $freshInvite['invited_cognome'],
            $generatedBadge,
            $passwordHash,
            (string) $freshInvite['invited_email'],
            'default',
            $invitedRole,
            (string) $freshInvite['reparto'],
            (int) ($freshInvite['invited_box_info'] ?? 0) === 1 ? 1 : 0,
        ]);

        $pdo->prepare(
            'UPDATE user_invites
             SET accepted_at = NOW(), accepted_user_cf = ?
             WHERE id = ?'
        )->execute([$technicalId, (int) $freshInvite['id']]);

        $pdo->commit();

        session_regenerate_id(true);
        $_SESSION['user'] = [
            'cf' => $technicalId,
            'nome' => (string) $freshInvite['invited_nome'],
            'cognome' => (string) $freshInvite['invited_cognome'],
            'avatar' => 'default',
            'capo' => $invitedRole,
            'reparto' => (string) $freshInvite['reparto'],
            'box_info' => (int) ($freshInvite['invited_box_info'] ?? 0) === 1 ? 1 : 0,
            'session_version' => 0,
        ];
        app_session_touch_user($pdo, $technicalId, true);

        header('Location: index.php', true, 302);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Attivazione invito non riuscita: ' . $e->getMessage());
        return $e->getMessage();
    }
}

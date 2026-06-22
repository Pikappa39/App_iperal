<?php
require __DIR__ . '/app_config.php';
require __DIR__ . '/account_identity.php';
require __DIR__ . '/session_bootstrap.php';
app_session_start();
require_once __DIR__ . '/connection_files/connection.php';
require_once __DIR__ . '/connection_files/invite_lib.php';

function appAcceptInviteEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function appAcceptInviteLoad(PDO $pdo, string $token): ?array
{
    if ($token === '') {
        return null;
    }

    $query = $pdo->prepare('SELECT * FROM user_invites WHERE token_hash = ? LIMIT 1');
    $query->execute([appInviteHashToken($token)]);
    $invite = $query->fetch(PDO::FETCH_ASSOC);
    return $invite ?: null;
}

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$errorMessage = '';
$successMessage = '';

if (!$connessione || !($pdo instanceof PDO)) {
    http_response_code(503);
    $errorMessage = 'Servizio temporaneamente non disponibile.';
}

$invite = ($errorMessage === '' && $token !== '') ? appAcceptInviteLoad($pdo, $token) : null;
if ($errorMessage === '' && !$invite) {
    http_response_code(404);
    $errorMessage = 'Invito non valido o non più disponibile.';
}

if ($errorMessage === '' && appInviteStatus($invite) !== 'pending') {
    http_response_code(410);
    $errorMessage = match (appInviteStatus($invite)) {
        'accepted' => 'Questo invito è già stato usato.',
        'revoked' => 'Questo invito è stato revocato.',
        default => 'Questo invito è scaduto. Chiedi un nuovo link al responsabile.',
    };
}

if ($errorMessage === '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!app_csrf_request_is_valid()) {
        $errorMessage = 'Richiesta non valida. Ricarica la pagina e riprova.';
    } else {
        $password = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if (strlen($password) < 12) {
            $errorMessage = 'La password deve contenere almeno 12 caratteri.';
        } elseif (!hash_equals($password, $confirmPassword)) {
            $errorMessage = 'Le password non corrispondono.';
        } else {
            try {
                $alreadyExists = $pdo->prepare(
                    'SELECT 1 FROM utenti WHERE email = ? LIMIT 1'
                );
                $alreadyExists->execute([(string) $invite['invited_email']]);
                if ($alreadyExists->fetchColumn()) {
                    throw new RuntimeException('Esiste già un account con questa email. Contatta il responsabile.');
                }

                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $generatedBadge = appGenerateUniqueUserBadge($pdo);
                $pdo->beginTransaction();

                $freshInvite = appAcceptInviteLoad($pdo, $token);
                if (!$freshInvite || appInviteStatus($freshInvite) !== 'pending') {
                    throw new RuntimeException('L’invito non è più disponibile.');
                }

                $technicalId = (string) $freshInvite['invited_cf'];

                $insert = $pdo->prepare(
                    'INSERT INTO utenti (cod_fiscale, nome, cognome, badge, password, email, avatar, capo, reparto)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)'
                );
                $insert->execute([
                    $technicalId,
                    (string) $freshInvite['invited_nome'],
                    (string) $freshInvite['invited_cognome'],
                    $generatedBadge,
                    $passwordHash,
                    (string) $freshInvite['invited_email'],
                    'default',
                    (string) $freshInvite['reparto'],
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
                    'capo' => 0,
                    'reparto' => (string) $freshInvite['reparto'],
                    'session_version' => 0,
                ];
                if ($pdo instanceof PDO) {
                    app_session_touch_user($pdo, $technicalId, true);
                }

                header('Location: index.php', true, 302);
                exit;
            } catch (Throwable $e) {
                if ($pdo instanceof PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Attivazione invito non riuscita: ' . $e->getMessage());
                $errorMessage = $e->getMessage();
            }
        }
    }
}

$departments = appDepartments();
$inviteDepartment = $invite ? ($departments[(string) ($invite['reparto'] ?? '')] ?? (string) ($invite['reparto'] ?? '')) : '';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attiva account</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="sfera.css?v=<?php echo rawurlencode(APP_VERSION); ?>">
</head>
<body>
<main class="app-shell">
    <div class="auth-form mx-auto">
        <h1 class="h3 mb-3">Attiva il tuo account</h1>
        <?php if ($errorMessage !== ''): ?>
            <div class="alert alert-danger"><?php echo appAcceptInviteEscape($errorMessage); ?></div>
            <a class="btn btn-outline-dark" href="login_reg.php">Torna al login</a>
        <?php elseif ($invite): ?>
            <p class="text-muted mb-4">Conferma la password per completare l’accesso a MyOrari.</p>
            <dl class="row small mb-4">
                <dt class="col-sm-4">Nome</dt>
                <dd class="col-sm-8"><?php echo appAcceptInviteEscape((string) $invite['invited_nome'] . ' ' . (string) $invite['invited_cognome']); ?></dd>
                <dt class="col-sm-4">Email</dt>
                <dd class="col-sm-8"><?php echo appAcceptInviteEscape((string) $invite['invited_email']); ?></dd>
                <dt class="col-sm-4">Reparto</dt>
                <dd class="col-sm-8"><?php echo appAcceptInviteEscape($inviteDepartment); ?></dd>
            </dl>

            <form method="post" class="d-grid gap-3">
                <input type="hidden" name="csrf_token" value="<?php echo appAcceptInviteEscape(app_csrf_token()); ?>">
                <input type="hidden" name="token" value="<?php echo appAcceptInviteEscape($token); ?>">
                <div>
                    <label class="form-label" for="password">Password</label>
                    <input class="form-control" type="password" id="password" name="password" minlength="12" autocomplete="new-password" required>
                </div>
                <div>
                    <label class="form-label" for="confirm_password">Conferma password</label>
                    <input class="form-control" type="password" id="confirm_password" name="confirm_password" minlength="12" autocomplete="new-password" required>
                </div>
                <button class="btn btn-primary" type="submit">Attiva account</button>
            </form>
        <?php endif; ?>
    </div>
</main>
</body>
</html>

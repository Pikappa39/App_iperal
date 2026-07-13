<?php
require dirname(__DIR__) . '/modules/auth/php/pages/accept_invite_page_context.php';

$pageContext = appAuthAcceptInvitePageContext();
extract($pageContext, EXTR_SKIP);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attiva account</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="sfera.css?v=<?php echo rawurlencode(APP_VERSION); ?>">
    <link rel="stylesheet" href="assets/css/modules/auth.css?v=<?php echo rawurlencode(APP_VERSION); ?>">
</head>
<body>
<main class="app-shell">
    <div class="auth-form mx-auto">
        <h1 class="h3 mb-3">Attiva il tuo account</h1>
        <?php if ($errorMessage !== ''): ?>
            <div class="alert alert-danger"><?php echo appAuthPageEscape($errorMessage); ?></div>
            <a class="btn btn-outline-dark" href="login_reg.php">Torna al login</a>
        <?php elseif ($invite): ?>
            <p class="text-muted mb-4">Conferma la password per completare l'accesso a MyOrari.</p>
            <dl class="row small mb-4">
                <dt class="col-sm-4">Nome</dt>
                <dd class="col-sm-8"><?php echo appAuthPageEscape((string) $invite['invited_nome'] . ' ' . (string) $invite['invited_cognome']); ?></dd>
                <dt class="col-sm-4">Email</dt>
                <dd class="col-sm-8"><?php echo appAuthPageEscape((string) $invite['invited_email']); ?></dd>
                <dt class="col-sm-4">Reparto</dt>
                <dd class="col-sm-8"><?php echo appAuthPageEscape($inviteDepartment); ?></dd>
                <?php if (appInviteHasBoxInfoPrivilege($invite)): ?>
                    <dt class="col-sm-4">Abilitazione</dt>
                    <dd class="col-sm-8">Box informazioni</dd>
                <?php endif; ?>
            </dl>

            <form method="post" class="d-grid gap-3">
                <input type="hidden" name="csrf_token" value="<?php echo appAuthPageEscape(app_csrf_token()); ?>">
                <input type="hidden" name="token" value="<?php echo appAuthPageEscape($token); ?>">
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

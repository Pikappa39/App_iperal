<?php
require dirname(__DIR__) . '/modules/auth/php/pages/password_reset_page_context.php';

$pageContext = appAuthResetPasswordPageContext();
extract($pageContext, EXTR_SKIP);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuova password &middot; MyOrari</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="sfera.css?v=<?php echo rawurlencode(APP_VERSION); ?>">
</head>
<body>
    <main class="container mt-5" style="max-width: 560px;">
        <h1>Scegli una nuova password</h1>
        <?php if (!$tokenIsValid): ?>
            <p class="text-danger">Il link non &egrave; valido. Richiedi un nuovo recupero password.</p>
            <a class="btn btn-primary" href="forgot_password.php">Richiedi un nuovo link</a>
        <?php else: ?>
            <form id="reset-password-form" novalidate>
                <input type="hidden" name="token" value="<?php echo appAuthPageEscape($token); ?>">
                <label class="form-label" for="password">Nuova password</label>
                <input class="form-control" type="password" id="password" name="password" autocomplete="new-password" minlength="12" required>
                <div class="form-text">Usa almeno 12 caratteri.</div>
                <label class="form-label mt-3" for="confirmation">Conferma nuova password</label>
                <input class="form-control" type="password" id="confirmation" name="confirmation" autocomplete="new-password" minlength="12" required>
                <p id="message" class="mt-3" aria-live="polite"></p>
                <button class="btn btn-primary" type="submit">Aggiorna password</button>
            </form>
        <?php endif; ?>
    </main>
    <?php if ($tokenIsValid): ?>
    <script>
    window.appPasswordResetConfirmConfig = {
        endpoint: "connection_files/confirm_password_reset.php",
        loginUrl: "login_reg.php"
    };
    </script>
    <script src="assets/js/modules/auth/password-reset-confirm.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
    <?php endif; ?>
</body>
</html>

<?php
require dirname(__DIR__) . '/modules/auth/php/pages/password_reset_page_context.php';

$pageContext = appAuthForgotPasswordPageContext();
extract($pageContext, EXTR_SKIP);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recupera password &middot; MyOrari</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="sfera.css?v=<?php echo rawurlencode(APP_VERSION); ?>">
</head>
<body>
    <main class="container mt-5" style="max-width: 560px;">
        <h1>Recupera password</h1>
        <p class="text-muted">Inserisci l'indirizzo email del tuo account. Riceverai un link valido per 60 minuti.</p>
        <form id="forgot-password-form" novalidate>
            <label class="form-label" for="email">Email</label>
            <input class="form-control" type="email" id="email" name="email" autocomplete="email" required>
            <?php if ($turnstileEnabled): ?>
                <div class="mt-3 cf-turnstile" data-sitekey="<?php echo appAuthPageEscape($turnstileSiteKey); ?>" data-error-callback="appTurnstileError"></div>
            <?php endif; ?>
            <p id="message" class="mt-3" aria-live="polite"></p>
            <button class="btn btn-primary" type="submit">Invia il link</button>
            <a class="btn btn-link" href="login_reg.php">Torna al login</a>
        </form>
    </main>
    <script>
    window.appPasswordResetRequestConfig = {
        endpoint: "connection_files/request_password_reset.php",
        turnstileEnabled: <?php echo $turnstileEnabled ? 'true' : 'false'; ?>
    };
    </script>
    <script src="assets/js/modules/auth/password-reset-request.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
    <?php if ($turnstileEnabled): ?>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php endif; ?>
</body>
</html>

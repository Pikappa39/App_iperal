<?php
require __DIR__ . '/app_config.php';
$turnstileEnabled = appTurnstileEnabled();
$turnstileSiteKey = $turnstileEnabled ? appTurnstileSiteKey() : '';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recupera password · MyOrari</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="sfera.css">
</head>
<body>
    <main class="container mt-5" style="max-width: 560px;">
        <h1>Recupera password</h1>
        <p class="text-muted">Inserisci l’indirizzo email del tuo account. Riceverai un link valido per 60 minuti.</p>
        <form id="forgot-password-form" novalidate>
            <label class="form-label" for="email">Email</label>
            <input class="form-control" type="email" id="email" name="email" autocomplete="email" required>
            <?php if ($turnstileEnabled): ?>
                <div class="mt-3 cf-turnstile" data-sitekey="<?php echo htmlspecialchars($turnstileSiteKey, ENT_QUOTES, 'UTF-8'); ?>" data-error-callback="appTurnstileError"></div>
            <?php endif; ?>
            <p id="message" class="mt-3" aria-live="polite"></p>
            <button class="btn btn-primary" type="submit">Invia il link</button>
            <a class="btn btn-link" href="login_reg.php">Torna al login</a>
        </form>
    </main>
    <?php if ($turnstileEnabled): ?>
    <script>
    window.appTurnstileError = function () {
        const message = document.getElementById('message');
        if (message) {
            message.className = 'mt-3 text-danger';
            message.textContent = 'Il controllo di sicurezza non è riuscito. Ricarica la pagina e riprova; se continua, disattiva temporaneamente estensioni che bloccano contenuti.';
        }
        return true;
    };
    </script>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php endif; ?>
    <script>
    const form = document.getElementById('forgot-password-form');
    const message = document.getElementById('message');
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        message.className = 'mt-3';
        message.textContent = '';
        if (<?php echo $turnstileEnabled ? 'true' : 'false'; ?> && !new FormData(form).get('cf-turnstile-response')) {
            message.className = 'mt-3 text-danger';
            message.textContent = 'Completa il controllo di sicurezza.';
            return;
        }
        const response = await fetch('connection_files/request_password_reset.php', {
            method: 'POST', body: new FormData(form), cache: 'no-cache'
        });
        const data = await response.json().catch(() => ({}));
        message.className = 'mt-3 ' + (data.ok ? 'text-success' : 'text-danger');
        message.textContent = data.message || data.error || 'Si è verificato un errore. Riprova.';
        if (data.ok) form.reset();
    });
    </script>
</body>
</html>

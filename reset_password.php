<?php
$token = strtolower(trim((string) ($_GET['token'] ?? '')));
$tokenIsValid = (bool) preg_match('/^[a-f0-9]{64}$/', $token);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuova password · MyOrari</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="sfera.css">
</head>
<body>
    <main class="container mt-5" style="max-width: 560px;">
        <h1>Scegli una nuova password</h1>
        <?php if (!$tokenIsValid): ?>
            <p class="text-danger">Il link non è valido. Richiedi un nuovo recupero password.</p>
            <a class="btn btn-primary" href="forgot_password.php">Richiedi un nuovo link</a>
        <?php else: ?>
            <form id="reset-password-form" novalidate>
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                <label class="form-label" for="password">Nuova password</label>
                <input class="form-control" type="password" id="password" name="password" autocomplete="new-password" minlength="10" required>
                <div class="form-text">Usa almeno 10 caratteri.</div>
                <label class="form-label mt-3" for="confirmation">Conferma nuova password</label>
                <input class="form-control" type="password" id="confirmation" name="confirmation" autocomplete="new-password" minlength="10" required>
                <p id="message" class="mt-3" aria-live="polite"></p>
                <button class="btn btn-primary" type="submit">Aggiorna password</button>
            </form>
        <?php endif; ?>
    </main>
    <?php if ($tokenIsValid): ?>
    <script>
    const form = document.getElementById('reset-password-form');
    const message = document.getElementById('message');
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        message.className = 'mt-3';
        message.textContent = '';
        const response = await fetch('connection_files/confirm_password_reset.php', {
            method: 'POST', body: new FormData(form), cache: 'no-cache'
        });
        const data = await response.json().catch(() => ({}));
        message.className = 'mt-3 ' + (data.ok ? 'text-success' : 'text-danger');
        message.textContent = data.message || data.error || 'Si è verificato un errore. Riprova.';
        if (data.ok) {
            form.reset();
            setTimeout(() => window.location.assign('login_reg.php'), 1800);
        }
    });
    </script>
    <?php endif; ?>
</body>
</html>

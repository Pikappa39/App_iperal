<?php
require __DIR__ . '/modules/auth/php/pages/login_page_context.php';

$loginContext = appAuthLoginPageContext();
extract($loginContext, EXTR_SKIP);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="sfera.css?v=<?php echo rawurlencode(APP_VERSION); ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
</head>
<body>
  <div class="container d-flex flex-column align-items-center mt-5" id="welcome">
    <div class="container d-flex flex-column align-items-center mt-5 visible" id="login">
      <h1>Accedi</h1>
      <form id="loginForm" class="auth-form">
        <input type="hidden" name="csrf_token" value="<?php echo appAuthPageEscape($csrfToken); ?>">
        <div class="form-group">
          <label for="exampleInputEmail1">Email</label>
          <input type="email" name="email" class="form-control" id="exampleInputEmail1" autocomplete="email" required>
        </div>
        <div class="form-group">
          <label for="exampleInputPassword1">Password</label>
          <input type="password" name="password" class="form-control" id="exampleInputPassword1" autocomplete="current-password" required>
        </div>
        <?php if ($turnstileEnabled): ?>
        <div class="mt-3 mb-3">
          <div class="cf-turnstile" data-sitekey="<?php echo appAuthPageEscape($turnstileSiteKey); ?>" data-error-callback="appTurnstileError"></div>
        </div>
        <?php endif; ?>
        <p id="login-error-message" class="text-danger mt-2" style="display: none;"></p>
        <button type="submit" class="btn btn-primary">Accedi</button>
        <a class="btn btn-link" href="forgot_password.php">Password dimenticata?</a>
      </form>
    </div>

    <?php if ($selfRegistrationEnabled): ?>
    <div class="container d-flex flex-column align-items-center mt-5 d-none" id="signup">
      <form id="signupForm" class="auth-form">
        <input type="hidden" name="csrf_token" value="<?php echo appAuthPageEscape($csrfToken); ?>">
        <div class="form-group">
          <label for="regmail">Email address</label>
          <input type="email" class="form-control" id="regmail" name="email" autocomplete="email" required>
          <input type="email" class="form-control mt-2" id="confmail" name="confirm_email" autocomplete="email" placeholder="Conferma email" required>
          <p id="error-message-email" class="text-danger" style="display: none;">EMAIL NON CORRISPONDENTI</p>
          <label for="regpass" class="mt-3">Password</label>
          <input type="password" class="form-control" id="regpass" name="password" autocomplete="new-password" minlength="12" required>
          <input type="password" class="form-control mt-2" id="confpass" name="confirm_password" autocomplete="new-password" minlength="12" placeholder="Conferma password" required>
          <p id="error-message-password" class="text-danger" style="display: none;">PASSWORD NON CORRISPONDENTI</p>
        </div>
        <label for="inputNome" class="mt-3">Nome e Cognome</label>
        <input type="text" class="form-control" name="nome" id="inputNome" autocomplete="given-name" placeholder="Nome" required>
        <input type="text" class="form-control mt-2" name="cognome" id="inputCognome" autocomplete="family-name" placeholder="Cognome" required>
        <label for="inputReparto" class="mt-3">Reparto</label>
        <select class="form-select" id="inputReparto" name="reparto" required>
          <option value="" selected disabled>Seleziona il tuo reparto</option>
          <?php foreach ($departments as $code => $label): ?>
            <option value="<?php echo appAuthPageEscape((string) $code); ?>"><?php echo appAuthPageEscape((string) $label); ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary mt-3">Registrati</button>
      </form>
    </div>
    <?php endif; ?>

    <div class="btn-group mt-3" role="group" aria-label="Autenticazione">
      <button type="button" class="btn btn-secondary" id="showLogin">Login</button>
      <?php if ($selfRegistrationEnabled): ?>
        <button type="button" class="btn btn-secondary" id="showSignup">Registrazione</button>
      <?php endif; ?>
    </div>
    <?php if (!$selfRegistrationEnabled): ?>
      <p class="text-muted mt-3 mb-0">La registrazione &egrave; gestita dal responsabile del reparto. Se hai ricevuto un link di invito, aprilo per attivare l'account.</p>
    <?php endif; ?>
  </div>

  <script>
  window.appLoginConfig = {
      nextTarget: <?php echo json_encode($nextTarget, JSON_UNESCAPED_SLASHES); ?>,
      signinEndpoint: "connection_files/signin.php",
      signupEndpoint: "connection_files/signup.php",
      turnstileEnabled: <?php echo $turnstileEnabled ? 'true' : 'false'; ?>
  };
  </script>
  <script src="assets/js/modules/auth/login.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
  <?php if ($turnstileEnabled): ?>
  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
  <?php endif; ?>
</body>
</html>

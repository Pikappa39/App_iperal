<?php
require __DIR__ . '/app_config.php';
require __DIR__ . '/session_bootstrap.php';
app_session_start();

$turnstileEnabled = appTurnstileEnabled();
$turnstileSiteKey = $turnstileEnabled ? appTurnstileSiteKey() : '';
$departments = appDepartments();

if (isset($_SESSION['user'])) {
    header('Location: index.php', true, 302);
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="sfera.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
</head>
<body>
  <div class="container d-flex flex-column align-items-center mt-5 " id="welcome">
                <!-- form di login -->
                <div class="container d-flex flex-column align-items-center mt-5 visible" id="login">
            <h1>Login</h1>
            <form id="loginForm" class="w-50">
              <div class="form-group">
                <label for="exampleInputEmail1">Email address</label>
                <input type="email" name="email" class="form-control" id="exampleInputEmail1" aria-describedby="emailHelp" placeholder="Enter email">
                <small id="emailHelp" class="form-text text-muted">We'll never share your email with anyone else.</small>
              </div>
              <div class="form-group">
                <label for="exampleInputPassword1">Password</label>
                <input type="password" name="password" class="form-control" id="exampleInputPassword1" placeholder="Password">
              </div>
              <div class="form-group form-check">
                <input type="checkbox" class="form-check-input" id="exampleCheck1">
                <label class="form-check-label" for="exampleCheck1">Check me out</label>
              </div>
              <?php if ($turnstileEnabled): ?>
              <div class="mt-3 mb-3">
                <div class="cf-turnstile" data-sitekey="<?php echo htmlspecialchars($turnstileSiteKey, ENT_QUOTES, 'UTF-8'); ?>"></div>
              </div>
              <?php endif; ?>
              <p id="login-error-message" class="text-danger mt-2" style="display: none;"></p>
              <button type="submit" class="btn btn-primary">Submit</button>
              <a class="btn btn-link" href="forgot_password.php">Password dimenticata?</a>
            </form>
            </div>

            <!-- form di registrazione -->
            <div class="container d-flex flex-column align-items-center mt-5 d-none" id="signup">
              <form id="signupForm" class="w-50">
                <div class="form-group">
                  <label for="exampleInputEmail1">Email address</label>
                  <input type="email" class="form-control" id="regmail" name="email" aria-describedby="emailHelp" placeholder="Enter email">
                  <input type="email" class="form-control mt-2" id="confmail" name="confirm_email" aria-describedby="emailHelp" placeholder="Conferma email">
                  <p id="error-message-email" class="text-danger" style="display: none;">EMAIL NON CORRISPONDENTI</p>
                  <label for="exampleInputPassword1" class="mt-3">Password</label>
                  <input type="password" class="form-control" id="regpass" name="password" placeholder="Password">
                  <input type="password" class="form-control mt-2" id="confpass" name="confirm_password" placeholder="Conferma password">
                  <p id="error-message-password" class="text-danger" style="display: none;">PASSWORD NON CORRISPONDENTI</p>
                </div>
                <label for="inputBadge" class="mt-3">Badge</label>
                <input type="text" class="form-control" id="inputBadge" name="badge" placeholder="Badge">
                <label for="inputCF" class="mt-3">Codice fiscale</label>
                <input type="text" class="form-control" id="inputCF" name="cf" placeholder="Codice fiscale">
                <label for="inputNomeCognome" class="mt-3">Nome e Cognome</label>
                <input type="text" class="form-control" name="nome" id="inputNome" placeholder="Nome">
                <input type="text" class="form-control mt-2" name="cognome" id="inputCognome" placeholder="Cognome">
                <label for="inputReparto" class="mt-3">Reparto</label>
                <select class="form-select" id="inputReparto" name="reparto" required>
                  <option value="" selected disabled>Seleziona il tuo reparto</option>
                  <?php foreach ($departments as $code => $label): ?>
                    <option value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary mt-3">Submit</button>
              </form>
            </div>
            
          <div class="btn-group mt-3" role="group" aria-label="Basic example">
            <button type="button" class="btn btn-secondary" id="showLogin">Login</button>
            <button type="button" class="btn btn-secondary" id="showSignup">Registrazione</button>
          </div>            

  </div>
</body>

<?php if ($turnstileEnabled): ?>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif; ?>
<script>
const formlogin = document.getElementById("login");
const formsignup = document.getElementById("signup");
const btnLogin = document.getElementById("showLogin");
const btnSignup = document.getElementById("showSignup");
const loginErrorMessage = document.getElementById("login-error-message");

document.addEventListener("DOMContentLoaded", function () {
  btnLogin.addEventListener("click", function () {
    formlogin.classList.remove("d-none");
    formsignup.classList.add("d-none");
  });

  btnSignup.addEventListener("click", function () {
    formlogin.classList.add("d-none");
    formsignup.classList.remove("d-none");
  });
});

const form = document.querySelector("#loginForm");

form.addEventListener("submit", function (e) {
  e.preventDefault();

  if (loginErrorMessage) {
    loginErrorMessage.style.display = "none";
    loginErrorMessage.textContent = "";
  }

  const formData = new FormData(this);
  const turnstileToken = formData.get("cf-turnstile-response");

  if (<?php echo $turnstileEnabled ? 'true' : 'false'; ?> && !turnstileToken) {
    if (loginErrorMessage) {
      loginErrorMessage.textContent = "Completa il controllo di sicurezza prima di accedere.";
      loginErrorMessage.style.display = "block";
    }
    return;
  }

  fetch("connection_files/signin.php", {
    method: "POST",
    body: formData,
    cache: "no-cache"
  })
  .then(async (res) => {
      const testo = await res.text();
      if (!testo) {
          throw new Error("Risposta vuota dal server (HTTP " + res.status + ")");
      }
      let data;
      try {
          data = JSON.parse(testo);
          console.log(data["logged"]);
      } catch {
          throw new Error("Risposta non valida: " + testo);
      }
      if (data.error_code && data.error) {
          console.error("[" + data.error_code + "] " + data.error);
      }
      if (data.logged) {
          window.location.replace("index.php");
      } else {
          if (loginErrorMessage && data.error) {
            loginErrorMessage.textContent = data.error;
            loginErrorMessage.style.display = "block";
          } else {
            alert("Email o password errati");
          }
      }
  });
});

const signupForm = document.querySelector("#signupForm");
const regmail = document.getElementById("regmail");
const confmail = document.getElementById("confmail");
const regpass = document.getElementById("regpass");
const confpass = document.getElementById("confpass");
const errorMessageEmail = document.getElementById("error-message-email");
const errorMessagePassword = document.getElementById("error-message-password");

signupForm.addEventListener("submit", function (e) {
  e.preventDefault();

  const emailOk = regmail.value === confmail.value;
  const passwordOk = regpass.value === confpass.value;

  errorMessageEmail.style.display = emailOk ? "none" : "block";
  errorMessagePassword.style.display = passwordOk ? "none" : "block";

  if (!emailOk || !passwordOk) {
    return;
  }

  const formData = new FormData(this);
  console.log("Invio dati al server...");

  fetch("connection_files/signup.php", {
    method: "POST",
    body: formData,
    cache: "no-cache"
  }).then(async (res) => {
    const testo = await res.text();
    console.log("Risposta dal server: " + testo);
  });
});
</script>
</html>

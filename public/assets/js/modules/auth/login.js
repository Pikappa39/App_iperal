(function () {
    const config = window.appLoginConfig || {};
    const loginPanel = document.getElementById("login");
    const signupPanel = document.getElementById("signup");
    const btnLogin = document.getElementById("showLogin");
    const btnSignup = document.getElementById("showSignup");
    const loginErrorMessage = document.getElementById("login-error-message");
    const loginForm = document.getElementById("loginForm");
    const signupForm = document.getElementById("signupForm");

    function showLoginError(message) {
        if (!loginErrorMessage) return;
        loginErrorMessage.textContent = message;
        loginErrorMessage.style.display = "block";
    }

    function clearLoginError() {
        if (!loginErrorMessage) return;
        loginErrorMessage.style.display = "none";
        loginErrorMessage.textContent = "";
    }

    window.appTurnstileError = function () {
        showLoginError("Il controllo di sicurezza non \u00E8 riuscito. Ricarica la pagina e riprova; se continua, disattiva temporaneamente estensioni che bloccano contenuti.");
        return true;
    };

    if (btnLogin && loginPanel) {
        btnLogin.addEventListener("click", () => {
            loginPanel.classList.remove("d-none");
            if (signupPanel) signupPanel.classList.add("d-none");
        });
    }

    if (btnSignup && signupPanel && loginPanel) {
        btnSignup.addEventListener("click", () => {
            loginPanel.classList.add("d-none");
            signupPanel.classList.remove("d-none");
        });
    }

    if (loginForm) {
        loginForm.addEventListener("submit", async (event) => {
            event.preventDefault();
            clearLoginError();

            const formData = new FormData(loginForm);
            if (config.turnstileEnabled && !formData.get("cf-turnstile-response")) {
                showLoginError("Completa il controllo di sicurezza prima di accedere.");
                return;
            }

            try {
                const response = await fetch(config.signinEndpoint || "connection_files/signin.php", {
                    method: "POST",
                    body: formData,
                    cache: "no-cache"
                });
                const text = await response.text();
                if (!text) {
                    throw new Error("Risposta vuota dal server (HTTP " + response.status + ")");
                }
                const data = JSON.parse(text);
                if (data.logged) {
                    window.location.replace(config.nextTarget || "index.php");
                    return;
                }
                showLoginError(data.error || "Email o password errati");
            } catch (error) {
                showLoginError(error.message || "Accesso non riuscito");
            }
        });
    }

    if (signupForm) {
        signupForm.addEventListener("submit", async (event) => {
            event.preventDefault();

            const regmail = document.getElementById("regmail");
            const confmail = document.getElementById("confmail");
            const regpass = document.getElementById("regpass");
            const confpass = document.getElementById("confpass");
            const errorMessageEmail = document.getElementById("error-message-email");
            const errorMessagePassword = document.getElementById("error-message-password");

            const emailOk = regmail && confmail && regmail.value === confmail.value;
            const passwordOk = regpass && confpass && regpass.value === confpass.value;

            if (errorMessageEmail) errorMessageEmail.style.display = emailOk ? "none" : "block";
            if (errorMessagePassword) errorMessagePassword.style.display = passwordOk ? "none" : "block";

            if (!emailOk || !passwordOk) {
                return;
            }

            try {
                const response = await fetch(config.signupEndpoint || "connection_files/signup.php", {
                    method: "POST",
                    body: new FormData(signupForm),
                    cache: "no-cache"
                });
                const text = await response.text();
                const data = JSON.parse(text || "{}");
                if (!response.ok || !data.ok) {
                    throw new Error(data.error || "Registrazione non riuscita");
                }
                alert("Registrazione completata. Ora puoi accedere.");
                if (signupPanel) signupPanel.classList.add("d-none");
                if (loginPanel) loginPanel.classList.remove("d-none");
                signupForm.reset();
            } catch (error) {
                alert(error.message || "Registrazione non riuscita");
            }
        });
    }
})();

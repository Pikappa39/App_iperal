(function () {
    const config = window.appPasswordResetConfirmConfig || {};
    const form = document.getElementById("reset-password-form");
    const message = document.getElementById("message");

    if (!form || !message) {
        return;
    }

    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        message.className = "mt-3";
        message.textContent = "";

        const response = await fetch(config.endpoint || "connection_files/confirm_password_reset.php", {
            method: "POST",
            body: new FormData(form),
            cache: "no-cache"
        });
        const data = await response.json().catch(() => ({}));
        message.className = "mt-3 " + (data.ok ? "text-success" : "text-danger");
        message.textContent = data.message || data.error || "Si \u00E8 verificato un errore. Riprova.";

        if (data.ok) {
            form.reset();
            setTimeout(() => window.location.assign(config.loginUrl || "login_reg.php"), 1800);
        }
    });
})();

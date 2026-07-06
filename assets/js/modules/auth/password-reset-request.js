(function () {
    const config = window.appPasswordResetRequestConfig || {};
    const form = document.getElementById("forgot-password-form");
    const message = document.getElementById("message");

    function setMessage(text, isError) {
        if (!message) return;
        message.className = "mt-3 " + (isError ? "text-danger" : "text-success");
        message.textContent = text;
    }

    window.appTurnstileError = function () {
        setMessage("Il controllo di sicurezza non \u00E8 riuscito. Ricarica la pagina e riprova; se continua, disattiva temporaneamente estensioni che bloccano contenuti.", true);
        return true;
    };

    if (!form || !message) {
        return;
    }

    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        message.className = "mt-3";
        message.textContent = "";

        const formData = new FormData(form);
        if (config.turnstileEnabled && !formData.get("cf-turnstile-response")) {
            setMessage("Completa il controllo di sicurezza.", true);
            return;
        }

        const response = await fetch(config.endpoint || "connection_files/request_password_reset.php", {
            method: "POST",
            body: formData,
            cache: "no-cache"
        });
        const data = await response.json().catch(() => ({}));
        setMessage(data.message || data.error || "Si \u00E8 verificato un errore. Riprova.", !data.ok);
        if (data.ok) form.reset();
    });
})();

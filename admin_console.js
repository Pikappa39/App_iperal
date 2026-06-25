(function () {
    const countdown = document.querySelector("[data-admin-console-countdown]");
    const expiresAt = countdown ? Number(countdown.dataset.expiresAt || 0) : 0;

    function formatRemaining(seconds) {
        const minutes = Math.floor(seconds / 60).toString().padStart(2, "0");
        const rest = Math.floor(seconds % 60).toString().padStart(2, "0");
        return `${minutes}:${rest}`;
    }

    function refreshCountdown() {
        if (!countdown || !expiresAt) {
            return;
        }

        const remaining = Math.max(0, Math.floor(expiresAt - Date.now() / 1000));
        countdown.textContent = formatRemaining(remaining);
        countdown.classList.toggle("admin-console-timer--warning", remaining <= 120);

        if (remaining <= 0) {
            document.querySelectorAll("button, input, select").forEach((element) => {
                element.disabled = true;
            });
            window.location.reload();
        }
    }

    document.querySelectorAll("[data-copy-target]").forEach((button) => {
        button.addEventListener("click", async () => {
            const target = document.querySelector(button.dataset.copyTarget || "");
            if (!(target instanceof HTMLInputElement)) {
                return;
            }

            try {
                await navigator.clipboard.writeText(target.value);
                button.textContent = "Copiato";
                window.setTimeout(() => {
                    button.textContent = "Copia";
                }, 1600);
            } catch (error) {
                target.focus();
                target.select();
            }
        });
    });

    refreshCountdown();
    window.setInterval(refreshCountdown, 1000);
}());

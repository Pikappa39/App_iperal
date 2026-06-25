<?php
require __DIR__ . '/app_config.php';

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Clear-Site-Data: "cache", "storage"');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Ripristina MyOrari</title>
    <style>
        :root {
            color-scheme: light dark;
            --bg: #eef4fb;
            --panel: rgba(255, 255, 255, 0.72);
            --text: #14213d;
            --muted: #5d6475;
            --line: rgba(20, 33, 61, 0.14);
            --accent: #0d6efd;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #000;
                --panel: rgba(255, 255, 255, 0.08);
                --text: #f8fafc;
                --muted: #cbd5e1;
                --line: rgba(255, 255, 255, 0.18);
                --accent: #60a5fa;
            }
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            padding: 22px;
            background:
                radial-gradient(circle at top left, rgba(13, 110, 253, 0.18), transparent 34%),
                radial-gradient(circle at bottom right, rgba(56, 189, 248, 0.14), transparent 38%),
                var(--bg);
            color: var(--text);
            font-family: "Open Sans", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        main {
            width: min(100%, 520px);
            padding: 26px;
            border: 1px solid var(--line);
            border-radius: 26px;
            background: var(--panel);
            box-shadow: 0 24px 55px rgba(15, 23, 42, 0.18);
            backdrop-filter: blur(18px) saturate(145%);
        }

        h1 {
            margin: 0 0 10px;
            font-family: "Poppins", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-size: clamp(1.55rem, 5vw, 2.15rem);
            line-height: 1.08;
        }

        p {
            margin: 0 0 16px;
            color: var(--muted);
            line-height: 1.5;
        }

        button,
        a {
            width: 100%;
            min-height: 48px;
            display: inline-grid;
            place-items: center;
            border-radius: 16px;
            font: inherit;
            font-weight: 800;
            text-decoration: none;
        }

        button {
            border: 0;
            background: linear-gradient(135deg, var(--accent), #2744d6);
            color: #fff;
            box-shadow: 0 14px 28px rgba(13, 110, 253, 0.22);
        }

        a {
            margin-top: 10px;
            border: 1px solid var(--line);
            color: var(--text);
            background: rgba(255, 255, 255, 0.12);
        }

        #status {
            min-height: 1.5em;
            margin: 16px 0 0;
            font-weight: 800;
            color: var(--text);
        }

        .details {
            margin-top: 14px;
            padding: 12px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.12);
            color: var(--muted);
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
<main>
    <h1>Ripristina MyOrari</h1>
    <p>Usa questa pagina se l'app si apre ma i pulsanti non rispondono, oppure se la PWA è rimasta bloccata dopo un aggiornamento.</p>
    <button type="button" id="resetBtn">Ripristina app su questo dispositivo</button>
    <a href="index.php?reset=1">Torna all'app</a>
    <p id="status" role="status" aria-live="polite"></p>
    <div class="details">
        Versione server: <?php echo htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8'); ?>.
        Il ripristino elimina cache locale, service worker e preferenze salvate su questo browser. Non elimina l'account.
    </div>
</main>

<script>
(function () {
    const button = document.getElementById("resetBtn");
    const status = document.getElementById("status");

    function setStatus(message) {
        status.textContent = message;
    }

    async function resetApp() {
        button.disabled = true;
        setStatus("Ripristino in corso...");

        try {
            if ("serviceWorker" in navigator) {
                const registrations = await navigator.serviceWorker.getRegistrations();
                await Promise.all(registrations.map((registration) => registration.unregister()));
            }

            if ("caches" in window) {
                const keys = await caches.keys();
                await Promise.all(keys.map((key) => caches.delete(key)));
            }

            try {
                localStorage.clear();
                sessionStorage.clear();
            } catch (error) {
                // Alcuni browser possono bloccare lo storage: il reset cache resta valido.
            }

            setStatus("Ripristino completato. Riapro l'app...");
            window.setTimeout(function () {
                window.location.replace("index.php?reset=" + Date.now());
            }, 900);
        } catch (error) {
            console.error(error);
            setStatus("Non sono riuscito a completare il ripristino. Chiudi il browser e riapri questa pagina.");
            button.disabled = false;
        }
    }

    button.addEventListener("click", resetApp);
})();
</script>
</body>
</html>

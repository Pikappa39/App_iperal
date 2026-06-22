<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/app_config.php';
require __DIR__ . '/connection_files/push_lib.php';
app_session_start();

$pushPublicKey = '';
if (isset($_SESSION['user'])) {
    try {
        $pushPublicKey = appPushPublicKey();
    } catch (Throwable $e) {
        error_log('Configurazione push non disponibile: ' . $e->getMessage());
    }
}

$clientBootstrap = [
    'userSession' => $_SESSION['user'] ?? null,
    'userKey' => $_SESSION['user']['cf'] ?? '',
    'capo' => $_SESSION['user']['capo'] ?? '0',
    'avatar' => $_SESSION['user']['avatar'] ?? 'default',
    'reparto' => $_SESSION['user']['reparto'] ?? 'Jolly',
    'departments' => appDepartments(),
    'pushPublicKey' => $pushPublicKey,
];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="sfera.css?v=<?php echo rawurlencode(APP_VERSION); ?>">
    <link rel="manifest" href="manifest.php?v=<?php echo rawurlencode(APP_VERSION); ?>">
    <link rel="apple-touch-icon" href="img/icon-192.png?v=<?php echo rawurlencode(APP_VERSION); ?>">
    <meta name="theme-color" content="#0d6efd">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>La mia pagina</title>
</head>
<body>
<div class="app-shell">
  <header class="app-header">
    <div class="app-header__title">
      <h3 id="titolo">App Iperal</h3>
    </div>
    <div class="app-header__actions">
      <?php if (isset($_SESSION["user"])): ?>
        <div class="dropdown">
          <button class="btn avatar-toggle dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Menu profilo">
            <img id="profileImg" src="img/default.png" width="40" height="40" class="rounded-circle" alt="Profilo">
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><button type="button"  class="dropdown-item" id="profileItem" >Profilo</button></li>
            <li><button type="button" class="dropdown-item" id="checkUpdatesItem">Controlla aggiornamenti</button></li>
            <li><button type="button" class="dropdown-item" id="scheduleChangesItem">Aggiornamenti orari</button></li>
            <li><button type="button" class="dropdown-item" id="communicationsItem">Comunicazioni</button></li>
            <li><button type="button" class="dropdown-item d-none" id="noteAdminItem">Note</button></li>
            <li><button type="button" class="dropdown-item " id="setting">Impostazioni</button></li>
            <li><a class="dropdown-item" id="logoutLink" href="connection_files/logout.php">Logout</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item d-none" id="uploadItem" href="testjs.php">Upload</a></li>
          </ul>
        </div>
      <?php else: ?>
        <a href="login_reg.php" class="btn btn-primary">Login/Registrazione</a>
      <?php endif; ?>
    </div>
  </header>
<!-- Update Banner -->
  <div id="updateBanner" class="update-banner app-hidden" hidden role="status" aria-live="polite">
    <span>Nuova versione disponibile</span>
    <button type="button" id="updateNowBtn" class="btn btn-light btn-sm">Aggiorna</button>
  </div>

  <div id="appToast" class="app-toast app-hidden" hidden role="status" aria-live="polite"></div>

  <section id="homeScreen" class="home-screen">
    <div class="home-actions">
      <button type="button" id="openOrari" class="home-orari sfera">Orari</button>
      <?php if (isset($_SESSION['user']) && in_array((int) ($_SESSION['user']['capo'] ?? 0), [1, 3], true)): ?>
        <a href="addetti.php" class="home-orari sfera home-addetti">Addetti</a>
      <?php endif; ?>
    </div>

  </section>
<!-- barra di navigazione, appare solo quando si è in una pagina secondaria e non nella home -->
  <div class="app-toolbar app-hidden" hidden>
    <button type="button" id="homebtn" class="btn btn-outline-dark btn-sm icon-btn" aria-label="Torna alla home" title="Home">
      <svg class="icon-btn__icon" viewBox="0 0 24 24" aria-hidden="true">
        <path d="M3 11.5 12 4l9 7.5"></path>
        <path d="M5.5 10.5V20h13v-9.5"></path>
        <path d="M9.5 20v-6h5v6"></path>
      </svg>
    </button>
    <button type="button" id="backbtn" class="btn btn-outline-dark btn-sm">Indietro</button>
  </div>

  <main id="contenitore" class="calendario mt-4 app-hidden" hidden></main>

  <footer class="app-version" aria-label="Versione applicazione">
    Versione <?php echo htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8'); ?>
  </footer>
</div>

<script>
window.appBootstrap = <?php echo json_encode(
    $clientBootstrap,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
); ?>;
window.userSession = window.appBootstrap.userSession;
window.userKey = window.appBootstrap.userKey;
window.capo = window.appBootstrap.capo;
window.avatar = window.appBootstrap.avatar;
window.reparto = window.appBootstrap.reparto;
window.pushPublicKey = window.appBootstrap.pushPublicKey;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="app_core.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
<script src="app_calendar.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
<script src="app_notes.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
<script src="app_communications.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
<script src="userhome.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
<script src="app_init.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
<script src="setting.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
<script>
(function () {
const updateBanner = document.getElementById("updateBanner");
const updateNowBtn = document.getElementById("updateNowBtn");
const checkUpdatesItem = document.getElementById("checkUpdatesItem");
const appToast = document.getElementById("appToast");
let waitingWorker = null;
let reloadingAfterUpdate = false;
let serviceWorkerRegistration = null;
let pushStateLoaded = false;

function showUpdateBanner() {
    if (!updateBanner) {
        return;
    }

    updateBanner.hidden = false;
    updateBanner.classList.remove("app-hidden");
}

function showAppToast(message) {
    if (!appToast) {
        return;
    }

    appToast.textContent = message;
    appToast.hidden = false;
    appToast.classList.remove("app-hidden");

    window.clearTimeout(showAppToast.timer);
    showAppToast.timer = window.setTimeout(function () {
        appToast.hidden = true;
        appToast.classList.add("app-hidden");
    }, 3200);
}
window.showAppToast = showAppToast;

function base64UrlToUint8Array(base64String) {
    const padding = "=".repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding)
        .replace(/-/g, "+")
        .replace(/_/g, "/");
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);

    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }

    return outputArray;
}

async function isPushSubscriptionActiveForCurrentUser(subscription) {
    if (!subscription) {
        return false;
    }

    try {
        const response = await fetch("connection_files/push_subscription_status.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(subscription.toJSON()),
            cache: "no-store"
        });
        const data = await response.json();
        return !!(response.ok && data.ok && data.enabled);
    } catch (error) {
        console.error("Errore nel controllo ownership push", error);
        return false;
    }
}

async function deactivatePushSubscriptionForCurrentDevice(subscription) {
    if (!subscription) {
        return;
    }

    const response = await fetch("connection_files/push_unsubscribe.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(subscription.toJSON()),
        cache: "no-store",
        keepalive: true
    });
    if (!response.ok) {
        throw new Error("Impossibile disattivare le notifiche");
    }
}

async function refreshPushState(registration) {
    if (!registration || !registration.pushManager || pushStateLoaded) {
        return;
    }

    pushStateLoaded = true;

    try {
        const subscription = await registration.pushManager.getSubscription();
        window.dispatchEvent(new CustomEvent("app:push-state", {
            detail: { enabled: await isPushSubscriptionActiveForCurrentUser(subscription) }
        }));
    } catch (error) {
        console.error("Errore nel controllo push", error);
    }
}

async function enablePushNotifications() {
    if (!("Notification" in window) || !("PushManager" in window)) {
        showAppToast("Le notifiche push non sono supportate su questo browser");
        return;
    }

    if (!serviceWorkerRegistration) {
        showAppToast("Il service worker non è ancora pronto");
        return;
    }

    try {
        const currentPermission = Notification.permission;
        const permission = currentPermission === "default"
            ? await Notification.requestPermission()
            : currentPermission;

        if (permission !== "granted") {
            showAppToast("Permesso notifiche non concesso");
            return;
        }

        const publicKey = (window.pushPublicKey || "").toString().trim();
        if (!publicKey) {
            showAppToast("Chiave push non disponibile");
            return;
        }

        const existingSubscription = await serviceWorkerRegistration.pushManager.getSubscription();
        const subscription = existingSubscription || await serviceWorkerRegistration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: base64UrlToUint8Array(publicKey)
        });

        const response = await fetch("connection_files/push_subscribe.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify(subscription.toJSON()),
            cache: "no-store"
        });

        const data = await response.json();
        if (!response.ok || !data.ok) {
            throw new Error(data.error || "Errore nel salvataggio della subscription");
        }

        window.dispatchEvent(new CustomEvent("app:push-state", {
            detail: { enabled: true }
        }));
        showAppToast("Notifiche attivate");
    } catch (error) {
        console.error("Errore push", error);
        showAppToast(error.message || "Non riesco ad attivare le notifiche");
    }
}

window.appNotifications = {
    async isEnabled() {
        if (!("serviceWorker" in navigator)) {
            return false;
        }

        const registration = serviceWorkerRegistration || await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();
        return isPushSubscriptionActiveForCurrentUser(subscription);
    },
    enable: enablePushNotifications
};

const logoutLink = document.getElementById("logoutLink");
if (logoutLink) {
    logoutLink.addEventListener("click", function (event) {
        event.preventDefault();
        const logoutUrl = logoutLink.href;

        (async function () {
            try {
                const registration = serviceWorkerRegistration || await navigator.serviceWorker.ready;
                const subscription = await registration.pushManager.getSubscription();
                await Promise.race([
                    deactivatePushSubscriptionForCurrentDevice(subscription),
                    new Promise((resolve) => window.setTimeout(resolve, 1200))
                ]);
            } catch (error) {
                console.error("Disattivazione notifiche al logout non riuscita", error);
            } finally {
                window.location.assign(logoutUrl);
            }
        })();
    });
}

if ('serviceWorker' in navigator) {
    if (sessionStorage.getItem('appUpdated') === '1') {
        sessionStorage.removeItem('appUpdated');
        window.addEventListener('load', function () {
            showAppToast('App aggiornata alla nuova versione');
        });
    }

    navigator.serviceWorker.addEventListener('controllerchange', function () {
        if (reloadingAfterUpdate) {
            return;
        }

        reloadingAfterUpdate = true;
        sessionStorage.setItem('appUpdated', '1');
        window.location.reload();
    });

    window.addEventListener('load', function () {
        navigator.serviceWorker.register('service-worker.php').then(function (registration) {
            serviceWorkerRegistration = registration;
            refreshPushState(registration);

            const checkWaiting = function () {
                if (registration.waiting) {
                    waitingWorker = registration.waiting;
                    showUpdateBanner();
                }
            };

            registration.addEventListener('updatefound', function () {
                const newWorker = registration.installing;
                if (!newWorker) {
                    return;
                }

                newWorker.addEventListener('statechange', function () {
                    if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                        waitingWorker = newWorker;
                        showUpdateBanner();
                    }
                });
            });

            checkWaiting();

            registration.update().catch(function () {});
            window.setInterval(function () {
                registration.update().catch(function () {});
            }, 60 * 60 * 1000);
        }).catch(function (error) {
            console.error('Service worker registration failed:', error);
        });
    });
}

function checkForUpdatesManually() {
    if (!serviceWorkerRegistration) {
        showAppToast('Controllo aggiornamenti non ancora pronto');
        return;
    }

    serviceWorkerRegistration.update().then(function () {
        if (serviceWorkerRegistration.waiting) {
            waitingWorker = serviceWorkerRegistration.waiting;
            showUpdateBanner();
            return;
        }

        if (serviceWorkerRegistration.installing) {
            showAppToast('Aggiornamento in preparazione');
            return;
        }

        showAppToast("Stai usando l'ultima versione");
    }).catch(function () {
        showAppToast('Non riesco a controllare gli aggiornamenti');
    });
}

if (updateNowBtn) {
    updateNowBtn.addEventListener('click', function () {
        if (waitingWorker) {
            updateBanner.hidden = true;
            updateBanner.classList.add("app-hidden");
            waitingWorker.postMessage({ type: 'SKIP_WAITING' });
        }
    });
}

if (checkUpdatesItem) {
    checkUpdatesItem.addEventListener('click', checkForUpdatesManually);
}

const avatar = window.avatar || "default";
let reparto = window.reparto || "Jolly";
const profileImg = document.querySelector("#profileImg");
if (profileImg) {
    profileImg.src = "img/" + avatar + ".png";
}

const capo = String(window.capo ?? "0");
const uploadItem = document.querySelector("#uploadItem");
if (uploadItem && (capo === "1" || capo==="3")) {
    uploadItem.classList.remove("d-none");
}
if (noteAdminItem && (capo === "1" || capo==="3")) {
    noteAdminItem.classList.remove("d-none");
}
})();
</script>
</body>
</html>

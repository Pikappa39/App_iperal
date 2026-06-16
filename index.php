<?php
require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/app_config.php';
app_session_start();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="sfera.css?v=<?php echo rawurlencode(APP_VERSION); ?>">
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" href="img/icon-192.png">
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
            <li><a class="dropdown-item" >Profilo</a></li>
            <li><button type="button" class="dropdown-item" id="checkUpdatesItem">Controlla aggiornamenti</button></li>
            <li><button type="button" class="dropdown-item d-none" id="noteAdminItem">Note</button></li>
            <li><a class="dropdown-item" href="connection_files/logout.php">Logout</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item d-none" id="uploadItem" href="testjs.php">Upload</a></li>
          </ul>
        </div>
      <?php else: ?>
        <a href="login_reg.php" class="btn btn-primary">Login/Registrazione</a>
      <?php endif; ?>
    </div>
  </header>

  <div id="updateBanner" class="update-banner app-hidden" hidden role="status" aria-live="polite">
    <span>Nuova versione disponibile</span>
    <button type="button" id="updateNowBtn" class="btn btn-light btn-sm">Aggiorna</button>
  </div>

  <div id="appToast" class="app-toast app-hidden" hidden role="status" aria-live="polite"></div>

  <section id="homeScreen" class="home-screen">
    <button type="button" id="openOrari" class="home-orari sfera">Orari</button>

  </section>

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
window.userSession = <?php
echo json_encode(
    ($_SESSION["user"]["nome"] ?? "") . " " . ($_SESSION["user"]["cognome"] ?? "")
);
?>;
window.userKey = <?php echo json_encode($_SESSION['user']['cf'] ?? ''); ?>;
window.capo = "<?php echo $_SESSION['user']['capo'] ?? '0'; ?>";
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="app_core.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
<script src="app_calendar.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
<script src="app_notes.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
<script src="app_init.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
<script>
(function () {
const updateBanner = document.getElementById("updateBanner");
const updateNowBtn = document.getElementById("updateNowBtn");
const checkUpdatesItem = document.getElementById("checkUpdatesItem");
const appToast = document.getElementById("appToast");
let waitingWorker = null;
let reloadingAfterUpdate = false;
let serviceWorkerRegistration = null;

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

const avatar = "<?php echo $_SESSION['user']['avatar'] ?? 'default'; ?>";
const profileImg = document.querySelector("#profileImg");
if (profileImg) {
    profileImg.src = "img/" + avatar + ".png";
}

const capo = window.capo || "0";
const uploadItem = document.querySelector("#uploadItem");
const noteAdminItem = document.querySelector("#noteAdminItem");
if (uploadItem && capo === "1") {
    uploadItem.classList.remove("d-none");
}
if (noteAdminItem && capo === "1") {
    noteAdminItem.classList.remove("d-none");
}
})();
</script>
</body>
</html>

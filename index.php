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

$releaseMeta = [];
$releaseMetaPath = __DIR__ . '/release_meta.json';
if (is_file($releaseMetaPath)) {
    $decodedReleaseMeta = json_decode((string) file_get_contents($releaseMetaPath), true);
    if (is_array($decodedReleaseMeta)) {
        $releaseMeta = $decodedReleaseMeta;
    }
}

$clientBootstrap = [
    'userSession' => $_SESSION['user'] ?? null,
    'userKey' => $_SESSION['user']['cf'] ?? '',
    'capo' => $_SESSION['user']['capo'] ?? '0',
    'avatar' => $_SESSION['user']['avatar'] ?? 'default',
    'avatars' => appAvailableAvatars(),
    'reparto' => $_SESSION['user']['reparto'] ?? 'Jolly',
    'departments' => appDepartments(),
    'pushPublicKey' => $pushPublicKey,
    'csrfToken' => app_csrf_token(),
    'appVersion' => APP_VERSION,
    'releaseMeta' => $releaseMeta,
];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <script>
    (function () {
        try {
            document.documentElement.dataset.theme = localStorage.getItem("app-iperal-theme") === "dark" ? "dark" : "light";
        } catch (error) {
            document.documentElement.dataset.theme = "light";
        }
    })();
    </script>
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
<link rel="icon" type="image/png" href="/favicon.png">
    
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
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
            <li><button type="button" class="dropdown-item" id="guideItem">Guida</button></li>
            <li><button type="button" class="dropdown-item" id="checkUpdatesItem">Controlla aggiornamenti</button></li>
            <li><button type="button" class="dropdown-item" id="repairAppItem">Ripristina app</button></li>
            <li><button type="button" class="dropdown-item " id="setting">Impostazioni</button></li>
            <li>
              <form id="logoutForm" action="connection_files/logout.php" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(app_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <button class="dropdown-item" id="logoutLink" type="submit">Logout</button>
              </form>
            </li>
          </ul>
        </div>
      <?php else: ?>
        <a href="login_reg.php" class="btn btn-primary">Login/Registrazione</a>
      <?php endif; ?>
    </div>
  </header>
<!-- Update Banner -->
  <div id="updateBanner" class="update-banner app-hidden" hidden role="status" aria-live="polite">
    <span>Completa aggiornamento disponibile</span>
    <button type="button" id="updateNowBtn" class="btn btn-light btn-sm">Completa</button>
  </div>

  <div id="appToast" class="app-toast app-hidden" hidden role="status" aria-live="polite"></div>

  <div id="changelogDialog" class="changelog-dialog app-hidden" hidden role="dialog" aria-modal="true" aria-labelledby="changelogTitle">
    <div class="changelog-dialog__panel">
      <button type="button" id="changelogCloseBtn" class="changelog-dialog__close" aria-label="Chiudi novità">×</button>
      <p class="changelog-dialog__eyebrow">Aggiornamento disponibile</p>
      <h2 id="changelogTitle">Novità dell'app</h2>
      <p id="changelogSubtitle" class="changelog-dialog__subtitle"></p>
      <div id="changelogBody" class="changelog-dialog__body"></div>
      <button type="button" id="changelogOkBtn" class="btn btn-primary changelog-dialog__action">Ho capito</button>
    </div>
  </div>

  <div id="repairDialog" class="changelog-dialog app-hidden" hidden role="dialog" aria-modal="true" aria-labelledby="repairTitle">
    <div class="changelog-dialog__panel">
      <button type="button" id="repairCloseBtn" class="changelog-dialog__close" aria-label="Chiudi ripristino">×</button>
      <p class="changelog-dialog__eyebrow">Ripristino app</p>
      <h2 id="repairTitle">Rimettiamo in ordine questa installazione</h2>
      <p class="changelog-dialog__subtitle" id="repairSubtitle">Usalo se la PWA si apre ma i pulsanti non rispondono, oppure se resta bloccata dopo un aggiornamento.</p>
      <div class="changelog-dialog__body">
        <p>Il ripristino elimina cache locale, service worker e preferenze salvate su questo browser. Non elimina il tuo account o i dati sul server.</p>
        <p id="repairStatus" class="repair-dialog__status" aria-live="polite"></p>
      </div>
      <button type="button" id="repairNowBtn" class="btn btn-primary changelog-dialog__action">Ripristina su questo dispositivo</button>
    </div>
  </div>

  <section id="homeScreen" class="home-screen">
    <div class="home-dashboard">
      <div class="home-actions">
        <button type="button" id="openOrari" class="home-orari sfera">
          <span class="home-action-icon" aria-hidden="true">⏱</span>
          <span class="home-action-label">Orari</span>
        </button>
        <button type="button" id="communicationsItem" class="home-orari sfera home-communications">
          <span class="home-action-icon" aria-hidden="true">✉</span>
          <span class="home-action-label">Comunicazioni</span>
        </button>
        <button type="button" id="scheduleAdjustmentsItem" class="home-orari sfera home-adjustments">
          <span class="home-action-icon" aria-hidden="true">±</span>
          <span class="home-action-label">Richieste ore</span>
        </button>
      </div>

      <?php if (isset($_SESSION['user'])): ?>
        <div class="home-tools" aria-label="Funzioni operative">
          <button type="button" class="home-tool" id="scheduleChangesItem">
            <span class="home-tool-icon" aria-hidden="true">↻</span>
            <strong>Aggiornamenti orari</strong>
            <span>Variazioni pubblicate</span>
          </button>
          <?php if (in_array((int) ($_SESSION['user']['capo'] ?? 0), [1, 2, 3], true)): ?>
            <a href="addetti.php" class="home-tool">
              <span class="home-tool-icon" aria-hidden="true">◎</span>
              <strong>Addetti</strong>
              <span>Utenti e associazioni</span>
            </a>
            <a class="home-tool" id="uploadItem" href="testjs.php">
              <span class="home-tool-icon" aria-hidden="true">↑</span>
              <strong>Upload</strong>
              <span>Carica file orari</span>
            </a>
            <button type="button" class="home-tool d-none" id="noteAdminItem">
              <span class="home-tool-icon" aria-hidden="true">✎</span>
              <strong>Note</strong>
              <span>Gestione note reparto</span>
            </button>
          <?php endif; ?>
          <?php if (in_array((int) ($_SESSION['user']['capo'] ?? 0), [1, 3], true)): ?>
            <button type="button" class="home-tool" id="departmentOverviewItem">
              <span class="home-tool-icon" aria-hidden="true">▦</span>
              <strong>Panoramica reparto</strong>
              <span>Vista settimanale</span>
            </button>
          <?php endif; ?>
          <?php if ((int) ($_SESSION['user']['capo'] ?? 0) === 3): ?>
            <a href="admin_console.php" class="home-tool home-tool--admin">
              <span class="home-tool-icon" aria-hidden="true">⌘</span>
              <strong>Console</strong>
              <span>Supervisione sistema</span>
            </a>
          <?php endif; ?>
        </div>
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
window.appCsrfToken = window.appBootstrap.csrfToken;
window.appVersion = window.appBootstrap.appVersion;
window.appReleaseMeta = window.appBootstrap.releaseMeta || {};
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="app_core.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
<script src="app_calendar.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
<script src="app_adjustments.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
<script src="app_department_overview.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
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
const changelogDialog = document.getElementById("changelogDialog");
const changelogCloseBtn = document.getElementById("changelogCloseBtn");
const changelogOkBtn = document.getElementById("changelogOkBtn");
const changelogTitle = document.getElementById("changelogTitle");
const changelogSubtitle = document.getElementById("changelogSubtitle");
const changelogBody = document.getElementById("changelogBody");
const repairDialog = document.getElementById("repairDialog");
const repairCloseBtn = document.getElementById("repairCloseBtn");
const repairNowBtn = document.getElementById("repairNowBtn");
const repairStatus = document.getElementById("repairStatus");
const repairAppItem = document.getElementById("repairAppItem");
let waitingWorker = null;
let reloadingAfterUpdate = false;
let serviceWorkerRegistration = null;
let pushStateLoaded = false;
let pushStateEnabled = false;
let pushStatePromise = null;
let isAppStartupPhase = true;
let pushKeyRotationNoticeShown = false;

// Se l'app viene riaperta dopo un aggiornamento, la pagina può essere già
// nuova mentre il service worker precedente è ancora attivo. Completiamo
// automaticamente il passaggio finché l'utente non ha iniziato a usare l'app.
function finishStartupPhase() {
    isAppStartupPhase = false;
}

window.setTimeout(finishStartupPhase, 8000);
["pointerdown", "keydown", "touchstart", "input"].forEach(function (eventName) {
    window.addEventListener(eventName, finishStartupPhase, {
        once: true,
        capture: true,
    });
});

function showUpdateBanner() {
    if (!updateBanner) {
        return;
    }

    updateBanner.hidden = false;
    updateBanner.classList.remove("app-hidden");
}

function activateWaitingWorker(worker) {
    if (!worker) {
        return;
    }

    waitingWorker = worker;
    updateBanner.hidden = true;
    updateBanner.classList.add("app-hidden");
    worker.postMessage({ type: "SKIP_WAITING" });
}

function handleWaitingWorker(worker) {
    if (!worker) {
        return;
    }

    waitingWorker = worker;
    if (isAppStartupPhase) {
        activateWaitingWorker(worker);
        return;
    }

    showUpdateBanner();
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

function appStorageGet(key) {
    try {
        return window.localStorage.getItem(key);
    } catch (error) {
        return null;
    }
}

function appStorageSet(key, value) {
    try {
        window.localStorage.setItem(key, value);
    } catch (error) {
        // Lo storage può essere disabilitato: in quel caso il popup resta non persistente.
    }
}

function appStorageRemove(key) {
    try {
        window.localStorage.removeItem(key);
    } catch (error) {
        // Storage non disponibile: niente da ripulire.
    }
}

function setRepairStatus(message) {
    if (repairStatus) {
        repairStatus.textContent = message;
    }
}

function showRepairDialog(reason = "") {
    if (!repairDialog) {
        window.location.assign("reset_app.php");
        return;
    }

    setRepairStatus(reason);
    repairDialog.hidden = false;
    repairDialog.classList.remove("app-hidden");
    window.setTimeout(function () {
        if (repairNowBtn) {
            repairNowBtn.focus();
        }
    }, 0);
}

function hideRepairDialog() {
    if (!repairDialog) {
        return;
    }

    repairDialog.hidden = true;
    repairDialog.classList.add("app-hidden");
    setRepairStatus("");
}

async function resetInstalledApp() {
    if (!repairNowBtn) {
        window.location.assign("reset_app.php");
        return;
    }

    repairNowBtn.disabled = true;
    setRepairStatus("Ripristino in corso...");
    try {
        if ("serviceWorker" in navigator) {
            const registrations = await navigator.serviceWorker.getRegistrations();
            await Promise.all(registrations.map((registration) => {
                const worker = registration.active || registration.waiting || registration.installing;
                if (worker) {
                    worker.postMessage({ type: "CLEAR_APP_CACHE" });
                }
                return Promise.resolve();
            }));
            await new Promise((resolve) => window.setTimeout(resolve, 250));
            await Promise.all(registrations.map((registration) => registration.unregister()));
        }
        if ("caches" in window) {
            const keys = await caches.keys();
            await Promise.all(keys.map((key) => caches.delete(key)));
        }
        try {
            window.localStorage.clear();
            window.sessionStorage.clear();
        } catch (error) {
            // Se lo storage è bloccato, completiamo comunque il reset cache.
        }
        setRepairStatus("Ripristino completato. Riapro l'app...");
        window.setTimeout(function () {
            window.location.replace("index.php?reset=" + Date.now());
        }, 700);
    } catch (error) {
        console.error("Ripristino app non riuscito", error);
        repairNowBtn.disabled = false;
        setRepairStatus("Non sono riuscito a completare il ripristino. Apro la pagina dedicata...");
        window.setTimeout(function () {
            window.location.assign("reset_app.php");
        }, 900);
    }
}

function registerAppBootSuccess() {
    appStorageRemove("app-iperal-boot-warning");
    appStorageSet("app-iperal-last-good-version", String(window.appVersion || ""));
    appStorageSet("app-iperal-last-good-at", String(Date.now()));
}

async function checkAppHealth() {
    if (!navigator.onLine) {
        return;
    }

    const controllerMissing = "serviceWorker" in navigator && !navigator.serviceWorker.controller;
    const lastGoodVersion = appStorageGet("app-iperal-last-good-version");
    try {
        const response = await fetch("manifest.php?health=" + encodeURIComponent(String(window.appVersion || "")) + "&t=" + Date.now(), {
            cache: "no-store"
        });
        if (!response.ok) {
            throw new Error("health-http-" + response.status);
        }
        registerAppBootSuccess();
        if (controllerMissing && lastGoodVersion && lastGoodVersion !== String(window.appVersion || "")) {
            showAppToast("App aggiornata: riapri se qualcosa non risponde");
        }
    } catch (error) {
        const previousWarning = Number(appStorageGet("app-iperal-boot-warning") || "0");
        appStorageSet("app-iperal-boot-warning", String(Date.now()));
        if (previousWarning > 0 && Date.now() - previousWarning < 10 * 60 * 1000) {
            showRepairDialog("Il controllo dell'app non risponde. Se i pulsanti restano bloccati, prova il ripristino.");
        } else {
            showAppToast("Connessione app instabile, riprovo al prossimo avvio");
        }
    }
}

function compareAppVersions(left, right) {
    const parse = function (value) {
        return String(value || "")
            .split(".")
            .map(function (part) {
                return Number.parseInt(part, 10) || 0;
            });
    };
    const a = parse(left);
    const b = parse(right);
    for (let i = 0; i < 3; i += 1) {
        if ((a[i] || 0) > (b[i] || 0)) return 1;
        if ((a[i] || 0) < (b[i] || 0)) return -1;
    }
    return 0;
}

function releaseEntryDateLabel(value) {
    const date = new Date(String(value || ""));
    if (Number.isNaN(date.getTime())) {
        return "";
    }

    return new Intl.DateTimeFormat("it-IT", {
        day: "2-digit",
        month: "long",
        year: "numeric"
    }).format(date);
}

function getChangelogEntries(lastSeenVersion, currentVersion) {
    const meta = window.appReleaseMeta || {};
    const releases = Array.isArray(meta.releases) ? meta.releases : [];
    const entries = releases
        .filter(function (entry) {
            const version = String(entry.version || "");
            if (!version) return false;
            if (compareAppVersions(version, currentVersion) > 0) return false;
            return lastSeenVersion
                ? compareAppVersions(version, lastSeenVersion) > 0
                : compareAppVersions(version, currentVersion) === 0;
        })
        .sort(function (a, b) {
            return compareAppVersions(a.version, b.version);
        });

    if (entries.length > 0) {
        return entries;
    }

    if (!lastSeenVersion || compareAppVersions(lastSeenVersion, currentVersion) < 0) {
        return [{
            version: currentVersion,
            previous_version: lastSeenVersion || "",
            description: "Aggiornamenti e miglioramenti dell'app.",
            released_at: meta.updated_at || ""
        }];
    }

    return [];
}

function hideChangelogDialog() {
    if (!changelogDialog) {
        return;
    }

    changelogDialog.hidden = true;
    changelogDialog.classList.add("app-hidden");
    appStorageSet("app-iperal-changelog-version", String(window.appVersion || ""));
}

function showChangelogIfNeeded() {
    const currentVersion = String(window.appVersion || "").trim();
    if (!currentVersion || !changelogDialog || !changelogBody) {
        return;
    }

    const lastSeenVersion = appStorageGet("app-iperal-changelog-version");
    if (lastSeenVersion === currentVersion) {
        return;
    }

    const entries = getChangelogEntries(lastSeenVersion, currentVersion);
    if (entries.length === 0) {
        appStorageSet("app-iperal-changelog-version", currentVersion);
        return;
    }

    const title = lastSeenVersion
        ? "Aggiornamento completato"
        : "Novità dell'app";
    const subtitle = lastSeenVersion
        ? "Versione " + lastSeenVersion + " → " + currentVersion
        : "Versione " + currentVersion;

    changelogTitle.textContent = title;
    changelogSubtitle.textContent = subtitle;
    changelogBody.innerHTML = "";

    const list = document.createElement("div");
    list.className = "changelog-dialog__list";
    entries.forEach(function (entry) {
        const item = document.createElement("article");
        item.className = "changelog-dialog__item";

        const heading = document.createElement("strong");
        heading.textContent = "v" + String(entry.version || currentVersion);

        const date = releaseEntryDateLabel(entry.released_at);
        if (date) {
            const small = document.createElement("span");
            small.textContent = date;
            heading.appendChild(small);
        }

        const description = document.createElement("p");
        description.textContent = String(entry.description || "Aggiornamenti e miglioramenti dell'app.");

        item.append(heading, description);
        list.appendChild(item);
    });

    changelogBody.appendChild(list);
    changelogDialog.hidden = false;
    changelogDialog.classList.remove("app-hidden");
    window.setTimeout(function () {
        if (changelogOkBtn) {
            changelogOkBtn.focus();
        }
    }, 0);
}

if (changelogCloseBtn) {
    changelogCloseBtn.addEventListener("click", hideChangelogDialog);
}
if (changelogOkBtn) {
    changelogOkBtn.addEventListener("click", hideChangelogDialog);
}
if (changelogDialog) {
    changelogDialog.addEventListener("click", function (event) {
        if (event.target === changelogDialog) {
            hideChangelogDialog();
        }
    });
}
window.addEventListener("keydown", function (event) {
    if (event.key === "Escape" && changelogDialog && !changelogDialog.hidden) {
        hideChangelogDialog();
    }
    if (event.key === "Escape" && repairDialog && !repairDialog.hidden) {
        hideRepairDialog();
    }
});
window.addEventListener("load", showChangelogIfNeeded);
window.addEventListener("load", function () {
    window.setTimeout(checkAppHealth, 1200);
});

if (repairCloseBtn) {
    repairCloseBtn.addEventListener("click", hideRepairDialog);
}
if (repairDialog) {
    repairDialog.addEventListener("click", function (event) {
        if (event.target === repairDialog) {
            hideRepairDialog();
        }
    });
}
if (repairNowBtn) {
    repairNowBtn.addEventListener("click", resetInstalledApp);
}
if (repairAppItem) {
    repairAppItem.addEventListener("click", function () {
        showRepairDialog("Ripristino avviato dal menu profilo.");
    });
}

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

function pushSubscriptionUsesCurrentPublicKey(subscription) {
    const publicKey = (window.pushPublicKey || "").toString().trim();
    const applicationServerKey = subscription
        && subscription.options
        && subscription.options.applicationServerKey;

    if (!publicKey || !applicationServerKey) {
        return false;
    }

    let expectedKey;
    let actualKey;
    try {
        expectedKey = base64UrlToUint8Array(publicKey);
        actualKey = applicationServerKey instanceof ArrayBuffer
            ? new Uint8Array(applicationServerKey)
            : new Uint8Array(
                applicationServerKey.buffer,
                applicationServerKey.byteOffset || 0,
                applicationServerKey.byteLength
            );
    } catch (error) {
        return false;
    }

    if (expectedKey.length !== actualKey.length) {
        return false;
    }

    return expectedKey.every(function (value, index) {
        return value === actualKey[index];
    });
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
        headers: { "Content-Type": "application/json", "X-CSRF-Token": window.appCsrfToken },
        body: JSON.stringify(subscription.toJSON()),
        cache: "no-store",
        keepalive: true
    });
    if (!response.ok) {
        throw new Error("Impossibile disattivare le notifiche");
    }
}

async function removeOutdatedPushSubscription(subscription) {
    if (!subscription) {
        return;
    }

    try {
        await deactivatePushSubscriptionForCurrentDevice(subscription);
    } catch (error) {
        // L'unsubscribe del browser deve comunque avvenire: il server
        // eliminerà le eventuali righe residue durante la rotazione VAPID.
        console.error("Disattivazione server della vecchia subscription non riuscita", error);
    }

    try {
        await subscription.unsubscribe();
    } catch (error) {
        console.error("Rimozione della vecchia subscription non riuscita", error);
    }

    if (!pushKeyRotationNoticeShown) {
        pushKeyRotationNoticeShown = true;
        showAppToast("Per sicurezza riattiva le notifiche nelle impostazioni");
    }
}

async function refreshPushState(registration, options = {}) {
    if (!registration || !registration.pushManager) {
        return false;
    }

    if (pushStateLoaded && !options.force) {
        return pushStateEnabled;
    }

    if (pushStatePromise && !options.force) {
        return pushStatePromise;
    }

    pushStatePromise = (async function () {
        try {
            const subscription = await registration.pushManager.getSubscription();
            if (subscription && !pushSubscriptionUsesCurrentPublicKey(subscription)) {
                await removeOutdatedPushSubscription(subscription);
                pushStateLoaded = true;
                pushStateEnabled = false;
                window.dispatchEvent(new CustomEvent("app:push-state", {
                    detail: { enabled: false }
                }));
                return pushStateEnabled;
            }

            pushStateEnabled = await isPushSubscriptionActiveForCurrentUser(subscription);
            pushStateLoaded = true;
            window.dispatchEvent(new CustomEvent("app:push-state", {
                detail: { enabled: pushStateEnabled }
            }));
            return pushStateEnabled;
        } catch (error) {
            console.error("Errore nel controllo push", error);
            return false;
        } finally {
            pushStatePromise = null;
        }
    })();

    return pushStatePromise;
}

function setPushStateEnabled(enabled) {
    pushStateLoaded = true;
    pushStateEnabled = !!enabled;
    pushStatePromise = null;
    window.dispatchEvent(new CustomEvent("app:push-state", {
        detail: { enabled: pushStateEnabled }
    }));
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

        let subscription = await serviceWorkerRegistration.pushManager.getSubscription();
        if (subscription && !pushSubscriptionUsesCurrentPublicKey(subscription)) {
            await removeOutdatedPushSubscription(subscription);
            subscription = null;
        }

        subscription = subscription || await serviceWorkerRegistration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: base64UrlToUint8Array(publicKey)
        });

        const response = await fetch("connection_files/push_subscribe.php", {
            method: "POST",
        headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": window.appCsrfToken
            },
            body: JSON.stringify(subscription.toJSON()),
            cache: "no-store"
        });

        const data = await response.json();
        if (!response.ok || !data.ok) {
            throw new Error(data.error || "Errore nel salvataggio della subscription");
        }

        setPushStateEnabled(true);
        showAppToast("Notifiche attivate");
    } catch (error) {
        console.error("Errore push", error);
        showAppToast(error.message || "Non riesco ad attivare le notifiche");
    }
}

async function disablePushNotifications() {
    if (!("serviceWorker" in navigator)) {
        return;
    }

    const registration = serviceWorkerRegistration || await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.getSubscription();
    if (!subscription) {
        setPushStateEnabled(false);
        return;
    }

    await deactivatePushSubscriptionForCurrentDevice(subscription);
    await subscription.unsubscribe();
    setPushStateEnabled(false);
    showAppToast("Notifiche disattivate");
}

window.appNotifications = {
    async isEnabled() {
        if (!("serviceWorker" in navigator)) {
            return false;
        }

        const registration = serviceWorkerRegistration || await navigator.serviceWorker.ready;
        return refreshPushState(registration);
    },
    enable: enablePushNotifications,
    disable: disablePushNotifications
};

const logoutLink = document.getElementById("logoutLink");
const guideItem = document.getElementById("guideItem");
if (logoutLink) {
    logoutLink.addEventListener("click", function (event) {
        event.preventDefault();
        const logoutForm = document.getElementById("logoutForm");

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
                logoutForm.submit();
            }
        })();
    });
}

if (guideItem) {
    guideItem.addEventListener("click", function () {
        window.location.href = "guida.php";
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
        navigator.serviceWorker.register(
            'service-worker.php?v=<?php echo rawurlencode(APP_VERSION); ?>',
            { updateViaCache: 'none' }
        ).then(function (registration) {
            serviceWorkerRegistration = registration;

            const checkWaiting = function () {
                if (registration.waiting) {
                    handleWaitingWorker(registration.waiting);
                }
            };

            registration.addEventListener('updatefound', function () {
                const newWorker = registration.installing;
                if (!newWorker) {
                    return;
                }

                newWorker.addEventListener('statechange', function () {
                    if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                        handleWaitingWorker(newWorker);
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
            handleWaitingWorker(serviceWorkerRegistration.waiting);
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
            activateWaitingWorker(waitingWorker);
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
if (noteAdminItem && (capo === "1" || capo === "2" || capo === "3")) {
    noteAdminItem.classList.remove("d-none");
}
})();
</script>
</body>
</html>

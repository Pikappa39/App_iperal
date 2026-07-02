<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/app_config.php';
require __DIR__ . '/connection_files/push_lib.php';
app_session_start();

if (!isset($_SESSION['user'])) {
    $next = 'index.php';
    $queryString = (string) ($_SERVER['QUERY_STRING'] ?? '');
    if ($queryString !== '') {
        $next .= '?' . $queryString;
    }
    header('Location: login_reg.php?next=' . rawurlencode($next), true, 302);
    exit;
}

$pushPublicKey = '';
try {
    $pushPublicKey = appPushPublicKey();
} catch (Throwable $e) {
    error_log('Configurazione push non disponibile: ' . $e->getMessage());
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
    'customerOrderDepartments' => appCustomerOrderDepartments(),
    'canUseBoxOrders' => isset($_SESSION['user']) && appUserHasBoxInfo($_SESSION['user']),
    'pushPublicKey' => $pushPublicKey,
    'csrfToken' => app_csrf_token(),
    'appVersion' => APP_VERSION,
    'releaseMeta' => $releaseMeta,
];

$homeAssetVersion = rawurlencode(APP_VERSION);
$homeRole = (int) ($_SESSION['user']['capo'] ?? 0);
$homeCanManagePeople = in_array($homeRole, [1, 2, 3], true);

function app_home_theme_icon(string $name, string $assetVersion): string
{
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeVersion = htmlspecialchars($assetVersion, ENT_QUOTES, 'UTF-8');

    return '<span class="home-theme-icon">'
        . '<img class="home-theme-icon__img home-theme-icon__img--light" src="img/home-icon-ui-light-' . $safeName . '.webp?v=' . $safeVersion . '" alt="">'
        . '<img class="home-theme-icon__img home-theme-icon__img--dark" src="img/home-icon-ui-dark-' . $safeName . '.webp?v=' . $safeVersion . '" alt="">'
        . '</span>';
}

function app_home_tile_content(string $iconName, string $title, string $subtitle, string $assetVersion): string
{
    return '<span class="home-tile__icon" aria-hidden="true">'
        . app_home_theme_icon($iconName, $assetVersion)
        . '</span><span><strong>'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
        . '</strong><small>'
        . htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8')
        . '</small></span>';
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <script>
    (function () {
        try {
            var isDark = localStorage.getItem("app-iperal-theme") === "dark";
            document.documentElement.dataset.theme = isDark ? "dark" : "light";
            document.documentElement.style.backgroundColor = isDark ? "#07146a" : "#e8f2ff";
            if (isDark) {
                var preload = document.createElement("link");
                preload.rel = "preload";
                preload.as = "image";
                preload.href = "img/home-background-dark.webp";
                document.head.appendChild(preload);
            }
        } catch (error) {
            document.documentElement.dataset.theme = "light";
        }
    })();
    </script>
    <style>
    html,
    body {
        background-color: #e8f2ff;
    }
    html[data-theme="dark"],
    html[data-theme="dark"] body {
        background-color: #07146a;
    }
    </style>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="sfera.css?v=<?php echo rawurlencode(APP_VERSION); ?>">
    <link rel="manifest" href="manifest.php?v=<?php echo rawurlencode(APP_VERSION); ?>">
    <link rel="apple-touch-icon" href="img/icon-192.png?v=<?php echo rawurlencode(APP_VERSION); ?>">
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#e8f2ff">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#07146a">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
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
        <div class="notification-center">
          <button type="button" class="notification-center__button" id="notificationBell" aria-label="Notifiche app" aria-expanded="false">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M15.5 18.5a3.5 3.5 0 0 1-7 0"></path>
              <path d="M5 17h14l-1.6-2.4V10a5.4 5.4 0 0 0-10.8 0v4.6L5 17Z"></path>
            </svg>
            <strong id="notificationBadge" class="notification-center__badge app-hidden" hidden>0</strong>
          </button>
          <div id="notificationPanel" class="notification-center__panel app-hidden" hidden>
            <div class="notification-center__header">
              <strong>Notifiche</strong>
              <button type="button" id="notificationRefresh" class="notification-center__refresh">Aggiorna</button>
            </div>
            <div id="notificationList" class="notification-center__list">
              <p class="notification-center__empty">Caricamento...</p>
            </div>
          </div>
        </div>
        <button class="avatar-toggle home-profile-trigger" type="button" id="profileMenuTrigger" aria-expanded="false" aria-controls="profileDrawer" aria-label="Menu profilo">
          <img id="profileImg" src="img/default.webp?v=<?php echo rawurlencode(APP_VERSION); ?>" width="40" height="40" class="rounded-circle" alt="Profilo">
        </button>
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

  <?php if (isset($_SESSION["user"])): ?>
    <div class="profile-drawer-backdrop app-hidden" id="profileDrawerBackdrop" hidden></div>
    <aside class="profile-drawer app-hidden" id="profileDrawer" aria-hidden="true" aria-label="Menu profilo" hidden>
      <div class="profile-drawer__header">
        <img class="profile-drawer__avatar" src="img/<?php echo htmlspecialchars((string) ($_SESSION['user']['avatar'] ?? 'default'), ENT_QUOTES, 'UTF-8'); ?>.webp?v=<?php echo rawurlencode(APP_VERSION); ?>" alt="">
        <div>
          <strong><?php echo htmlspecialchars(trim((string) ($_SESSION['user']['nome'] ?? '') . ' ' . (string) ($_SESSION['user']['cognome'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></strong>
          <span><?php echo htmlspecialchars(appDepartments()[(string) ($_SESSION['user']['reparto'] ?? '')] ?? (string) ($_SESSION['user']['reparto'] ?? 'Reparto'), ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <button class="profile-drawer__close" type="button" id="profileDrawerClose" aria-label="Chiudi menu"></button>
      </div>

      <div class="profile-drawer__section">
        <p>Area personale</p>
        <button type="button" class="profile-drawer__item" id="profileItem">
          <span class="profile-drawer__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"></circle><path d="M5 21a7 7 0 0 1 14 0"></path></svg></span>
          <span><strong>Profilo</strong><small>Avatar e dati account</small></span>
        </button>
        <button type="button" class="profile-drawer__item" id="setting">
          <span class="profile-drawer__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3v3"></path><path d="M12 18v3"></path><path d="M3 12h3"></path><path d="M18 12h3"></path><circle cx="12" cy="12" r="4"></circle></svg></span>
          <span><strong>Impostazioni</strong><small>Tema e notifiche</small></span>
        </button>
        <button type="button" class="profile-drawer__item" id="guideItem">
          <span class="profile-drawer__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 5h10a4 4 0 0 1 4 4v10H9a4 4 0 0 0-4-4Z"></path><path d="M5 5v14"></path><path d="M9 9h6"></path><path d="M9 12h4"></path></svg></span>
          <span><strong>Guida</strong><small>Manuale e supporto</small></span>
        </button>
      </div>

      <div class="profile-drawer__section">
        <p>Sistema</p>
        <?php if ((int) ($_SESSION['user']['capo'] ?? 0) === 3): ?>
          <a href="admin_console.php" class="profile-drawer__item">
            <span class="profile-drawer__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 6h16v12H4Z"></path><path d="M8 10h8"></path><path d="M8 14h5"></path></svg></span>
            <span><strong>Console</strong><small>Supervisione sistema</small></span>
          </a>
        <?php endif; ?>
        <button type="button" class="profile-drawer__item" id="checkUpdatesItem">
          <span class="profile-drawer__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 12a8 8 0 0 1 13.6-5.7"></path><path d="M18 3v5h-5"></path><path d="M20 12a8 8 0 0 1-13.6 5.7"></path><path d="M6 21v-5h5"></path></svg></span>
          <span><strong>Aggiornamenti</strong><small>Controlla novita</small></span>
        </button>
        <form id="logoutForm" action="connection_files/logout.php" method="post">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(app_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
          <button class="profile-drawer__item" id="logoutLink" type="submit">
            <span class="profile-drawer__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M15 6h3v12h-3"></path><path d="M10 9 7 12l3 3"></path><path d="M7 12h9"></path></svg></span>
            <span><strong>Logout</strong><small>Esci dall'app</small></span>
          </button>
        </form>
      </div>
    </aside>
  <?php endif; ?>

  <section id="homeScreen" class="home-screen">
    <div class="home-dashboard">
      <details class="home-board home-board--schedule" open>
        <summary class="home-board__header">
          <div>
            <h2>Orari personali</h2>
            <p>Consultazione, variazioni e caricamento dei turni</p>
          </div>
          <span class="home-board__chevron" aria-hidden="true"></span>
        </summary>
        <div class="home-board__content">
          <div class="home-tile-grid home-tile-grid--<?php echo $homeCanManagePeople ? '3' : '2'; ?>">
            <button type="button" id="openOrari" class="home-tile home-tile--icon-only home-tile--blue">
              <?php echo app_home_tile_content('orari-clock', 'Orari', 'Vista personale dei turni', $homeAssetVersion); ?>
            </button>
            <button type="button" id="scheduleChangesItem" class="home-tile home-tile--icon-only home-tile--green">
              <?php echo app_home_tile_content('aggiornamenti-sync', 'Aggiornamenti', 'Variazioni pubblicate', $homeAssetVersion); ?>
            </button>
            <?php if ($homeCanManagePeople): ?>
              <a id="uploadItem" href="testjs.php" class="home-tile home-tile--icon-only home-tile--amber">
                <?php echo app_home_tile_content('upload-arrow', 'Upload', 'Carica file orari', $homeAssetVersion); ?>
              </a>
            <?php endif; ?>
          </div>
        </div>
      </details>

      <details class="home-board home-board--people is-closed" data-initial-closed="1" open>
        <summary class="home-board__header">
          <div>
            <h2>Gestione reparto</h2>
            <p>Persone, comunicazioni e richieste operative</p>
          </div>
          <span class="home-board__chevron" aria-hidden="true"></span>
        </summary>
        <div class="home-board__content">
          <div class="home-tile-grid home-tile-grid--<?php echo $homeCanManagePeople ? '5' : '3'; ?>">
            <button type="button" id="communicationsItem" class="home-tile home-tile--icon-only home-tile--cyan">
              <?php echo app_home_tile_content('comunicazioni-chat', 'Comunicazioni', 'Messaggi di reparto', $homeAssetVersion); ?>
            </button>
            <button type="button" id="scheduleAdjustmentsItem" class="home-tile home-tile--icon-only home-tile--red">
              <?php echo app_home_tile_content('richieste-ore-alarm', 'Richieste ore', 'Extra e variazioni', $homeAssetVersion); ?>
            </button>
            <?php if ($homeCanManagePeople): ?>
              <a href="addetti.php" class="home-tile home-tile--icon-only home-tile--green">
                <?php echo app_home_tile_content('addetti-users', 'Addetti', 'Utenti e inviti', $homeAssetVersion); ?>
              </a>
            <?php endif; ?>
            <button type="button" id="departmentOverviewItem" class="home-tile home-tile--icon-only home-tile--violet">
              <?php echo app_home_tile_content('panoramica-grid', 'Panoramica', 'Presenze reparto', $homeAssetVersion); ?>
            </button>
            <?php if ($homeCanManagePeople): ?>
              <button type="button" id="noteAdminItem" class="home-tile home-tile--icon-only home-tile--slate">
                <?php echo app_home_tile_content('note-pencil', 'Note', 'Gestione note reparto', $homeAssetVersion); ?>
              </button>
            <?php endif; ?>
          </div>
        </div>
      </details>

      <details class="home-board home-board--department is-closed" data-initial-closed="1" open>
        <summary class="home-board__header">
          <div>
            <h2>Attività reparto</h2>
            <p>Strumenti collegati al lavoro quotidiano</p>
          </div>
          <span class="home-board__chevron" aria-hidden="true"></span>
        </summary>
        <div class="home-board__content">
          <div class="home-tile-grid home-tile-grid--1">
            <button type="button" id="customerOrdersItem" class="home-tile home-tile--icon-only home-tile--lime">
              <?php echo app_home_tile_content('ordini-cart', 'Ordini clienti', 'Preparazione e ritiro', $homeAssetVersion); ?>
            </button>
          </div>
        </div>
      </details>
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
window.appAssetVersion = window.appBootstrap.appVersion;
window.appReleaseMeta = window.appBootstrap.releaseMeta || {};
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="app_core.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
<script src="app_init.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
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
const notificationBell = document.getElementById("notificationBell");
const notificationBadge = document.getElementById("notificationBadge");
const notificationPanel = document.getElementById("notificationPanel");
const notificationList = document.getElementById("notificationList");
const notificationRefresh = document.getElementById("notificationRefresh");
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

function setNotificationBadge(total) {
    if (!notificationBadge) {
        return;
    }

    const count = Number(total || 0);
    notificationBadge.textContent = count > 99 ? "99+" : String(count);
    notificationBadge.hidden = count < 1;
    notificationBadge.classList.toggle("app-hidden", count < 1);
}

function setNotificationList(items) {
    if (!notificationList) {
        return;
    }

    notificationList.innerHTML = "";
    if (!Array.isArray(items) || items.length === 0) {
        const empty = document.createElement("p");
        empty.className = "notification-center__empty";
        empty.textContent = "Non hai notifiche da vedere.";
        notificationList.appendChild(empty);
        return;
    }

    items.forEach(function (item) {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "notification-center__item";
        button.dataset.url = String(item.url || "index.php");

        const title = document.createElement("strong");
        title.textContent = String(item.title || "Notifica");
        const body = document.createElement("span");
        body.textContent = String(item.body || "");
        button.append(title, body);

        button.addEventListener("click", function () {
            hideNotificationPanel();
            openNotificationTarget(button.dataset.url || "index.php");
        });
        notificationList.appendChild(button);
    });
}

async function refreshNotificationCenter() {
    if (!notificationList || !getCurrentUserKey()) {
        return;
    }

    try {
        const response = await fetch("connection_files/notification_center.php", { cache: "no-store" });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.ok) {
            throw new Error(data.error || "Notifiche non disponibili");
        }
        setNotificationBadge(data.total || 0);
        setNotificationList(data.items || []);
    } catch (error) {
        console.error("Centro notifiche non disponibile", error);
        if (notificationList) {
            notificationList.innerHTML = '<p class="notification-center__empty">Non riesco a caricare le notifiche.</p>';
        }
    }
}

function showNotificationPanel() {
    if (!notificationPanel || !notificationBell) {
        return;
    }

    notificationPanel.hidden = false;
    notificationPanel.classList.remove("app-hidden");
    notificationBell.setAttribute("aria-expanded", "true");
    refreshNotificationCenter();
}

function hideNotificationPanel() {
    if (!notificationPanel || !notificationBell) {
        return;
    }

    notificationPanel.hidden = true;
    notificationPanel.classList.add("app-hidden");
    notificationBell.setAttribute("aria-expanded", "false");
}

async function openNotificationTarget(url) {
    const target = new URL(url || "index.php", window.location.href);
    if (target.searchParams.get("changes") === "1") {
        const batchId = target.searchParams.get("batch") || "";
        await mostraModificheOrari(batchId);
        refreshNotificationCenter();
        return;
    }
    if (target.searchParams.get("communications") === "1") {
        await appLoadFeature("communications");
        await mostraComunicazioni();
        refreshNotificationCenter();
        return;
    }
    if (target.searchParams.get("adjustments") === "1") {
        await appLoadFeature("adjustments");
        await mostraRichiesteOre();
        refreshNotificationCenter();
        return;
    }
    if (target.searchParams.get("orders") === "1") {
        await appLoadFeature("customerOrders");
        await mostraOrdiniClienti();
        refreshNotificationCenter();
        return;
    }
    if (target.searchParams.get("orari") === "1") {
        await appLoadFeature("calendar");
        appState.currentYear = today.getFullYear();
        appState.currentMonth = today.getMonth() + 1;
        await mostraGiorni(appState.currentYear, appState.currentMonth);
        refreshNotificationCenter();
        return;
    }

    window.location.assign(target.href);
}

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

async function clearAppRuntimeCaches() {
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
        }
        if ("caches" in window) {
            const keys = await caches.keys();
            await Promise.all(keys.map((key) => caches.delete(key)));
        }
    } catch (error) {
        console.error("Pulizia cache app non riuscita", error);
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
            clearAppRuntimeCaches();
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
if (notificationBell) {
    notificationBell.addEventListener("click", function (event) {
        event.stopPropagation();
        if (notificationPanel && !notificationPanel.hidden) {
            hideNotificationPanel();
        } else {
            showNotificationPanel();
        }
    });
}
if (notificationPanel) {
    notificationPanel.addEventListener("click", function (event) {
        event.stopPropagation();
    });
}
if (notificationRefresh) {
    notificationRefresh.addEventListener("click", refreshNotificationCenter);
}
document.addEventListener("click", hideNotificationPanel);
window.addEventListener("keydown", function (event) {
    if (event.key === "Escape" && changelogDialog && !changelogDialog.hidden) {
        hideChangelogDialog();
    }
    if (event.key === "Escape" && notificationPanel && !notificationPanel.hidden) {
        hideNotificationPanel();
    }
});
window.addEventListener("load", showChangelogIfNeeded);
window.addEventListener("load", function () {
    window.setTimeout(checkAppHealth, 1200);
    window.setTimeout(refreshNotificationCenter, 1500);
    window.setInterval(refreshNotificationCenter, 60000);
});

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

    navigator.serviceWorker.addEventListener('message', function (event) {
        if (!event.data || event.data.type !== "APP_PUSH_RECEIVED") {
            return;
        }

        handleRealtimePush(event.data.payload || {});
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

function clearScheduleRuntimeCache() {
    appState.weekCache = Object.create(null);
    appState.monthSchedulePromises = Object.create(null);
    appState.scheduleVersionCache = Object.create(null);
}

async function refreshCurrentScheduleView() {
    if (!["giorni", "giorno"].includes(appState.view) || !appState.currentYear || !appState.currentMonth) {
        return;
    }

    await appLoadFeature("calendar");
    await mostraGiorni(appState.currentYear, appState.currentMonth, { replaceHistory: true });
}

async function refreshCurrentCommunicationView() {
    if (appState.view !== "communications") {
        return;
    }

    await appLoadFeature("communications");
    await mostraComunicazioni();
}

async function refreshCurrentAdjustmentView() {
    if (!["scheduleAdjustments", "giorno"].includes(appState.view)) {
        return;
    }

    await appLoadFeature("adjustments");
    if (appState.view === "scheduleAdjustments") {
        await mostraRichiesteOre();
    }
}

function handleRealtimePush(payload) {
    const type = String(payload.type || "");
    if (!type) {
        return;
    }

    refreshNotificationCenter();

    if (["schedule_changed", "schedule_uploaded", "adjustment_review"].includes(type)) {
        clearScheduleRuntimeCache();
        showAppToast(payload.body || "Orari aggiornati");
        refreshCurrentScheduleView().catch((error) => console.error("Refresh orari da push non riuscito", error));
        if (type === "adjustment_review") {
            refreshCurrentAdjustmentView().catch((error) => console.error("Refresh richieste da push non riuscito", error));
        }
        return;
    }

    if (type === "communication") {
        showAppToast(payload.title || "Nuova comunicazione");
        refreshCurrentCommunicationView().catch((error) => console.error("Refresh comunicazioni da push non riuscito", error));
        return;
    }

    if (
        type === "adjustment_created"
        || type === "adjustment_decision"
        || type === "extra_department_hours_created"
        || type === "extra_department_hours_decision"
    ) {
        showAppToast(payload.title || "Richieste ore aggiornate");
        refreshCurrentAdjustmentView().catch((error) => console.error("Refresh richieste ore da push non riuscito", error));
    }
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
    profileImg.src = "img/" + avatar + ".webp?v=" + encodeURIComponent(String(window.appAssetVersion || window.appVersion || ""));
}
document.querySelectorAll(".profile-drawer__avatar").forEach(function (image) {
    image.src = "img/" + avatar + ".webp?v=" + encodeURIComponent(String(window.appAssetVersion || window.appVersion || ""));
});

function initProfileDrawer() {
    const trigger = document.getElementById("profileMenuTrigger");
    const drawer = document.getElementById("profileDrawer");
    const backdrop = document.getElementById("profileDrawerBackdrop");
    const close = document.getElementById("profileDrawerClose");
    if (!trigger || !drawer || !backdrop || !close) return;

    function openDrawer() {
        drawer.hidden = false;
        backdrop.hidden = false;
        drawer.classList.remove("app-hidden");
        backdrop.classList.remove("app-hidden");
        drawer.classList.remove("profile-drawer-entering");
        void drawer.offsetWidth;
        drawer.classList.add("profile-drawer-entering");
        document.body.classList.add("profile-drawer-open");
        drawer.setAttribute("aria-hidden", "false");
        trigger.setAttribute("aria-expanded", "true");
    }

    function closeDrawer() {
        document.body.classList.remove("profile-drawer-open");
        drawer.setAttribute("aria-hidden", "true");
        trigger.setAttribute("aria-expanded", "false");
        window.setTimeout(function () {
            if (!document.body.classList.contains("profile-drawer-open")) {
                drawer.hidden = true;
                backdrop.hidden = true;
                drawer.classList.add("app-hidden");
                backdrop.classList.add("app-hidden");
            }
        }, 260);
    }

    trigger.addEventListener("click", openDrawer);
    drawer.addEventListener("animationend", function (event) {
        if (event.animationName === "profileDrawerEnter") {
            drawer.classList.remove("profile-drawer-entering");
        }
    });
    close.addEventListener("click", closeDrawer);
    backdrop.addEventListener("click", closeDrawer);
    drawer.querySelectorAll(".profile-drawer__item").forEach(function (item) {
        item.addEventListener("click", function () {
            if (item.id !== "logoutLink") closeDrawer();
        });
    });
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") closeDrawer();
    });
}

function initHomeBoards() {
    const dashboard = document.querySelector(".home-dashboard");
    if (!dashboard) return;

    const desktopBoards = window.matchMedia("(min-width: 900px) and (min-height: 650px) and (hover: hover) and (pointer: fine)");
    dashboard.querySelectorAll(".home-board").forEach(function (board) {
        if (board.dataset.boardReady === "1") return;
        board.dataset.boardReady = "1";

        const summary = board.querySelector(".home-board__header");
        const content = board.querySelector(".home-board__content");
        if (!summary || !content) return;

        function measureOpenHeight() {
            const previousHeight = content.style.height;
            const wasClosed = board.classList.contains("is-closed");
            board.classList.remove("is-closed", "is-collapsing");
            content.style.height = "auto";
            content.dataset.openHeight = String(content.scrollHeight);
            if (wasClosed) {
                board.classList.add("is-closed");
                content.style.height = "0px";
            } else {
                content.style.height = previousHeight === "0px" ? content.dataset.openHeight + "px" : previousHeight || "auto";
            }
        }

        function syncBoardMode() {
            if (desktopBoards.matches) {
                board.open = true;
                board.classList.remove("is-closed", "is-collapsing");
                content.style.height = "auto";
                return;
            }
            measureOpenHeight();
            if (board.dataset.initialClosed === "1") {
                board.classList.add("is-closed");
                content.style.height = "0px";
                return;
            }
            content.style.height = content.dataset.openHeight + "px";
        }

        syncBoardMode();

        if (desktopBoards.addEventListener) {
            desktopBoards.addEventListener("change", syncBoardMode);
        } else {
            desktopBoards.addListener(syncBoardMode);
        }

        summary.addEventListener("click", function (event) {
            event.preventDefault();

            if (desktopBoards.matches) {
                board.open = true;
                board.classList.remove("is-closed", "is-collapsing");
                content.style.height = "auto";
                return;
            }

            if (!board.classList.contains("is-closed")) {
                board.classList.add("is-collapsing");
                const openHeight = content.dataset.openHeight || String(content.scrollHeight);
                content.style.height = openHeight + "px";
                requestAnimationFrame(function () {
                    content.style.height = "0px";
                });
                return;
            }

            board.classList.remove("is-closed", "is-collapsing");
            board.open = true;
            content.style.height = "0px";
            const targetHeight = content.dataset.openHeight || String(content.scrollHeight);
            requestAnimationFrame(function () {
                content.style.height = targetHeight + "px";
            });
        });

        content.addEventListener("transitionend", function (event) {
            if (event.propertyName !== "height") return;
            if (content.style.height === "0px") {
                board.open = true;
                board.classList.add("is-closed");
                board.classList.remove("is-collapsing");
                return;
            }
            if (!board.classList.contains("is-closed")) {
                content.dataset.openHeight = String(content.scrollHeight);
                content.style.height = "auto";
            }
        });
    });
}

function initHomeBackgroundMotion() {
    const homeScreen = document.getElementById("homeScreen");
    if (!homeScreen) return;

    const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");
    const limitedMotion = window.matchMedia("(pointer: coarse)");
    let ticking = false;

    function updateBackgroundOffset() {
        ticking = false;
        if (reducedMotion.matches || limitedMotion.matches) {
            document.documentElement.style.setProperty("--bg-y", "0px");
            return;
        }

        const offset = Math.max(-42, Math.min(42, window.scrollY * -0.08));
        document.documentElement.style.setProperty("--bg-y", offset.toFixed(2) + "px");
    }

    function requestBackgroundUpdate() {
        if (ticking) return;
        ticking = true;
        window.requestAnimationFrame(updateBackgroundOffset);
    }

    updateBackgroundOffset();
    window.addEventListener("scroll", requestBackgroundUpdate, { passive: true });
    if (reducedMotion.addEventListener) {
        reducedMotion.addEventListener("change", requestBackgroundUpdate);
    } else {
        reducedMotion.addListener(requestBackgroundUpdate);
    }
    if (limitedMotion.addEventListener) {
        limitedMotion.addEventListener("change", requestBackgroundUpdate);
    } else {
        limitedMotion.addListener(requestBackgroundUpdate);
    }
}

initProfileDrawer();
initHomeBoards();
initHomeBackgroundMotion();
})();
</script>
</body>
</html>

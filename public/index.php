<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require dirname(__DIR__) . '/session_bootstrap.php';
require dirname(__DIR__) . '/app_config.php';
require dirname(__DIR__) . '/connection_files/push_lib.php';
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
$releaseMetaPath = dirname(__DIR__) . '/release_meta.json';
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
    'serviceWorkerUrl' => 'service-worker.php?v=' . rawurlencode(APP_VERSION),
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
    <meta id="appThemeColor" name="theme-color" content="#e8f2ff">
    <script>
    (function () {
        var themeColors = {
            light: "#e8f2ff",
            dark: "#07146a"
        };

        window.appApplyThemeChrome = function (theme) {
            var selectedTheme = theme === "dark" ? "dark" : "light";
            var themeColor = themeColors[selectedTheme];
            var themeColorMeta = document.getElementById("appThemeColor");

            document.documentElement.dataset.theme = selectedTheme;
            document.documentElement.style.backgroundColor = themeColor;
            document.documentElement.style.setProperty("--app-status-bg", themeColor);

            if (themeColorMeta) {
                themeColorMeta.setAttribute("content", themeColor);
            }
        };

        try {
            var selectedTheme = localStorage.getItem("app-iperal-theme") === "dark" ? "dark" : "light";
            window.appApplyThemeChrome(selectedTheme);
            if (selectedTheme === "dark") {
                var preload = document.createElement("link");
                preload.rel = "preload";
                preload.as = "image";
                preload.href = "img/home-background-dark.webp";
                document.head.appendChild(preload);
            }
        } catch (error) {
            window.appApplyThemeChrome("light");
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
    <link rel="stylesheet" href="assets/css/modules/profile.css?v=<?php echo rawurlencode(APP_VERSION); ?>">
    <link rel="stylesheet" href="assets/css/modules/holidays.css?v=<?php echo rawurlencode(APP_VERSION); ?>">
    <link rel="stylesheet" href="assets/css/modules/customer-orders.css?v=<?php echo rawurlencode(APP_VERSION); ?>">
    <link rel="manifest" href="manifest.php?v=<?php echo rawurlencode(APP_VERSION); ?>">
    <link rel="apple-touch-icon" href="img/icon-192.png?v=<?php echo rawurlencode(APP_VERSION); ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>La mia pagina</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico?v=<?php echo rawurlencode(APP_VERSION); ?>">
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
          <div class="home-tile-grid home-tile-grid--<?php echo $homeCanManagePeople ? '4' : '3'; ?>">
            <button type="button" id="openOrari" class="home-tile home-tile--icon-only home-tile--blue">
              <?php echo app_home_tile_content('orari-clock', 'Orari', 'Vista personale dei turni', $homeAssetVersion); ?>
            </button>
            <button type="button" id="scheduleChangesItem" class="home-tile home-tile--icon-only home-tile--green">
              <?php echo app_home_tile_content('aggiornamenti-sync', 'Aggiornamenti', 'Variazioni pubblicate', $homeAssetVersion); ?>
            </button>
            <button type="button" id="personalHolidaysItem" class="home-tile home-tile--icon-only home-tile--cyan">
              <?php echo app_home_tile_content('ferie-calendar', 'Ferie personali', 'Richieste e calendario', $homeAssetVersion); ?>
            </button>
            <?php if ($homeCanManagePeople): ?>
              <a id="uploadItem" href="upload_turni.php" class="home-tile home-tile--icon-only home-tile--amber">
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
          <div class="home-tile-grid home-tile-grid--<?php echo $homeCanManagePeople ? '6' : '3'; ?>">
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
              <button type="button" id="departmentHolidaysItem" class="home-tile home-tile--icon-only home-tile--amber">
                <?php echo app_home_tile_content('ferie-calendar', 'Elenco ferie', 'Ferie del reparto', $homeAssetVersion); ?>
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
          <div class="home-tile-grid home-tile-grid--2">
            <button type="button" id="customerOrdersItem" class="home-tile home-tile--icon-only home-tile--lime">
              <?php echo app_home_tile_content('ordini-cart', 'Ordini clienti', 'Preparazione e ritiro', $homeAssetVersion); ?>
            </button>
            <button type="button" id="holidayCampaignItem" class="home-tile home-tile--icon-only home-tile--cyan">
              <?php echo app_home_tile_content('ferie-calendar', 'Inserimento ferie', 'Preferenze reparto', $homeAssetVersion); ?>
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
window.appServiceWorkerUrl = window.appBootstrap.serviceWorkerUrl;
window.appReleaseMeta = window.appBootstrap.releaseMeta || {};
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="app_core.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
<script src="app_init.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
<script src="assets/js/pages/index-shell.js?v=<?php echo rawurlencode(APP_VERSION); ?>"></script>
</body>
</html>

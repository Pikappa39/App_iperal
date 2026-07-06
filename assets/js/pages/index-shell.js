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
        await appLoadFeature("scheduleChanges");
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
        ? "Versione " + lastSeenVersion + " \u2192 " + currentVersion
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
            window.appServiceWorkerUrl || "service-worker.php",
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

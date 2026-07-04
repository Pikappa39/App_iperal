//dichiaro variabili prendendole da index.php
const container = document.getElementById("contenitore");
const titolo = document.getElementById("titolo");
const back = document.getElementById("backbtn");
const homeBtn = document.getElementById("homebtn");
const homeScreen = document.getElementById("homeScreen");
const appToolbar = document.querySelector(".app-toolbar");
const openOrari = document.getElementById("openOrari");
const noteAdminItem = document.getElementById("noteAdminItem");
const scheduleChangesItem = document.getElementById("scheduleChangesItem");
const scheduleAdjustmentsItem = document.getElementById("scheduleAdjustmentsItem");
const personalHolidaysItem = document.getElementById("personalHolidaysItem");
const departmentHolidaysItem = document.getElementById("departmentHolidaysItem");
const departmentOverviewItem = document.getElementById("departmentOverviewItem");
const customerOrdersItem = document.getElementById("customerOrdersItem");
const holidayCampaignItem = document.getElementById("holidayCampaignItem");
const communicationsItem = document.getElementById("communicationsItem");
const profileItem = document.getElementById("profileItem");
const setting=document.getElementById("setting");
const appState = {
    view: "home",
    currentYear: null,
    currentMonth: null,
    currentWeek: null,
    departmentOverviewDepartment: null,
    holidayCampaignDepartment: null,
    departmentOverviewMode: "day",
    departmentOverviewDay: null,
    selectedDay: null,
    noteViewToken: 0,
    calendarViewToken: 0,
    weekCache: Object.create(null),
    monthSchedulePromises: Object.create(null),
    scheduleVersionCache: Object.create(null),
    monthNotesCache: Object.create(null),
    monthNotesPromises: Object.create(null),
    holidaysAddMode: false,
    holidaysYearCache: Object.create(null),
    holidayCampaignCache: Object.create(null),
    transientCache: Object.create(null)
};
let appNavigationReady = false;
let appNavigationRestoring = false;
let appNavigationPosition = 0;

const MONTH_LABELS = ["Gen", "Feb", "Mar", "Apr", "Mag", "Giu", "Lug", "Ago", "Set", "Ott", "Nov", "Dic"];
const SCHEDULE_ENDPOINT = "connection_files/schedule.php";
const MONTH_SCHEDULE_ENDPOINT = "connection_files/month_schedule.php";
const NOTES_ENDPOINT = "connection_files/note.php";
const HOLIDAYS_ENDPOINT = "connection_files/holidays.php";
const HOLIDAY_CAMPAIGN_ENDPOINT = "connection_files/holiday_campaign.php";
const SCHEDULE_DAY_KEYS = ["luned\u00EC", "marted\u00EC", "mercoled\u00EC", "gioved\u00EC", "venerd\u00EC", "sabato", "domenica"];
const today = new Date();
const YEAR_CHOICES = Array.from({ length: 5 }, (_, index) => today.getFullYear() - 2 + index);
const todayKey = formatDateKey(today.getFullYear(), today.getMonth() + 1, today.getDate());
const APP_FEATURE_SCRIPTS = {
    calendar: ["app_adjustments.js", "app_notes.js", "app_calendar.js"],
    notes: ["app_notes.js"],
    adjustments: ["app_adjustments.js"],
    departmentOverview: ["app_department_overview.js"],
    customerOrders: ["app_customer_orders.js"],
    communications: ["app_communications.js"],
    profile: ["userhome.js"],
    settings: ["setting.js"]
};
const appLoadedScripts = Object.create(null);

function appScriptUrl(src) {
    if (src.includes("?")) {
        return src;
    }

    const version = encodeURIComponent(String(window.appAssetVersion || window.appVersion || ""));
    return version ? src + "?v=" + version : src;
}

function appLoadScript(src) {
    const url = appScriptUrl(src);
    if (appLoadedScripts[url]) {
        return appLoadedScripts[url];
    }

    appLoadedScripts[url] = new Promise((resolve, reject) => {
        const script = document.createElement("script");
        script.src = url;
        script.async = false;
        script.onload = resolve;
        script.onerror = () => {
            delete appLoadedScripts[url];
            reject(new Error("Non riesco a caricare " + src));
        };
        document.head.appendChild(script);
    });

    return appLoadedScripts[url];
}

async function appLoadFeature(name) {
    const scripts = APP_FEATURE_SCRIPTS[name] || [];
    for (const script of scripts) {
        await appLoadScript(script);
    }
}

function appCacheGet(key, ttlMs) {
    const entry = appState.transientCache[key];
    if (!entry) {
        return null;
    }

    if ((Date.now() - entry.createdAt) > ttlMs) {
        delete appState.transientCache[key];
        return null;
    }

    return entry.value;
}

function appCacheSet(key, value) {
    appState.transientCache[key] = {
        value,
        createdAt: Date.now()
    };
    return value;
}

function appCacheForget(prefix) {
    Object.keys(appState.transientCache).forEach((key) => {
        if (key.startsWith(prefix)) {
            delete appState.transientCache[key];
        }
    });
}

function appRunWithBusyElement(element, callback, busyText = "") {
    if (!element || element.dataset.appBusy === "1") {
        return;
    }

    const originalHtml = element.innerHTML;
    element.dataset.appBusy = "1";
    element.disabled = true;
    if (busyText) {
        element.innerHTML = "";
        const spinner = document.createElement("span");
        spinner.className = "app-spinner";
        spinner.setAttribute("aria-hidden", "true");
        const label = document.createElement("span");
        label.textContent = busyText;
        element.append(spinner, label);
    }

    Promise.resolve()
        .then(callback)
        .catch((error) => {
            console.error("Operazione non riuscita", error);
            showAppToast(error.message || "Operazione non riuscita");
        })
        .finally(() => {
            element.disabled = false;
            element.dataset.appBusy = "0";
            if (busyText) {
                element.innerHTML = originalHtml;
            }
        });
}

//questa funzione imposta la vista corrente, il titolo e svuota il contenitore
function setVista(classes, titoloTesto, options = {}) {
    appState.calendarViewToken += 1;
    titolo.innerText = titoloTesto;
    container.className = classes;
    container.innerHTML = "";
    if (options.record !== false) {
        appNavigationDeferRecord();
    }
}

function showHomeScreen() {
    appState.calendarViewToken += 1;
    if (typeof closeCalendarPicker === "function") {
        closeCalendarPicker();
    }
    appState.view = "home";
    appState.currentYear = null;
    appState.currentMonth = null;
    appState.currentWeek = null;
    appState.departmentOverviewDepartment = null;
    appState.holidayCampaignDepartment = null;
    appState.departmentOverviewMode = "day";
    appState.departmentOverviewDay = null;
    appState.selectedDay = null;
    appState.holidaysAddMode = false;
    if (typeof closeHolidayPanel === "function") {
        closeHolidayPanel();
    }
    titolo.innerText = "App Iperal";
    homeScreen.hidden = false;
    appToolbar.hidden = true;
    container.hidden = true;
    homeScreen.classList.remove("app-hidden");
    appToolbar.classList.add("app-hidden");
    container.classList.add("app-hidden");
    container.innerHTML = "";
    appNavigationRecordCurrentView();
}

function appNavigationBuildState() {
    const state = {
        app: "myorari",
        view: appState.view,
        year: appState.currentYear,
        month: appState.currentMonth,
        week: appState.currentWeek,
        department: appState.departmentOverviewDepartment,
        holidayDepartment: appState.holidayCampaignDepartment,
        overviewMode: appState.departmentOverviewMode,
        overviewDay: appState.departmentOverviewDay,
        settingsPanel: appState.settingsPanel || "main",
        position: appNavigationPosition
    };

    if (appState.view === "giorno" && appState.selectedDay) {
        state.day = appState.selectedDay;
    }

    return state;
}

function appNavigationRecordCurrentView() {
    if (!appNavigationReady || appNavigationRestoring) {
        return;
    }

    const currentState = window.history.state;
    const currentPosition = currentState && currentState.app === "myorari"
        ? Number(currentState.position || 0)
        : appNavigationPosition;
    appNavigationPosition = currentPosition;
    const currentViewState = appNavigationBuildState();
    if (currentState && currentState.app === "myorari" && JSON.stringify(currentState) === JSON.stringify(currentViewState)) {
        return;
    }

    appNavigationPosition = currentPosition + 1;
    const nextState = appNavigationBuildState();
    window.history.pushState(nextState, document.title, window.location.pathname);
}

function appNavigationDeferRecord() {
    const defer = window.queueMicrotask || ((callback) => Promise.resolve().then(callback));
    defer(appNavigationRecordCurrentView);
}

function appNavigationInitialize() {
    appNavigationPosition = 0;
    window.history.replaceState(appNavigationBuildState(), document.title, window.location.pathname);
    appNavigationReady = true;
}

function appNavigationReplaceCurrentView() {
    if (!appNavigationReady || appNavigationRestoring) {
        return;
    }

    window.history.replaceState(appNavigationBuildState(), document.title, window.location.pathname);
}

function appNavigationGoBack() {
    if (appState.view === "home") {
        return;
    }

    if (appNavigationPosition > 0) {
        window.history.back();
        return;
    }

    // Una schermata puo essere stata aperta direttamente da una notifica:
    // in quel caso non esiste una vista interna precedente e torniamo alla Home.
    appNavigationRestoring = true;
    showHomeScreen();
    appNavigationRestoring = false;
    window.history.replaceState(appNavigationBuildState(), document.title, window.location.pathname);
}

async function appNavigationRestore(state) {
    if (!state || state.app !== "myorari") {
        return;
    }

    appNavigationPosition = Number(state.position || 0);
    appNavigationRestoring = true;
    try {
        switch (state.view) {
            case "home":
                showHomeScreen();
                break;
            case "anni":
                await appLoadFeature("calendar");
                mostraAnni();
                break;
            case "mesi":
                await appLoadFeature("calendar");
                mostraMesi(state.year);
                break;
            case "giorni":
                await appLoadFeature("calendar");
                await mostraGiorni(state.year, state.month);
                break;
            case "giorno":
                await appLoadFeature("calendar");
                if (state.day) {
                    await mostragiorno(state.day);
                } else {
                    await mostraGiorni(state.year, state.month);
                }
                break;
            case "noteAdmin":
                await appLoadFeature("notes");
                await mostraNoteAdmin();
                break;
            case "scheduleChanges":
                await mostraModificheOrari();
                break;
            case "scheduleAdjustments":
                await appLoadFeature("adjustments");
                await mostraRichiesteOre();
                break;
            case "personalHolidays":
                mostraFeriePersonali(state.year || today.getFullYear(), { replaceHistory: true });
                break;
            case "departmentHolidays":
                await mostraElencoFerie(state.year || today.getFullYear());
                break;
            case "departmentOverview":
                await appLoadFeature("departmentOverview");
                await mostraPanoramicaReparto(state.year, state.week, state.department, {
                    mode: state.overviewMode,
                    day: state.overviewDay
                });
                break;
            case "communications":
                await appLoadFeature("communications");
                await mostraComunicazioni();
                break;
            case "customerOrders":
                await appLoadFeature("customerOrders");
                await mostraOrdiniClienti();
                break;
            case "holidayCampaign":
                await mostraAttivitaFerie(state.year || today.getFullYear(), { department: state.holidayDepartment || state.department || "" });
                break;
            case "profilo":
                await appLoadFeature("profile");
                mostraProfilo();
                break;
            case "setting":
                await appLoadFeature("settings");
                if (state.settingsPanel === "screen") {
                    mostraImpostazioniSchermo();
                } else if (state.settingsPanel === "notifications") {
                    mostraImpostazioniNotifiche();
                } else {
                    mostrasetting();
                }
                break;
            default:
                showHomeScreen();
        }
    } finally {
        appNavigationRestoring = false;
    }
}

window.addEventListener("popstate", function (event) {
    appNavigationRestore(event.state);
});

function showCalendarShell() {
    homeScreen.hidden = true;
    appToolbar.hidden = false;
    container.hidden = false;
    homeScreen.classList.add("app-hidden");
    appToolbar.classList.remove("app-hidden");
    container.classList.remove("app-hidden");
}

function getCurrentUser() {
    const user = window.userSession;
    if (!user) return "";

    return (user.nome + " " + user.cognome)
        .trim()
        .toUpperCase();}

function getCurrentUserKey() {
    return (window.userKey || "").toString().trim().toUpperCase();
}

function isCapoUser() {
    const capo = String(window.userSession?.capo ?? "");
    return ["1", "2", "3"].includes(capo);
}

function getDayLabel(date) {
    return date.toLocaleDateString("it-IT", { weekday: "long" });
}

function formatDateKey(anno, mese, giorno) {
    return [
        String(anno),
        String(mese).padStart(2, "0"),
        String(giorno).padStart(2, "0")
    ].join("-");
}

function formatMonthKey(anno, mese) {
    return [
        String(anno),
        String(mese).padStart(2, "0")
    ].join("-");
}

function getIsoWeekInfo(date) {
    const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
    const dayNum = d.getUTCDay() || 7;
    d.setUTCDate(d.getUTCDate() + 4 - dayNum);
    const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
    return {
        year: d.getUTCFullYear(),
        week: Math.ceil((((d - yearStart) / 86400000) + 1) / 7)
    };
}

async function getWeekData(anno, settimana) {
    if (!getCurrentUserKey()) {
        return [];
    }

    const key = String(anno) + ":" + String(settimana);

    if (!appState.weekCache[key]) {
        appState.weekCache[key] = (async () => {
            try {
                const query = new URLSearchParams({ year: String(anno), week: String(settimana) });
                const response = await fetch(SCHEDULE_ENDPOINT + "?" + query.toString(), {
                    cache: "no-store"
                });
                if (response.ok) {
                    const payload = await response.json();
                    return Array.isArray(payload.rows) ? payload.rows : [];
                }
            } catch (error) {
                console.error("Errore nel caricamento della settimana", anno, settimana, error);
            }

            return [];
        })();
    }

    return appState.weekCache[key];
}

async function getMonthScheduleData(anno, mese) {
    if (!getCurrentUserKey()) {
        return {};
    }

    const key = String(anno) + ":" + String(mese);
    if (!appState.monthSchedulePromises[key]) {
        appState.monthSchedulePromises[key] = (async () => {
            const query = new URLSearchParams({ year: String(anno), month: String(mese) });
            const response = await fetch(MONTH_SCHEDULE_ENDPOINT + "?" + query.toString());
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || !payload.ok || !payload.weeks || typeof payload.weeks !== "object") {
                throw new Error(payload.error || "Orario mensile non disponibile");
            }

            Object.entries(payload.weeks).forEach(([weekKey, rows]) => {
                appState.weekCache[weekKey] = Promise.resolve(Array.isArray(rows) ? rows : []);
            });
            Object.entries(payload.schedule_versions || {}).forEach(([weekKey, version]) => {
                appState.scheduleVersionCache[weekKey] = version;
            });

            return payload.weeks;
        })().catch((error) => {
            delete appState.monthSchedulePromises[key];
            throw error;
        });
    }

    return appState.monthSchedulePromises[key];
}

async function getWeeksScheduleData(weeksToLoad) {
    const settimaneCaricate = {};
    await Promise.all([...weeksToLoad.values()].map(async (isoWeek) => {
        const key = isoWeek.year + ":" + isoWeek.week;
        settimaneCaricate[key] = await getWeekData(isoWeek.year, isoWeek.week);
    }));
    return settimaneCaricate;
}

function formatScheduleChangeDate(value) {
    const date = new Date(value + "T00:00:00");
    return date.toLocaleDateString("it-IT", {
        weekday: "short",
        day: "2-digit",
        month: "2-digit",
        year: "numeric"
    });
}

async function mostraModificheOrari(batchId = "") {
    showCalendarShell();
    setVista("calendario vista-modifiche mt-4", "Aggiornamenti orari");
    appState.view = "scheduleChanges";

    const loading = document.createElement("p");
    loading.className = "changes-empty";
    loading.textContent = "Caricamento modifiche...";
    container.appendChild(loading);

    try {
        const query = batchId ? "?batch=" + encodeURIComponent(batchId) : "";
        const cacheKey = "scheduleChanges:" + (batchId || "all");
        let data = appCacheGet(cacheKey, 60 * 1000);
        if (!data) {
            const response = await fetch("connection_files/schedule_changes.php" + query, {
                cache: "no-store"
            });
            data = await response.json();

            if (!response.ok || !data.ok) {
                throw new Error(data.error || "Errore nel caricamento delle modifiche");
            }
            appCacheSet(cacheKey, data);
        }

        container.innerHTML = "";
        if (!Array.isArray(data.changes) || data.changes.length === 0) {
            const empty = document.createElement("p");
            empty.className = "changes-empty";
            empty.textContent = "Non ci sono modifiche da mostrare.";
            container.appendChild(empty);
            return;
        }

        const list = document.createElement("div");
        list.className = "changes-list";

        data.changes.forEach((change) => {
            const card = document.createElement("article");
            card.className = "change-card";

            const heading = document.createElement("div");
            heading.className = "change-card__heading";
            const date = document.createElement("strong");
            date.textContent = formatScheduleChangeDate(change.schedule_date);
            const editor = document.createElement("span");
            editor.textContent = change.changed_by_name
                ? "Modificato da " + change.changed_by_name
                : "Modificato dal capo";
            heading.append(date, editor);

            const shifts = document.createElement("div");
            shifts.className = "change-card__shifts";
            const previous = document.createElement("span");
    previous.textContent = "\u2039";
            const arrow = document.createElement("span");
            arrow.className = "change-card__arrow";
            arrow.setAttribute("aria-label", "diventa");
            arrow.textContent = "\u2192";
            const next = document.createElement("span");
    next.textContent = "\u203A";
            shifts.append(previous, arrow, next);

            card.append(heading, shifts);
            list.appendChild(card);
        });

        container.appendChild(list);
    } catch (error) {
        container.innerHTML = "";
        const message = document.createElement("p");
        message.className = "changes-empty";
        message.textContent = error.message || "Non riesco a caricare le modifiche.";
        container.appendChild(message);
    }
}


function createHolidayPlaceholder(kind) {
    const panel = document.createElement("section");
    panel.className = "holiday-placeholder";
    const badge = document.createElement("span");
    badge.className = "holiday-placeholder__badge";
    badge.textContent = kind === "department" ? "Reparto" : "Personale";
    const heading = document.createElement("h2");
    heading.className = "holiday-placeholder__title";
    heading.textContent = kind === "department" ? "Elenco ferie" : "Ferie personali";
    const text = document.createElement("p");
    text.className = "holiday-placeholder__text";
    text.textContent = kind === "department"
        ? "Qui vedrai le ferie degli addetti del reparto, con filtri e stato richieste."
        : "Qui potrai consultare le tue ferie e preparare le richieste quando collegheremo la gestione dati.";
    const status = document.createElement("div");
    status.className = "holiday-placeholder__status";
    status.textContent = "Schermata pronta per il prossimo passo.";
    panel.append(badge, heading, text, status);
    return panel;
}

function getMondayOfIsoWeek(isoYear, isoWeek) {
    const januaryFourth = new Date(isoYear, 0, 4);
    const day = januaryFourth.getDay() || 7;
    const monday = new Date(januaryFourth);
    monday.setDate(januaryFourth.getDate() - day + 1 + ((isoWeek - 1) * 7));
    monday.setHours(0, 0, 0, 0);
    return monday;
}

function getIsoWeeksInYear(anno) {
    return getIsoWeekInfo(new Date(anno, 11, 28)).week;
}

function formatHolidayWeekRange(startDate, endDate) {
    const sameMonth = startDate.getMonth() === endDate.getMonth();
    const sameYear = startDate.getFullYear() === endDate.getFullYear();
    const startOptions = sameMonth && sameYear ? { day: "2-digit" } : { day: "2-digit", month: "short" };
    const endOptions = sameYear ? { day: "2-digit", month: "short" } : { day: "2-digit", month: "short", year: "numeric" };
    return startDate.toLocaleDateString("it-IT", startOptions) + " - " + endDate.toLocaleDateString("it-IT", endOptions);
}

function getAdjacentHolidayYear(anno, direction) {
    const minYear = YEAR_CHOICES[0];
    const maxYear = YEAR_CHOICES[YEAR_CHOICES.length - 1];
    return Math.min(maxYear, Math.max(minYear, anno + direction));
}

function createHolidayYearNavigation(anno, onChange) {
    const navigation = document.createElement("nav");
    navigation.className = "calendar-navigation holiday-year-navigation";
    navigation.setAttribute("aria-label", "Navigazione anno ferie");
    const previous = document.createElement("button");
    previous.type = "button";
    previous.className = "calendar-navigation__arrow";
    previous.textContent = "\u2039";
    previous.setAttribute("aria-label", "Anno precedente");
    previous.disabled = anno <= YEAR_CHOICES[0];
    previous.addEventListener("click", () => onChange(getAdjacentHolidayYear(anno, -1)));
    const label = document.createElement("div");
    label.className = "calendar-navigation__label";
    const yearLabel = document.createElement("strong");
    yearLabel.textContent = anno;
    label.appendChild(yearLabel);
    const next = document.createElement("button");
    next.type = "button";
    next.className = "calendar-navigation__arrow";
    next.textContent = "\u203A";
    next.setAttribute("aria-label", "Anno successivo");
    next.disabled = anno >= YEAR_CHOICES[YEAR_CHOICES.length - 1];
    next.addEventListener("click", () => onChange(getAdjacentHolidayYear(anno, 1)));
    navigation.append(previous, label, next);
    return navigation;
}

function closeHolidayPanel() {
    const panel = document.querySelector(".holiday-panel-backdrop");
    if (panel) panel.remove();
}

function createHolidayPanelShell(titleText, subtitleText) {
    closeHolidayPanel();
    const backdrop = document.createElement("div");
    backdrop.className = "holiday-panel-backdrop";
    backdrop.addEventListener("click", (event) => { if (event.target === backdrop) closeHolidayPanel(); });
    const panel = document.createElement("section");
    panel.className = "holiday-panel";
    panel.setAttribute("role", "dialog");
    panel.setAttribute("aria-modal", "true");
    const header = document.createElement("header");
    header.className = "holiday-panel__header";
    const titleGroup = document.createElement("div");
    const title = document.createElement("h2");
    title.textContent = titleText;
    const subtitle = document.createElement("p");
    subtitle.textContent = subtitleText;
    titleGroup.append(title, subtitle);
    const close = document.createElement("button");
    close.type = "button";
    close.className = "holiday-panel__close";
    close.textContent = "\u00D7";
    close.setAttribute("aria-label", "Chiudi pannello ferie");
    close.addEventListener("click", closeHolidayPanel);
    header.append(titleGroup, close);
    const body = document.createElement("div");
    body.className = "holiday-panel__body";
    panel.append(header, body);
    backdrop.appendChild(panel);
    document.body.appendChild(backdrop);
    return body;
}

async function fetchHolidayYearData(anno) {
    const department = String(window.userSession?.reparto || "");
    const cacheKey = String(anno) + ":" + department;
    const cached = appState.holidaysYearCache[cacheKey];
    if (cached && Date.now() - cached.createdAt < 30000) return cached.value;
    const query = new URLSearchParams({ view: "year", year: String(anno) });
    const response = await fetch(HOLIDAYS_ENDPOINT + "?" + query.toString(), { cache: "no-store" });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.ok) throw new Error(data.error || "Elenco ferie non disponibile");
    appState.holidaysYearCache[cacheKey] = { value: data, createdAt: Date.now() };
    return data;
}

async function fetchHolidayWeekData(anno, week) {
    const query = new URLSearchParams({ view: "week", year: String(anno), week: String(week) });
    const response = await fetch(HOLIDAYS_ENDPOINT + "?" + query.toString(), { cache: "no-store" });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.ok) throw new Error(data.error || "Dettaglio ferie non disponibile");
    return data;
}

function forgetHolidayYearCache(anno) {
    Object.keys(appState.holidaysYearCache).forEach((key) => {
        if (key.startsWith(String(anno) + ":")) delete appState.holidaysYearCache[key];
    });
}

function createHolidayActions(canManage) {
    const actions = document.createElement("div");
    actions.className = "holiday-actions";
    if (!canManage) return actions;
    const add = document.createElement("button");
    add.type = "button";
    add.className = "btn btn-sm " + (appState.holidaysAddMode ? "btn-primary" : "btn-outline-primary");
    add.setAttribute("aria-pressed", appState.holidaysAddMode ? "true" : "false");
    add.textContent = appState.holidaysAddMode ? "Modalita aggiunta attiva" : "+ Aggiungi ferie";
    add.addEventListener("click", () => {
        appState.holidaysAddMode = !appState.holidaysAddMode;
        mostraElencoFerie(appState.currentYear || today.getFullYear(), { replaceHistory: true });
    });
    actions.appendChild(add);
    return actions;
}

function createHolidayWeekSphere(weekInfo) {
    const sfera = document.createElement("button");
    sfera.type = "button";
    sfera.className = "sfera holiday-week-sphere";
    sfera.dataset.anno = String(weekInfo.isoYear);
    sfera.dataset.settimana = String(weekInfo.week);
    if (weekInfo.isCurrent) sfera.classList.add("today");
    if (appState.holidaysAddMode) sfera.classList.add("holiday-week-sphere--add-mode");
    const content = document.createElement("div");
    content.className = "sfera__content";
    const day = document.createElement("div");
    day.className = "sfera__day";
    day.textContent = "Settimana";
    const number = document.createElement("div");
    number.className = "sfera__number";
    number.textContent = weekInfo.week;
    const time = document.createElement("div");
    time.className = "sfera__time";
    time.textContent = weekInfo.rangeLabel;
    const hours = document.createElement("div");
    hours.className = "sfera__hours";
    hours.textContent = weekInfo.holidayCount > 0 ? (weekInfo.holidayCount === 1 ? "1 in ferie" : weekInfo.holidayCount + " in ferie") : "Lun - Dom";
    content.append(day, number, time, hours);
    sfera.appendChild(content);
    sfera.addEventListener("click", () => openHolidayWeekPanel(weekInfo, appState.holidaysAddMode ? "add" : "detail"));
    return sfera;
}

function renderHolidayList(containerElement, holidays, weekInfo, canManage) {
    const list = document.createElement("div");
    list.className = "holiday-panel__list";
    if (!Array.isArray(holidays) || holidays.length === 0) {
        const empty = document.createElement("p");
        empty.className = "holiday-panel__empty";
        empty.textContent = "Nessuna ferie inserita per questa settimana.";
        list.appendChild(empty);
        containerElement.appendChild(list);
        return;
    }
    holidays.forEach((holiday) => {
        const item = document.createElement("article");
        item.className = "holiday-panel__item";
        const name = document.createElement("strong");
        name.textContent = holiday.display_name || "Addetto";
        const meta = document.createElement("span");
        meta.textContent = holiday.user_cf ? "Utente registrato" : "Nominativo Excel";
        item.append(name, meta);
        if (canManage) {
            const remove = document.createElement("button");
            remove.type = "button";
            remove.className = "btn btn-outline-danger btn-sm";
            remove.textContent = "Rimuovi";
            remove.addEventListener("click", () => deleteHoliday(weekInfo, Number(holiday.id || 0), remove));
            item.appendChild(remove);
        }
        list.appendChild(item);
    });
    containerElement.appendChild(list);
}

function renderHolidayAddForm(containerElement, data, weekInfo) {
    const form = document.createElement("form");
    form.className = "holiday-add-form";
    const label = document.createElement("label");
    label.className = "form-label";
    label.textContent = "Addetto";
    const select = document.createElement("select");
    select.name = "person_key";
    select.className = "form-select";
    select.required = true;
    const placeholder = document.createElement("option");
    placeholder.value = "";
    placeholder.textContent = data.people?.length ? "Seleziona addetto" : "Nessun addetto disponibile";
    select.appendChild(placeholder);
    const already = new Set((data.holidays || []).map((holiday) => String(holiday.person_key || "")));
    (data.people || []).forEach((person) => {
        const option = document.createElement("option");
        option.value = person.person_key;
        option.textContent = person.label || person.display_name || person.person_key;
        if (already.has(person.person_key)) option.textContent += " - gia in ferie";
        select.appendChild(option);
    });
    const submit = document.createElement("button");
    submit.type = "submit";
    submit.className = "btn btn-primary";
    submit.textContent = "Salva";
    submit.disabled = !data.people?.length;
    const status = document.createElement("p");
    status.className = "holiday-add-form__status";
    form.append(label, select, submit, status);
    form.addEventListener("submit", (event) => {
        event.preventDefault();
        saveHoliday(weekInfo, select.value, submit, status);
    });
    containerElement.appendChild(form);
}

async function saveHoliday(weekInfo, personKey, button, status) {
    if (!personKey) {
        status.textContent = "Seleziona un addetto.";
        return;
    }
    button.disabled = true;
    status.textContent = "Salvataggio...";
    try {
        const body = new URLSearchParams({ action: "add", year: String(weekInfo.isoYear), week: String(weekInfo.week), person_key: personKey, csrf_token: String(window.appCsrfToken || "") });
        const response = await fetch(HOLIDAYS_ENDPOINT, { method: "POST", body, credentials: "same-origin" });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.ok) throw new Error(data.error || "Salvataggio non riuscito");
        forgetHolidayYearCache(weekInfo.isoYear);
        await mostraElencoFerie(weekInfo.isoYear, { replaceHistory: true });
        await openHolidayWeekPanel(weekInfo, "add");
        showAppToast("Ferie salvata.");
    } catch (error) {
        status.textContent = error.message || "Salvataggio non riuscito.";
        button.disabled = false;
    }
}

async function deleteHoliday(weekInfo, holidayId, button) {
    if (!holidayId) return;
    button.disabled = true;
    try {
        const body = new URLSearchParams({ action: "delete", year: String(weekInfo.isoYear), week: String(weekInfo.week), holiday_id: String(holidayId), csrf_token: String(window.appCsrfToken || "") });
        const response = await fetch(HOLIDAYS_ENDPOINT, { method: "POST", body, credentials: "same-origin" });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.ok) throw new Error(data.error || "Rimozione non riuscita");
        forgetHolidayYearCache(weekInfo.isoYear);
        await mostraElencoFerie(weekInfo.isoYear, { replaceHistory: true });
        await openHolidayWeekPanel(weekInfo, "detail");
        showAppToast("Ferie rimossa.");
    } catch (error) {
        button.disabled = false;
        showAppToast(error.message || "Rimozione non riuscita.");
    }
}

async function openHolidayWeekPanel(weekInfo, mode = "detail") {
    const body = createHolidayPanelShell("Settimana " + weekInfo.week, weekInfo.rangeLabel + " \u00B7 " + weekInfo.isoYear);
    const loading = document.createElement("p");
    loading.className = "holiday-panel__empty";
    loading.textContent = "Caricamento ferie...";
    body.appendChild(loading);
    try {
        const data = await fetchHolidayWeekData(weekInfo.isoYear, weekInfo.week);
        body.innerHTML = "";
        if (mode === "add" && data.can_manage) renderHolidayAddForm(body, data, weekInfo);
        renderHolidayList(body, data.holidays || [], weekInfo, Boolean(data.can_manage));
    } catch (error) {
        body.innerHTML = "";
        const message = document.createElement("p");
        message.className = "holiday-panel__empty";
        message.textContent = error.message || "Non riesco a caricare la settimana.";
        body.appendChild(message);
    }
}

async function fetchPersonalHolidayData(anno) {
    const query = new URLSearchParams({ view: "personal", year: String(anno) });
    const response = await fetch(HOLIDAYS_ENDPOINT + "?" + query.toString(), { cache: "no-store" });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.ok) throw new Error(data.error || "Ferie personali non disponibili");
    return data;
}

function normalizePersonalHoliday(holiday) {
    const isoYear = Number(holiday.iso_year || today.getFullYear());
    const isoWeek = Number(holiday.iso_week || 0);
    const startDate = getMondayOfIsoWeek(isoYear, isoWeek);
    const endDate = new Date(startDate);
    endDate.setDate(startDate.getDate() + 6);
    endDate.setHours(23, 59, 59, 999);
    return Object.assign({}, holiday, {
        isoYear,
        isoWeek,
        startDate,
        endDate,
        rangeLabel: formatHolidayWeekRange(startDate, endDate)
    });
}

function daysUntilHoliday(startDate) {
    const start = new Date(startDate);
    start.setHours(0, 0, 0, 0);
    const current = new Date(today);
    current.setHours(0, 0, 0, 0);
    return Math.max(0, Math.ceil((start.getTime() - current.getTime()) / 86400000));
}

function personalHolidayStatus(holidays) {
    const normalizedToday = new Date(today);
    normalizedToday.setHours(12, 0, 0, 0);
    const current = holidays.find((holiday) => normalizedToday >= holiday.startDate && normalizedToday <= holiday.endDate) || null;
    const next = holidays.find((holiday) => holiday.startDate > normalizedToday) || null;
    return { current, next };
}

function createConfettiPiece(index) {
    const piece = document.createElement("span");
    piece.className = "personal-holiday-confetti__piece";
    piece.style.setProperty("--delay", String((index % 8) * 0.16) + "s");
    piece.style.setProperty("--left", String((index * 13) % 100) + "%");
    piece.style.setProperty("--hue", String((index * 47) % 360));
    return piece;
}

function renderPersonalHolidayHero(holidays) {
    const status = personalHolidayStatus(holidays);
    const hero = document.createElement("section");
    hero.className = "personal-holiday-hero";
    const eyebrow = document.createElement("span");
    eyebrow.className = "personal-holiday-hero__eyebrow";
    eyebrow.textContent = "Ferie personali";
    const title = document.createElement("h2");
    const subtitle = document.createElement("p");

    if (status.current) {
        hero.classList.add("personal-holiday-hero--active");
        title.textContent = "Sei in ferie!";
        subtitle.textContent = "Settimana " + status.current.isoWeek + " · " + status.current.rangeLabel;
        const confetti = document.createElement("div");
        confetti.className = "personal-holiday-confetti";
        for (let i = 0; i < 22; i++) confetti.appendChild(createConfettiPiece(i));
        hero.appendChild(confetti);
    } else if (status.next) {
        const days = daysUntilHoliday(status.next.startDate);
        title.textContent = days === 0 ? "Le ferie iniziano oggi" : ("Mancano " + days + " giorni");
        subtitle.textContent = "Prossime ferie: settimana " + status.next.isoWeek + " · " + status.next.rangeLabel;
    } else {
        hero.classList.add("personal-holiday-hero--empty");
        title.textContent = "Nessuna ferie programmata";
        subtitle.textContent = "Quando verranno approvate, le vedrai qui con il countdown.";
    }

    hero.append(eyebrow, title, subtitle);
    return hero;
}

function personalHolidayBucket(holiday) {
    const normalizedToday = new Date(today);
    normalizedToday.setHours(12, 0, 0, 0);
    if (normalizedToday >= holiday.startDate && normalizedToday <= holiday.endDate) return "present";
    return holiday.endDate < normalizedToday ? "past" : "future";
}

function renderPersonalHolidayList(holidays) {
    const section = document.createElement("section");
    section.className = "personal-holiday-list";
    const title = document.createElement("h2");
    title.textContent = "Ferie dell'anno";
    section.appendChild(title);

    if (!holidays.length) {
        const empty = document.createElement("p");
        empty.className = "personal-holiday-list__empty";
        empty.textContent = "Non ci sono ferie ufficiali per l'anno in corso.";
        section.appendChild(empty);
        return section;
    }

    const labels = { future: "Future", present: "In corso", past: "Passate" };
    ["present", "future", "past"].forEach((bucket) => {
        const items = holidays.filter((holiday) => personalHolidayBucket(holiday) === bucket);
        if (!items.length) return;
        const group = document.createElement("div");
        group.className = "personal-holiday-group personal-holiday-group--" + bucket;
        const heading = document.createElement("h3");
        heading.textContent = labels[bucket];
        group.appendChild(heading);
        items.forEach((holiday) => {
            const item = document.createElement("article");
            item.className = "personal-holiday-item";
            const week = document.createElement("strong");
            week.textContent = "Settimana " + holiday.isoWeek;
            const range = document.createElement("span");
            range.textContent = holiday.rangeLabel;
            item.append(week, range);
            group.appendChild(item);
        });
        section.appendChild(group);
    });
    return section;
}

async function mostraFeriePersonali(anno = today.getFullYear(), options = {}) {
    showCalendarShell();
    setVista("calendario vista-ferie vista-ferie-personali mt-4", "Ferie personali", { record: !options.replaceHistory });
    if (options.replaceHistory) appNavigationReplaceCurrentView();
    appState.view = "personalHolidays";
    appState.currentYear = anno;
    appState.currentMonth = null;
    appState.currentWeek = null;
    container.appendChild(createHolidayYearNavigation(anno, (targetYear) => mostraFeriePersonali(targetYear, { replaceHistory: true })));
    const loading = document.createElement("p");
    loading.className = "changes-empty";
    loading.textContent = "Caricamento ferie personali...";
    container.appendChild(loading);
    try {
        const data = await fetchPersonalHolidayData(anno);
        const holidays = (data.holidays || []).map(normalizePersonalHoliday).sort((left, right) => left.startDate - right.startDate);
        loading.remove();
        container.appendChild(renderPersonalHolidayHero(holidays));
        container.appendChild(renderPersonalHolidayList(holidays));
    } catch (error) {
        loading.textContent = error.message || "Non riesco a caricare le ferie personali.";
    }
}

async function mostraElencoFerie(anno = today.getFullYear(), options = {}) {
    showCalendarShell();
    appState.view = "departmentHolidays";
    appState.currentYear = anno;
    appState.currentMonth = null;
    appState.currentWeek = null;
    setVista("calendario vista-ferie griglia-settimane mt-4", "Elenco ferie", { record: !options.replaceHistory });
    if (options.replaceHistory) appNavigationReplaceCurrentView();
    container.appendChild(createHolidayYearNavigation(anno, (targetYear) => mostraElencoFerie(targetYear, { replaceHistory: true })));
    const intro = document.createElement("section");
    intro.className = "holiday-weeks-intro";
    const introTitle = document.createElement("h2");
    introTitle.textContent = "Settimane " + anno;
    const introText = document.createElement("p");
    introText.textContent = "Vista reparto per blocchi lunedi-domenica. Ogni sfera rappresenta una settimana dell'anno.";
    intro.append(introTitle, introText);
    container.appendChild(intro);
    const loading = document.createElement("p");
    loading.className = "changes-empty";
    loading.textContent = "Caricamento ferie...";
    container.appendChild(loading);
    let yearData = { weeks: {}, can_manage: false };
    try {
        yearData = await fetchHolidayYearData(anno);
    } catch (error) {
        loading.textContent = error.message || "Non riesco a caricare le ferie.";
        return;
    }
    loading.remove();
    container.appendChild(createHolidayActions(Boolean(yearData.can_manage)));
    const grid = document.createElement("div");
    grid.className = "holiday-week-grid";
    const currentWeek = getIsoWeekInfo(today);
    const weeksInYear = getIsoWeeksInYear(anno);
    for (let week = 1; week <= weeksInYear; week++) {
        const startDate = getMondayOfIsoWeek(anno, week);
        const endDate = new Date(startDate);
        endDate.setDate(startDate.getDate() + 6);
        grid.appendChild(createHolidayWeekSphere({ isoYear: anno, week, startDate, endDate, rangeLabel: formatHolidayWeekRange(startDate, endDate), holidayCount: Number(yearData.weeks?.[String(week)] || 0), isCurrent: currentWeek.year === anno && currentWeek.week === week }));
    }
    container.appendChild(grid);
}

async function fetchHolidayCampaignData(anno, department = "") {
    const query = new URLSearchParams({ year: String(anno) });
    if (department) query.set("reparto", department);
    const response = await fetch(HOLIDAY_CAMPAIGN_ENDPOINT + "?" + query.toString(), { cache: "no-store" });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.ok) throw new Error(data.error || "Attivita ferie non disponibile");
    return data;
}

function groupHolidayCampaignPreferences(preferences) {
    const weeks = Object.create(null);
    (preferences || []).forEach((pref) => {
        const key = String(pref.iso_week);
        if (!weeks[key]) weeks[key] = [];
        weeks[key].push(pref);
    });
    return weeks;
}

function summarizeHolidayCampaignPreferences(preferences) {
    return (preferences || []).reduce((summary, pref) => {
        const status = String(pref.status || "pending");
        if (!Object.prototype.hasOwnProperty.call(summary, status)) {
            summary[status] = 0;
        }
        summary[status] += 1;
        summary.total += 1;
        return summary;
    }, { pending: 0, approved: 0, rejected: 0, cancelled: 0, total: 0 });
}

function holidayCampaignPreferenceStatus(pref) {
    const status = String(pref?.status || "pending");
    if (status === "approved") {
        return { label: "Approvata", tone: "approved" };
    }
    if (status === "rejected") {
        return { label: "Rifiutata", tone: "rejected" };
    }
    if (status === "cancelled") {
        return { label: "Annullata", tone: "muted" };
    }
    return { label: "In attesa", tone: "pending" };
}

function holidayCampaignApprovalMeta(pref) {
    const pieces = [];
    if (Number(pref?.approved_by_manager || 0) === 1) pieces.push("Capo ok");
    if (Number(pref?.approved_by_admin || 0) === 1) pieces.push("Admin ok");
    if (Number(pref?.approved_by_director || 0) === 1) pieces.push("Direttore ok");
    return pieces.length ? pieces.join(" \u00B7 ") : "Ancora senza approvazioni";
}

function isAdminUser() {
    return String(window.userSession?.capo ?? "") === "3";
}

function holidayCampaignDepartmentValue(data) {
    return String(data?.department || appState.holidayCampaignDepartment || window.userSession?.reparto || "");
}

function createHolidayCampaignDepartmentSelect(data, anno) {
    if (!isAdminUser()) return null;
    const wrap = document.createElement("label");
    wrap.className = "holiday-campaign-department";
    const textLabel = document.createElement("span");
    textLabel.textContent = "Reparto";
    const select = document.createElement("select");
    select.className = "form-select form-select-sm";
    const selected = holidayCampaignDepartmentValue(data);
    Object.entries(window.appBootstrap?.departments || {}).forEach(([code, label]) => {
        const option = document.createElement("option");
        option.value = code;
        option.textContent = label;
        option.selected = code === selected;
        select.appendChild(option);
    });
    select.addEventListener("change", () => {
        appState.holidayCampaignDepartment = select.value;
        mostraAttivitaFerie(anno, { replaceHistory: true, department: select.value });
    });
    wrap.append(textLabel, select);
    return wrap;
}

function renderHolidayCampaignSummary(summary) {
    const wrap = document.createElement("div");
    wrap.className = "holiday-campaign-summary";
    [
        ["In attesa", summary.pending || 0, "pending"],
        ["Approvate", summary.approved || 0, "approved"],
        ["Rifiutate", summary.rejected || 0, "rejected"]
    ].forEach(([label, value, tone]) => {
        const chip = document.createElement("span");
        chip.className = "holiday-campaign-summary__chip holiday-campaign-summary__chip--" + tone;
        chip.textContent = label + ": " + value;
        wrap.appendChild(chip);
    });
    return wrap;
}

function renderHolidayCampaignControls(data, anno) {
    const controls = document.createElement("section");
    controls.className = "holiday-campaign-controls";
    const title = document.createElement("h2");
    title.textContent = Number(data.campaign?.submitted_to_director || 0) === 1 ? "Proposta ferie inviata" : (data.active ? "Attivita ferie avviata" : "Attivita ferie non avviata");
    const textLabel = document.createElement("p");
    textLabel.textContent = data.can_review_weeks
        ? "Gli addetti inviano richieste settimana per settimana. Qui puoi approvarle, rifiutarle e poi inviare la proposta finale al direttore."
        : "Seleziona le settimane desiderate: resteranno richieste in attesa di approvazione.";
    controls.append(title, textLabel);
    const departmentSelect = createHolidayCampaignDepartmentSelect(data, anno);
    if (departmentSelect) controls.appendChild(departmentSelect);
    controls.appendChild(renderHolidayCampaignSummary(data.summary || summarizeHolidayCampaignPreferences(data.preferences || [])));

    if (data.campaign && Number(data.campaign.submitted_to_director || 0) === 1) {
        const banner = document.createElement("div");
        banner.className = "holiday-campaign-banner";
        banner.textContent = Number(data.campaign.director_approval_simulated || 0) === 1
            ? "Proposta gia inviata: approvazione direttore simulata e ferie ufficiali aggiornate."
            : "Proposta gia inviata e approvata dal direttore.";
        controls.appendChild(banner);
    }

    if (data.can_manage) {
        const actions = document.createElement("div");
        actions.className = "holiday-campaign-actions";
        const toggleButton = document.createElement("button");
        toggleButton.type = "button";
        toggleButton.className = "btn " + (data.active ? "btn-outline-danger" : "btn-primary");
        toggleButton.textContent = data.active ? "Chiudi attivita" : "Avvia attivita";
        toggleButton.addEventListener("click", () => updateHolidayCampaign(data.active ? "close" : "open", anno, holidayCampaignDepartmentValue(data), toggleButton));
        actions.appendChild(toggleButton);

        if (data.active) {
            const submitButton = document.createElement("button");
            submitButton.type = "button";
            submitButton.className = "btn btn-outline-primary";
            submitButton.textContent = "Invia proposta al direttore";
            submitButton.disabled = !data.can_submit_to_director;
            submitButton.title = data.can_submit_to_director
                ? "Invia al direttore la proposta ferie gia revisionata"
                : "Prima completa la revisione di tutte le richieste e approva almeno una settimana";
            submitButton.addEventListener("click", () => updateHolidayCampaign("submit_to_director", anno, holidayCampaignDepartmentValue(data), submitButton));
            actions.appendChild(submitButton);
        }

        controls.appendChild(actions);
    }
    return controls;
}

function createHolidayCampaignWeekSphere(weekInfo, data, grouped) {
    const prefs = grouped[String(weekInfo.week)] || [];
    const mine = prefs.find((pref) => String(pref.user_cf || "") === String(data.viewer_cf || ""));
    const others = prefs.filter((pref) => String(pref.user_cf || "") !== String(data.viewer_cf || ""));
    const summary = summarizeHolidayCampaignPreferences(prefs);
    const sfera = document.createElement("button");
    sfera.type = "button";
    sfera.className = "sfera holiday-week-sphere holiday-campaign-week";
    if (mine) sfera.classList.add("holiday-campaign-week--mine");
    if (others.length) sfera.classList.add("holiday-campaign-week--shared");
    if ((summary.approved || 0) > 0) sfera.classList.add("holiday-campaign-week--approved");
    if ((summary.rejected || 0) > 0) sfera.classList.add("holiday-campaign-week--rejected");
    const content = document.createElement("div");
    content.className = "sfera__content";
    const day = document.createElement("div");
    day.className = "sfera__day";
    day.textContent = data.can_review_weeks ? "Revisiona" : (mine ? "Scelta mia" : "Settimana");
    const number = document.createElement("div");
    number.className = "sfera__number";
    number.textContent = weekInfo.week;
    const time = document.createElement("div");
    time.className = "sfera__time";
    time.textContent = weekInfo.rangeLabel;
    const hours = document.createElement("div");
    hours.className = "sfera__hours";
    if (prefs.length === 0) {
        hours.textContent = data.can_review_weeks ? "Nessuna richiesta" : "Libera";
    } else if (data.can_review_weeks) {
        hours.textContent = [
            summary.pending ? (summary.pending + " attesa") : "",
            summary.approved ? (summary.approved + " ok") : "",
            summary.rejected ? (summary.rejected + " no") : ""
        ].filter(Boolean).join(" \u00B7 ");
    } else {
        hours.textContent = prefs.length + (prefs.length === 1 ? " richiesta" : " richieste");
    }
    content.append(day, number, time, hours);
    sfera.appendChild(content);
    sfera.addEventListener("click", () => {
        if (data.can_review_weeks) {
            openHolidayCampaignWeekPanel(weekInfo);
            return;
        }
        if (!data.active) {
            showAppToast("Attivita ferie non avviata.");
            return;
        }
        toggleHolidayPreference(weekInfo, sfera);
    });
    return sfera;
}

function renderHolidayCampaignOwnRequest(body, weekInfo, data, prefs) {
    if (!data.active || !data.can_review_weeks) return;
    const mine = (prefs || []).find((pref) => String(pref.user_cf || "") === String(data.viewer_cf || ""));
    const item = document.createElement("article");
    item.className = "holiday-review-item holiday-review-item--mine";
    const heading = document.createElement("strong");
    heading.textContent = "La mia richiesta";
    const status = document.createElement("span");
    status.className = "holiday-review-item__meta";
    status.textContent = mine ? ("Gia presente - " + holidayCampaignPreferenceStatus(mine).label.toLowerCase()) : "Nessuna richiesta per questa settimana";
    const actions = document.createElement("div");
    actions.className = "holiday-review-item__actions";
    const toggle = document.createElement("button");
    toggle.type = "button";
    toggle.className = mine ? "btn btn-sm btn-outline-danger" : "btn btn-sm btn-primary";
    toggle.textContent = mine ? "Rimuovi la mia richiesta" : "Aggiungi le mie ferie";
    toggle.addEventListener("click", () => toggleHolidayPreference(weekInfo, toggle, { reopenPanel: true }));
    actions.appendChild(toggle);
    item.append(heading, status, actions);
    body.appendChild(item);
}

function renderHolidayCampaignWeekPanel(body, weekInfo, data) {
    const grouped = groupHolidayCampaignPreferences(data.preferences || []);
    const prefs = grouped[String(weekInfo.week)] || [];
    renderHolidayCampaignOwnRequest(body, weekInfo, data, prefs);
    if (!prefs.length) {
        const empty = document.createElement("p");
        empty.className = "holiday-panel__empty";
        empty.textContent = data.can_review_weeks
            ? "Nessuna richiesta presente in questa settimana."
            : "Nessuno ha richiesto questa settimana.";
        body.appendChild(empty);
        return;
    }

    const list = document.createElement("div");
    list.className = "holiday-review-list";
    prefs.forEach((pref) => {
        const item = document.createElement("article");
        item.className = "holiday-review-item";
        const heading = document.createElement("strong");
        heading.textContent = pref.display_name || "Addetto";
        const status = document.createElement("span");
        const statusMeta = holidayCampaignPreferenceStatus(pref);
        status.className = "holiday-review-item__status holiday-review-item__status--" + statusMeta.tone;
        status.textContent = statusMeta.label;
        const approvals = document.createElement("span");
        approvals.className = "holiday-review-item__meta";
        approvals.textContent = holidayCampaignApprovalMeta(pref);
        item.append(heading, status, approvals);

        if (data.can_review_weeks && data.active) {
            const actions = document.createElement("div");
            actions.className = "holiday-review-item__actions";

            if (String(pref.status || "") !== "approved") {
                const approve = document.createElement("button");
                approve.type = "button";
                approve.className = "btn btn-sm btn-primary";
                approve.textContent = "Approva";
                approve.addEventListener("click", () => reviewHolidayPreference(pref.id, "approve", weekInfo, approve));
                actions.appendChild(approve);
            }

            if (String(pref.status || "") !== "rejected") {
                const reject = document.createElement("button");
                reject.type = "button";
                reject.className = "btn btn-sm btn-outline-danger";
                reject.textContent = "Rifiuta";
                reject.addEventListener("click", () => reviewHolidayPreference(pref.id, "reject", weekInfo, reject));
                actions.appendChild(reject);
            }

            if (String(pref.status || "") !== "pending" || Number(pref.approved_by_manager || 0) === 1 || Number(pref.approved_by_admin || 0) === 1) {
                const reset = document.createElement("button");
                reset.type = "button";
                reset.className = "btn btn-sm btn-outline-secondary";
                reset.textContent = "Ripristina";
                reset.addEventListener("click", () => reviewHolidayPreference(pref.id, "reset", weekInfo, reset));
                actions.appendChild(reset);
            }

            item.appendChild(actions);
        }

        list.appendChild(item);
    });
    body.appendChild(list);
}

async function openHolidayCampaignWeekPanel(weekInfo) {
    const body = createHolidayPanelShell("Settimana " + weekInfo.week, weekInfo.rangeLabel + " \u00B7 " + weekInfo.isoYear);
    const loading = document.createElement("p");
    loading.className = "holiday-panel__empty";
    loading.textContent = "Caricamento richieste ferie...";
    body.appendChild(loading);
    try {
        const department = appState.holidayCampaignDepartment || String(window.userSession?.reparto || "");
        const data = await fetchHolidayCampaignData(weekInfo.isoYear, department);
        body.innerHTML = "";
        renderHolidayCampaignWeekPanel(body, weekInfo, data);
    } catch (error) {
        body.innerHTML = "";
        const message = document.createElement("p");
        message.className = "holiday-panel__empty";
        message.textContent = error.message || "Non riesco a caricare la settimana.";
        body.appendChild(message);
    }
}

async function updateHolidayCampaign(action, anno, department, button) {
    button.disabled = true;
    try {
        const body = new URLSearchParams({ action, year: String(anno), csrf_token: String(window.appCsrfToken || "") });
        if (department) body.set("reparto", department);
        const response = await fetch(HOLIDAY_CAMPAIGN_ENDPOINT, { method: "POST", body, credentials: "same-origin" });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.ok) throw new Error(data.error || "Operazione non riuscita");
        await mostraAttivitaFerie(anno, { replaceHistory: true, department });
        if (action === "submit_to_director") {
            showAppToast(data.message || "Proposta inviata al direttore.");
        } else {
            showAppToast(action === "open" ? "Attivita ferie avviata." : "Attivita ferie chiusa.");
        }
    } catch (error) {
        button.disabled = false;
        showAppToast(error.message || "Operazione non riuscita.");
    }
}

async function reviewHolidayPreference(preferenceId, decision, weekInfo, button) {
    button.disabled = true;
    try {
        const department = appState.holidayCampaignDepartment || "";
        const body = new URLSearchParams({
            action: "review_preference",
            year: String(weekInfo.isoYear),
            preference_id: String(preferenceId),
            decision,
            csrf_token: String(window.appCsrfToken || "")
        });
        if (department) body.set("reparto", department);
        const response = await fetch(HOLIDAY_CAMPAIGN_ENDPOINT, { method: "POST", body, credentials: "same-origin" });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.ok) throw new Error(data.error || "Operazione non riuscita");
        await mostraAttivitaFerie(weekInfo.isoYear, { replaceHistory: true, department });
        await openHolidayCampaignWeekPanel(weekInfo);
        const labels = { approve: "Settimana approvata.", reject: "Settimana rifiutata.", reset: "Richiesta ripristinata." };
        showAppToast(labels[decision] || "Richiesta aggiornata.");
    } catch (error) {
        button.disabled = false;
        showAppToast(error.message || "Operazione non riuscita.");
    }
}

async function toggleHolidayPreference(weekInfo, button, options = {}) {
    button.disabled = true;
    try {
        const department = appState.holidayCampaignDepartment || "";
        const body = new URLSearchParams({ action: "toggle_preference", year: String(weekInfo.isoYear), week: String(weekInfo.week), csrf_token: String(window.appCsrfToken || "") });
        if (department) body.set("reparto", department);
        const response = await fetch(HOLIDAY_CAMPAIGN_ENDPOINT, { method: "POST", body, credentials: "same-origin" });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.ok) throw new Error(data.error || "Richiesta non salvata");
        await mostraAttivitaFerie(weekInfo.isoYear, { replaceHistory: true, department });
        if (options.reopenPanel) {
            await openHolidayCampaignWeekPanel(weekInfo);
        }
        showAppToast(data.selected ? "Preferenza ferie salvata." : "Preferenza ferie rimossa.");
    } catch (error) {
        button.disabled = false;
        showAppToast(error.message || "Richiesta non salvata.");
    }
}

async function mostraAttivitaFerie(anno = today.getFullYear(), options = {}) {
    const department = options.department || appState.holidayCampaignDepartment || String(window.userSession?.reparto || "");
    appState.holidayCampaignDepartment = department;
    let data;
    try {
        data = await fetchHolidayCampaignData(anno, department);
    } catch (error) {
        showAppToast(error.message || "Attivita ferie non disponibile.");
        return;
    }
    if (!data.active && !data.can_manage) {
        showAppToast("Attivita ferie non avviata.");
        return;
    }
    showCalendarShell();
    appState.view = "holidayCampaign";
    appState.currentYear = anno;
    appState.currentMonth = null;
    appState.currentWeek = null;
    setVista("calendario vista-ferie griglia-settimane mt-4", "Inserimento ferie", { record: !options.replaceHistory });
    if (options.replaceHistory) appNavigationReplaceCurrentView();
    container.appendChild(createHolidayYearNavigation(anno, (targetYear) => mostraAttivitaFerie(targetYear, { replaceHistory: true, department: appState.holidayCampaignDepartment || department })));
    container.appendChild(renderHolidayCampaignControls(data, anno));
    if (!data.active && !(data.preferences || []).length) return;
    const grouped = groupHolidayCampaignPreferences(data.preferences || []);
    const grid = document.createElement("div");
    grid.className = "holiday-week-grid";
    const currentWeek = getIsoWeekInfo(today);
    const weeksInYear = getIsoWeeksInYear(anno);
    for (let week = 1; week <= weeksInYear; week++) {
        const startDate = getMondayOfIsoWeek(anno, week);
        const endDate = new Date(startDate);
        endDate.setDate(startDate.getDate() + 6);
        grid.appendChild(createHolidayCampaignWeekSphere({ isoYear: anno, week, rangeLabel: formatHolidayWeekRange(startDate, endDate), isCurrent: currentWeek.year === anno && currentWeek.week === week }, data, grouped));
    }
    container.appendChild(grid);
}

function openScheduleChangesFromUrl() {
    const params = new URLSearchParams(window.location.search);
    if (params.get("changes") !== "1") {
        return;
    }

    const batchId = params.get("batch") || "";
    window.history.replaceState({}, document.title, window.location.pathname);
    mostraModificheOrari(batchId);
}

window.openScheduleChangesFromUrl = openScheduleChangesFromUrl;

function getDayNoteList(notesMese, dataKey) {
    if (!notesMese || !notesMese.notes) {
        return [];
    }

    const notes = notesMese.notes[dataKey];
    return Array.isArray(notes) ? notes : [];
}

function getCurrentUserNoteFromDayNotes(dayNotes) {
    if (!Array.isArray(dayNotes)) {
        return "";
    }

    const currentUserKey = getCurrentUserKey();
    const currentUser = getCurrentUser();
    if (!currentUserKey && !currentUser) {
        return "";
    }

    const found = dayNotes.find((entry) => {
        const entryKey = (entry.userKey || "").toString().trim().toUpperCase();
        const entryName = (entry.userName || "").toString().trim().toUpperCase();
        return (currentUserKey && entryKey === currentUserKey) || (currentUser && entryName === currentUser);
    });

    return found ? (found.note || "").toString() : "";
}

function truncateNote(text, maxLength) {
    const value = (text || "").toString().trim();
    if (value.length <= maxLength) {
        return value;
    }

    return value.slice(0, Math.max(0, maxLength - 3)) + "...";
}

async function getMonthNotes(anno, mese) {
    const key = formatMonthKey(anno, mese);

    if (!appState.monthNotesPromises[key]) {
        appState.monthNotesPromises[key] = (async () => {
            try {
                const response = await fetch(NOTES_ENDPOINT + "?month=" + encodeURIComponent(key), {
                    cache: "no-store"
                });

                if (!response.ok) {
                    const fallback = { month: key, notes: {} };
                    appState.monthNotesCache[key] = fallback;
                    return fallback;
                }

                const data = await response.json();
                const normalized = {
                    month: data.month || key,
                    notes: data.notes || {}
                };
                appState.monthNotesCache[key] = normalized;
                return normalized;
            } catch (error) {
                console.error("Errore nel caricamento note mese", key, error);
                const fallback = { month: key, notes: {} };
                appState.monthNotesCache[key] = fallback;
                return fallback;
            }
        })();
    }

    return appState.monthNotesPromises[key];
}

async function getAllNotesForCapo() {
    if (!isCapoUser()) {
        throw new Error("Accesso negato");
    }

    const response = await fetch(NOTES_ENDPOINT + "?all=1", {
        cache: "no-store"
    });
    const data = await response.json();

    if (!response.ok || !data.ok) {
        throw new Error(data.error || "Non riesco a caricare le note");
    }

    return Array.isArray(data.months) ? data.months : [];
}

function formatDateLabel(dateKey) {
    const date = new Date(dateKey + "T00:00:00");
    if (Number.isNaN(date.getTime())) {
        return dateKey;
    }

    return date.toLocaleDateString("it-IT", {
        weekday: "long",
        day: "2-digit",
        month: "long",
        year: "numeric"
    });
}

function formatMonthLabel(monthKey) {
    const [year, month] = monthKey.split("-");
    const date = new Date(Number(year), Number(month) - 1, 1);
    if (Number.isNaN(date.getTime())) {
        return monthKey;
    }

    return date.toLocaleDateString("it-IT", {
        month: "long",
        year: "numeric"
    });
}

function updateMonthNotesCache(anno, mese, dataKey, entries) {
    const monthKey = formatMonthKey(anno, mese);
    const base = appState.monthNotesCache[monthKey] || { month: monthKey, notes: {} };
    const nextNotes = Object.assign({}, base.notes);

    if (Array.isArray(entries) && entries.length > 0) {
        nextNotes[dataKey] = entries;
    } else {
        delete nextNotes[dataKey];
    }

    const normalized = {
        month: monthKey,
        notes: nextNotes
    };

    appState.monthNotesCache[monthKey] = normalized;
    appState.monthNotesPromises[monthKey] = Promise.resolve(normalized);
}

function getRigaOrarioDaSettimana(dataSettimana, userCf, userName) {
    const normalizedUserCf = (userCf || "").toString().trim().toUpperCase();
    if (!Array.isArray(dataSettimana) || !normalizedUserCf) {
        return null;
    }

    return dataSettimana.find((riga) => {
        const rowCf = (riga.COD_FISCALE || "").toString().trim().toUpperCase();
        return rowCf === normalizedUserCf;
    }) || null;
}

function getOrarioDaSettimana(dataSettimana, userCf, userName, giornoParola) {
    const soloAddetto = getRigaOrarioDaSettimana(dataSettimana, userCf, userName);

    if (!soloAddetto) {
        return "";
    }

    return soloAddetto[giornoParola] || "";
}

function minutiLavoratiDaTurno(turno) {
    const testo = (turno || "").toString();
    const intervalli = /(?:^|\s|\/)(\d{1,2})\s*[:.]\s*(\d{2})\s*[-\u2013\u2014]\s*(\d{1,2})\s*[:.]\s*(\d{2})(?=$|\s|\/)/g;
    let totale = 0;
    let match;
    let haIntervalliEspliciti = false;

    while ((match = intervalli.exec(testo)) !== null) {
        haIntervalliEspliciti = true;
        const inizioOre = Number(match[1]);
        const inizioMinuti = Number(match[2]);
        const fineOre = Number(match[3]);
        const fineMinuti = Number(match[4]);

        if (inizioOre > 23 || fineOre > 23 || inizioMinuti > 59 || fineMinuti > 59) {
            continue;
        }

        const inizio = inizioOre * 60 + inizioMinuti;
        let fine = fineOre * 60 + fineMinuti;
        if (fine < inizio) {
            fine += 24 * 60;
        }

        totale += fine - inizio;
    }

    if (haIntervalliEspliciti) {
        return totale;
    }

    // Gli Excel standard salvano le fasce come coppie di orari, ad esempio
    // "05:00 10:00 10:30 12:00", senza il trattino tra entrata e uscita.
    const orari = [];
    const orariSemplici = /(?:^|\s|\/)(\d{1,2})\s*[:.]\s*(\d{2})(?=$|\s|\/)/g;
    while ((match = orariSemplici.exec(testo)) !== null) {
        const ore = Number(match[1]);
        const minuti = Number(match[2]);
        if (ore <= 23 && minuti <= 59) {
            orari.push(ore * 60 + minuti);
        }
    }

    for (let indice = 0; indice + 1 < orari.length; indice += 2) {
        let fine = orari[indice + 1];
        if (fine < orari[indice]) {
            fine += 24 * 60;
        }
        totale += fine - orari[indice];
    }

    return totale;
}

function minutiLavoratiSettimana(dataSettimana, userCf, userName) {
    const riga = getRigaOrarioDaSettimana(dataSettimana, userCf, userName);
    if (!riga) {
        return 0;
    }

    return SCHEDULE_DAY_KEYS.reduce((totale, giorno) => totale + minutiLavoratiDaTurno(riga[giorno]), 0);
}

function formatTotaleOreSettimanali(minuti) {
    const ore = Math.floor(minuti / 60);
    const minutiResidui = minuti % 60;

    if (minutiResidui === 0) {
        return ore + "h";
    }

    return ore + "h " + String(minutiResidui).padStart(2, "0") + "m";
}

async function mostraOrari(Nsettimana, giorno_parola, anno = today.getFullYear()) {
    const userCf = getCurrentUserKey();
    const userName = getCurrentUser();
    if (!userCf && !userName) {
        return "";
    }

    const data = await getWeekData(anno, Nsettimana);
    return getOrarioDaSettimana(data, userCf, userName, giorno_parola);
}

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
const departmentOverviewItem = document.getElementById("departmentOverviewItem");
const communicationsItem = document.getElementById("communicationsItem");
const profileItem = document.getElementById("profileItem");
const setting=document.getElementById("setting");
const appState = {
    view: "home",
    currentYear: null,
    currentMonth: null,
    currentWeek: null,
    departmentOverviewDepartment: null,
    selectedDay: null,
    noteViewToken: 0,
    calendarViewToken: 0,
    weekCache: Object.create(null),
    monthNotesCache: Object.create(null),
    monthNotesPromises: Object.create(null),
    transientCache: Object.create(null)
};
let appNavigationReady = false;
let appNavigationRestoring = false;
let appNavigationPosition = 0;

const MONTH_LABELS = ["Gen", "Feb", "Mar", "Apr", "Mag", "Giu", "Lug", "Ago", "Set", "Ott", "Nov", "Dic"];
const SCHEDULE_ENDPOINT = "connection_files/schedule.php";
const NOTES_ENDPOINT = "connection_files/note.php";
const SCHEDULE_DAY_KEYS = ["lunedì", "martedì", "mercoledì", "giovedì", "venerdì", "sabato", "domenica"];
const today = new Date();
const YEAR_CHOICES = Array.from({ length: 5 }, (_, index) => today.getFullYear() - 2 + index);
const todayKey = formatDateKey(today.getFullYear(), today.getMonth() + 1, today.getDate());

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
    appState.selectedDay = null;
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

    // Una schermata può essere stata aperta direttamente da una notifica:
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
                mostraAnni();
                break;
            case "mesi":
                mostraMesi(state.year);
                break;
            case "giorni":
                await mostraGiorni(state.year, state.month);
                break;
            case "giorno":
                if (state.day) {
                    await mostragiorno(state.day);
                } else {
                    await mostraGiorni(state.year, state.month);
                }
                break;
            case "noteAdmin":
                await mostraNoteAdmin();
                break;
            case "scheduleChanges":
                await mostraModificheOrari();
                break;
            case "scheduleAdjustments":
                await mostraRichiesteOre();
                break;
            case "departmentOverview":
                await mostraPanoramicaReparto(state.year, state.week, state.department);
                break;
            case "communications":
                await mostraComunicazioni();
                break;
            case "profilo":
                mostraProfilo();
                break;
            case "setting":
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
            previous.textContent = change.previous_shift || "Nessun turno";
            const arrow = document.createElement("span");
            arrow.className = "change-card__arrow";
            arrow.setAttribute("aria-label", "diventa");
            arrow.textContent = "→";
            const next = document.createElement("span");
            next.textContent = change.new_shift || "Nessun turno";
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
    const intervalli = /(?:^|\s|\/)(\d{1,2})\s*[:.]\s*(\d{2})\s*[-–—]\s*(\d{1,2})\s*[:.]\s*(\d{2})(?=$|\s|\/)/g;
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

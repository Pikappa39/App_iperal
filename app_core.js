//dichiaro variabili prendendole da index.php
const container = document.getElementById("contenitore");
const titolo = document.getElementById("titolo");
const back = document.getElementById("backbtn");
const homeBtn = document.getElementById("homebtn");
const homeScreen = document.getElementById("homeScreen");
const appToolbar = document.querySelector(".app-toolbar");
const openOrari = document.getElementById("openOrari");
const noteAdminItem = document.getElementById("noteAdminItem");

const appState = {
    view: "home",
    currentYear: null,
    currentMonth: null,
    selectedDay: null,
    noteViewToken: 0,
    weekCache: Object.create(null),
    monthNotesCache: Object.create(null),
    monthNotesPromises: Object.create(null)
};

const YEAR_CHOICES = [2024, 2025, 2026];
const MONTH_LABELS = ["Gen", "Feb", "Mar", "Apr", "Mag", "Giu", "Lug", "Ago", "Set", "Ott", "Nov", "Dic"];
const WEEK_DATA_DIRS = ["turni_json", "connection_files"];
const NOTES_ENDPOINT = "connection_files/note.php";
const today = new Date();
const todayKey = formatDateKey(today.getFullYear(), today.getMonth() + 1, today.getDate());
//questa funzione imposta la vista corrente, il titolo e svuota il contenitore
function setVista(classes, titoloTesto) {
    titolo.innerText = titoloTesto;
    container.className = classes;
    container.innerHTML = "";
}

function showHomeScreen() {
    appState.view = "home";
    appState.currentYear = null;
    appState.currentMonth = null;
    appState.selectedDay = null;
    titolo.innerText = "App Iperal";
    homeScreen.hidden = false;
    appToolbar.hidden = true;
    container.hidden = true;
    homeScreen.classList.remove("app-hidden");
    appToolbar.classList.add("app-hidden");
    container.classList.add("app-hidden");
    container.innerHTML = "";
}

function showCalendarShell() {
    homeScreen.hidden = true;
    appToolbar.hidden = false;
    container.hidden = false;
    homeScreen.classList.add("app-hidden");
    appToolbar.classList.remove("app-hidden");
    container.classList.remove("app-hidden");
}

function getCurrentUser() {
    return (window.userSession || "").toString().trim().toUpperCase();
}

function getCurrentUserKey() {
    return (window.userKey || "").toString().trim().toUpperCase();
}

function isCapoUser() {
    return String(window.capo || "") === "1";
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

function getWeekNumber(date) {
    const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
    const dayNum = d.getUTCDay() || 7;
    d.setUTCDate(d.getUTCDate() + 4 - dayNum);
    const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
    return Math.ceil((((d - yearStart) / 86400000) + 1) / 7);
}

async function getWeekData(settimana) {
    const key = String(settimana);

    if (!appState.weekCache[key]) {
        appState.weekCache[key] = (async () => {
            for (const dir of WEEK_DATA_DIRS) {
                try {
                    const response = await fetch(dir + "/" + key + ".json", {
                        cache: "force-cache"
                    });
                    if (response.ok) {
                        return await response.json();
                    }
                } catch (error) {
                    console.error("Errore nel caricamento della settimana", key, dir, error);
                }
            }

            return [];
        })();
    }

    return appState.weekCache[key];
}

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

function getOrarioDaSettimana(dataSettimana, user, giornoParola) {
    if (!Array.isArray(dataSettimana) || !user) {
        return "";
    }

    const soloAddetto = dataSettimana.find((riga) => {
        return (riga.ADDETTO || "").toString().trim().toUpperCase() === user;
    });

    if (!soloAddetto) {
        return "";
    }

    return soloAddetto[giornoParola] || "";
}

async function mostraOrari(Nsettimana, giorno_parola) {
    const user = getCurrentUser();
    if (!user) {
        return "";
    }

    const data = await getWeekData(Nsettimana);
    return getOrarioDaSettimana(data, user, giorno_parola);
}

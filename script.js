const container = document.getElementById("contenitore");
const titolo = document.getElementById("titolo");
const back = document.getElementById("backbtn");
const homeBtn = document.getElementById("homebtn");
const homeScreen = document.getElementById("homeScreen");
const appToolbar = document.querySelector(".app-toolbar");
const openOrari = document.getElementById("openOrari");
const noteAdminItem = document.getElementById("noteAdminItem");

let indice;
let annocorrente;
let mesecorrente;
const WEEK_DATA_DIRS = ["turni_json", "connection_files"];
const NOTES_ENDPOINT = "connection_files/note.php";
const today = new Date();
const todayKey = formatDateKey(today.getFullYear(), today.getMonth() + 1, today.getDate());

const cacheSettimane = {};
const cacheNoteMese = {};
const cacheNoteMesePromise = {};

let giornoSelezionato = null;
let noteViewToken = 0;

function setVista(classes, titoloTesto) {
    titolo.innerText = titoloTesto;
    container.className = classes;
    container.innerHTML = "";
}

function showHomeScreen() {
    indice = "home";
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

    if (!cacheSettimane[key]) {
        cacheSettimane[key] = (async () => {
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

    return cacheSettimane[key];
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

    if (!cacheNoteMesePromise[key]) {
        cacheNoteMesePromise[key] = (async () => {
            try {
                const response = await fetch(NOTES_ENDPOINT + "?month=" + encodeURIComponent(key), {
                    cache: "no-store"
                });

                if (!response.ok) {
                    const fallback = { month: key, notes: {} };
                    cacheNoteMese[key] = fallback;
                    return fallback;
                }

                const data = await response.json();
                const normalized = {
                    month: data.month || key,
                    notes: data.notes || {}
                };
                cacheNoteMese[key] = normalized;
                return normalized;
            } catch (error) {
                console.error("Errore nel caricamento note mese", key, error);
                const fallback = { month: key, notes: {} };
                cacheNoteMese[key] = fallback;
                return fallback;
            }
        })();
    }

    return cacheNoteMesePromise[key];
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
    const base = cacheNoteMese[monthKey] || { month: monthKey, notes: {} };
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

    cacheNoteMese[monthKey] = normalized;
    cacheNoteMesePromise[monthKey] = Promise.resolve(normalized);
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

function creaSferaGiorno(giornoInfo, target = container) {
    const sfera = document.createElement("div");
    sfera.classList.add("sfera");
    if (giornoInfo.opaco) {
        sfera.classList.add("opaco");
    }

    const content = document.createElement("div");
    content.className = "sfera__content";

    const day = document.createElement("div");
    day.className = "sfera__day";
    day.textContent = giornoInfo.giorno_parola;

    const number = document.createElement("div");
    number.className = "sfera__number";
    number.textContent = giornoInfo.numero;

    const time = document.createElement("div");
    time.className = "sfera__time";
    time.textContent = giornoInfo.orario || "RIPOSO";

    content.appendChild(day);
    content.appendChild(number);
    content.appendChild(time);

    if (giornoInfo.noteSummary) {
        const note = document.createElement("div");
        note.className = "sfera__note";
        note.textContent = giornoInfo.noteSummary;
        content.appendChild(note);
    }

    sfera.appendChild(content);
    sfera.setAttribute("data-settimana", giornoInfo.settimana);
    sfera.setAttribute("data-giorno", giornoInfo.numero);
    sfera.setAttribute("data-giorno-parola", giornoInfo.giorno_parola);
    sfera.setAttribute("data-data-key", giornoInfo.dataKey);

    sfera.onclick = function () {
        mostragiorno(giornoInfo);
    };
    target.appendChild(sfera);
}

function mostraAnni() {
    indice = "anni";
    setVista("calendario griglia-anni mt-4", "Anni");

    const anni = [2024, 2025, 2026];
    const fragment = document.createDocumentFragment();

    for (let i = 0; i < anni.length; i++) {
        const anno = anni[i];
        const sfera = document.createElement("div");
        const div = document.createElement("div");

        sfera.classList.add("sfera");
        sfera.innerText = anno;
        sfera.setAttribute("data-anno", anno);
        sfera.onclick = function () {
            const annoScelto = parseInt(this.getAttribute("data-anno"), 10);
            mostraMesi(annoScelto);
            annocorrente = annoScelto;
        };

        fragment.appendChild(sfera);
        div.textContent = anno;
        fragment.appendChild(div);
    }

    container.appendChild(fragment);
}

function mostraMesi(anno) {
    indice = "mesi";
    setVista("calendario griglia-mesi mt-4", "Mesi " + anno);

    const mesi = ["Gen", "Feb", "Mar", "Apr", "Mag", "Giu", "Lug", "Ago", "Set", "Ott", "Nov", "Dic"];
    const fragment = document.createDocumentFragment();

    for (let i = 0; i < mesi.length; i++) {
        const mese = mesi[i];
        const numeroMese = i + 1;
        const sfera = document.createElement("div");

        sfera.classList.add("sfera");
        sfera.innerText = mese;
        sfera.setAttribute("data-mese", numeroMese);
        sfera.onclick = function () {
            const meseScelto = parseInt(this.getAttribute("data-mese"), 10);
            mostraGiorni(anno, meseScelto);
            annocorrente = anno;
            mesecorrente = meseScelto;
        };

        fragment.appendChild(sfera);
    }

    container.appendChild(fragment);
}

async function mostraGiorni(anno, mese) {
    showCalendarShell();
    indice = "giorni";
    setVista("calendario griglia-giorni mt-4", "Giorni " + mese + "/" + anno);

    const primoGiorno = (() => {
        const giorno = new Date(anno, mese - 1, 1).getDay();
        return giorno === 0 ? 6 : giorno - 1;
    })();

    const giorniNelMese = new Date(anno, mese, 0).getDate();

    let mesePrec;
    let annoPrec;
    if (mese === 1) {
        mesePrec = 12;
        annoPrec = anno - 1;
    } else {
        mesePrec = mese - 1;
        annoPrec = anno;
    }

    const giorniMesePrec = new Date(annoPrec, mesePrec, 0).getDate();

    let meseSucc;
    let annoSucc;
    if (mese === 12) {
        meseSucc = 1;
        annoSucc = anno + 1;
    } else {
        meseSucc = mese + 1;
        annoSucc = anno;
    }

    const giorniDaMostrare = [];
    const settimaneDaCaricare = new Set();

    for (let i = 0; i < primoGiorno; i++) {
        const giorno = giorniMesePrec - primoGiorno + 1 + i;
        const data = new Date(annoPrec, mesePrec - 1, giorno);
        const giorno_parola = getDayLabel(data);
        const settimana = getWeekNumber(data);

        giorniDaMostrare.push({
            numero: giorno,
            opaco: true,
            giorno_parola,
            settimana,
            dataKey: formatDateKey(annoPrec, mesePrec, giorno)
        });
        settimaneDaCaricare.add(settimana);
    }

    for (let i = 1; i <= giorniNelMese; i++) {
        const data = new Date(anno, mese - 1, i);
        const giorno_parola = getDayLabel(data);
        const settimana = getWeekNumber(data);

        giorniDaMostrare.push({
            numero: i,
            opaco: false,
            giorno_parola,
            settimana,
            dataKey: formatDateKey(anno, mese, i)
        });
        settimaneDaCaricare.add(settimana);
    }

    const celleDopo = (7 - ((primoGiorno + giorniNelMese) % 7)) % 7;
    for (let i = 1; i <= celleDopo; i++) {
        const data = new Date(annoSucc, meseSucc - 1, i);
        const giorno_parola = getDayLabel(data);
        const settimana = getWeekNumber(data);

        giorniDaMostrare.push({
            numero: i,
            opaco: true,
            giorno_parola,
            settimana,
            dataKey: formatDateKey(annoSucc, meseSucc, i)
        });
        settimaneDaCaricare.add(settimana);
    }

    const noteMese = await getMonthNotes(anno, mese);
    const settimaneCaricate = {};
    await Promise.all([...settimaneDaCaricare].map(async (settimana) => {
        settimaneCaricate[settimana] = await getWeekData(settimana);
    }));

    const user = getCurrentUser();
    const fragment = document.createDocumentFragment();

    for (let i = 0; i < giorniDaMostrare.length; i += 7) {
        const row = document.createElement("div");
        row.className = "week-row";

        const label = document.createElement("div");
        label.className = "week-label";
        label.textContent = "Settimana " + giorniDaMostrare[i].settimana;

        const grid = document.createElement("div");
        grid.className = "week-grid";

        for (let j = i; j < i + 7 && j < giorniDaMostrare.length; j++) {
            const giorno = giorniDaMostrare[j];
            const dataSettimana = settimaneCaricate[giorno.settimana] || [];
            const orario = getOrarioDaSettimana(dataSettimana, user, giorno.giorno_parola);
            const dayNotes = getDayNoteList(noteMese, giorno.dataKey);
            const currentUserNote = getCurrentUserNoteFromDayNotes(dayNotes);

            creaSferaGiorno({
                numero: giorno.numero,
                opaco: giorno.opaco,
                giorno_parola: giorno.giorno_parola,
                settimana: giorno.settimana,
                orario,
                dataKey: giorno.dataKey,
                noteSummary: currentUserNote
                    ? truncateNote(currentUserNote, 26)
                    : (dayNotes.length > 0 ? (dayNotes.length === 1 ? "1 nota" : dayNotes.length + " note") : "")
            }, grid);
        }

        row.appendChild(label);
        row.appendChild(grid);
        fragment.appendChild(row);
    }

    container.appendChild(fragment);

    const candidates = Array.from(container.querySelectorAll("[data-giorno]"));
    const matchingToday = candidates.find((el) => el.getAttribute("data-giorno") === String(today.getDate()) && el.getAttribute("data-giorno-parola") === getDayLabel(today) && formatDateKey(anno, mese, today.getDate()) === todayKey);

    if (matchingToday) {
        matchingToday.classList.add("today");
        matchingToday.scrollIntoView({
            behavior: "auto",
            block: "center",
            inline: "center"
        });
    }
}

async function mostragiorno(giornoInfo) {
    indice = "giorno";
    giornoSelezionato = giornoInfo;
    container.classList.remove("griglia-giorni", "griglia-mesi", "griglia-anni");
    container.classList.add("vista-giorno");
    container.innerHTML = "";

    const [annoStr, meseStr] = (giornoInfo.dataKey || "").split("-");
    const anno = parseInt(annoStr, 10);
    const mese = parseInt(meseStr, 10);

    const wrapper = document.createElement("div");
    wrapper.className = "note-panel";

    const title = document.createElement("h4");
    title.className = "note-panel__title";
    title.textContent = "Nota per " + giornoInfo.giorno_parola + " " + giornoInfo.dataKey;

    const subtitle = document.createElement("div");
    subtitle.className = "note-panel__subtitle";
    subtitle.textContent = getCurrentUser()
        ? "La nota viene salvata per il tuo utente e per questo giorno."
        : "Effettua il login per scrivere o salvare una nota.";

    const existingNotes = document.createElement("div");
    existingNotes.className = "note-panel__existing";
    existingNotes.textContent = "Caricamento note...";

    const textarea = document.createElement("textarea");
    textarea.placeholder = "Inserisci le tue note qui...";
    textarea.disabled = !getCurrentUser();

    const status = document.createElement("div");
    status.className = "note-panel__status";

    const salvaBtn = document.createElement("button");
    salvaBtn.innerText = "Salva";
    salvaBtn.classList.add("btn", "btn-primary");
    salvaBtn.disabled = !getCurrentUser();

    wrapper.appendChild(title);
    wrapper.appendChild(subtitle);
    wrapper.appendChild(existingNotes);
    wrapper.appendChild(textarea);
    wrapper.appendChild(status);
    wrapper.appendChild(salvaBtn);
    container.appendChild(wrapper);

    const token = ++noteViewToken;

    try {
        const noteMese = await getMonthNotes(anno, mese);
        if (token !== noteViewToken) {
            return;
        }

        const dayNotes = getDayNoteList(noteMese, giornoInfo.dataKey);
        const ownNote = getCurrentUserNoteFromDayNotes(dayNotes);
        textarea.value = ownNote;

        if (dayNotes.length > 0) {
            existingNotes.textContent = dayNotes.length === 1
                ? "1 nota per questo giorno."
                : "Ci sono " + dayNotes.length + " note per questo giorno.";
        } else {
            existingNotes.textContent = "Nessuna nota salvata per questo giorno.";
        }
    } catch (error) {
        console.error("Errore nel caricamento della nota", error);
        existingNotes.textContent = "Non riesco a caricare le note di questo giorno.";
    }

    if (!getCurrentUser()) {
        status.textContent = "Devi accedere per salvare una nota.";
        return;
    }

    textarea.focus();

    salvaBtn.addEventListener("click", async function () {
        const currentToken = noteViewToken;
        const noteText = textarea.value;
        const normalizedNote = noteText.trim();

        salvaBtn.disabled = true;
        textarea.disabled = true;
        status.textContent = "Salvataggio in corso...";

        const formData = new FormData();
        formData.append("date", giornoInfo.dataKey);
        formData.append("note", noteText);

        try {
            const response = await fetch(NOTES_ENDPOINT, {
                method: "POST",
                body: formData
            });

            const responseText = await response.text();
            let result = null;

            try {
                result = responseText ? JSON.parse(responseText) : null;
            } catch (parseError) {
                console.error("Risposta non JSON dal server:", responseText);
                throw new Error("Il server ha risposto con un formato non valido.");
            }

            if (!response.ok || !result.ok) {
                const serverError = result.error || "Salvataggio non riuscito";
                if (result.details) {
                    console.error("Dettagli errore server:", result.details);
                }
                throw new Error(serverError);
            }

            if (currentToken !== noteViewToken) {
                return;
            }

            updateMonthNotesCache(anno, mese, giornoInfo.dataKey, result.notes || []);
            const updatedDayNotes = Array.isArray(result.notes) ? result.notes : [];

            if (updatedDayNotes.length > 0) {
                existingNotes.textContent = updatedDayNotes.length === 1
                    ? "1 nota per questo giorno."
                    : "Ci sono " + updatedDayNotes.length + " note per questo giorno.";
            } else {
                existingNotes.textContent = "Nessuna nota salvata per questo giorno.";
            }

            status.textContent = normalizedNote ? "Nota salvata con successo." : "Nota rimossa.";
            showAppToast(normalizedNote ? "Nota salvata" : "Nota rimossa");
        } catch (error) {
            console.error("Errore nel salvataggio della nota", error);
            status.textContent = "Non riesco a salvare la nota.";
            showAppToast("Errore nel salvataggio della nota");
        } finally {
            if (currentToken === noteViewToken) {
                salvaBtn.disabled = false;
                textarea.disabled = false;
            }
        }
    });
}

async function mostraNoteAdmin() {
    showCalendarShell();
    indice = "noteAdmin";
    setVista("calendario vista-note-admin mt-4", "Note dipendenti");

    const panel = document.createElement("div");
    panel.className = "admin-notes";

    const intro = document.createElement("div");
    intro.className = "admin-notes__intro";
    intro.textContent = "Qui trovi tutte le note inserite dagli utenti, raggruppate per mese.";

    const body = document.createElement("div");
    body.className = "admin-notes__body";
    body.textContent = "Caricamento note...";

    panel.appendChild(intro);
    panel.appendChild(body);
    container.appendChild(panel);

    try {
        const months = await getAllNotesForCapo();
        body.innerHTML = "";

        const usefulMonths = months.filter((monthData) => Array.isArray(monthData.entries) && monthData.entries.length > 0);
        if (usefulMonths.length === 0) {
            body.textContent = "Non ci sono ancora note salvate.";
            return;
        }

        const fragment = document.createDocumentFragment();

        usefulMonths.forEach((monthData) => {
            const section = document.createElement("section");
            section.className = "admin-notes__month";

            const title = document.createElement("h4");
            title.className = "admin-notes__month-title";
            title.textContent = formatMonthLabel(monthData.month);

            const list = document.createElement("div");
            list.className = "admin-notes__list";

            monthData.entries.forEach((entry) => {
                const card = document.createElement("article");
                card.className = "admin-note-card";

                const meta = document.createElement("div");
                meta.className = "admin-note-card__meta";

                const user = document.createElement("div");
                user.className = "admin-note-card__user";
                user.textContent = entry.userName || "Utente";

                const date = document.createElement("div");
                date.className = "admin-note-card__date";
                date.textContent = formatDateLabel(entry.date || "");

                const note = document.createElement("div");
                note.className = "admin-note-card__text";
                note.textContent = entry.note || "";

                meta.appendChild(user);
                meta.appendChild(date);
                card.appendChild(meta);
                card.appendChild(note);
                list.appendChild(card);
            });

            section.appendChild(title);
            section.appendChild(list);
            fragment.appendChild(section);
        });

        body.appendChild(fragment);
    } catch (error) {
        console.error("Errore nel caricamento note admin", error);
        body.textContent = "Non riesco a caricare l'elenco delle note.";
        showAppToast("Errore nel caricamento note");
    }
}

back.onclick = function () {
    if (indice === "home") {
        return;
    }

    if (indice === "anni") {
        alert("non puoi andare piu indietro di cosi");
        return;
    }

    if (indice === "mesi") {
        mostraAnni();
        return;
    }

    if (indice === "giorni") {
        mostraMesi(annocorrente);
        return;
    }

    if (indice === "giorno") {
        mostraGiorni(annocorrente, mesecorrente);
        return;
    }

    if (indice === "noteAdmin") {
        showHomeScreen();
    }
};

if (openOrari) {
    openOrari.addEventListener("click", () => {
        annocorrente = today.getFullYear();
        mesecorrente = today.getMonth() + 1;
        mostraGiorni(annocorrente, mesecorrente);
    });
}

if (homeBtn) {
    homeBtn.addEventListener("click", showHomeScreen);
}

if (noteAdminItem) {
    noteAdminItem.addEventListener("click", () => {
        mostraNoteAdmin();
    });
}

showHomeScreen();

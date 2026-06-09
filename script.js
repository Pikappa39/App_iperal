const container = document.getElementById("contenitore");
const titolo = document.getElementById("titolo");
const back = document.getElementById("backbtn");
const homeBtn = document.getElementById("homebtn");
const homeScreen = document.getElementById("homeScreen");
const appToolbar = document.querySelector(".app-toolbar");
const openOrari = document.getElementById("openOrari");

let indice;
let annocorrente;
let mesecorrente;
const WEEK_DATA_DIRS = ["turni_json", "connection_files"];
const today = new Date();
const todayKey = formatDateKey(today.getFullYear(), today.getMonth() + 1, today.getDate());

// Cache delle settimane gia' scaricate.
// Salviamo le Promise per evitare richieste duplicate in parallelo.
const cacheSettimane = {};

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

function creaSferaGiorno(numero, opaco, giorno_parola, settimana, orario, target = container) {
    const sfera = document.createElement("div");
    sfera.classList.add("sfera");
    if (opaco) {
        sfera.classList.add("opaco");
    }

    const content = document.createElement("div");
    content.className = "sfera__content";

    const day = document.createElement("div");
    day.className = "sfera__day";
    day.textContent = giorno_parola;

    const number = document.createElement("div");
    number.className = "sfera__number";
    number.textContent = numero;

    const time = document.createElement("div");
    time.className = "sfera__time";
    time.textContent = orario || "RIPOSO";

    content.appendChild(day);
    content.appendChild(number);
    content.appendChild(time);

    sfera.appendChild(content);
    sfera.setAttribute("data-settimana", settimana);
    sfera.setAttribute("data-giorno", numero);
    sfera.setAttribute("data-giorno-parola", giorno_parola);

    sfera.onclick = function () {
        mostragiorno();
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
    const giorniConData = [];

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

    // Scarichiamo tutte le settimane una volta sola e in parallelo.
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
            creaSferaGiorno(giorno.numero, giorno.opaco, giorno.giorno_parola, giorno.settimana, orario, grid);
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

function mostragiorno() {
    indice = "giorno";
    container.classList.remove("griglia-giorni", "griglia-mesi", "griglia-anni");
    container.classList.add("vista-giorno");
    container.innerHTML = "";

    const textarea = document.createElement("textarea");
    textarea.placeholder = "Inserisci le tue note qui...";

    const salvaBtn = document.createElement("button");
    salvaBtn.innerText = "Salva";
    salvaBtn.classList.add("btn", "btn-primary");

    container.appendChild(textarea);
    container.appendChild(salvaBtn);
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

showHomeScreen();

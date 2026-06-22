function buildVisibleDaysForMonth(anno, mese) {
    const firstWeekdayIndex = (() => {
        const day = new Date(anno, mese - 1, 1).getDay();
        return day === 0 ? 6 : day - 1;
    })();

    const daysInMonth = new Date(anno, mese, 0).getDate();
    const previousMonth = mese === 1 ? 12 : mese - 1;
    const previousYear = mese === 1 ? anno - 1 : anno;
    const daysInPreviousMonth = new Date(previousYear, previousMonth, 0).getDate();
    const nextMonth = mese === 12 ? 1 : mese + 1;
    const nextYear = mese === 12 ? anno + 1 : anno;

    const visibleDays = [];
    const weeksToLoad = new Set();

    for (let i = 0; i < firstWeekdayIndex; i++) {
        const dayNumber = daysInPreviousMonth - firstWeekdayIndex + 1 + i;
        const date = new Date(previousYear, previousMonth - 1, dayNumber);
        const dayLabel = getDayLabel(date);
        const weekNumber = getWeekNumber(date);

        visibleDays.push({
            numero: dayNumber,
            opaco: true,
            giorno_parola: dayLabel,
            settimana: weekNumber,
            dataKey: formatDateKey(previousYear, previousMonth, dayNumber)
        });
        weeksToLoad.add(weekNumber);
    }

    for (let dayNumber = 1; dayNumber <= daysInMonth; dayNumber++) {
        const date = new Date(anno, mese - 1, dayNumber);
        const dayLabel = getDayLabel(date);
        const weekNumber = getWeekNumber(date);

        visibleDays.push({
            numero: dayNumber,
            opaco: false,
            giorno_parola: dayLabel,
            settimana: weekNumber,
            dataKey: formatDateKey(anno, mese, dayNumber)
        });
        weeksToLoad.add(weekNumber);
    }

    const trailingCells = (7 - ((firstWeekdayIndex + daysInMonth) % 7)) % 7;
    for (let dayNumber = 1; dayNumber <= trailingCells; dayNumber++) {
        const date = new Date(nextYear, nextMonth - 1, dayNumber);
        const dayLabel = getDayLabel(date);
        const weekNumber = getWeekNumber(date);

        visibleDays.push({
            numero: dayNumber,
            opaco: true,
            giorno_parola: dayLabel,
            settimana: weekNumber,
            dataKey: formatDateKey(nextYear, nextMonth, dayNumber)
        });
        weeksToLoad.add(weekNumber);
    }

    return {
        visibleDays,
        weeksToLoad
    };
}

function getNoteSummaryForDay(currentUserNote, dayNotes) {
    if (currentUserNote) {
        return truncateNote(currentUserNote, 26);
    }

    if (!Array.isArray(dayNotes) || dayNotes.length === 0) {
        return "";
    }

    return dayNotes.length === 1 ? "1 nota" : dayNotes.length + " note";
}

function createDaySphere(giornoInfo, target = container) {
    const sfera = document.createElement("button");
    sfera.type = "button";
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
    sfera.setAttribute("aria-label", `Apri ${giornoInfo.giorno_parola} ${giornoInfo.numero}: ${giornoInfo.orario || "riposo"}`);

    sfera.onclick = function () {
        mostragiorno(giornoInfo);
    };
    target.appendChild(sfera);
}

const FULL_MONTH_LABELS = [
    "Gennaio", "Febbraio", "Marzo", "Aprile", "Maggio", "Giugno",
    "Luglio", "Agosto", "Settembre", "Ottobre", "Novembre", "Dicembre"
];

function getAdjacentMonth(anno, mese, direction) {
    const date = new Date(anno, mese - 1 + direction, 1);
    return { anno: date.getFullYear(), mese: date.getMonth() + 1 };
}

function closeCalendarPicker() {
    const picker = document.querySelector(".calendar-picker-backdrop");
    if (picker) picker.remove();
}

function createCalendarPicker(titleText) {
    closeCalendarPicker();

    const backdrop = document.createElement("div");
    backdrop.className = "calendar-picker-backdrop";
    backdrop.addEventListener("click", function (event) {
        if (event.target === backdrop) closeCalendarPicker();
    });

    const panel = document.createElement("section");
    panel.className = "calendar-picker";
    const header = document.createElement("div");
    header.className = "calendar-picker__header";
    const title = document.createElement("h4");
    title.textContent = titleText;
    const close = document.createElement("button");
    close.type = "button";
    close.className = "calendar-picker__close";
    close.textContent = "×";
    close.setAttribute("aria-label", "Chiudi selettore calendario");
    close.addEventListener("click", closeCalendarPicker);
    header.append(title, close);
    panel.appendChild(header);
    backdrop.appendChild(panel);
    document.body.appendChild(backdrop);

    return panel;
}

function openMonthPicker(anno) {
    const panel = createCalendarPicker("Mesi " + anno);
    const yearButton = document.createElement("button");
    yearButton.type = "button";
    yearButton.className = "calendar-picker__year";
    yearButton.textContent = "Cambia anno";
    yearButton.addEventListener("click", function () {
        openYearPicker(anno);
    });

    const grid = document.createElement("div");
    grid.className = "calendar-picker__grid";
    FULL_MONTH_LABELS.forEach(function (label, index) {
        const button = document.createElement("button");
        button.type = "button";
        button.textContent = label;
        button.addEventListener("click", function () {
            closeCalendarPicker();
            mostraGiorni(anno, index + 1, { replaceHistory: true });
        });
        grid.appendChild(button);
    });

    panel.append(yearButton, grid);
}

function openYearPicker(selectedYear) {
    const panel = createCalendarPicker("Scegli l'anno");
    const grid = document.createElement("div");
    grid.className = "calendar-picker__grid calendar-picker__grid--years";
    YEAR_CHOICES.forEach(function (anno) {
        const button = document.createElement("button");
        button.type = "button";
        button.textContent = anno;
        button.classList.toggle("is-selected", anno === selectedYear);
        button.addEventListener("click", function () {
            openMonthPicker(anno);
        });
        grid.appendChild(button);
    });
    panel.appendChild(grid);
}

function createCalendarNavigation(anno, mese) {
    const navigation = document.createElement("nav");
    navigation.className = "calendar-navigation";
    navigation.setAttribute("aria-label", "Navigazione calendario");

    const previous = document.createElement("button");
    previous.type = "button";
    previous.className = "calendar-navigation__arrow";
    previous.textContent = "‹";
    previous.setAttribute("aria-label", "Mese precedente");
    previous.addEventListener("click", function () {
        const target = getAdjacentMonth(anno, mese, -1);
        mostraGiorni(target.anno, target.mese, { replaceHistory: true });
    });

    const label = document.createElement("div");
    label.className = "calendar-navigation__label";
    const monthButton = document.createElement("button");
    monthButton.type = "button";
    monthButton.textContent = FULL_MONTH_LABELS[mese - 1];
    monthButton.addEventListener("click", function () {
        openMonthPicker(anno);
    });
    const yearButton = document.createElement("button");
    yearButton.type = "button";
    yearButton.textContent = anno;
    yearButton.addEventListener("click", function () {
        openYearPicker(anno);
    });
    label.append(monthButton, yearButton);

    const next = document.createElement("button");
    next.type = "button";
    next.className = "calendar-navigation__arrow";
    next.textContent = "›";
    next.setAttribute("aria-label", "Mese successivo");
    next.addEventListener("click", function () {
        const target = getAdjacentMonth(anno, mese, 1);
        mostraGiorni(target.anno, target.mese, { replaceHistory: true });
    });

    navigation.append(previous, label, next);
    return navigation;
}

function mostraAnni() {
    appState.view = "anni";
    appState.currentMonth = null;
    setVista("calendario griglia-anni mt-4", "Anni");

    const fragment = document.createDocumentFragment();

    for (let i = 0; i < YEAR_CHOICES.length; i++) {
        const anno = YEAR_CHOICES[i];
        const sfera = document.createElement("div");

        sfera.classList.add("sfera");
        sfera.innerText = anno;
        sfera.setAttribute("data-anno", anno);
        sfera.onclick = function () {
            const annoScelto = parseInt(this.getAttribute("data-anno"), 10);
            mostraMesi(annoScelto);
            appState.currentYear = annoScelto;
        };

        fragment.appendChild(sfera);
    }

    container.appendChild(fragment);
}

function mostraMesi(anno) {
    appState.view = "mesi";
    appState.currentYear = anno;
    appState.currentMonth = null;
    setVista("calendario griglia-mesi mt-4", "Mesi " + anno);

    const fragment = document.createDocumentFragment();

    for (let i = 0; i < MONTH_LABELS.length; i++) {
        const mese = MONTH_LABELS[i];
        const numeroMese = i + 1;
        const sfera = document.createElement("div");

        sfera.classList.add("sfera");
        sfera.innerText = mese;
        sfera.setAttribute("data-mese", numeroMese);
        sfera.onclick = function () {
            const meseScelto = parseInt(this.getAttribute("data-mese"), 10);
            mostraGiorni(anno, meseScelto);
            appState.currentYear = anno;
            appState.currentMonth = meseScelto;
        };

        fragment.appendChild(sfera);
    }

    container.appendChild(fragment);
}

async function mostraGiorni(anno, mese, options = {}) {
    showCalendarShell();
    appState.view = "giorni";
    appState.currentYear = anno;
    appState.currentMonth = mese;
    setVista("calendario griglia-giorni mt-4", "Orari", { record: !options.replaceHistory });
    if (options.replaceHistory) {
        appNavigationReplaceCurrentView();
    }
    const viewToken = appState.calendarViewToken;

    const { visibleDays, weeksToLoad } = buildVisibleDaysForMonth(anno, mese);
    container.appendChild(createCalendarNavigation(anno, mese));

    const noteMese = await getMonthNotes(anno, mese);
    const settimaneCaricate = {};
    await Promise.all([...weeksToLoad].map(async (settimana) => {
        settimaneCaricate[settimana] = await getWeekData(anno, settimana);
    }));

    if (viewToken !== appState.calendarViewToken || appState.view !== "giorni") {
        return;
    }

    const userCf = getCurrentUserKey();
    const userName = getCurrentUser();
    const fragment = document.createDocumentFragment();

    for (let i = 0; i < visibleDays.length; i += 7) {
        const row = document.createElement("div");
        row.className = "week-row";

        const label = document.createElement("div");
        label.className = "week-label";
        label.textContent = "Settimana " + visibleDays[i].settimana;

        const grid = document.createElement("div");
        grid.className = "week-grid";

        for (let j = i; j < i + 7 && j < visibleDays.length; j++) {
            const giorno = visibleDays[j];
            const dataSettimana = settimaneCaricate[giorno.settimana] || [];
            const orario = getOrarioDaSettimana(dataSettimana, userCf, userName, giorno.giorno_parola);
            const dayNotes = getDayNoteList(noteMese, giorno.dataKey);
            const currentUserNote = getCurrentUserNoteFromDayNotes(dayNotes);

            createDaySphere({
                numero: giorno.numero,
                opaco: giorno.opaco,
                giorno_parola: giorno.giorno_parola,
                settimana: giorno.settimana,
                orario,
                dataKey: giorno.dataKey,
                noteSummary: getNoteSummaryForDay(currentUserNote, dayNotes)
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

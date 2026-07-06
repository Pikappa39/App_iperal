// Ferie personali.
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
        subtitle.textContent = "Settimana " + status.current.isoWeek + " \u00B7 " + status.current.rangeLabel;
        const confetti = document.createElement("div");
        confetti.className = "personal-holiday-confetti";
        for (let i = 0; i < 22; i++) confetti.appendChild(createConfettiPiece(i));
        hero.appendChild(confetti);
    } else if (status.next) {
        const days = daysUntilHoliday(status.next.startDate);
        title.textContent = days === 0 ? "Le ferie iniziano oggi" : ("Mancano " + days + " giorni");
        subtitle.textContent = "Prossime ferie: settimana " + status.next.isoWeek + " \u00B7 " + status.next.rangeLabel;
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

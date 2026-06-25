const DEPARTMENT_OVERVIEW_ENDPOINT = "connection_files/department_schedule.php";
const OVERVIEW_START_MINUTES = 0;
const OVERVIEW_END_MINUTES = 24 * 60;

function canViewDepartmentOverview() {
    return ["1", "3"].includes(String(window.userSession?.capo ?? "0"));
}

function overviewStateLabel(state) {
    return {
        registered: "Associato",
        inactive: "Account disattivato",
        unregistered: "Non registrato",
        unverified: "Da verificare",
        no_schedule: "Nessun turno"
    }[state] || "Da verificare";
}

function overviewMondayDate(year, week) {
    const fourthJanuary = new Date(Date.UTC(year, 0, 4));
    const weekday = fourthJanuary.getUTCDay() || 7;
    fourthJanuary.setUTCDate(fourthJanuary.getUTCDate() - weekday + 1 + ((week - 1) * 7));
    return fourthJanuary;
}

function overviewWeekInfoFromDate(date) {
    return getIsoWeekInfo(new Date(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate()));
}

function overviewWeekDays(year, week) {
    const monday = overviewMondayDate(year, week);
    return SCHEDULE_DAY_KEYS.map((name, index) => {
        const date = new Date(monday);
        date.setUTCDate(monday.getUTCDate() + index);
        return {
            name,
            short: name.slice(0, 3),
            date: date.toLocaleDateString("it-IT", { timeZone: "UTC", day: "2-digit", month: "2-digit" })
        };
    });
}

function overviewShiftIntervals(shift) {
    const values = String(shift || "").match(/\d{1,2}\s*[:.]\s*\d{2}/g) || [];
    const times = values.map((value) => {
        const [hours, minutes] = value.replace(".", ":").split(":");
        return Number(hours) * 60 + Number(minutes);
    });
    const intervals = [];
    for (let index = 0; index + 1 < times.length; index += 2) {
        let end = times[index + 1];
        if (end < times[index]) end += 24 * 60;
        if (end > times[index]) intervals.push({ start: times[index], end });
    }
    return intervals;
}

function overviewShiftMinutes(shift) {
    return overviewShiftIntervals(shift).reduce((total, interval) => total + interval.end - interval.start, 0);
}

function overviewTotalMinutes(person) {
    return SCHEDULE_DAY_KEYS.reduce((total, day) => total + overviewShiftMinutes(person.days?.[day]?.shift), 0);
}

function overviewFormatTime(minutes) {
    const normalized = ((minutes % (24 * 60)) + (24 * 60)) % (24 * 60);
    return String(Math.floor(normalized / 60)).padStart(2, "0") + ":" + String(normalized % 60).padStart(2, "0");
}

function overviewCarryFromPreviousDay(person, days, dayIndex) {
    if (dayIndex === 0) return [];
    const previousShift = person.days?.[days[dayIndex - 1].name]?.shift;
    return overviewShiftIntervals(previousShift)
        .filter((interval) => interval.end > 24 * 60)
        .map((interval) => ({ start: 0, end: interval.end - (24 * 60) }));
}

function overviewDepartmentOptions(selectedDepartment) {
    const select = document.createElement("select");
    select.className = "form-select form-select-sm overview-controls__department";
    select.setAttribute("aria-label", "Reparto");
    Object.entries(window.appBootstrap?.departments || {}).forEach(([code, label]) => {
        const option = document.createElement("option");
        option.value = code;
        option.textContent = label;
        option.selected = code === selectedDepartment;
        select.appendChild(option);
    });
    return select;
}

function overviewCreateTimeline(day, carryIntervals = []) {
    const shift = String(day?.shift || "").trim();
    const variation = String(day?.variation || "");
    const ownIntervals = overviewShiftIntervals(shift);
    const continuesNextDay = ownIntervals.some((interval) => interval.end > 24 * 60);
    const cell = document.createElement("div");
    cell.className = "overview-shift";
    if (variation) cell.classList.add("overview-shift--" + variation);

    if (!shift && carryIntervals.length === 0) {
        cell.classList.add("overview-shift--missing");
        cell.textContent = "-";
        return cell;
    }
    if (shift.toUpperCase() === "RIPOSO" && carryIntervals.length === 0) {
        cell.classList.add("overview-shift--rest");
        cell.textContent = "Riposo";
        return cell;
    }

    const track = document.createElement("div");
    track.className = "overview-shift__track";
    const totalRange = OVERVIEW_END_MINUTES - OVERVIEW_START_MINUTES;
    const appendBar = (interval, continuation = false) => {
        const start = Math.max(OVERVIEW_START_MINUTES, interval.start);
        const end = Math.min(OVERVIEW_END_MINUTES, interval.end);
        if (end <= start) return;
        const bar = document.createElement("span");
        bar.className = "overview-shift__bar";
        if (continuation) bar.classList.add("overview-shift__bar--continuation");
        bar.style.setProperty("--start", ((start - OVERVIEW_START_MINUTES) / totalRange * 100).toFixed(2) + "%");
        bar.style.setProperty("--width", ((end - start) / totalRange * 100).toFixed(2) + "%");
        track.appendChild(bar);
    };
    carryIntervals.forEach((interval) => appendBar(interval, true));
    ownIntervals.forEach((interval) => appendBar(interval));

    const text = document.createElement("span");
    text.className = "overview-shift__text";
    const carryLabel = carryIntervals.map((interval) => "continua " + overviewFormatTime(interval.start) + "-" + overviewFormatTime(interval.end)).join(" / ");
    const nextDayLabel = continuesNextDay
        ? "continua " + ownIntervals.filter((interval) => interval.end > 24 * 60).map((interval) => "00:00-" + overviewFormatTime(interval.end)).join(" / ")
        : "";
    text.textContent = [shift.toUpperCase() === "RIPOSO" ? "" : shift, carryLabel, nextDayLabel].filter(Boolean).join(" · ");
    cell.title = variation === "approved" && day.original_shift !== shift
        ? "Previsto: " + day.original_shift + " | Effettivo approvato: " + shift
        : shift;
    if (carryLabel) cell.title += " | " + carryLabel;
    if (nextDayLabel) cell.title += " | " + nextDayLabel;
    cell.setAttribute("aria-label", cell.title);
    cell.append(track, text);

    if (variation === "approved" || variation === "pending" || variation === "review") {
        const marker = document.createElement("span");
        marker.className = "overview-shift__marker";
        marker.textContent = variation === "approved" ? "Approvato" : variation === "review" ? "Riesamina" : "In attesa";
        cell.appendChild(marker);
    }
    return cell;
}

function overviewCreatePersonIdentity(person) {
    const identity = document.createElement("div");
    identity.className = "overview-person";
    const name = document.createElement("strong");
    name.textContent = person.name || person.source_name || "Nominativo senza nome";
    const state = document.createElement("span");
    state.className = "overview-person__state overview-person__state--" + person.state;
    state.textContent = overviewStateLabel(person.state);
    identity.append(name, state);

    if (person.source_name && person.source_name !== person.name) {
        const source = document.createElement("span");
        source.className = "overview-person__source";
        source.textContent = "Excel: " + person.source_name;
        identity.appendChild(source);
    }
    return identity;
}

function overviewCreateTable(people, days) {
    const wrap = document.createElement("div");
    wrap.className = "overview-table-wrap";
    const table = document.createElement("table");
    table.className = "overview-table";
    const head = document.createElement("thead");
    const headRow = document.createElement("tr");
    const personHead = document.createElement("th");
    personHead.scope = "col";
    personHead.textContent = "Addetto";
    headRow.appendChild(personHead);
    days.forEach((day) => {
        const header = document.createElement("th");
        header.scope = "col";
        const label = document.createElement("strong");
        label.textContent = day.short;
        const date = document.createElement("span");
        date.textContent = day.date;
        const scale = document.createElement("span");
        scale.className = "overview-day-scale";
        ["00", "12", "24"].forEach((hour) => {
            const tick = document.createElement("span");
            tick.textContent = hour;
            scale.appendChild(tick);
        });
        header.append(label, date, scale);
        headRow.appendChild(header);
    });
    const totalHead = document.createElement("th");
    totalHead.scope = "col";
    totalHead.textContent = "Totale";
    headRow.appendChild(totalHead);
    head.appendChild(headRow);

    const body = document.createElement("tbody");
    people.forEach((person) => {
        const row = document.createElement("tr");
        row.dataset.overviewPerson = "";
        row.dataset.name = (person.name + " " + person.source_name).toLocaleLowerCase("it-IT");
        row.dataset.state = person.state;
        if (person.state !== "registered") row.classList.add("overview-row--" + person.state);

        const identity = document.createElement("th");
        identity.scope = "row";
        identity.appendChild(overviewCreatePersonIdentity(person));
        row.appendChild(identity);
        days.forEach((day, dayIndex) => {
            const cell = document.createElement("td");
            cell.appendChild(overviewCreateTimeline(person.days?.[day.name], overviewCarryFromPreviousDay(person, days, dayIndex)));
            row.appendChild(cell);
        });
        const total = document.createElement("td");
        total.className = "overview-total";
        total.textContent = formatTotaleOreSettimanali(overviewTotalMinutes(person));
        row.appendChild(total);
        body.appendChild(row);
    });

    table.append(head, body);
    wrap.appendChild(table);
    return wrap;
}

function overviewCreateCards(people, days) {
    const cards = document.createElement("div");
    cards.className = "overview-cards";
    people.forEach((person) => {
        const card = document.createElement("article");
        card.className = "overview-card";
        card.dataset.overviewPerson = "";
        card.dataset.name = (person.name + " " + person.source_name).toLocaleLowerCase("it-IT");
        card.dataset.state = person.state;
        if (person.state !== "registered") card.classList.add("overview-row--" + person.state);

        const header = document.createElement("header");
        header.className = "overview-card__header";
        header.appendChild(overviewCreatePersonIdentity(person));
        const total = document.createElement("strong");
        total.className = "overview-total";
        total.textContent = formatTotaleOreSettimanali(overviewTotalMinutes(person));
        header.appendChild(total);
        card.appendChild(header);

        const dayList = document.createElement("div");
        dayList.className = "overview-card__days";
        days.forEach((day, dayIndex) => {
            const line = document.createElement("div");
            line.className = "overview-card__day";
            const label = document.createElement("span");
            label.textContent = day.name + " " + day.date;
            line.append(label, overviewCreateTimeline(person.days?.[day.name], overviewCarryFromPreviousDay(person, days, dayIndex)));
            dayList.appendChild(line);
        });
        card.appendChild(dayList);
        cards.appendChild(card);
    });
    return cards;
}

function overviewApplyFilters(root, query, state) {
    const normalizedQuery = String(query || "").trim().toLocaleLowerCase("it-IT");
    root.querySelectorAll("[data-overview-person]").forEach((element) => {
        const matchesName = !normalizedQuery || String(element.dataset.name || "").includes(normalizedQuery);
        const matchesState = state === "all"
            || (state === "registered" && element.dataset.state === "registered")
            || (state === "needs_attention" && ["unregistered", "unverified", "inactive", "no_schedule"].includes(element.dataset.state));
        element.hidden = !matchesName || !matchesState;
    });
}

function overviewCreateFilters(root) {
    const filters = document.createElement("div");
    filters.className = "overview-filters";
    const search = document.createElement("input");
    search.type = "search";
    search.className = "form-control";
    search.placeholder = "Cerca addetto";
    search.setAttribute("aria-label", "Cerca addetto");
    const state = document.createElement("select");
    state.className = "form-select";
    [
        ["all", "Tutti"],
        ["registered", "Associati"],
        ["needs_attention", "Da verificare"]
    ].forEach(([value, label]) => {
        const option = document.createElement("option");
        option.value = value;
        option.textContent = label;
        state.appendChild(option);
    });
    const refresh = () => overviewApplyFilters(root, search.value, state.value);
    search.addEventListener("input", refresh);
    state.addEventListener("change", refresh);
    filters.append(search, state);
    return filters;
}

async function departmentOverviewFetch(year, week, department) {
    const query = new URLSearchParams({ year: String(year), week: String(week) });
    if (department) query.set("reparto", department);
    const cacheKey = "departmentOverview:" + String(year) + ":" + String(week) + ":" + String(department || "");
    const cached = appCacheGet(cacheKey, 60 * 1000);
    if (cached) {
        return cached;
    }

    const response = await fetch(DEPARTMENT_OVERVIEW_ENDPOINT + "?" + query.toString(), { cache: "no-store" });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.ok) throw new Error(data.error || "Panoramica reparto non disponibile");
    return appCacheSet(cacheKey, data);
}

async function mostraPanoramicaReparto(year, week, department, options = {}) {
    if (!canViewDepartmentOverview()) {
        showAppToast("Accesso riservato ai responsabili");
        return;
    }
    const currentWeek = getIsoWeekInfo(today);
    year = Number(year || currentWeek.year);
    week = Number(week || currentWeek.week);
    department = department || String(window.userSession?.reparto || "");
    showCalendarShell();
    appState.view = "departmentOverview";
    appState.currentYear = year;
    appState.currentWeek = week;
    appState.departmentOverviewDepartment = department;
    setVista("calendario vista-department-overview mt-4", "Panoramica reparto", { record: !options.replaceHistory });
    if (options.replaceHistory) appNavigationReplaceCurrentView();
    const viewToken = appState.calendarViewToken;

    const loading = document.createElement("p");
    loading.className = "changes-empty";
    loading.textContent = "Caricamento panoramica reparto...";
    container.appendChild(loading);

    try {
        const data = await departmentOverviewFetch(year, week, department);
        if (viewToken !== appState.calendarViewToken || appState.view !== "departmentOverview") return;
        container.innerHTML = "";
        appState.departmentOverviewDepartment = data.department;
        const days = overviewWeekDays(data.year, data.week);
        const monday = overviewMondayDate(data.year, data.week);
        const previousMonday = new Date(monday);
        previousMonday.setUTCDate(monday.getUTCDate() - 7);
        const nextMonday = new Date(monday);
        nextMonday.setUTCDate(monday.getUTCDate() + 7);

        const controls = document.createElement("section");
        controls.className = "overview-controls";
        const previous = document.createElement("button");
        previous.type = "button";
        previous.className = "btn btn-outline-dark";
        previous.textContent = "Settimana precedente";
        previous.addEventListener("click", () => {
            const target = overviewWeekInfoFromDate(previousMonday);
            mostraPanoramicaReparto(target.year, target.week, data.department, { replaceHistory: true });
        });
        const heading = document.createElement("div");
        heading.className = "overview-controls__heading";
        const title = document.createElement("strong");
        title.textContent = data.department_label + " - settimana " + data.week;
        const range = document.createElement("span");
        range.textContent = days[0].date + " - " + days[6].date + " " + data.year;
        heading.append(title, range);
        const next = document.createElement("button");
        next.type = "button";
        next.className = "btn btn-outline-dark";
        next.textContent = "Settimana successiva";
        next.addEventListener("click", () => {
            const target = overviewWeekInfoFromDate(nextMonday);
            mostraPanoramicaReparto(target.year, target.week, data.department, { replaceHistory: true });
        });
        controls.append(previous, heading, next);
        if (String(window.userSession?.capo ?? "") === "3") {
            const departmentSelect = overviewDepartmentOptions(data.department);
            departmentSelect.addEventListener("change", () => mostraPanoramicaReparto(data.year, data.week, departmentSelect.value, { replaceHistory: true }));
            controls.appendChild(departmentSelect);
        }

        const intro = document.createElement("p");
        intro.className = "overview-intro";
        intro.textContent = "Le fasce mostrano l'orario sulla stessa scala dalle 00:00 alle 24:00. Una fascia tratteggiata indica la continuazione di un turno iniziato il giorno precedente. Verde: variazione approvata. Ambra: nominativo o situazione da verificare.";
        const people = Array.isArray(data.people) ? data.people : [];
        const content = document.createElement("div");
        content.className = "overview-content";
        content.append(overviewCreateTable(people, days), overviewCreateCards(people, days));
        container.append(controls, intro, overviewCreateFilters(content), content);
    } catch (error) {
        if (viewToken !== appState.calendarViewToken || appState.view !== "departmentOverview") return;
        loading.textContent = error.message || "Panoramica reparto non disponibile";
    }
}

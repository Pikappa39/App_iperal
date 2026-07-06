// Elenco ferie reparto.
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

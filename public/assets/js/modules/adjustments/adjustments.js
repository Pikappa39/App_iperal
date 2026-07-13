const SCHEDULE_ADJUSTMENTS_ENDPOINT = "connection_files/schedule_adjustments.php";

function canApproveScheduleAdjustments() {
    const role = String(window.userSession?.capo ?? "0");
    return role === "1" || role === "3";
}

function adjustmentStatusLabel(status) {
    return {
        pending: "In attesa",
        review: "Da riesaminare",
        approved: "Approvata",
        rejected: "Rifiutata",
        recorded: "Registrata"
    }[status] || status;
}

function adjustmentStatusClass(status) {
    return {
        pending: "text-bg-warning",
        review: "text-bg-info",
        approved: "text-bg-success",
        rejected: "text-bg-secondary",
        recorded: "text-bg-primary"
    }[status] || "text-bg-secondary";
}

const adjustmentDayDataPromises = Object.create(null);

function clearAdjustmentDayData() {
    Object.keys(adjustmentDayDataPromises).forEach((key) => {
        delete adjustmentDayDataPromises[key];
    });
}

function adjustmentShiftForInput(shift) {
    const times = String(shift || "").match(/\d{1,2}\s*[:.]\s*\d{2}/g) || [];
    if (times.length < 2 || times.length % 2 !== 0) {
        return "";
    }

    const format = (value) => {
        const [hours, minutes] = value.replace(".", ":").split(":");
        return String(Number(hours)).padStart(2, "0") + ":" + minutes;
    };
    const intervals = [];
    for (let index = 0; index < times.length; index += 2) {
        intervals.push(format(times[index]) + "-" + format(times[index + 1]));
    }
    return intervals.join(" / ");
}

async function adjustmentFetch(query = "", options = {}) {
    const response = await fetch(SCHEDULE_ADJUSTMENTS_ENDPOINT + (query ? "?" + query : ""), {
        cache: "no-store",
        ...options
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.ok) {
        throw new Error(data.error || "Operazione non riuscita");
    }
    return data;
}

async function adjustmentPost(values) {
    values.append("csrf_token", window.appCsrfToken || "");
    const result = await adjustmentFetch("", { method: "POST", body: values });
    appCacheForget("scheduleAdjustments:");
    clearAdjustmentDayData();
    return result;
}

async function getDayAdjustmentData(dateKey, options = {}) {
    if (!adjustmentDayDataPromises[dateKey] || options.force) {
        adjustmentDayDataPromises[dateKey] = adjustmentFetch("view=day&date=" + encodeURIComponent(dateKey));
    }

    return adjustmentDayDataPromises[dateKey];
}

function adjustmentTypeLabel(kind) {
    return {
        schedule_adjustment: "Variazione orario",
        extra_department: "Altro reparto",
        extra_store: "Altro negozio"
    }[kind] || "Richiesta ore";
}

function createDurationSelect(name = "minutes") {
    const select = document.createElement("select");
    select.className = "form-select";
    select.name = name;
    select.required = true;

    for (let minutes = 15; minutes <= 16 * 60; minutes += 15) {
        const option = document.createElement("option");
        option.value = String(minutes);
        option.textContent = formatTotaleOreSettimanali(minutes);
        if (minutes === 60) {
            option.selected = true;
        }
        select.appendChild(option);
    }

    return select;
}

function createDepartmentSelect() {
    const select = document.createElement("select");
    select.className = "form-select";
    select.name = "target_reparto";
    select.required = true;

    const placeholder = document.createElement("option");
    placeholder.value = "";
    placeholder.textContent = "Scegli reparto";
    select.appendChild(placeholder);

    const currentDepartment = String(window.reparto || "");
    Object.entries(window.appBootstrap?.departments || {}).forEach(([key, label]) => {
        if (key === currentDepartment) {
            return;
        }
        const option = document.createElement("option");
        option.value = key;
        option.textContent = label;
        select.appendChild(option);
    });

    return select;
}

function adjustmentWeekValue(dateString) {
    const date = new Date(String(dateString || "") + "T12:00:00");
    if (Number.isNaN(date.getTime()) || typeof getIsoWeekInfo !== "function") {
        return "";
    }
    const info = getIsoWeekInfo(date);
    return String(info.year) + "-W" + String(info.week).padStart(2, "0");
}

function adjustmentRequestUserKey(request) {
    return String(request.user_cf || request.user_name || "").trim();
}

function adjustmentRequestUserLabel(request) {
    return String(request.user_name || request.user_cf || "Addetto").trim();
}

function createAdjustmentMeta(request, includeUser = false) {
    const meta = document.createElement("div");
    meta.className = "adjustment-request__meta";
    const status = document.createElement("span");
    status.className = "badge " + adjustmentStatusClass(request.status);
    status.textContent = adjustmentStatusLabel(request.status);
    meta.appendChild(status);
    if (includeUser && request.user_name) {
        const user = document.createElement("strong");
        user.textContent = request.user_name;
        meta.appendChild(user);
    }
    const date = document.createElement("span");
    date.textContent = formatDateLabel(request.schedule_date);
    meta.appendChild(date);
    return meta;
}

function createExtraHourApprovalLine(label, status, decidedByName) {
    const line = document.createElement("div");
    line.className = "extra-hour-request__approval";
    const badge = document.createElement("span");
    badge.className = "badge " + adjustmentStatusClass(status || "pending");
    badge.textContent = adjustmentStatusLabel(status || "pending");
    line.append(label + ": ", badge);
    if (decidedByName) {
        const name = document.createElement("small");
        name.textContent = " da " + decidedByName;
        line.appendChild(name);
    }
    return line;
}

function createExtraHourDecisionActions(request, side, label) {
    const decision = document.createElement("div");
    decision.className = "adjustment-request__actions";
    const note = document.createElement("input");
    note.className = "form-control form-control-sm";
    note.maxLength = 1000;
    note.placeholder = "Nota decisione (facoltativa)";

    const approve = document.createElement("button");
    approve.type = "button";
    approve.className = "btn btn-success btn-sm";
    approve.textContent = "Approva " + label;
    const reject = document.createElement("button");
    reject.type = "button";
    reject.className = "btn btn-outline-danger btn-sm";
    reject.textContent = "Rifiuta";
    const status = document.createElement("span");
    status.className = "small";

    const decide = async (action) => {
        approve.disabled = true;
        reject.disabled = true;
        status.textContent = "Salvataggio...";
        const form = new FormData();
        form.append("action", action);
        form.append("request_id", request.id);
        form.append("decision_side", side);
        form.append("decision_note", note.value);
        try {
            await adjustmentPost(form);
            showAppToast(action === "approve_extra" ? "Ore approvate" : "Ore rifiutate");
            mostraRichiesteOre();
        } catch (error) {
            status.textContent = error.message;
            approve.disabled = false;
            reject.disabled = false;
        }
    };
    approve.addEventListener("click", () => decide("approve_extra"));
    reject.addEventListener("click", () => decide("reject_extra"));
    decision.append(note, approve, reject, status);
    return decision;
}

function createExtraHourRequestCard(request, options = {}) {
    const card = document.createElement("article");
    card.className = "adjustment-request adjustment-request--extra";
    card.appendChild(createAdjustmentMeta(request, Boolean(options.includeUser)));

    const type = document.createElement("strong");
    type.className = "adjustment-request__type";
    type.textContent = adjustmentTypeLabel(request.kind);
    card.appendChild(type);

    const details = document.createElement("div");
    details.className = "adjustment-request__shifts";
    const duration = document.createElement("strong");
    duration.textContent = "Durata: " + (request.duration_label || formatTotaleOreSettimanali(Number(request.minutes || 0)));
    details.appendChild(duration);

    if (request.kind === "extra_department") {
        const route = document.createElement("div");
        route.textContent = "Da " + (request.origin_reparto_label || request.origin_reparto || "-")
            + " a " + (request.target_reparto_label || request.target_reparto || "-");
        details.appendChild(route);
        details.appendChild(createExtraHourApprovalLine("Capo reparto origine", request.origin_status, request.origin_decided_by_name));
        details.appendChild(createExtraHourApprovalLine("Capo reparto destinazione", request.target_status, request.target_decided_by_name));
    } else {
        const store = document.createElement("div");
        store.textContent = "Negozio/Ipermercato: " + (request.store_name || "-");
        details.appendChild(store);
    }
    card.appendChild(details);

    if (request.request_note) {
        const note = document.createElement("p");
        note.className = "adjustment-request__note";
        note.textContent = "Nota: " + request.request_note;
        card.appendChild(note);
    }
    if (request.origin_decision_note) {
        const note = document.createElement("p");
        note.className = "adjustment-request__note";
        note.textContent = "Nota capo origine: " + request.origin_decision_note;
        card.appendChild(note);
    }
    if (request.target_decision_note) {
        const note = document.createElement("p");
        note.className = "adjustment-request__note";
        note.textContent = "Nota capo destinazione: " + request.target_decision_note;
        card.appendChild(note);
    }

    if (options.canDecide && request.can_decide_origin) {
        card.appendChild(createExtraHourDecisionActions(request, "origin", "origine"));
    }
    if (options.canDecide && request.can_decide_target) {
        card.appendChild(createExtraHourDecisionActions(request, "target", "destinazione"));
    }

    return card;
}

function createAdjustmentRequestCard(request, options = {}) {
    if (request.kind && request.kind !== "schedule_adjustment") {
        return createExtraHourRequestCard(request, options);
    }

    const card = document.createElement("article");
    card.className = "adjustment-request";
    card.appendChild(createAdjustmentMeta(request, Boolean(options.includeUser)));

    const shifts = document.createElement("div");
    shifts.className = "adjustment-request__shifts";
    const original = document.createElement("div");
    original.textContent = "Previsto al momento della segnalazione: " + (request.original_shift || "-");
    shifts.appendChild(original);
    if (request.current_original_shift !== request.original_shift) {
        const current = document.createElement("div");
        current.textContent = "Nuovo previsto: " + (request.current_original_shift || "Orario rimosso");
        shifts.appendChild(current);
    }
    const requested = document.createElement("strong");
    requested.textContent = "Effettivo segnalato: " + request.requested_shift;
    shifts.appendChild(requested);
    card.appendChild(shifts);

    if (request.request_note) {
        const note = document.createElement("p");
        note.className = "adjustment-request__note";
        note.textContent = "Nota addetto: " + request.request_note;
        card.appendChild(note);
    }
    if (request.review_reason) {
        const review = document.createElement("p");
        review.className = "adjustment-request__review";
        review.textContent = request.review_reason;
        card.appendChild(review);
    }
    if (request.decision_note) {
        const decision = document.createElement("p");
        decision.className = "adjustment-request__note";
        decision.textContent = "Nota del capo: " + request.decision_note;
        card.appendChild(decision);
    }
    if (request.decided_by_name) {
        const decisionMeta = document.createElement("div");
        decisionMeta.className = "adjustment-request__decision";
        decisionMeta.textContent = request.status === "review"
            ? "Ultima decisione di " + request.decided_by_name
            : "Gestita da " + request.decided_by_name;
        card.appendChild(decisionMeta);
    }

    if (options.canDecide && ["pending", "review"].includes(request.status)) {
        const decision = document.createElement("div");
        decision.className = "adjustment-request__actions";
        const note = document.createElement("input");
        note.className = "form-control form-control-sm";
        note.maxLength = 1000;
        note.placeholder = "Nota per l'addetto (facoltativa)";

        const approve = document.createElement("button");
        approve.type = "button";
        approve.className = "btn btn-success btn-sm";
        approve.textContent = "Approva";
        const reject = document.createElement("button");
        reject.type = "button";
        reject.className = "btn btn-outline-danger btn-sm";
        reject.textContent = "Rifiuta";
        const status = document.createElement("span");
        status.className = "small";

        const decide = async (action) => {
            approve.disabled = true;
            reject.disabled = true;
            status.textContent = "Salvataggio...";
            const form = new FormData();
            form.append("action", action);
            form.append("request_id", request.id);
            form.append("decision_note", note.value);
            form.append("expected_current_original_shift", request.current_original_shift || "");
            try {
                await adjustmentPost(form);
                showAppToast(action === "approve" ? "Variazione approvata" : "Variazione rifiutata");
                mostraRichiesteOre();
            } catch (error) {
                status.textContent = error.message;
                approve.disabled = false;
                reject.disabled = false;
            }
        };
        approve.addEventListener("click", () => decide("approve"));
        reject.addEventListener("click", () => decide("reject"));
        decision.append(note, approve, reject, status);
        card.appendChild(decision);
    }

    return card;
}

function createDayAdjustmentPanel(giornoInfo) {
    const panel = document.createElement("section");
    panel.className = "adjustment-panel";
    panel.textContent = "Caricamento segnalazioni ore...";
    loadDayAdjustmentPanel(panel, giornoInfo);
    return panel;
}

async function loadDayAdjustmentPanel(panel, giornoInfo) {
    try {
        const data = await getDayAdjustmentData(giornoInfo.dataKey);
        const scheduleRequests = (data.requests || []).filter((request) => request.kind === "schedule_adjustment");
        panel.innerHTML = "";

        const title = document.createElement("h4");
        title.className = "adjustment-panel__title";
        title.textContent = "Variazione ore";
        const intro = document.createElement("p");
        intro.className = "adjustment-panel__intro";
        intro.textContent = "Segnala l'orario effettivo: il turno Excel resta sempre nello storico e la modifica diventa valida solo dopo l'approvazione del capo.";
        panel.append(title, intro);

        scheduleRequests.forEach((request) => panel.appendChild(createAdjustmentRequestCard(request)));
        const hasOpen = scheduleRequests.some((request) => ["pending", "review", "approved"].includes(request.status));
        if (!data.can_create || hasOpen) {
            return;
        }

        const form = document.createElement("form");
        form.className = "adjustment-form";
        const label = document.createElement("label");
        label.textContent = "Orario effettivo";
        const shift = document.createElement("input");
        shift.className = "form-control";
        shift.name = "requested_shift";
        shift.required = true;
        shift.maxLength = 255;
        shift.placeholder = "05:00-10:00 / 10:30-12:00";
        shift.value = adjustmentShiftForInput(giornoInfo.orario);
        const note = document.createElement("textarea");
        note.className = "form-control";
        note.name = "request_note";
        note.maxLength = 1000;
        note.rows = 2;
        note.placeholder = "Motivo della variazione (facoltativo)";
        const submit = document.createElement("button");
        submit.type = "submit";
        submit.className = "btn btn-primary";
        submit.textContent = "Invia al capo per approvazione";
        const status = document.createElement("div");
        status.className = "adjustment-panel__status";
        form.append(label, shift, note, submit, status);
        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            submit.disabled = true;
            status.textContent = "Invio in corso...";
            const values = new FormData(form);
            values.append("action", "create");
            values.append("date", giornoInfo.dataKey);
            try {
                await adjustmentPost(values);
                showAppToast("Segnalazione inviata al capo");
                loadDayAdjustmentPanel(panel, giornoInfo);
            } catch (error) {
                status.textContent = error.message;
                submit.disabled = false;
            }
        });
        panel.appendChild(form);
    } catch (error) {
        panel.textContent = error.message;
    }
}

function createExternalDepartmentHoursPanel(giornoInfo) {
    const panel = document.createElement("section");
    panel.className = "adjustment-panel";
    panel.textContent = "Caricamento ore in altro reparto...";
    loadExternalDepartmentHoursPanel(panel, giornoInfo);
    return panel;
}

async function loadExternalDepartmentHoursPanel(panel, giornoInfo) {
    try {
        const data = await getDayAdjustmentData(giornoInfo.dataKey);
        const requests = (data.requests || []).filter((request) => request.kind === "extra_department");
        panel.innerHTML = "";

        const title = document.createElement("h4");
        title.className = "adjustment-panel__title";
        title.textContent = "Ore in altro reparto";
        const intro = document.createElement("p");
        intro.className = "adjustment-panel__intro";
        intro.textContent = "Segnala ore svolte in un reparto diverso: dovranno confermare sia il tuo capo reparto sia il capo del reparto indicato.";
        panel.append(title, intro);

        requests.forEach((request) => panel.appendChild(createExtraHourRequestCard(request)));

        const form = document.createElement("form");
        form.className = "adjustment-form";
        const departmentLabel = document.createElement("label");
        departmentLabel.textContent = "Reparto in cui hai lavorato";
        const department = createDepartmentSelect();
        const durationLabel = document.createElement("label");
        durationLabel.textContent = "Durata";
        const duration = createDurationSelect();
        const note = document.createElement("textarea");
        note.className = "form-control";
        note.name = "request_note";
        note.maxLength = 1000;
        note.rows = 2;
        note.placeholder = "Nota facoltativa";
        const submit = document.createElement("button");
        submit.type = "submit";
        submit.className = "btn btn-primary";
        submit.textContent = "Invia per doppia approvazione";
        const status = document.createElement("div");
        status.className = "adjustment-panel__status";
        form.append(departmentLabel, department, durationLabel, duration, note, submit, status);
        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            submit.disabled = true;
            status.textContent = "Invio in corso...";
            const values = new FormData(form);
            values.append("action", "create_extra_department");
            values.append("date", giornoInfo.dataKey);
            try {
                await adjustmentPost(values);
                showAppToast("Richiesta inviata ai capi reparto");
                loadExternalDepartmentHoursPanel(panel, giornoInfo);
            } catch (error) {
                status.textContent = error.message;
                submit.disabled = false;
            }
        });
        panel.appendChild(form);
    } catch (error) {
        panel.textContent = error.message;
    }
}

function createExternalStoreHoursPanel(giornoInfo) {
    const panel = document.createElement("section");
    panel.className = "adjustment-panel";
    panel.textContent = "Caricamento ore in altro negozio...";
    loadExternalStoreHoursPanel(panel, giornoInfo);
    return panel;
}

async function loadExternalStoreHoursPanel(panel, giornoInfo) {
    try {
        const data = await getDayAdjustmentData(giornoInfo.dataKey);
        const requests = (data.requests || []).filter((request) => request.kind === "extra_store");
        panel.innerHTML = "";

        const title = document.createElement("h4");
        title.className = "adjustment-panel__title";
        title.textContent = "Ore in altro negozio";
        const intro = document.createElement("p");
        intro.className = "adjustment-panel__intro";
        intro.textContent = "Registra ore svolte in un altro ipermercato o negozio per la contabilità.";
        panel.append(title, intro);

        requests.forEach((request) => panel.appendChild(createExtraHourRequestCard(request)));

        const form = document.createElement("form");
        form.className = "adjustment-form";
        const storeLabel = document.createElement("label");
        storeLabel.textContent = "Negozio o ipermercato";
        const store = document.createElement("input");
        store.className = "form-control";
        store.name = "store_name";
        store.maxLength = 120;
        store.required = true;
        store.placeholder = "Es. Iperal Verdello";
        const durationLabel = document.createElement("label");
        durationLabel.textContent = "Durata";
        const duration = createDurationSelect();
        const note = document.createElement("textarea");
        note.className = "form-control";
        note.name = "request_note";
        note.maxLength = 1000;
        note.rows = 2;
        note.placeholder = "Nota facoltativa";
        const submit = document.createElement("button");
        submit.type = "submit";
        submit.className = "btn btn-primary";
        submit.textContent = "Registra ore";
        const status = document.createElement("div");
        status.className = "adjustment-panel__status";
        form.append(storeLabel, store, durationLabel, duration, note, submit, status);
        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            submit.disabled = true;
            status.textContent = "Salvataggio...";
            const values = new FormData(form);
            values.append("action", "create_extra_store");
            values.append("date", giornoInfo.dataKey);
            try {
                await adjustmentPost(values);
                showAppToast("Ore in altro negozio registrate");
                loadExternalStoreHoursPanel(panel, giornoInfo);
            } catch (error) {
                status.textContent = error.message;
                submit.disabled = false;
            }
        });
        panel.appendChild(form);
    } catch (error) {
        panel.textContent = error.message;
    }
}

function createManagerAdjustmentFilters(requests, onChange) {
    const filters = document.createElement("section");
    filters.className = "adjustments-filters";

    const userField = document.createElement("label");
    userField.className = "adjustments-filters__field";
    userField.textContent = "Addetto";
    const userSelect = document.createElement("select");
    userSelect.className = "form-select";
    const allUsers = document.createElement("option");
    allUsers.value = "";
    allUsers.textContent = "Tutti gli addetti";
    userSelect.appendChild(allUsers);

    const users = Array.from(new Map(
        requests
            .filter((request) => adjustmentRequestUserKey(request) !== "")
            .map((request) => [adjustmentRequestUserKey(request), adjustmentRequestUserLabel(request)])
    ).entries()).sort((left, right) => left[1].localeCompare(right[1], "it", { sensitivity: "base" }));
    users.forEach(([value, label]) => {
        const option = document.createElement("option");
        option.value = value;
        option.textContent = label;
        userSelect.appendChild(option);
    });
    userField.appendChild(userSelect);

    const weekField = document.createElement("label");
    weekField.className = "adjustments-filters__field";
    weekField.textContent = "Settimana";
    const weekInput = document.createElement("input");
    weekInput.type = "week";
    weekInput.className = "form-control";
    weekField.appendChild(weekInput);

    const statusField = document.createElement("label");
    statusField.className = "adjustments-filters__field";
    statusField.textContent = "Stato";
    const statusSelect = document.createElement("select");
    statusSelect.className = "form-select";
    [
        ["", "Tutti gli stati"],
        ["pending", adjustmentStatusLabel("pending")],
        ["review", adjustmentStatusLabel("review")],
        ["approved", adjustmentStatusLabel("approved")],
        ["rejected", adjustmentStatusLabel("rejected")]
    ].forEach(([value, label]) => {
        const option = document.createElement("option");
        option.value = value;
        option.textContent = label;
        statusSelect.appendChild(option);
    });
    statusField.appendChild(statusSelect);

    const typeField = document.createElement("label");
    typeField.className = "adjustments-filters__field";
    typeField.textContent = "Tipo";
    const typeSelect = document.createElement("select");
    typeSelect.className = "form-select";
    [
        ["", "Tutti i tipi"],
        ["schedule_adjustment", adjustmentTypeLabel("schedule_adjustment")],
        ["extra_department", adjustmentTypeLabel("extra_department")],
        ["extra_store", adjustmentTypeLabel("extra_store")]
    ].forEach(([value, label]) => {
        const option = document.createElement("option");
        option.value = value;
        option.textContent = label;
        typeSelect.appendChild(option);
    });
    typeField.appendChild(typeSelect);

    const reset = document.createElement("button");
    reset.type = "button";
    reset.className = "btn btn-outline-secondary adjustments-filters__reset";
    reset.textContent = "Azzera filtri";

    const emitChange = () => onChange({
        userKey: userSelect.value,
        week: weekInput.value,
        status: statusSelect.value,
        type: typeSelect.value
    });
    userSelect.addEventListener("change", emitChange);
    weekInput.addEventListener("change", emitChange);
    statusSelect.addEventListener("change", emitChange);
    typeSelect.addEventListener("change", emitChange);
    reset.addEventListener("click", () => {
        userSelect.value = "";
        weekInput.value = "";
        statusSelect.value = "";
        typeSelect.value = "";
        emitChange();
    });

    filters.append(userField, weekField, statusField, typeField, reset);
    return filters;
}

function filterManagerAdjustmentRequests(requests, filters) {
    return requests.filter((request) => {
        if (filters.userKey && adjustmentRequestUserKey(request) !== filters.userKey) {
            return false;
        }
        if (filters.week && adjustmentWeekValue(request.schedule_date) !== filters.week) {
            return false;
        }
        if (filters.status && String(request.status) !== filters.status) {
            return false;
        }
        if (filters.type && String(request.kind || "schedule_adjustment") !== filters.type) {
            return false;
        }
        return true;
    });
}

async function mostraRichiesteOre() {
    showCalendarShell();
    appState.view = "scheduleAdjustments";
    setVista("calendario vista-adjustments mt-4", "Richieste ore");

    const wrapper = document.createElement("div");
    wrapper.className = "adjustments-list";
    wrapper.textContent = "Caricamento richieste...";
    container.appendChild(wrapper);
    const manager = canApproveScheduleAdjustments();

    try {
        const view = manager ? "manage" : "mine";
        const cacheKey = "scheduleAdjustments:" + view;
        let data = appCacheGet(cacheKey, 30 * 1000);
        if (!data) {
            data = await adjustmentFetch("view=" + view);
            appCacheSet(cacheKey, data);
        }
        wrapper.innerHTML = "";
        if (!data.requests.length) {
            wrapper.textContent = manager
                ? "Non ci sono richieste ore da gestire."
                : "Non hai ancora inserito richieste ore.";
            return;
        }
        const requests = Array.isArray(data.requests) ? data.requests : [];
        if (!manager) {
            requests.forEach((request) => {
                wrapper.appendChild(createAdjustmentRequestCard(request, {
                    includeUser: false,
                    canDecide: false
                }));
            });
            return;
        }

        const state = {
            userKey: "",
            week: "",
            status: "",
            type: ""
        };
        const summary = document.createElement("div");
        summary.className = "adjustments-filters__summary";
        const list = document.createElement("div");
        list.className = "adjustments-list";
        const render = () => {
            const filtered = filterManagerAdjustmentRequests(requests, state);
            summary.textContent = filtered.length === requests.length
                ? "Stai vedendo tutte le richieste del reparto."
                : "Richieste mostrate: " + filtered.length + " su " + requests.length + ".";
            list.innerHTML = "";
            if (!filtered.length) {
                list.textContent = "Nessuna richiesta trovata con i filtri selezionati.";
                return;
            }
            filtered.forEach((request) => {
                list.appendChild(createAdjustmentRequestCard(request, {
                    includeUser: true,
                    canDecide: true
                }));
            });
        };

        wrapper.appendChild(createManagerAdjustmentFilters(requests, (nextState) => {
            state.userKey = nextState.userKey;
            state.week = nextState.week;
            state.status = nextState.status;
            state.type = nextState.type;
            render();
        }));
        wrapper.append(summary, list);
        render();
    } catch (error) {
        wrapper.textContent = error.message;
    }
}

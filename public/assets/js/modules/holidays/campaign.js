// Campagna e inserimento preferenze ferie.
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

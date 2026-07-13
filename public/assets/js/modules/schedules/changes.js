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
            previous.textContent = change.previous_shift || "Riposo";
            const arrow = document.createElement("span");
            arrow.className = "change-card__arrow";
            arrow.setAttribute("aria-label", "diventa");
            arrow.textContent = "\u2192";
            const next = document.createElement("span");
            next.textContent = change.new_shift || "Riposo";
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
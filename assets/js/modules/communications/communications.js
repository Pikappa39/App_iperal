const COMMUNICATIONS_ENDPOINT = "connection_files/communications.php";

function formatCommunicationDate(value) {
    const raw = String(value || "").trim();
    if (!raw) return "";

    // MySQL restituisce DATETIME in UTC senza il suffisso del fuso orario.
    // Lo esplicitiamo qui prima di convertirlo nell'ora italiana.
    const isoValue = raw.replace(" ", "T") + "Z";
    const date = new Date(isoValue);
    if (Number.isNaN(date.getTime())) return raw;

    return new Intl.DateTimeFormat("it-IT", {
        timeZone: "Europe/Rome",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit"
    }).format(date);
}

async function communicationFetch(view) {
    const cacheKey = "communications:" + String(view || "inbox");
    const ttl = view === "users" ? 5 * 60 * 1000 : 30 * 1000;
    const cached = appCacheGet(cacheKey, ttl);
    if (cached) {
        return cached;
    }

    const response = await fetch(COMMUNICATIONS_ENDPOINT + "?view=" + encodeURIComponent(view), { cache: "no-store" });
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.error || "Operazione non riuscita");
    return appCacheSet(cacheKey, data);
}

function communicationCard(item, statusText = "") {
    const card = document.createElement("article");
    card.className = "card communication-card mb-3";
    const body = document.createElement("div");
    body.className = "card-body";
    const title = document.createElement("h5");
    title.textContent = item.title;
    const meta = document.createElement("div");
    meta.className = "small communication-card__meta mb-2";
    meta.textContent = (item.author_name ? "Da " + item.author_name + " \u00B7 " : "") + formatCommunicationDate(item.created_at);
    const text = document.createElement("p");
    text.className = "mb-2";
    text.textContent = item.message;
    body.append(title, meta, text);
    if (item.priority === "important") {
        const badge = document.createElement("span");
        badge.className = "badge text-bg-danger me-2";
        badge.textContent = "Importante";
        body.appendChild(badge);
    }
    if (statusText) {
        const status = document.createElement("span");
        status.className = "small communication-card__meta";
        status.textContent = statusText;
        body.appendChild(status);
    }
    card.appendChild(body);
    return card;
}

async function mostraComunicazioni() {
    showCalendarShell();
    appState.view = "communications";
    setVista("container mt-4", "Comunicazioni");
    const wrapper = document.createElement("div");
    wrapper.className = "mx-auto";
    wrapper.style.maxWidth = "760px";
    container.appendChild(wrapper);

    const inboxTitle = document.createElement("h4");
    inboxTitle.textContent = "Ricevute";
    const inbox = document.createElement("div");
    inbox.textContent = "Caricamento...";
    wrapper.append(inboxTitle, inbox);
    try {
        const data = await communicationFetch("inbox");
        inbox.innerHTML = "";
        if (!data.communications.length) inbox.textContent = "Non hai comunicazioni.";
        data.communications.forEach((item) => {
            const card = communicationCard(item);
            if (!item.acknowledged_at) {
                const acknowledge = document.createElement("button");
                acknowledge.className = "btn btn-outline-primary btn-sm mt-2";
                acknowledge.textContent = "Presa visione";
                acknowledge.onclick = async () => {
                    acknowledge.disabled = true;
                    acknowledge.textContent = "Confermo...";
                    const form = new FormData();
                    form.append("action", "acknowledge");
                    form.append("communication_id", item.id);
                    form.append("csrf_token", window.appCsrfToken || "");
                    try {
                        const response = await fetch(COMMUNICATIONS_ENDPOINT, { method: "POST", body: form });
                        const result = await response.json();
                        if (response.ok && result.ok) {
                            appCacheForget("communications:");
                            mostraComunicazioni();
                        } else {
                            acknowledge.disabled = false;
                            acknowledge.textContent = "Presa visione";
                            showAppToast(result.error || "Impossibile confermare");
                        }
                    } catch (error) {
                        acknowledge.disabled = false;
                        acknowledge.textContent = "Presa visione";
                        showAppToast(error.message || "Impossibile confermare");
                    }
                };
                card.querySelector(".card-body").appendChild(acknowledge);
            }
            inbox.appendChild(card);
        });
    } catch (error) { inbox.textContent = error.message; }

    if (!isCapoUser()) return;
    const compose = document.createElement("form");
    compose.className = "card card-body mt-5 communication-compose";
    compose.innerHTML = '<h4>Nuova comunicazione</h4><input class="form-control mb-2" name="title" maxlength="150" placeholder="Titolo" required><textarea class="form-control mb-2" name="message" maxlength="3000" rows="4" placeholder="Messaggio" required></textarea><select class="form-select mb-2" name="priority"><option value="normal">Normale</option><option value="important">Importante</option></select><select class="form-select mb-2" name="target_type"><option value="department">Tutto il reparto</option><option value="user">Singolo addetto</option></select><select class="form-select mb-2" name="department"></select><select class="form-select mb-2 d-none" name="recipient_cf"></select><button class="btn btn-primary" type="submit">Invia</button><p class="mb-0 mt-2" aria-live="polite"></p>';
    wrapper.appendChild(compose);
    const department = compose.elements.department;
    const recipient = compose.elements.recipient_cf;
    const targetType = compose.elements.target_type;
    const status = compose.querySelector("p");
    const role = String(window.userSession?.capo || "0");
    Object.entries(window.appBootstrap.departments || {}).forEach(([code, label]) => {
        if (role === "3" || code === window.userSession?.reparto) department.add(new Option(label, code));
    });
    try {
        const userData = await communicationFetch("users");
        userData.users.forEach((user) => recipient.add(new Option(user.cognome + " " + user.nome, user.cod_fiscale)));
    } catch (error) { status.textContent = error.message; }
    targetType.onchange = () => {
        const single = targetType.value === "user";
        department.classList.toggle("d-none", single);
        recipient.classList.toggle("d-none", !single);
    };
    compose.onsubmit = async (event) => {
        event.preventDefault();
        const submit = compose.querySelector("button[type='submit']");
        if (submit) submit.disabled = true;
        status.textContent = "Invio in corso...";
        const values = new FormData(compose);
        values.append("action", "send");
        values.append("csrf_token", window.appCsrfToken || "");
        try {
            const response = await fetch(COMMUNICATIONS_ENDPOINT, { method: "POST", body: values });
            const result = await response.json().catch(() => ({}));
            status.textContent = response.ok && result.ok ? "Inviata a " + result.recipients + " destinatari." : (result.error || "Impossibile inviare.");
            if (response.ok && result.ok) {
                appCacheForget("communications:");
                compose.reset();
                targetType.onchange();
            }
        } catch (error) {
            status.textContent = error.message || "Impossibile inviare.";
        } finally {
            if (submit) submit.disabled = false;
        }
    };

    const sentTitle = document.createElement("h4");
    sentTitle.className = "mt-5";
    sentTitle.textContent = "Inviate";
    const sent = document.createElement("div");
    sent.textContent = "Caricamento...";
    wrapper.append(sentTitle, sent);
    try {
        const data = await communicationFetch("sent");
        sent.innerHTML = "";
        if (!data.communications.length) sent.textContent = "Non hai ancora inviato comunicazioni.";
        data.communications.forEach((item) => sent.appendChild(communicationCard(item, "Destinatari: " + item.recipients + " \u00B7 Letta: " + item.read_count + " \u00B7 Confermata: " + item.acknowledged_count)));
    } catch (error) { sent.textContent = error.message; }
}

const CUSTOMER_ORDERS_ENDPOINT = "connection_files/customer_orders.php";

function customerOrderStatusLabel(status) {
    return {
        registered: "Registrato",
        ordered: "Ordinato",
        arrived: "Arrivato",
        arrived_to_call: "Arrivato / da chiamare",
        called: "Chiamato",
        delivered: "Consegnato",
        partial: "Parziale",
        cancelled: "Annullato",
        unavailable: "Non disponibile"
    }[status] || status;
}

function customerOrderStatusClass(status) {
    return {
        registered: "text-bg-secondary",
        ordered: "text-bg-primary",
        arrived: "text-bg-warning",
        arrived_to_call: "text-bg-warning",
        called: "text-bg-info",
        delivered: "text-bg-success",
        partial: "text-bg-dark",
        cancelled: "text-bg-danger",
        unavailable: "text-bg-danger"
    }[status] || "text-bg-secondary";
}

function customerOrderStatusProgress(status) {
    return {
        registered: 0,
        ordered: 30,
        arrived: 55,
        arrived_to_call: 55,
        called: 78,
        delivered: 100,
        partial: 50,
        cancelled: 0,
        unavailable: 0
    }[status] ?? 0;
}

function customerOrderProgress(order) {
    const items = Array.isArray(order.items) ? order.items : [];
    if (!items.length) {
        const percent = customerOrderStatusProgress(order.status);
        return { percent, delivered: percent === 100 ? 1 : 0, total: 1 };
    }

    const total = items.length;
    const delivered = items.filter((item) => item.status === "delivered").length;
    const sum = items.reduce((current, item) => current + customerOrderStatusProgress(item.status), 0);
    return {
        percent: Math.round(sum / total),
        delivered,
        total
    };
}

function customerOrderMainItemLabel(order) {
    const items = Array.isArray(order.items) ? order.items : [];
    if (!items.length) return "Nessun articolo";
    const first = items[0];
    const article = [first.quantity, first.article_name].filter(Boolean).join(" ");
    if (items.length === 1) return article || "1 articolo";
    return (article || "1 articolo") + " +" + (items.length - 1);
}

function customerOrderDate(value) {
    const raw = String(value || "").trim();
    if (!raw) return "";
    const date = new Date(raw.replace(" ", "T"));
    if (Number.isNaN(date.getTime())) return raw;
    return new Intl.DateTimeFormat("it-IT", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit"
    }).format(date);
}

async function customerOrdersFetch(query = "", options = {}) {
    const response = await fetch(CUSTOMER_ORDERS_ENDPOINT + (query ? "?" + query : ""), {
        cache: "no-store",
        ...options
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.ok) {
        throw new Error(data.error || "Ordini clienti non disponibili");
    }
    return data;
}

async function customerOrdersMeta() {
    const cached = appCacheGet("customerOrders:meta", 5 * 60 * 1000);
    if (cached) return cached;
    return appCacheSet("customerOrders:meta", await customerOrdersFetch("view=meta"));
}

async function customerOrdersPost(values) {
    values.append("csrf_token", window.appCsrfToken || "");
    const result = await customerOrdersFetch("", { method: "POST", body: values });
    appCacheForget("customerOrders:list");
    return result;
}

function customerOrderDepartmentSelect(meta, selected = "", options = {}) {
    const select = document.createElement("select");
    select.className = "form-select";
    select.name = "target_reparto";
    select.required = true;
    if (options.placeholder) {
        select.add(new Option(options.placeholder, ""));
    }
    Object.entries(meta.departments || {}).forEach(([code, label]) => {
        const option = new Option(label, code);
        option.selected = code === selected;
        select.add(option);
    });
    return select;
}

function customerOrderItemStatusSelect(meta, selected = "registered") {
    const select = document.createElement("select");
    select.className = "form-select form-select-sm";
    select.name = "status";
    (meta.item_statuses || []).forEach((status) => {
        const option = new Option(status.label, status.value);
        option.selected = status.value === selected;
        select.add(option);
    });
    return select;
}

function customerOrderInput(name, placeholder, value = "", required = false, maxLength = 255) {
    const input = document.createElement("input");
    input.className = "form-control";
    input.name = name;
    input.placeholder = placeholder;
    input.value = value || "";
    input.required = required;
    input.maxLength = maxLength;
    return input;
}

function customerOrderTextarea(name, placeholder, value = "", rows = 2, maxLength = 1000) {
    const textarea = document.createElement("textarea");
    textarea.className = "form-control";
    textarea.name = name;
    textarea.placeholder = placeholder;
    textarea.value = value || "";
    textarea.rows = rows;
    textarea.maxLength = maxLength;
    return textarea;
}

function customerOrderNewItemRow(removable = true) {
    const row = document.createElement("div");
    row.className = "customer-order-item-form";
    row.dataset.orderItemRow = "1";

    const article = customerOrderInput("article_name", "Articolo", "", true, 255);
    const quantity = customerOrderInput("quantity", "Quantità", "", true, 80);
    const price = customerOrderInput("price_at_order", "Prezzo ordine opzionale", "", false, 20);
    price.inputMode = "decimal";
    const ean = customerOrderInput("ean", "EAN opzionale", "", false, 64);
    const internalCode = customerOrderInput("internal_code", "Codice interno opzionale", "", false, 64);
    const note = customerOrderTextarea("item_note", "Nota articolo opzionale", "", 2, 1000);
    const remove = document.createElement("button");
    remove.type = "button";
    remove.className = "btn btn-outline-danger btn-sm";
    remove.textContent = "Rimuovi riga";
    remove.hidden = !removable;
    remove.addEventListener("click", () => row.remove());

    row.append(article, quantity, price, ean, internalCode, note, remove);
    return row;
}

function customerOrderReadItemRows(container) {
    return Array.from(container.querySelectorAll("[data-order-item-row]")).map((row) => ({
        article_name: row.querySelector("[name='article_name']")?.value.trim() || "",
        quantity: row.querySelector("[name='quantity']")?.value.trim() || "",
        price_at_order: row.querySelector("[name='price_at_order']")?.value.trim() || "",
        ean: row.querySelector("[name='ean']")?.value.trim() || "",
        internal_code: row.querySelector("[name='internal_code']")?.value.trim() || "",
        item_note: row.querySelector("[name='item_note']")?.value.trim() || ""
    })).filter((item) => item.article_name || item.quantity || item.ean || item.internal_code || item.item_note);
}

function customerOrderCreateForm(meta, reload) {
    const form = document.createElement("form");
    form.className = "customer-order-compose";

    const hint = document.createElement("p");
    hint.className = "customer-order-compose__hint";
    hint.textContent = meta.can_choose_department
        ? "Vista box: scegli il reparto destinatario del prodotto, poi inserisci cliente e articoli."
        : "L'ordine verrà condiviso con il tuo reparto e con il box informazioni.";

    const grid = document.createElement("div");
    grid.className = "customer-order-compose__grid";
    if (meta.can_choose_department) {
        const field = document.createElement("label");
        field.textContent = "Reparto destinatario";
        field.appendChild(customerOrderDepartmentSelect(meta, "", { placeholder: "Scegli reparto" }));
        grid.appendChild(field);
    }

    const nameField = document.createElement("label");
    nameField.textContent = "Nome cliente";
    nameField.appendChild(customerOrderInput("customer_name", "Nome", "", true, 100));
    const surnameField = document.createElement("label");
    surnameField.textContent = "Cognome cliente";
    surnameField.appendChild(customerOrderInput("customer_surname", "Cognome", "", true, 100));
    const phoneField = document.createElement("label");
    phoneField.textContent = "Telefono";
    phoneField.appendChild(customerOrderInput("customer_phone", "Telefono", "", true, 40));
    grid.append(nameField, surnameField, phoneField);

    const note = customerOrderTextarea("general_note", "Note generali: richieste particolari, orari in cui chiamare, alternative accettate...", "", 3, 2000);
    const itemList = document.createElement("div");
    itemList.className = "customer-order-compose__items";
    itemList.appendChild(customerOrderNewItemRow(false));

    const addItem = document.createElement("button");
    addItem.type = "button";
    addItem.className = "btn btn-outline-primary btn-sm";
    addItem.textContent = "Aggiungi articolo";
    addItem.addEventListener("click", () => itemList.appendChild(customerOrderNewItemRow(true)));

    const submit = document.createElement("button");
    submit.type = "submit";
    submit.className = "btn btn-primary";
    submit.textContent = "Registra ordine";
    const status = document.createElement("p");
    status.className = "customer-order-compose__status";

    form.append(hint, grid, note, itemList, addItem, submit, status);
    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        const items = customerOrderReadItemRows(itemList);
        if (!items.length) {
            status.textContent = "Inserisci almeno un articolo.";
            return;
        }
        submit.disabled = true;
        status.textContent = "Salvataggio ordine...";
        const values = new FormData(form);
        values.append("action", "create");
        values.append("items_json", JSON.stringify(items));
        try {
            await customerOrdersPost(values);
            showAppToast("Ordine cliente registrato");
            form.reset();
            itemList.innerHTML = "";
            itemList.appendChild(customerOrderNewItemRow(false));
            await reload();
        } catch (error) {
            status.textContent = error.message;
        } finally {
            submit.disabled = false;
        }
    });

    return form;
}

function customerOrderItemEditorForm(order, item, meta, reload) {
    const form = document.createElement("form");
    form.className = "customer-order-item-editor";
    form.dataset.itemId = String(item.id);

    const status = customerOrderItemStatusSelect(meta, item.status);
    const article = customerOrderInput("article_name", "Articolo", item.article_name, true, 255);
    const quantity = customerOrderInput("quantity", "Quantità", item.quantity, true, 80);
    const price = customerOrderInput("price_at_order", "Prezzo ordine", item.price_at_order, false, 20);
    price.inputMode = "decimal";
    const ean = customerOrderInput("ean", "EAN", item.ean, false, 64);
    const internalCode = customerOrderInput("internal_code", "Codice interno", item.internal_code, false, 64);
    const note = customerOrderTextarea("item_note", "Nota articolo", item.item_note, 2, 1000);
    const save = document.createElement("button");
    save.type = "submit";
    save.className = "btn btn-outline-primary btn-sm";
    save.textContent = "Salva articolo";
    const cancel = document.createElement("button");
    cancel.type = "button";
    cancel.className = "btn btn-outline-danger btn-sm";
    cancel.textContent = "Annulla articolo";
    const formStatus = document.createElement("span");
    formStatus.className = "small customer-order-card__muted";

    form.append(status, article, quantity, price, ean, internalCode, note, save, cancel, formStatus);
    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        save.disabled = true;
        formStatus.textContent = "Salvataggio...";
        const values = new FormData(form);
        values.append("action", "update_item");
        values.append("item_id", item.id);
        try {
            await customerOrdersPost(values);
            showAppToast("Articolo aggiornato");
            await reload();
        } catch (error) {
            formStatus.textContent = error.message;
            save.disabled = false;
        }
    });
    cancel.addEventListener("click", async () => {
        if (!window.confirm("Annullare questo articolo dell'ordine?")) return;
        cancel.disabled = true;
        formStatus.textContent = "Annullamento...";
        const values = new FormData(form);
        values.set("status", "cancelled");
        values.append("action", "update_item");
        values.append("item_id", item.id);
        try {
            await customerOrdersPost(values);
            showAppToast("Articolo annullato");
            await reload();
        } catch (error) {
            formStatus.textContent = error.message;
            cancel.disabled = false;
        }
    });

    return form;
}

function customerOrderItemFacts(item) {
    return [
        ["Quantità", item.quantity],
        ["Prezzo", item.price_at_order ? item.price_at_order : ""],
        ["EAN", item.ean],
        ["Codice", item.internal_code]
    ].filter(([, value]) => String(value || "").trim() !== "");
}

function customerOrderItemRow(order, item, meta, reload) {
    const row = document.createElement("article");
    row.className = "customer-order-item-row";
    row.dataset.itemId = String(item.id);

    const summary = document.createElement("div");
    summary.className = "customer-order-item-row__summary";

    const status = document.createElement("span");
    status.className = "badge customer-order-item-row__status " + customerOrderStatusClass(item.status);
    status.textContent = item.status_label || customerOrderStatusLabel(item.status);

    const main = document.createElement("div");
    main.className = "customer-order-item-row__main";
    const title = document.createElement("h4");
    title.textContent = item.article_name || "Articolo senza nome";
    const note = document.createElement("p");
    note.textContent = item.item_note ? "Nota: " + item.item_note : "Nessuna nota articolo";
    main.append(title, note);

    const facts = document.createElement("div");
    facts.className = "customer-order-item-row__facts";
    customerOrderItemFacts(item).forEach(([label, value]) => {
        const fact = document.createElement("span");
        fact.className = "customer-order-item-row__fact";
        fact.innerHTML = "<small></small><strong></strong>";
        fact.querySelector("small").textContent = label;
        fact.querySelector("strong").textContent = value;
        facts.appendChild(fact);
    });

    summary.append(status, main, facts);

    if (order.can_edit) {
        const edit = document.createElement("button");
        edit.type = "button";
        edit.className = "customer-order-item-row__edit";
        edit.setAttribute("aria-expanded", "false");
        edit.textContent = "Modifica";

        const editor = customerOrderItemEditorForm(order, item, meta, reload);
        editor.hidden = true;
        edit.addEventListener("click", () => {
            const willOpen = editor.hidden;
            editor.hidden = !willOpen;
            row.classList.toggle("is-editing", willOpen);
            edit.setAttribute("aria-expanded", String(willOpen));
            edit.textContent = willOpen ? "Chiudi" : "Modifica";
        });

        summary.appendChild(edit);
        row.append(summary, editor);
        return row;
    }

    row.appendChild(summary);
    return row;
}

function customerOrderAddItemForm(order, reload) {
    const details = document.createElement("details");
    details.className = "customer-order-add-item";
    const summary = document.createElement("summary");
    summary.textContent = "Aggiungi articolo";
    const form = document.createElement("form");
    form.className = "customer-order-item-form";
    const price = customerOrderInput("price_at_order", "Prezzo ordine opzionale", "", false, 20);
    price.inputMode = "decimal";
    form.append(
        customerOrderInput("article_name", "Articolo", "", true, 255),
        customerOrderInput("quantity", "Quantità", "", true, 80),
        price,
        customerOrderInput("ean", "EAN opzionale", "", false, 64),
        customerOrderInput("internal_code", "Codice interno opzionale", "", false, 64),
        customerOrderTextarea("item_note", "Nota articolo opzionale", "", 2, 1000)
    );
    const submit = document.createElement("button");
    submit.type = "submit";
    submit.className = "btn btn-outline-primary btn-sm";
    submit.textContent = "Aggiungi";
    const status = document.createElement("span");
    status.className = "small customer-order-card__muted";
    form.append(submit, status);
    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        submit.disabled = true;
        status.textContent = "Salvataggio...";
        const values = new FormData(form);
        values.append("action", "add_item");
        values.append("order_id", order.id);
        try {
            await customerOrdersPost(values);
            showAppToast("Articolo aggiunto");
            await reload();
        } catch (error) {
            status.textContent = error.message;
            submit.disabled = false;
        }
    });
    details.append(summary, form);
    return details;
}

function customerOrderDetailsForm(order, reload) {
    const details = document.createElement("details");
    details.className = "customer-order-details-edit";
    const summary = document.createElement("summary");
    summary.textContent = "Modifica cliente e note";
    const form = document.createElement("form");
    form.className = "customer-order-details-edit__form";
    form.append(
        customerOrderInput("customer_name", "Nome", order.customer_name, true, 100),
        customerOrderInput("customer_surname", "Cognome", order.customer_surname, true, 100),
        customerOrderInput("customer_phone", "Telefono", order.customer_phone, true, 40),
        customerOrderTextarea("general_note", "Note ordine", order.general_note, 3, 2000)
    );
    const submit = document.createElement("button");
    submit.type = "submit";
    submit.className = "btn btn-outline-primary btn-sm";
    submit.textContent = "Salva dati ordine";
    const status = document.createElement("span");
    status.className = "small customer-order-card__muted";
    form.append(submit, status);
    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        submit.disabled = true;
        status.textContent = "Salvataggio...";
        const values = new FormData(form);
        values.append("action", "update_order");
        values.append("order_id", order.id);
        try {
            await customerOrdersPost(values);
            showAppToast("Ordine aggiornato");
            await reload();
        } catch (error) {
            status.textContent = error.message;
            submit.disabled = false;
        }
    });
    details.append(summary, form);
    return details;
}

function customerOrderSummary(order) {
    const summary = document.createElement("summary");
    summary.className = "customer-order-card__summary";

    const main = document.createElement("div");
    main.className = "customer-order-card__summary-main";

    const customer = document.createElement("h3");
    customer.textContent = order.customer_name + " " + order.customer_surname;

    const compact = document.createElement("p");
    compact.textContent = [
        customerOrderMainItemLabel(order),
        order.customer_phone,
        order.target_reparto_label
    ].filter(Boolean).join(" \u00B7 ");

    const metaLine = document.createElement("small");
    metaLine.textContent = "Preso da " + order.taken_by_name + " \u00B7 " + customerOrderDate(order.taken_at);

    main.append(customer, compact, metaLine);

    const side = document.createElement("div");
    side.className = "customer-order-card__summary-side";

    const badges = document.createElement("div");
    badges.className = "customer-order-card__badges";
    const status = document.createElement("span");
    status.className = "badge " + customerOrderStatusClass(order.status);
    status.textContent = order.status_label || customerOrderStatusLabel(order.status);
    const department = document.createElement("span");
    department.className = "badge text-bg-light";
    department.textContent = order.target_reparto_label;
    badges.append(status, department);

    const progress = customerOrderProgress(order);
    const progressWrap = document.createElement("div");
    progressWrap.className = "customer-order-card__progress";
    const progressText = document.createElement("strong");
    progressText.textContent = progress.percent + "%";
    const progressBar = document.createElement("span");
    progressBar.className = "customer-order-card__progress-bar";
    const progressFill = document.createElement("span");
    progressFill.style.width = progress.percent + "%";
    progressBar.appendChild(progressFill);
    const progressHint = document.createElement("small");
    progressHint.textContent = progress.delivered + "/" + progress.total + " consegnati";
    progressWrap.append(progressText, progressBar, progressHint);

    side.append(badges, progressWrap);
    summary.append(main, side);
    return summary;
}

function customerOrderCard(order, meta, reload) {
    const card = document.createElement("details");
    card.className = "customer-order-card";
    card.dataset.status = order.status;
    card.dataset.department = order.target_reparto;
    card.dataset.search = [
        order.customer_name,
        order.customer_surname,
        order.customer_phone,
        order.taken_by_name,
        order.target_reparto_label,
        ...(order.items || []).map((item) => [item.article_name, item.ean, item.internal_code, item.price_at_order].join(" "))
    ].join(" ").toLocaleLowerCase("it-IT");

    const body = document.createElement("div");
    body.className = "customer-order-card__body";

    const metaLine = document.createElement("p");
    metaLine.className = "customer-order-card__muted";
    metaLine.textContent = "Preso da " + order.taken_by_name + " \u00B7 " + customerOrderDate(order.taken_at);

    const contacts = document.createElement("div");
    contacts.className = "customer-order-card__contacts";
    const phone = document.createElement("a");
    phone.href = "tel:" + String(order.customer_phone || "").replace(/[^\d+]/g, "");
    phone.textContent = order.customer_phone;
    contacts.append("Telefono: ", phone);

    const items = document.createElement("div");
    items.className = "customer-order-card__items";
    (order.items || []).forEach((item) => items.appendChild(customerOrderItemRow(order, item, meta, reload)));

    card.appendChild(customerOrderSummary(order));
    body.append(metaLine, contacts);
    if (order.general_note) {
        const note = document.createElement("p");
        note.className = "customer-order-card__note";
        note.textContent = "Note: " + order.general_note;
        body.appendChild(note);
    }
    body.appendChild(items);
    if (order.can_edit) {
        body.append(customerOrderAddItemForm(order, reload), customerOrderDetailsForm(order, reload));
    }
    card.appendChild(body);
    return card;
}

function customerOrdersApplyFilters(root, filters) {
    const query = String(filters.query || "").trim().toLocaleLowerCase("it-IT");
    root.querySelectorAll(".customer-order-card").forEach((card) => {
        const matchesQuery = !query || String(card.dataset.search || "").includes(query);
        const matchesDepartment = !filters.department || card.dataset.department === filters.department;
        card.hidden = !matchesQuery || !matchesDepartment;
    });
}

function customerOrdersFilterBar(meta, onReload, list) {
    const filters = document.createElement("section");
    filters.className = "customer-order-filters";
    const search = customerOrderInput("search", "Cerca cliente, telefono, articolo o codice", "", false, 160);
    const status = document.createElement("select");
    status.className = "form-select";
    [
        ["open", "Ordini aperti"],
        ["all", "Tutti"],
        ["closed", "Chiusi"],
        ["registered", "Registrati"],
        ["ordered", "Ordinati"],
        ["arrived_to_call", "Arrivati / da chiamare"],
        ["called", "Chiamati"],
        ["partial", "Parziali"],
        ["delivered", "Consegnati"],
        ["cancelled", "Annullati"]
    ].forEach(([value, label]) => status.add(new Option(label, value)));
    const department = customerOrderDepartmentSelect(meta);
    department.required = false;
    department.insertBefore(new Option("Tutti i reparti", ""), department.firstChild);
    department.value = "";
    department.hidden = !meta.can_choose_department;
    const reload = document.createElement("button");
    reload.type = "button";
    reload.className = "btn btn-outline-secondary";
    reload.textContent = "Aggiorna";

    const emitFilter = () => customerOrdersApplyFilters(list, {
        query: search.value,
        department: department.value
    });
    search.addEventListener("input", emitFilter);
    department.addEventListener("change", emitFilter);
    status.addEventListener("change", () => onReload(status.value, department.value));
    reload.addEventListener("click", () => onReload(status.value, department.value, true));
    filters.append(search, status, department, reload);
    return filters;
}

function customerOrdersHelpPanel(meta) {
    const details = document.createElement("details");
    details.className = "customer-order-help";

    const summary = document.createElement("summary");
    summary.className = "customer-order-help__summary";
    const mark = document.createElement("span");
    mark.className = "customer-order-help__mark";
    mark.textContent = "?";
    const copy = document.createElement("span");
    copy.innerHTML = "<strong>Guida lampo ordini</strong><small>Come inserirli, seguirli e chi viene avvisato</small>";
    summary.append(mark, copy);

    const body = document.createElement("div");
    body.className = "customer-order-help__body";

    const flow = document.createElement("div");
    flow.className = "customer-order-help__flow";
    [
        ["1", meta.can_choose_department ? "Scegli reparto" : "Reparto automatico", meta.can_choose_department ? "Dal box indichi dove deve lavorarlo." : "Dal reparto va al tuo reparto + box."],
        ["2", "Dati cliente", "Nome, telefono e note speciali: qui vincono i dettagli."],
        ["3", "Articoli", "Aggiungi righe, quantità, EAN/codice se li hai e prezzo del momento."],
        ["4", "Aggiorna stati", "Ogni articolo vive da solo; l'ordine si riassume automaticamente."]
    ].forEach(([step, title, text]) => {
        const item = document.createElement("article");
        const badge = document.createElement("span");
        badge.textContent = step;
        const strong = document.createElement("strong");
        strong.textContent = title;
        const small = document.createElement("small");
        small.textContent = text;
        item.append(badge, strong, small);
        flow.appendChild(item);
    });

    const states = document.createElement("div");
    states.className = "customer-order-help__states";
    [
        ["registered", "Appena inserito"],
        ["ordered", "Prodotto ordinato"],
        ["arrived", "Arrivato: cliente da chiamare"],
        ["called", "Cliente avvisato"],
        ["delivered", "Consegnato"],
        ["partial", "Articoli in stati diversi"],
        ["cancelled", "Tutto annullato/non disponibile"]
    ].forEach(([status, text]) => {
        const pill = document.createElement("span");
        pill.className = "customer-order-help__pill";
        pill.textContent = customerOrderStatusLabel(status) + " \u00B7 " + text;
        states.appendChild(pill);
    });

    const note = document.createElement("p");
    note.className = "customer-order-help__note";
    note.textContent = "Promemoria furbo: il prezzo ordine è storico. Se finisce una promo, resta scritto quanto costava quando il cliente ha ordinato. Gli aggiornamenti ordini restano nel campanellino in-app, non nelle notifiche di sistema.";

    body.append(flow, states, note);
    details.append(summary, body);
    return details;
}

function customerOrderCollapsibleSection(title, subtitle, content, options = {}) {
    const section = document.createElement("details");
    section.className = "customer-order-section";
    section.open = Boolean(options.open);

    const summary = document.createElement("summary");
    summary.className = "customer-order-section__summary";
    const copy = document.createElement("span");
    const heading = document.createElement("strong");
    heading.textContent = title;
    const hint = document.createElement("small");
    hint.textContent = subtitle;
    copy.append(heading, hint);
    summary.appendChild(copy);

    const body = document.createElement("div");
    body.className = "customer-order-section__body";
    (Array.isArray(content) ? content : [content]).forEach((node) => body.appendChild(node));

    section.append(summary, body);
    return section;
}

async function mostraOrdiniClienti() {
    showCalendarShell();
    appState.view = "customerOrders";
    setVista("calendario vista-customer-orders mt-4", "Ordini clienti");

    const wrapper = document.createElement("div");
    wrapper.className = "customer-orders";
    wrapper.textContent = "Caricamento ordini clienti...";
    container.appendChild(wrapper);

    const renderList = async (meta, list, status = "open", department = "", force = false) => {
        list.textContent = "Caricamento...";
        const query = new URLSearchParams({ view: "list", status });
        if (department) query.set("department", department);
        const cacheKey = "customerOrders:list:" + query.toString();
        let data = !force ? appCacheGet(cacheKey, 20 * 1000) : null;
        if (!data) {
            data = await customerOrdersFetch(query.toString());
            appCacheSet(cacheKey, data);
        }
        list.innerHTML = "";
        const orders = Array.isArray(data.orders) ? data.orders : [];
        if (!orders.length) {
            list.textContent = status === "open" ? "Non ci sono ordini aperti." : "Nessun ordine trovato.";
            return;
        }
        orders.forEach((order) => list.appendChild(customerOrderCard(order, meta, () => renderList(meta, list, status, department, true))));
    };

    try {
        const meta = await customerOrdersMeta();
        wrapper.innerHTML = "";
        const help = customerOrdersHelpPanel(meta);
        const list = document.createElement("div");
        list.className = "customer-order-list";
        const form = customerOrderCreateForm(meta, () => renderList(meta, list, "open", "", true));
        const filters = customerOrdersFilterBar(meta, (status, department, force = false) => renderList(meta, list, status, department, force), list);
        const createSection = customerOrderCollapsibleSection(
            "Crea nuovo ordine",
            "Apri quando devi registrare una nuova richiesta cliente.",
            form
        );
        const listSection = customerOrderCollapsibleSection(
            "Lista ordini",
            "Controlla lo stato, filtra e aggiorna gli ordini del reparto.",
            [filters, list],
            { open: true }
        );
        wrapper.append(help, createSection, listSection);
        await renderList(meta, list);
    } catch (error) {
        wrapper.textContent = error.message;
    }
}

//questa funzione createDayNotePanel crea il pannello per visualizzare e modificare le note di un giorno specifico
function createDayNotePanel(giornoInfo) {
    const wrapper = document.createElement("div");
    wrapper.className = "note-panel";

    const title = document.createElement("h4");
    title.className = "note-panel__title";
    title.textContent = "Nota per " + giornoInfo.giorno_parola + " " + giornoInfo.dataKey;

    const subtitle = document.createElement("div");
    subtitle.className = "note-panel__subtitle";
    subtitle.textContent = getCurrentUser()
        ? "La nota viene salvata per il tuo utente e per questo giorno."
        : "Effettua il login per scrivere o salvare una nota.";

    const existingNotes = document.createElement("div");
    existingNotes.className = "note-panel__existing";
    existingNotes.textContent = "Caricamento note...";

    const textarea = document.createElement("textarea");
    textarea.placeholder = "Inserisci le tue note qui...";
    textarea.disabled = !getCurrentUser();

    const status = document.createElement("div");
    status.className = "note-panel__status";

    const saveButton = document.createElement("button");
    saveButton.innerText = "Salva";
    saveButton.classList.add("btn", "btn-primary");
    saveButton.disabled = !getCurrentUser();

    wrapper.appendChild(title);
    wrapper.appendChild(subtitle);
    wrapper.appendChild(existingNotes);
    wrapper.appendChild(textarea);
    wrapper.appendChild(status);
    wrapper.appendChild(saveButton);

    return {
        wrapper,
        existingNotes,
        textarea,
        status,
        saveButton
    };
}
//questa funzione setDayNotesSummary aggiorna il riepilogo delle note esistenti per un giorno specifico
function setDayNotesSummary(existingNotes, dayNotes) {
    if (!Array.isArray(dayNotes) || dayNotes.length === 0) {
        existingNotes.textContent = "Nessuna nota salvata per questo giorno.";
        return;
    }

    existingNotes.textContent = dayNotes.length === 1
        ? "1 nota per questo giorno."
        : "Ci sono " + dayNotes.length + " note per questo giorno.";
}
//questa funzione loadDayNoteContent carica il contenuto della nota per un giorno specifico e aggiorna l'interfaccia utente
//viene richiamata da mostragiorno e gestisce il caricamento asincrono delle note dal server
async function loadDayNoteContent(anno, mese, giornoInfo, textarea, existingNotes, token) {
    try {
        const noteMese = await getMonthNotes(anno, mese);
        if (token !== appState.noteViewToken) {
            return null;
        }

        const dayNotes = getDayNoteList(noteMese, giornoInfo.dataKey);
        textarea.value = getCurrentUserNoteFromDayNotes(dayNotes);
        setDayNotesSummary(existingNotes, dayNotes);
        return dayNotes;
    } catch (error) {
        console.error("Errore nel caricamento della nota", error);
        existingNotes.textContent = "Non riesco a caricare le note di questo giorno.";
        return null;
    }
}
//questa funzione bindSaveDayNoteButton associa l'evento di salvataggio della nota al pulsante "Salva" e gestisce la logica di invio al server
//viene richiamata da mostragiorno e gestisce il salvataggio asincrono delle note sul server
function bindSaveDayNoteButton(anno, mese, giornoInfo, textarea, existingNotes, status, saveButton) {
    saveButton.addEventListener("click", async function () {
        const currentToken = appState.noteViewToken;
        const noteText = textarea.value;
        const normalizedNote = noteText.trim();

        saveButton.disabled = true;
        textarea.disabled = true;
        status.textContent = "Salvataggio in corso...";

        const formData = new FormData();
        formData.append("date", giornoInfo.dataKey);
        formData.append("note", noteText);

        try {
            const response = await fetch(NOTES_ENDPOINT, {
                method: "POST",
                body: formData
            });

            const responseText = await response.text();
            let result = null;

            try {
                result = responseText ? JSON.parse(responseText) : null;
            } catch (parseError) {
                console.error("Risposta non JSON dal server:", responseText);
                throw new Error("Il server ha risposto con un formato non valido.");
            }

            if (!response.ok || !result.ok) {
                const serverError = result.error || "Salvataggio non riuscito";
                if (result.details) {
                    console.error("Dettagli errore server:", result.details);
                }
                throw new Error(serverError);
            }

            if (currentToken !== appState.noteViewToken) {
                return;
            }

            updateMonthNotesCache(anno, mese, giornoInfo.dataKey, result.notes || []);
            setDayNotesSummary(existingNotes, Array.isArray(result.notes) ? result.notes : []);

            status.textContent = normalizedNote ? "Nota salvata con successo." : "Nota rimossa.";
            showAppToast(normalizedNote ? "Nota salvata" : "Nota rimossa");
        } catch (error) {
            console.error("Errore nel salvataggio della nota", error);
            status.textContent = "Non riesco a salvare la nota.";
            showAppToast("Errore nel salvataggio della nota");
        } finally {
            if (currentToken === appState.noteViewToken) {
                saveButton.disabled = false;
                textarea.disabled = false;
            }
        }
    });
}

async function mostragiorno(giornoInfo) {
    appState.calendarViewToken += 1;
    appState.view = "giorno";
    appState.selectedDay = giornoInfo;
    appNavigationRecordCurrentView();
    container.classList.remove("griglia-giorni", "griglia-mesi", "griglia-anni");
    container.classList.add("vista-giorno");
    container.innerHTML = "";

    const [annoStr, meseStr] = (giornoInfo.dataKey || "").split("-");
    const anno = parseInt(annoStr, 10);
    const mese = parseInt(meseStr, 10);

    const notePanel = createDayNotePanel(giornoInfo);
    container.appendChild(notePanel.wrapper);

    const token = ++appState.noteViewToken;
    const dayNotes = await loadDayNoteContent(
        anno,
        mese,
        giornoInfo,
        notePanel.textarea,
        notePanel.existingNotes,
        token
    );

    if (!getCurrentUser()) {
        notePanel.status.textContent = "Devi accedere per salvare una nota.";
        return;
    }

    notePanel.textarea.focus();
    bindSaveDayNoteButton(anno, mese, giornoInfo, notePanel.textarea, notePanel.existingNotes, notePanel.status, notePanel.saveButton);
}
//questa funzione serve a generare le note di un utente
function createAdminNoteCard(entry) {
    //la costante
    const card = document.createElement("article");
    card.className = "admin-note-card";

    const meta = document.createElement("div");
    meta.className = "admin-note-card__meta";

    const user = document.createElement("div");
    user.className = "admin-note-card__user";
    user.textContent = entry.userName || "Utente";

    const date = document.createElement("div");
    date.className = "admin-note-card__date";
    date.textContent = formatDateLabel(entry.date || "");

    const note = document.createElement("div");
    note.className = "admin-note-card__text";
    note.textContent = entry.note || "";

    meta.appendChild(user);
    meta.appendChild(date);
    card.appendChild(meta);
    card.appendChild(note);

    return card;
}
//questa funzione createAdminMonthSection crea una sezione per un mese specifico contenente le note degli utenti
//viene richiamata da mostranoteadmin
//viene richiamata in essa createadminnotecard che serve a generare le card conteneti le note di un utente
function createAdminMonthSection(monthData) {
    const section = document.createElement("section");
    section.className = "admin-notes__month";

    const title = document.createElement("h4");
    title.className = "admin-notes__month-title";
    title.textContent = formatMonthLabel(monthData.month);

    const list = document.createElement("div");
    list.className = "admin-notes__list";

    monthData.entries.forEach((entry) => {
        list.appendChild(createAdminNoteCard(entry));
    });

    section.appendChild(title);
    section.appendChild(list);
    return section;
}

async function mostraNoteAdmin() {
    showCalendarShell();
    appState.view = "noteAdmin";
    setVista("calendario vista-note-admin mt-4", "Note dipendenti");

    const panel = document.createElement("div");
    panel.className = "admin-notes";

    const intro = document.createElement("div");
    intro.className = "admin-notes__intro";
    intro.textContent = "Qui trovi tutte le note inserite dagli utenti, raggruppate per mese.";

    const body = document.createElement("div");
    body.className = "admin-notes__body";
    body.textContent = "Caricamento note...";

    panel.appendChild(intro);
    panel.appendChild(body);
    container.appendChild(panel);

    try {
        const months = await getAllNotesForCapo();
        body.innerHTML = "";

        const usefulMonths = months.filter((monthData) => Array.isArray(monthData.entries) && monthData.entries.length > 0);
        if (usefulMonths.length === 0) {
            body.textContent = "Non ci sono ancora note salvate.";
            return;
        }

        const fragment = document.createDocumentFragment();

        usefulMonths.forEach((monthData) => {
            fragment.appendChild(createAdminMonthSection(monthData));
        });

        body.appendChild(fragment);
    } catch (error) {
        console.error("Errore nel caricamento note admin", error);
        body.textContent = "Non riesco a caricare l'elenco delle note.";
        showAppToast("Errore nel caricamento note");
    }
}

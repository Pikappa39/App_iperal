(function () {
    const config = window.appScheduleUploadConfig || {};
    const form = document.getElementById("uploadForm");
    const statusBox = document.getElementById("status");
    const back = document.getElementById("back");
    const submitUpload = document.getElementById("submitUpload");
    const mappingPanel = document.getElementById("mappingPanel");
    const mappingRows = document.getElementById("mappingRows");
    const markAllUnregistered = document.getElementById("markAllUnregistered");
    const repartoField = document.getElementById("reparto");
    const excelFiles = document.getElementById("excelFiles");
    let readyToUpload = false;

    if (!form || !statusBox || !submitUpload || !mappingPanel || !mappingRows || !markAllUnregistered) {
        return;
    }

    function resetMappingState() {
        readyToUpload = false;
        mappingPanel.hidden = true;
        mappingRows.innerHTML = "";
        markAllUnregistered.checked = false;
        submitUpload.textContent = "Analizza file e associa nominativi";
        statusBox.innerHTML = "";
    }

    function showError(message) {
        statusBox.innerHTML = "";
        const alert = document.createElement("div");
        alert.className = "alert alert-danger";
        alert.textContent = message;
        statusBox.appendChild(alert);
    }

    async function sendForm(mode, mappings = null) {
        const formData = new FormData(form);
        formData.append("mode", mode);
        formData.append("csrf_token", config.csrfToken || "");
        if (mode === "preview") {
            formData.append("auto_upload", "1");
        }
        if (mappings !== null) {
            formData.append("mappings", JSON.stringify(mappings));
        }

        const res = await fetch(config.endpoint || "connection_files/upload.php", {
            method: "POST",
            body: formData,
            cache: "no-cache"
        });
        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch {
            throw new Error(text || "Risposta non valida");
        }
        if (!res.ok || data.ok === false) {
            throw new Error(data.error || "Errore upload");
        }
        return data;
    }

    function renderMappings(data) {
        mappingRows.innerHTML = "";
        markAllUnregistered.checked = false;
        const users = Array.isArray(data.users) ? data.users : [];

        (data.names || []).forEach((entry) => {
            const tr = document.createElement("tr");
            const nameCell = document.createElement("td");
            nameCell.textContent = entry.name;

            const userCell = document.createElement("td");
            const select = document.createElement("select");
            select.className = "form-select schedule-mapping";
            select.dataset.key = entry.key;
            select.required = true;

            const placeholder = document.createElement("option");
            placeholder.value = "";
            placeholder.textContent = "Seleziona utente\u2026";
            select.appendChild(placeholder);

            const unregistered = document.createElement("option");
            unregistered.value = "__UNREGISTERED__";
            unregistered.textContent = "Utente non registrato";
            select.appendChild(unregistered);

            users.forEach((user) => {
                const option = document.createElement("option");
                option.value = user.cod_fiscale;
                option.textContent = `${user.nome} ${user.cognome}`;
                option.selected = user.cod_fiscale === entry.userCf;
                select.appendChild(option);
            });

            userCell.appendChild(select);
            tr.append(nameCell, userCell);
            mappingRows.appendChild(tr);
        });

        mappingPanel.hidden = false;
        readyToUpload = true;
        submitUpload.textContent = "Salva associazioni e carica turni";
    }

    function showUploadResult(data) {
        statusBox.innerHTML = "";
        const alert = document.createElement("div");
        alert.className = "alert alert-success";
        const list = document.createElement("ul");
        list.className = "mb-0";

        (data.results || []).forEach((item) => {
            const row = document.createElement("li");
            if (item.error) {
                row.className = "text-danger";
                row.textContent = `${item.file || "File"}: ${item.error}`;
            } else {
                const historyCount = item.history && Number.isFinite(Number(item.history.stored))
                    ? ` - storico: ${Number(item.history.stored)} modifiche`
                    : "";
                row.textContent = `${item.file || "File"} \u2192 ${item.output || ""} (${item.righe || 0} righe)${historyCount}`;
            }
            list.appendChild(row);
        });

        alert.appendChild(list);
        statusBox.appendChild(alert);
    }

    function getMappings() {
        const mappings = {};
        const selects = mappingRows.querySelectorAll(".schedule-mapping");
        for (const select of selects) {
            if (!select.value) {
                select.focus();
                throw new Error("Scegli un utente per ogni nominativo.");
            }
            mappings[select.dataset.key] = select.value;
        }
        return mappings;
    }

    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        submitUpload.disabled = true;

        try {
            if (!readyToUpload) {
                statusBox.textContent = "Analisi del file in corso...";
                const data = await sendForm("preview");
                if (Array.isArray(data.results)) {
                    showUploadResult(data);
                    return;
                }
                if (!(data.names || []).length) {
                    statusBox.textContent = "Tutti i nominativi sono gia associati.";
                    return;
                }
                renderMappings(data);
                statusBox.textContent = "Controlla le associazioni e conferma il caricamento.";
                return;
            }

            statusBox.textContent = "Caricamento in corso...";
            const data = await sendForm("upload", getMappings());
            showUploadResult(data);
        } catch (error) {
            showError(error.message);
        } finally {
            submitUpload.disabled = false;
        }
    });

    if (excelFiles) {
        excelFiles.addEventListener("change", resetMappingState);
    }

    if (repartoField) {
        repartoField.addEventListener("change", resetMappingState);
    }

    markAllUnregistered.addEventListener("change", () => {
        if (!markAllUnregistered.checked) {
            return;
        }
        mappingRows.querySelectorAll(".schedule-mapping").forEach((select) => {
            select.value = "__UNREGISTERED__";
        });
    });

    if (back) {
        back.addEventListener("click", () => {
            window.location.href = config.backUrl || "index.php";
        });
    }
})();

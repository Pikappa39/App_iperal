(function () {
    const countdown = document.querySelector("[data-admin-console-countdown]");
    const expiresAt = countdown ? Number(countdown.dataset.expiresAt || 0) : 0;

    function formatRemaining(seconds) {
        const minutes = Math.floor(seconds / 60).toString().padStart(2, "0");
        const rest = Math.floor(seconds % 60).toString().padStart(2, "0");
        return `${minutes}:${rest}`;
    }

    function refreshCountdown() {
        if (!countdown || !expiresAt) {
            return;
        }

        const remaining = Math.max(0, Math.floor(expiresAt - Date.now() / 1000));
        countdown.textContent = formatRemaining(remaining);
        countdown.classList.toggle("admin-console-timer--warning", remaining <= 120);

        if (remaining <= 0) {
            document.querySelectorAll("button, input, select").forEach((element) => {
                element.disabled = true;
            });
            window.location.reload();
        }
    }

    document.querySelectorAll("[data-copy-target]").forEach((button) => {
        button.addEventListener("click", async () => {
            const target = document.querySelector(button.dataset.copyTarget || "");
            if (!(target instanceof HTMLInputElement)) {
                return;
            }

            try {
                await navigator.clipboard.writeText(target.value);
                button.textContent = "Copiato";
                window.setTimeout(() => {
                    button.textContent = "Copia";
                }, 1600);
            } catch (error) {
                target.focus();
                target.select();
            }
        });
    });

    document.querySelectorAll("form").forEach((form) => {
        const action = form.querySelector('input[name="action"]')?.value || "";
        if (!["manual_invite_link", "revoke_invite"].includes(action)) {
            return;
        }

        form.addEventListener("submit", (event) => {
            if (form.dataset.submitting === "1") {
                event.preventDefault();
                return;
            }

            form.dataset.submitting = "1";
            const label = action === "revoke_invite" ? "Revoco..." : "Genero...";
            form.querySelectorAll('button[type="submit"]').forEach((button) => {
                button.disabled = true;
                button.textContent = label;
            });
        });
    });

    const search = document.querySelector("[data-admin-console-search]");
    const searchStatus = document.querySelector("[data-admin-console-search-status]");
    const rows = Array.from(document.querySelectorAll("[data-admin-console-row]"));
    const panels = Array.from(document.querySelectorAll("[data-admin-console-panel]"));

    function updateSearch() {
        if (!(search instanceof HTMLInputElement)) {
            return;
        }

        const query = search.value.trim().toLocaleLowerCase();
        let visibleRows = 0;
        const active = query.length > 0;

        rows.forEach((row) => {
            const text = (row.dataset.searchText || "").toLocaleLowerCase();
            const visible = !active || text.includes(query);
            row.hidden = !visible;
            if (visible) {
                visibleRows += 1;
            }
        });

        panels.forEach((panel) => {
            const panelRows = Array.from(panel.querySelectorAll("[data-admin-console-row]"));
            const hasVisibleRows = panelRows.some((row) => !row.hidden);
            panel.hidden = active && !hasVisibleRows;
            if (active && hasVisibleRows) {
                panel.open = true;
            }
        });

        if (searchStatus) {
            if (!active) {
                searchStatus.textContent = "";
            } else {
                searchStatus.textContent = visibleRows === 1
                    ? "1 risultato trovato"
                    : `${visibleRows} risultati trovati`;
            }
        }
    }

    if (search instanceof HTMLInputElement) {
        search.addEventListener("input", updateSearch);
        updateSearch();
    }

    refreshCountdown();
    window.setInterval(refreshCountdown, 1000);
}());

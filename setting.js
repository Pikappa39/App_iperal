const APP_THEME_KEY = "app-iperal-theme";

function applyTheme(theme) {
    const selectedTheme = theme === "dark" ? "dark" : "light";
    document.documentElement.dataset.theme = selectedTheme;
    localStorage.setItem(APP_THEME_KEY, selectedTheme);
}

function getTheme() {
    return localStorage.getItem(APP_THEME_KEY) === "dark" ? "dark" : "light";
}

function createSettingsButton(label, onClick) {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "settings-menu__item";
    button.textContent = label;
    button.addEventListener("click", onClick);
    return button;
}

function setupSettingsScreen(title, panel) {
    showCalendarShell();
    setVista("calendario vista-settings mt-4", title);
    appState.view = "setting";
    container.hidden = false;
    container.classList.remove("app-hidden");
    container.appendChild(panel);
}

function mostrasetting() {
    appState.settingsPanel = "main";

    const wrapper = document.createElement("div");
    wrapper.className = "settings-menu";
    wrapper.append(
        createSettingsButton("Schermo", mostraImpostazioniSchermo),
        createSettingsButton("Notifiche", mostraImpostazioniNotifiche)
    );

    setupSettingsScreen("Impostazioni", wrapper);
}

function mostraImpostazioniSchermo() {
    appState.settingsPanel = "screen";

    const panel = document.createElement("section");
    panel.className = "settings-panel";

    const description = document.createElement("p");
    description.className = "settings-panel__description";
    description.textContent = "Scegli l'aspetto dell'app.";

    const choices = document.createElement("div");
    choices.className = "theme-choices";

    [
        ["light", "Modalità chiara"],
        ["dark", "Modalità scura"]
    ].forEach(function ([theme, label]) {
        const choice = document.createElement("button");
        choice.type = "button";
        choice.className = "theme-choice";
        choice.dataset.theme = theme;
        choice.textContent = label;
        choice.setAttribute("aria-pressed", String(getTheme() === theme));
        choice.addEventListener("click", function () {
            applyTheme(theme);
            choices.querySelectorAll(".theme-choice").forEach(function (button) {
                button.setAttribute("aria-pressed", String(button.dataset.theme === theme));
            });
        });
        choices.appendChild(choice);
    });

    panel.append(description, choices);
    setupSettingsScreen("Schermo", panel);
}

function mostraImpostazioniNotifiche() {
    appState.settingsPanel = "notifications";

    const panel = document.createElement("section");
    panel.className = "settings-panel";
    const row = document.createElement("label");
    row.className = "notification-setting";
    const text = document.createElement("span");
    text.textContent = "Attiva notifiche";
    const toggle = document.createElement("input");
    toggle.type = "checkbox";
    toggle.className = "notification-setting__toggle";
    toggle.setAttribute("role", "switch");
    toggle.setAttribute("aria-label", "Attiva notifiche");
    toggle.disabled = true;
    const slider = document.createElement("span");
    slider.className = "notification-setting__slider";
    row.append(text, toggle, slider);
    panel.appendChild(row);
    setupSettingsScreen("Notifiche", panel);

    const refresh = async function () {
        try {
            toggle.checked = await window.appNotifications.isEnabled();
        } catch (error) {
            console.error("Errore nel controllo notifiche", error);
            toggle.checked = false;
        } finally {
            toggle.disabled = false;
        }
    };

    toggle.addEventListener("change", async function () {
        toggle.disabled = true;
        try {
            if (toggle.checked) {
                await window.appNotifications.enable();
            } else {
                await window.appNotifications.disable();
            }
        } catch (error) {
            console.error("Errore aggiornamento notifiche", error);
            showAppToast("Non riesco ad aggiornare le notifiche");
        } finally {
            await refresh();
        }
    });

    window.addEventListener("app:push-state", function updateState(event) {
        toggle.checked = !!event.detail.enabled;
    }, { once: true });
    refresh();
}

applyTheme(getTheme());

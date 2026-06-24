const PROFILE_AVATAR_ENDPOINT = "connection_files/update_avatar.php";

function getAvailableAvatars() {
    const avatars = window.appBootstrap?.avatars;
    return Array.isArray(avatars) && avatars.length ? avatars : ["default"];
}

function getAvatarImagePath(avatar) {
    return "img/" + avatar + ".png";
}

function syncCurrentAvatar(avatar) {
    window.avatar = avatar;
    if (window.userSession) {
        window.userSession.avatar = avatar;
    }

    const profileImg = document.getElementById("profileImg");
    if (profileImg) {
        profileImg.src = getAvatarImagePath(avatar);
    }
}

async function saveProfileAvatar(avatar) {
    const body = new URLSearchParams();
    body.set("avatar", avatar);
    body.set("csrf_token", window.appCsrfToken || "");

    const response = await fetch(PROFILE_AVATAR_ENDPOINT, {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
        },
        body: body.toString(),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.ok) {
        throw new Error(data.error || "Non riesco ad aggiornare l'immagine profilo");
    }
    return data;
}

function updateAvatarChoiceSelection(choices, activeAvatar) {
    choices.querySelectorAll(".profile-avatar-choice").forEach((button) => {
        button.setAttribute("aria-pressed", String(button.dataset.avatar === activeAvatar));
    });
}

function createAvatarChoices(avatarPreview) {
    const choices = document.createElement("div");
    choices.className = "profile-avatar-choices";

    const status = document.createElement("p");
    status.className = "profile-avatar-choices__status";
    status.hidden = true;

    const activeAvatar = String(window.userSession?.avatar || window.avatar || "default");

    getAvailableAvatars().forEach((avatar) => {
        const choice = document.createElement("button");
        choice.type = "button";
        choice.className = "profile-avatar-choice";
        choice.dataset.avatar = avatar;
        choice.setAttribute("aria-pressed", String(avatar === activeAvatar));
        choice.setAttribute("aria-label", "Seleziona " + avatar);

        const image = document.createElement("img");
        image.src = getAvatarImagePath(avatar);
        image.alt = "";
        image.width = 72;
        image.height = 72;
        image.loading = "lazy";

        choice.appendChild(image);
        choice.addEventListener("click", async () => {
            if (choice.dataset.avatar === String(window.userSession?.avatar || window.avatar || "default")) {
                status.hidden = false;
                status.textContent = "Stai già usando questa immagine.";
                return;
            }

            status.hidden = false;
            status.textContent = "Salvataggio in corso...";
            choices.querySelectorAll(".profile-avatar-choice").forEach((button) => {
                button.disabled = true;
            });

            try {
                const data = await saveProfileAvatar(choice.dataset.avatar);
                syncCurrentAvatar(data.avatar);
                avatarPreview.src = data.avatar_url;
                updateAvatarChoiceSelection(choices, data.avatar);
                status.textContent = "Immagine profilo aggiornata.";
                showAppToast("Immagine profilo aggiornata");
            } catch (error) {
                status.textContent = error.message || "Non riesco ad aggiornare l'immagine profilo";
            } finally {
                choices.querySelectorAll(".profile-avatar-choice").forEach((button) => {
                    button.disabled = false;
                });
            }
        });

        choices.appendChild(choice);
    });

    choices.appendChild(status);
    return choices;
}

function mostraProfilo() {
    showCalendarShell();
    setVista("calendario vista-profilo mt-4", "Utente");
    appState.view = "profilo";

    const wrapper = document.createElement("section");
    wrapper.className = "profile-card";

    const header = document.createElement("div");
    header.className = "profile-card__header";

    const avatarButton = document.createElement("button");
    avatarButton.type = "button";
    avatarButton.className = "profile-card__avatar";

    const avatarImg = document.createElement("img");
    avatarImg.src = getAvatarImagePath(String(window.userSession?.avatar || window.avatar || "default"));
    avatarImg.alt = "Avatar profilo";
    avatarImg.width = 88;
    avatarImg.height = 88;
    avatarButton.appendChild(avatarImg);

    const titleGroup = document.createElement("div");
    titleGroup.className = "profile-card__title";
    const title = document.createElement("h1");
    title.textContent = (userSession.nome || "") + " " + (userSession.cognome || "");
    const subtitle = document.createElement("p");
    subtitle.textContent = "Gestisci i dettagli base del tuo profilo.";
    titleGroup.append(title, subtitle);
    header.append(avatarButton, titleGroup);

    const reparti = window.appBootstrap?.departments || {};
    const reparto = reparti[userSession.reparto] || userSession.reparto;
    let ruolo = "Ruolo non definito";
    switch (Number(userSession.capo)) {
        case 0:
            ruolo = "Addetto alle vendite";
            break;
        case 1:
            ruolo = "Capo Reparto";
            break;
        case 2:
            ruolo = "Vice Capo";
            break;
        case 3:
            ruolo = "Admin";
            break;
    }

    const infoGrid = document.createElement("div");
    infoGrid.className = "profile-card__info";
    [
        ["Ruolo", ruolo],
        ["Reparto", reparto || "-"],
    ].forEach(([label, value]) => {
        const item = document.createElement("div");
        item.className = "profile-card__info-item";
        const itemLabel = document.createElement("span");
        itemLabel.className = "profile-card__info-label";
        itemLabel.textContent = label;
        const itemValue = document.createElement("strong");
        itemValue.className = "profile-card__info-value";
        itemValue.textContent = value;
        item.append(itemLabel, itemValue);
        infoGrid.appendChild(item);
    });

    const actions = document.createElement("div");
    actions.className = "profile-card__actions";
    const imageButton = document.createElement("button");
    imageButton.type = "button";
    imageButton.className = "btn btn-outline-primary";
    imageButton.textContent = "Immagine";
    actions.appendChild(imageButton);

    const chooser = document.createElement("section");
    chooser.className = "profile-avatar-picker";
    chooser.hidden = true;

    const chooserTitle = document.createElement("h2");
    chooserTitle.className = "profile-avatar-picker__title";
    chooserTitle.textContent = "Scegli la tua immagine";

    const chooserIntro = document.createElement("p");
    chooserIntro.className = "profile-avatar-picker__intro";
    chooserIntro.textContent = "Seleziona uno degli avatar disponibili: l'aggiornamento viene salvato subito.";

    chooser.append(chooserTitle, chooserIntro, createAvatarChoices(avatarImg));

    imageButton.addEventListener("click", () => {
        chooser.hidden = !chooser.hidden;
        imageButton.textContent = chooser.hidden ? "Immagine" : "Chiudi immagini";
    });
    avatarButton.addEventListener("click", () => {
        chooser.hidden = false;
        imageButton.textContent = "Chiudi immagini";
    });

    wrapper.append(header, infoGrid, actions, chooser);

    container.innerHTML = "";
    container.appendChild(wrapper);
}

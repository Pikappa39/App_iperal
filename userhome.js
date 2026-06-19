function mostraProfilo() {
    showCalendarShell();
    setVista("calendario vista-note-admin mt-4", "Utente");
    appState.view = "profilo";

    const wrapper = document.createElement("div");
    wrapper.classList.add(
        "d-flex",
        "flex-column",
        "align-items-center",
        "justify-content-center",
        "gap-2"
    );

    const user = getCurrentUser();

    // --- MAPPATURA REPARTI ---
    const reparti = {
        gro: "Grocery",
        ls: "Freschi libero servizio",
        orto: "Ortofrutta",
        cs: "Casse",
        box: "Box",
        drv: "Drive",
        gas: "Gastronomia/Panetteria",
        mac: "Macelleria"
    };

    const reparto = reparti[userSession.reparto] || userSession.reparto;
    let ruolo;
    switch(userSession.capo){
        case 0:
            ruolo="Addetto alle vendite";
            break;
        case 1:
            ruolo="Capo Reparto";
            break;
        case 2:
            ruolo="Vice Capo";
            break;
        case 3:
            ruolo="Admin"
    }

    // --- TITOLO ---
    const titolo = document.createElement("h1");
    titolo.innerText = userSession.nome + " " + userSession.cognome;

    // --- INFO ---
    const info = document.createElement("div");
    info.innerHTML = `
        Ruolo: ${ruolo}<br>
        Reparto: ${reparto}
    `;

    // --- AVATAR ---
    const avatarBtn = document.createElement("button");
    avatarBtn.classList.add(
        "rounded-circle",
        "p-0",
        "border-0",
        "d-flex",
        "align-items-center",
        "justify-content-center"
    );
    avatarBtn.style.height = "80px";
    avatarBtn.style.width = "80px";

    const avatarImg = document.createElement("img");
    avatarImg.classList.add("w-100", "h-100");
    avatarImg.style.objectFit = "cover";
    avatarImg.src = "img/" + userSession.avatar + ".png";

    avatarBtn.appendChild(avatarImg);

    // --- APPEND ---
    wrapper.appendChild(avatarBtn);
    wrapper.appendChild(titolo);
    wrapper.appendChild(info);

    container.innerHTML = "";
    container.appendChild(wrapper);
}
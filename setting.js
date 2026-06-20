function mostrasetting() {
    showCalendarShell();
    setVista("calendario vista-note-admin mt-4", "Utente");
    appState.view = "setting";

    container.innerHTML = "";
    container.hidden = false;
    container.classList.remove("app-hidden");

    const wrapper = document.createElement("div");
    wrapper.classList.add(
        "d-flex",
        "flex-column",
        "align-items-center",
        "justify-content-center",
        "gap-2"
    );

    const imp = ["Schermo", "Notifiche", "Lingua"];

    imp.forEach(element => {
        const imp_btn = document.createElement("button");
        imp_btn.innerText = element; // ✔ parola giusta
        wrapper.appendChild(imp_btn); // ✔ append bottone
    });

    container.appendChild(wrapper); // ✔ append wrapper
}




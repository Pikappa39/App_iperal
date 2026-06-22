back.onclick = function () {
    if (appState.view === "home") {
        return;
    }

    if (appState.view === "anni") {
        alert("non puoi andare piu indietro di cosi");
        return;
    }

    if (appState.view === "mesi") {
        mostraAnni();
        return;
    }

    if (appState.view === "giorni") {
        mostraMesi(appState.currentYear);
        return;
    }

    if (appState.view === "giorno") {
        mostraGiorni(appState.currentYear, appState.currentMonth);
        return;
    }

    if (appState.view === "noteAdmin") {
        showHomeScreen();
    }
    if (appState.view === "profilo") {
        showHomeScreen();
    }
    if (appState.view === "scheduleChanges") {
        showHomeScreen();
    }
    if (appState.view === "communications") {
        showHomeScreen();
    }
    if (appState.view === "setting") {
        if (appState.settingsPanel && appState.settingsPanel !== "main") {
            mostrasetting();
            return;
        }
        showHomeScreen();
    }
};

if (openOrari) {
    openOrari.addEventListener("click", () => {
        appState.currentYear = today.getFullYear();
        appState.currentMonth = today.getMonth() + 1;
        mostraGiorni(appState.currentYear, appState.currentMonth);
    });
}

if (homeBtn) {
    homeBtn.addEventListener("click", showHomeScreen);
}

if (noteAdminItem) {
    noteAdminItem.addEventListener("click", () => {
        mostraNoteAdmin();
    });
}
if (scheduleChangesItem) {
    scheduleChangesItem.addEventListener("click", () => {
        mostraModificheOrari();
    });
}
if (communicationsItem) {
    communicationsItem.addEventListener("click", () => {
        mostraComunicazioni();
    });
}
if(profileItem){
    profileItem.addEventListener("click", () => {
        mostraProfilo();
    });
}

if(setting){
    setting.addEventListener("click" ,()=>{
        mostrasetting();
    })
}

showHomeScreen();
if (window.openScheduleChangesFromUrl) {
    window.openScheduleChangesFromUrl();
}
if (new URLSearchParams(window.location.search).get("communications") === "1") {
    window.history.replaceState({}, document.title, window.location.pathname);
    mostraComunicazioni();
}

appNavigationInitialize();

back.onclick = function () {
    appNavigationGoBack();
};

if (openOrari) {
    openOrari.addEventListener("click", () => {
        if (!getCurrentUserKey()) {
            window.location.assign("login_reg.php");
            return;
        }
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

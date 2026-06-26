back.onclick = function () {
    appNavigationGoBack();
};

if (openOrari) {
    openOrari.addEventListener("click", () => {
        if (!getCurrentUserKey()) {
            window.location.assign("login_reg.php");
            return;
        }
        appRunWithBusyElement(openOrari, async () => {
            appState.currentYear = today.getFullYear();
            appState.currentMonth = today.getMonth() + 1;
            await mostraGiorni(appState.currentYear, appState.currentMonth);
        }, "Apro...");
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
        appRunWithBusyElement(scheduleChangesItem, () => mostraModificheOrari(), "Apro...");
    });
}
if (scheduleAdjustmentsItem) {
    scheduleAdjustmentsItem.addEventListener("click", () => {
        appRunWithBusyElement(scheduleAdjustmentsItem, () => mostraRichiesteOre(), "Apro...");
    });
}
if (departmentOverviewItem) {
    departmentOverviewItem.addEventListener("click", () => {
        appRunWithBusyElement(departmentOverviewItem, async () => {
            const currentWeek = getIsoWeekInfo(today);
            await mostraPanoramicaReparto(currentWeek.year, currentWeek.week);
        }, "Apro...");
    });
}
if (communicationsItem) {
    communicationsItem.addEventListener("click", () => {
        appRunWithBusyElement(communicationsItem, () => mostraComunicazioni(), "Apro...");
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

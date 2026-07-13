back.onclick = function () {
    appNavigationGoBack();
};

if (openOrari) {
    openOrari.addEventListener("click", () => {
        appRunWithBusyElement(openOrari, async () => {
            await appLoadFeature("calendar");
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
        appRunWithBusyElement(noteAdminItem, async () => {
            await appLoadFeature("notes");
            await mostraNoteAdmin();
        }, "Apro...");
    });
}
if (scheduleChangesItem) {
    scheduleChangesItem.addEventListener("click", () => {
        appRunWithBusyElement(scheduleChangesItem, async () => {
            await appLoadFeature("scheduleChanges");
            await mostraModificheOrari();
        }, "Apro...");
    });
}
if (scheduleAdjustmentsItem) {
    scheduleAdjustmentsItem.addEventListener("click", () => {
        appRunWithBusyElement(scheduleAdjustmentsItem, async () => {
            await appLoadFeature("adjustments");
            await mostraRichiesteOre();
        }, "Apro...");
    });
}
if (personalHolidaysItem) {
    personalHolidaysItem.addEventListener("click", () => {
        appRunWithBusyElement(personalHolidaysItem, async () => {
            await appLoadFeature("holidays");
            await mostraFeriePersonali();
        }, "Apro...");
    });
}
if (departmentHolidaysItem) {
    departmentHolidaysItem.addEventListener("click", () => {
        appRunWithBusyElement(departmentHolidaysItem, async () => {
            await appLoadFeature("holidays");
            await mostraElencoFerie();
        }, "Apro...");
    });
}
if (departmentOverviewItem) {
    departmentOverviewItem.addEventListener("click", () => {
        appRunWithBusyElement(departmentOverviewItem, async () => {
            await appLoadFeature("departmentOverview");
            const currentWeek = getIsoWeekInfo(today);
            await mostraPanoramicaReparto(currentWeek.year, currentWeek.week);
        }, "Apro...");
    });
}
if (customerOrdersItem) {
    customerOrdersItem.addEventListener("click", () => {
        appRunWithBusyElement(customerOrdersItem, async () => {
            await appLoadFeature("customerOrders");
            await mostraOrdiniClienti();
        }, "Apro...");
    });
}
if (holidayCampaignItem) {
    holidayCampaignItem.addEventListener("click", () => {
        appRunWithBusyElement(holidayCampaignItem, async () => {
            await appLoadFeature("holidays");
            await mostraAttivitaFerie();
        }, "Apro...");
    });
}
if (communicationsItem) {
    communicationsItem.addEventListener("click", () => {
        appRunWithBusyElement(communicationsItem, async () => {
            await appLoadFeature("communications");
            await mostraComunicazioni();
        }, "Apro...");
    });
}
if(profileItem){
    profileItem.addEventListener("click", () => {
        appRunWithBusyElement(profileItem, async () => {
            await appLoadFeature("profile");
            mostraProfilo();
        }, "Apro...");
    });
}

if(setting){
    setting.addEventListener("click" ,()=>{
        appRunWithBusyElement(setting, async () => {
            await appLoadFeature("settings");
            mostrasetting();
        }, "Apro...");
    })
}

showHomeScreen();
const startupParams = new URLSearchParams(window.location.search);
if (startupParams.get("changes") === "1") {
    appLoadFeature("scheduleChanges").then(() => window.openScheduleChangesFromUrl?.());
}
if (startupParams.get("communications") === "1") {
    window.history.replaceState({}, document.title, window.location.pathname);
    appLoadFeature("communications").then(() => mostraComunicazioni());
}
if (startupParams.get("adjustments") === "1") {
    window.history.replaceState({}, document.title, window.location.pathname);
    appLoadFeature("adjustments").then(() => mostraRichiesteOre());
}
if (startupParams.get("orders") === "1") {
    window.history.replaceState({}, document.title, window.location.pathname);
    appLoadFeature("customerOrders").then(() => mostraOrdiniClienti());
}
if (startupParams.get("orari") === "1") {
    window.history.replaceState({}, document.title, window.location.pathname);
    appLoadFeature("calendar").then(() => {
        appState.currentYear = today.getFullYear();
        appState.currentMonth = today.getMonth() + 1;
        return mostraGiorni(appState.currentYear, appState.currentMonth);
    });
}

appNavigationInitialize();

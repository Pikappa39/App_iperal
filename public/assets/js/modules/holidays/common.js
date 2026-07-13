// Modulo ferie: costanti, date e componenti UI condivisi.
const HOLIDAYS_ENDPOINT = "connection_files/holidays.php";
const HOLIDAY_CAMPAIGN_ENDPOINT = "connection_files/holiday_campaign.php";

function createHolidayPlaceholder(kind) {
    const panel = document.createElement("section");
    panel.className = "holiday-placeholder";
    const badge = document.createElement("span");
    badge.className = "holiday-placeholder__badge";
    badge.textContent = kind === "department" ? "Reparto" : "Personale";
    const heading = document.createElement("h2");
    heading.className = "holiday-placeholder__title";
    heading.textContent = kind === "department" ? "Elenco ferie" : "Ferie personali";
    const text = document.createElement("p");
    text.className = "holiday-placeholder__text";
    text.textContent = kind === "department"
        ? "Qui vedrai le ferie degli addetti del reparto, con filtri e stato richieste."
        : "Qui potrai consultare le tue ferie e preparare le richieste quando collegheremo la gestione dati.";
    const status = document.createElement("div");
    status.className = "holiday-placeholder__status";
    status.textContent = "Schermata pronta per il prossimo passo.";
    panel.append(badge, heading, text, status);
    return panel;
}

function getMondayOfIsoWeek(isoYear, isoWeek) {
    const januaryFourth = new Date(isoYear, 0, 4);
    const day = januaryFourth.getDay() || 7;
    const monday = new Date(januaryFourth);
    monday.setDate(januaryFourth.getDate() - day + 1 + ((isoWeek - 1) * 7));
    monday.setHours(0, 0, 0, 0);
    return monday;
}

function getIsoWeeksInYear(anno) {
    return getIsoWeekInfo(new Date(anno, 11, 28)).week;
}

function formatHolidayWeekRange(startDate, endDate) {
    const sameMonth = startDate.getMonth() === endDate.getMonth();
    const sameYear = startDate.getFullYear() === endDate.getFullYear();
    const startOptions = sameMonth && sameYear ? { day: "2-digit" } : { day: "2-digit", month: "short" };
    const endOptions = sameYear ? { day: "2-digit", month: "short" } : { day: "2-digit", month: "short", year: "numeric" };
    return startDate.toLocaleDateString("it-IT", startOptions) + " - " + endDate.toLocaleDateString("it-IT", endOptions);
}

function getAdjacentHolidayYear(anno, direction) {
    const minYear = YEAR_CHOICES[0];
    const maxYear = YEAR_CHOICES[YEAR_CHOICES.length - 1];
    return Math.min(maxYear, Math.max(minYear, anno + direction));
}

function createHolidayYearNavigation(anno, onChange) {
    const navigation = document.createElement("nav");
    navigation.className = "calendar-navigation holiday-year-navigation";
    navigation.setAttribute("aria-label", "Navigazione anno ferie");
    const previous = document.createElement("button");
    previous.type = "button";
    previous.className = "calendar-navigation__arrow";
    previous.textContent = "\u2039";
    previous.setAttribute("aria-label", "Anno precedente");
    previous.disabled = anno <= YEAR_CHOICES[0];
    previous.addEventListener("click", () => onChange(getAdjacentHolidayYear(anno, -1)));
    const label = document.createElement("div");
    label.className = "calendar-navigation__label";
    const yearLabel = document.createElement("strong");
    yearLabel.textContent = anno;
    label.appendChild(yearLabel);
    const next = document.createElement("button");
    next.type = "button";
    next.className = "calendar-navigation__arrow";
    next.textContent = "\u203A";
    next.setAttribute("aria-label", "Anno successivo");
    next.disabled = anno >= YEAR_CHOICES[YEAR_CHOICES.length - 1];
    next.addEventListener("click", () => onChange(getAdjacentHolidayYear(anno, 1)));
    navigation.append(previous, label, next);
    return navigation;
}

function closeHolidayPanel() {
    const panel = document.querySelector(".holiday-panel-backdrop");
    if (panel) panel.remove();
}

function createHolidayPanelShell(titleText, subtitleText) {
    closeHolidayPanel();
    const backdrop = document.createElement("div");
    backdrop.className = "holiday-panel-backdrop";
    backdrop.addEventListener("click", (event) => { if (event.target === backdrop) closeHolidayPanel(); });
    const panel = document.createElement("section");
    panel.className = "holiday-panel";
    panel.setAttribute("role", "dialog");
    panel.setAttribute("aria-modal", "true");
    const header = document.createElement("header");
    header.className = "holiday-panel__header";
    const titleGroup = document.createElement("div");
    const title = document.createElement("h2");
    title.textContent = titleText;
    const subtitle = document.createElement("p");
    subtitle.textContent = subtitleText;
    titleGroup.append(title, subtitle);
    const close = document.createElement("button");
    close.type = "button";
    close.className = "holiday-panel__close";
    close.textContent = "\u00D7";
    close.setAttribute("aria-label", "Chiudi pannello ferie");
    close.addEventListener("click", closeHolidayPanel);
    header.append(titleGroup, close);
    const body = document.createElement("div");
    body.className = "holiday-panel__body";
    panel.append(header, body);
    backdrop.appendChild(panel);
    document.body.appendChild(backdrop);
    return body;
}

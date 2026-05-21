const container = document.getElementById("contenitore");
const titolo = document.getElementById("titolo");
const back = document.getElementById("backbtn");
let indice;
let annocorrente;

// 🔵 1. FUNZIONE ANNI (PARTE ALL'AVVIO)
function mostraAnni() {
    indice = "anni";
            container.classList.remove("vista-giorno", "griglia-mesi", "griglia-giorni");
    titolo.innerText = "Anni";
    container.className = "calendario griglia-anni mt-4";
    container.innerHTML = "";

    const anni = [2024, 2025, 2026];
    let i;
    let anno;
    let sfera;
    let div;

    for (i = 0; i < anni.length; i++) {
        anno = anni[i];

        sfera = document.createElement("div");
        div = document.createElement("div");

        sfera.classList.add("sfera");
        sfera.innerText = anno;

        sfera.setAttribute("data-anno", anno);
        sfera.onclick = function () {
            var annoScelto = parseInt(this.getAttribute("data-anno"), 10);
            mostraMesi(annoScelto);
            annocorrente = annoScelto;
        };

        container.appendChild(sfera);
        div.textContent = anno;
        container.appendChild(div);
    }
}

// 🟡 2. FUNZIONE MESI
function mostraMesi(anno) {
    indice = "mesi";7
            container.classList.remove("vista-giorno", "griglia-giorni", "griglia-anni");

    titolo.innerText = "Mesi " + anno;
    container.className = "calendario griglia-mesi mt-4";
    container.innerHTML = "";

    const mesi = ["Gen", "Feb", "Mar", "Apr", "Mag", "Giu",
                  "Lug", "Ago", "Set", "Ott", "Nov", "Dic"];

    let i;
    let mese;
    let numeroMese;
    let sfera;

    for (i = 0; i < mesi.length; i++) {
        mese = mesi[i];
        numeroMese = i + 1;

        sfera = document.createElement("div");
        sfera.classList.add("sfera");
        sfera.innerText = mese;

        sfera.setAttribute("data-mese", numeroMese);
        sfera.onclick = function () {
            var meseScelto = parseInt(this.getAttribute("data-mese"), 10);
            mostraGiorni(anno, meseScelto);
            annocorrente = anno;
        };

        container.appendChild(sfera);
    }
}

// Crea una sfera giorno e la aggiunge al contenitore
function creaSferaGiorno(numero, opaco, messaggioAlert, giorno_parola) {
    const sfera = document.createElement("div");
    sfera.classList.add("sfera");

    if (opaco === true) {
        sfera.classList.add("opaco");
    }
//inserisco il numero del giorno all'interno della sfera ed a capo del numero inserisco un tag <br> per andare a capo e mostrare il giorno della settimana sotto al numero del giorno
    sfera.innerHTML = numero+"<br>"+giorno_parola;

    if (messaggioAlert !== "") {
        var testoAlert = messaggioAlert;
        sfera.onclick = function () {
            alert(testoAlert);
        };
    }
    sfera.onclick=function(){
    mostragiorno();
    }
    container.appendChild(sfera);
}

// 🟢 3. FUNZIONE GIORNI
function mostraGiorni(anno, mese) {
        container.classList.remove("vista-giorno", "griglia-mesi", "griglia-anni");

    indice = "giorni";
    titolo.innerText = "Giorni " + mese + "/" + anno;
    container.className = "calendario griglia-giorni mt-4";
    container.innerHTML = "";

    // Giorno della settimana del 1° del mese (0 = domenica … 6 = sabato)
    let primoGiorno = new Date(anno, mese - 1, 1).getDay();

    // Convertiamo in calendario che parte da lunedì (0 = lunedì … 6 = domenica)
    if (primoGiorno === 0) {
        primoGiorno = 6;
    } else {
        primoGiorno = primoGiorno - 1;
    }

    const giorniNelMese = new Date(anno, mese, 0).getDate();
    // Questa sezione serve a verificare se iol mese precedente fa parte dell'anno
    //corrennte o no
    let mesePrec;
    let annoPrec;
    if (mese === 1) {
        mesePrec = 12;
        annoPrec = anno - 1;
    } else {
        mesePrec = mese - 1;
        annoPrec = anno;
    }

    const giorniMesePrec = new Date(annoPrec, mesePrec, 0).getDate();
    // Giorni del mese precedente (opachi)
    /*let i;
    let giorno;
    let messaggio;
*/
    for (i = 0; i < primoGiorno; i++) {
        giorno = giorniMesePrec - primoGiorno + 1 + i;
        messaggio = "Hai selezionato " + giorno + "/" + mesePrec + "/" + annoPrec;
        let data = new Date(annoPrec, mesePrec - 1, giorno);
        let giorno_parola = data.toLocaleDateString("it-IT", {
            weekday: "short"
        });
        creaSferaGiorno(giorno, true, messaggio, giorno_parola);
    }

    // Giorni del mese corrente
    for (i = 1; i <= giorniNelMese; i++) {
        messaggio = "Hai selezionato " + i + "/" + mese + "/" + anno;
        let data = new Date(anno, mese - 1, i);
        let giorno_parola = data.toLocaleDateString("it-IT", {
            weekday: "short"
        });
        creaSferaGiorno(i, false, messaggio, giorno_parola);
    }

    // Quante celle servono dopo l'ultimo giorno per chiudere l'ultima settimana
    const totaleCelle = primoGiorno + giorniNelMese;
    let resto = totaleCelle % 7;
    let celleDopo;

    if (resto === 0) {
        celleDopo = 0;
    } else {
        celleDopo = 7 - resto;
    }

    let meseSucc;
    let annoSucc;
    if (mese === 12) {
        meseSucc = 1;
        annoSucc = anno + 1;
    } else {
        meseSucc = mese + 1;
        annoSucc = anno;
    }

    // Giorni del mese successivo (opachi)
    for (i = 1; i <= celleDopo; i++) {
        messaggio = "Hai selezionato " + i + "/" + meseSucc + "/" + annoSucc;
        let data = new Date(annoSucc, meseSucc - 1, i);
        let giorno_parola = data.toLocaleDateString("it-IT", {
            weekday: "short"
        });
        creaSferaGiorno(i, true, messaggio, giorno_parola);
    }
}

//Questa funzione mostra un dettaglio di un giorno selezionato, mostrando una sezione note inseiribili dall'uiutente e un pulsante salva che mostra un alert con il testo inserito dall'utente
function mostragiorno(){
    indice="giorno";
    container.classList.remove("griglia-giorni", "griglia-mesi", "griglia-anni");
container.classList.add("vista-giorno");
container.innerHTML = "";
const textarea = document.createElement("textarea");
textarea.placeholder = "Inserisci le tue note qui...";
const salvaBtn = document.createElement("button");
salvaBtn.innerText = "Salva";
salvaBtn.classList.add("btn", "btn-primary");
container.appendChild(textarea);
container.appendChild(salvaBtn);

}
back.onclick = function () {
    if (indice === "anni") {
        alert("non puoi andare piu indietro di cosìiiii");
    }

    if (indice === "mesi") {
        mostraAnni();
    }

    if (indice === "giorni") {
        mostraMesi(annocorrente);
    }
    if (indice === "giorno") {
        mostraGiorni(mesecorrente);
    }
};

// 🔥 4. AVVIO DEL PROGRAMMA (FONDAMENTALE)
mostraAnni();

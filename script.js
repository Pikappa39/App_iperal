const container = document.getElementById("contenitore");
const titolo = document.getElementById("titolo");
const back = document.getElementById("backbtn");
let indice;
let annocorrente;
let mesecorrente;
let cacheSettimane = {};
//dichiarazione variabile per prendere il json con i dati del calendario
let calendarioData;

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
//ciclo for per creare le sfere degli anni, la i  è un indice che scorre gli elementi dell'array anni
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
//ciclo for analogo a quello degli anni
    for (i = 0; i < mesi.length; i++) {
        mese = mesi[i];
        numeroMese = i + 1;

        sfera = document.createElement("div");
        sfera.classList.add("sfera");
        sfera.innerText = mese;

        sfera.setAttribute("data-mese", numeroMese);
        sfera.onclick = function () {
            var meseScelto = parseInt(this.getAttribute("data-mese"), 10);
            mostraGiorni(anno, meseScelto, );
            annocorrente = anno;
            mesecorrente = meseScelto;
        };

        container.appendChild(sfera);
    }
}

// Crea una sfera giorno e la aggiunge al contenitore
 function creaSferaGiorno(numero, opaco,giorno_parola, settimana,orario) {
    const sfera = document.createElement("div");
    sfera.classList.add("sfera");
//inserisco il numero del giorno all'interno della sfera ed a capo del numero inserisco un tag <br> per andare a capo e mostrare il giorno della settimana sotto al numero del giorno
    sfera.innerHTML = numero+"<br>"+giorno_parola+"<br>"+ settimana+ "<br>" + orario;
    sfera.onclick=function(){
        mostragiorno();
    }
    container.appendChild(sfera);
}

// 🟢 3. FUNZIONE GIORNI richiamata dalla funzione dei mesi
 async function mostraGiorni(anno, mese) {
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
    // Questa sezione serve a verificare se il mese precedente fa parte dell'anno
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
//costante che  indica quanti giorni ha il mese precedente
    const giorniMesePrec = new Date(annoPrec, mesePrec, 0).getDate();

    for (i = 0; i < primoGiorno; i++) {
        giorno = giorniMesePrec - primoGiorno + 1 + i;
        let data = new Date(annoPrec, mesePrec - 1, giorno);
        let giorno_parola = data.toLocaleDateString("it-IT", {
            weekday: "long"
        });
        let settimana =  getWeekNumber(data);
        let orario = await mostraOrari(settimana, giorno_parola);
        console.log("ORARIO GIORNO PRECEDENTE", orario);
        creaSferaGiorno(giorno, true, giorno_parola, settimana, orario);
    }

    // Giorni del mese corrente
    for (i = 1; i <= giorniNelMese; i++) {
        messaggio = "Hai selezionato " + i + "/" + mese + "/" + anno;
        let data = new Date(anno, mese - 1, i);
        let settimana= getWeekNumber(data);
        let giorno_parola = data.toLocaleDateString("it-IT", {
            weekday: "long"
        });
        let orario = await mostraOrari(settimana, giorno_parola);
        creaSferaGiorno(i, false, giorno_parola, settimana, orario);
        //mostraOrari(settimana);
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
            weekday: "long"
        });
        let settimana = getWeekNumber(data);
        let orario = await mostraOrari(settimana, giorno_parola);
        console.log("ORARIO GIORNO SUCCESSIVO"+ orario);
        creaSferaGiorno(i, true, giorno_parola, settimana, orario);
    }
}
//funzione per calcolare il numero della settimana di una data, utilizzando la formula del calendario ISO 8601
 function getWeekNumber(date) {
    const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));

    const dayNum = d.getUTCDay() || 7;
    d.setUTCDate(d.getUTCDate() + 4 - dayNum);

    const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));

    return Math.ceil((((d - yearStart) / 86400000) + 1) / 7);
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
        mostraGiorni(annocorrente,mesecorrente);
    }
};


//funzione che prende i dati del json del calendario in base al nome utente e al numero settimana e mostra gli orari nelle sfere di mostragiorni()




    async function mostraOrari(Nsettimana, giorno_parola) {
    try {
        // Attendiamo che la fetch finisca
        const response = await fetch("connection_files/" + Nsettimana + ".json");
        
        // Attendiamo che il JSON venga estratto
        const data = await response.json();
        
        const user = window.userSession.toUpperCase();
        //due console log per verificare che il nome utente venga preso correttamente e che sia in maiuscolo e combaci con quello del json
        const soloAddetto = data.find(riga => riga.ADDETTO.toUpperCase().trim() === user.trim());
        console.log(soloAddetto, "OOOOOOOO");
        // Trasformiamo il giorno da "ven" a "venerdì" (se nel JSON hai i giorni interi)
        console.log("SOLO ADDETTO TROVATO:", soloAddetto.ADDETTO, soloAddetto[giorno_parola]);
       if (soloAddetto && soloAddetto[giorno_parola]) {
            console.log("ORARIO TROVATO:", soloAddetto[giorno_parola]);
            
            // ADESSO il return lancia il valore fuori dalla funzione!
            return soloAddetto[giorno_parola]; 
        }
        else{ console.log("Non trovato orario per utente o giorno:", user, giorno_parola);
            return "";
        }
         // Ritorna vuoto se non trova l'orario o l'addetto
    
}
   
         catch (e) {
        console.error("Errore nel caricamento del file della settimana", e);
        return "";
    }
}
// 🔥 4. AVVIO DEL PROGRAMMA (FONDAMENTALE)
mostraAnni();


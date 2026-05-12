const container = document.getElementById("contenitore");
const titolo = document.getElementById("titolo");
const back=document.getElementById("backbtn");
let indice;
let annocorrente;
// 🔵 1. FUNZIONE ANNI (PARTE ALL'AVVIO)
function mostraAnni() {
    indice="anni";
    titolo.innerText = "Anni";
    container.innerHTML = ""; // pulisce

    const anni = [2024, 2025, 2026];

    anni.forEach(anno => {

        const sfera = document.createElement("div");
        let div= document.createElement("div");
        
        sfera.classList.add("sfera");
        sfera.innerText = anno;

        // 👇 QUI parte la funzione mesi
        sfera.onclick = () => {
            mostraMesi(anno);
            annocorrente= anno;
        };
        container.appendChild(sfera);div.textContent=anno;
        container.appendChild(div);
    });
}


// 🟡 2. FUNZIONE MESI
function mostraMesi(anno) {
    indice="mesi";
    titolo.innerText = "Mesi " + anno;
    container.innerHTML = "";
    anno=anno;
    const mesi = ["Gen", "Feb", "Mar", "Apr", "Mag", "Giu",
                  "Lug", "Ago", "Set", "Ott", "Nov", "Dic"];

    mesi.forEach((mese, index) => {
        
         let div= document.createElement("div");
        const sfera = document.createElement("div");
        sfera.classList.add("sfera");
        sfera.innerText = mese;

        // 👇 QUI parte giorni
        sfera.onclick = () =>{

         mostraGiorni(anno, index + 1);
        annocorrente=anno;
        };
        container.appendChild(sfera);
        container.appendChild(div);
    });
}


// 🟢 3. FUNZIONE GIORNI
function mostraGiorni(anno, mese) {
    indice="giorni";
    let primoGiorno = new Date(anno, mese - 1, 1).getDay();
    primoGiorno = (primoGiorno === 0) ? 6 : primoGiorno - 1;
    
    titolo.innerText = `Giorni ${mese}/${anno}`;
    anno=anno;
    container.innerHTML = "";
    const giorni = new Date(anno, mese, 0).getDate();
       let data= new Date (anno,mese-1, 1);
       let giorno= data.toLocaleDateString("it-IT",{
        weekday: "long"});

        alert (giorno);
   /*for (let i = 1; i <= giorni; i++) {
        let data= new Date (anno,mese-1, 1);
        alert (data.getDay());
                let giornosettimana= data.toLocaleDateString("it-IT",{
        weekday:"short"
    });
    let data= new Date (anno,mese-1, 1);
        alert (data.getDay());
        const row= document.createElement("div");
        row.classList.add("row");
        const sfera = document.createElement("div");
        sfera.classList.add("sfera");
        sfera.innerText = i+ giornosettimana;

        sfera.onclick = () => {
            alert(`Hai selezionato ${i}/${mese}/${anno}`);
        };
        row.appendChild(sfera);
            container.appendChild(row);

        
        
    }*/
};
back.onclick=function(){
    if(indice==="anni"){
        alert("non puoi andare piu indietro di cosìiiii");
    }
    if (indice==="mesi"){
        mostraAnni(annocorrente);
    };
    if(indice==="giorni"){
        mostraMesi(annocorrente);
    }

};
document.addEventListener

// 🔥 4. AVVIO DEL PROGRAMMA (FONDAMENTALE)
mostraAnni();
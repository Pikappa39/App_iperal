const container = document.getElementById("contenitore");
const titolo = document.getElementById("titolo");
const back=document.getElementById("backbtn");

// 🔵 1. FUNZIONE ANNI (PARTE ALL'AVVIO)
function mostraAnni() {
    titolo.innerText = "Anni";
    container.innerHTML = ""; // pulisce

    const anni = [2024, 2025, 2026];

    anni.forEach(anno => {

        const sfera = document.createElement("div");
        sfera.classList.add("sfera");
        sfera.innerText = anno;

        // 👇 QUI parte la funzione mesi
        sfera.onclick = () => mostraMesi(anno);

        container.appendChild(sfera);
    });
}


// 🟡 2. FUNZIONE MESI
function mostraMesi(anno) {
    titolo.innerText = "Mesi " + anno;
    container.innerHTML = "";

    const mesi = ["Gen", "Feb", "Mar", "Apr", "Mag", "Giu",
                  "Lug", "Ago", "Set", "Ott", "Nov", "Dic"];

    mesi.forEach((mese, index) => {

        const sfera = document.createElement("div");
        sfera.classList.add("sfera");
        sfera.innerText = mese;

        // 👇 QUI parte giorni
        sfera.onclick = () => mostraGiorni(anno, index + 1);

        container.appendChild(sfera);
    });
}


// 🟢 3. FUNZIONE GIORNI
function mostraGiorni(anno, mese) {
    titolo.innerText = `Giorni ${mese}/${anno}`;
    container.innerHTML = "";

    const giorni = new Date(anno, mese, 0).getDate();

    for (let i = 1; i <= giorni; i++) {

        const sfera = document.createElement("div");
        sfera.classList.add("sfera");
        sfera.innerText = i;

        sfera.onclick = () => {
            alert(`Hai selezionato ${i}/${mese}/${anno}`);
        };

        container.appendChild(sfera);
    }
};
back.onclick=function(){
    alert ("opollo")

};
document.addEventListener

// 🔥 4. AVVIO DEL PROGRAMMA (FONDAMENTALE)
mostraAnni();
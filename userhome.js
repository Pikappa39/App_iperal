//file scrtto a mano commentato per comprendere meglio anche gli altri file
function mostraProfilo() {
    showCalendarShell();
setVista("calendario vista-note-admin mt-4", "Utente");
appState.view = "profilo";
const wrapper=document.createElement("div");
wrapper.classList.add(
    "d-flex",
    "flex-column",
    "align-items-center",
    "justify-content-center",
    "gap-2" // spazio tra elementi
);
const titolo=document.createElement("h1");
const ruolo="Addetto alla vendita";
const reparto="Grocery";
const data_assunzione="12/10/2022";
const user=getCurrentUser();
titolo.innerText=user;
const div = document.createElement("div");
div.innerHTML = "Ruolo: " + ruolo + "<br>Reparto: " + reparto;
const avatar_round = document.createElement("button");
avatar_round.classList.add(
    "rounded-circle",
    "p-0",
    "border-0",
    "d-flex",
    "align-items-center",
    "justify-content-center"
);
avatar_round.style.height = "80px";
avatar_round.style.width = "80px";

const avatarImg = document.createElement("img");
avatarImg.classList.add("w-100", "h-100");

avatarImg.src = "img/" + avatar + ".png";
avatarImg.style.objectFit = "cover";

avatar_round.appendChild(avatarImg);

if (avatar) {
    avatar_round.src = "img/" + avatar + ".png";
    wrapper.appendChild(avatar_round)
    wrapper.appendChild(titolo);
    wrapper.append(div);
}
container.appendChild(wrapper)
if(user){
    avatar=2;
}
}

async function ottienidati(){

}
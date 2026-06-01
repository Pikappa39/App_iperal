<?php
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="sfera.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
</head>
<body>
  <div class="container d-flex flex-column align-items-center mt-5 " id="welcome">
                <!-- form di login, visibile solo se non loggato, altrimenti mostrare un messaggio di benvenuto e un pulsante per il logout, se non loggato è visibile il form di registrazione -->
                <div class="container d-flex flex-column align-items-center mt-5 visible" id="login">
            <h1>Login</h1>
            <form id="loginForm" class="w-50">
              <div class="form-group">
                <label for="exampleInputEmail1">Email address</label>
                <input type="email" name="email" class="form-control" id="exampleInputEmail1" aria-describedby="emailHelp" placeholder="Enter email">
                <small id="emailHelp" class="form-text text-muted">We'll never share your email with anyone else.</small>
              </div>
              <div class="form-group">
                <label for="exampleInputPassword1">Password</label>
                <input type="password" name="password" class="form-control" id="exampleInputPassword1" placeholder="Password">
              </div>
              <div class="form-group form-check">
                <input type="checkbox" class="form-check-input" id="exampleCheck1">
                <label class="form-check-label" for="exampleCheck1">Check me out</label>
              </div>
              <button type="submit" class="btn btn-primary">Submit</button>
            </form>
            </div>



            <!-- form di registrazione -->
            <div class="container d-flex flex-column align-items-center mt-5 d-none" id="signup">
              <form id="signupForm" class="w-50">
                <div class="form-group">
                  <label for="exampleInputEmail1">Email address</label>
                  <input type="email" class="form-control" id="regmail" name="email" aria-describedby="emailHelp" placeholder="Enter email">
                  <input type="email" class="form-control mt-2" id="confmail" name="confirm_email" aria-describedby="emailHelp" placeholder="Conferma email">
                  <p id="error-message-email" class="text-danger" style="display: none;">EMAIL NON CORRISPONDENTI</p>
                  <label for="exampleInputPassword1" class="mt-3">Password</label>
                  <input type="password" class="form-control" id="regpass" name="password" placeholder="Password">
                  <input type="password" class="form-control mt-2" id="confpass" name="confirm_password" placeholder="Conferma password">
                  <p id="error-message-password" class="text-danger" style="display: none;">PASSWORD NON CORRISPONDENTI</p>
                </div>
                <label for="inputBadge" class="mt-3">Badge</label>
                <input type="text" class="form-control" id="inputBadge" name="badge"placeholder="Badge">
                <label for="inputCF" class="mt-3">Codice fiscale</label>
                <input type="text" class="form-control" id="inputCF" name="cf" placeholder="Codice fiscale">
                <label for="inputNomeCognome" class="mt-3">Nome e Cognome</label>
                <input type="text" class="form-control" name="nome" id="inputNome" placeholder="Nome">
                <input type="text" class="form-control mt-2"name="cognome" id="inputCognome" placeholder="Cognome">
                <button type="submit" class="btn btn-primary mt-3">Submit</button>

              </form>

            </div>
            
          <div class="btn-group mt-3" role="group" aria-label="Basic example">
            <button type="button" class="btn btn-secondary" id="showLogin">Login</button>
            <button type="button" class="btn btn-secondary" id="showSignup">Registrazione</button>
          </div>            

  </div>
</body>


<script>
   var checkMail=false;
  var checkPassword=false;
const formlogin=document.getElementById("login");
const formsignup=document.getElementById("signup");
const btnLogin=document.getElementById("showLogin");
const btnSignup=document.getElementById("showSignup");
document.addEventListener("DOMContentLoaded", function(){
  btnLogin.addEventListener("click", function(){
    formlogin.classList.remove("d-none");
    formsignup.classList.add("d-none");
  });
  btnSignup.addEventListener("click", function(){
    formlogin.classList.add("d-none");
    formsignup.classList.remove("d-none");
  })
})
const form = document.querySelector("#loginForm");

form.addEventListener("submit", function(e) {
    e.preventDefault();

    const formData = new FormData(this);

    fetch("connection_files/signin.php", {
        method: "POST",
        body: formData,
        cache: "no-cache"
    })
    .then(async (res) => {
        const testo = await res.text();
        if (!testo) {
            throw new Error("Risposta vuota dal server (HTTP " + res.status + ")");
        }
        let data;
        try {
            data = JSON.parse(testo);
            console.log(data);
        } catch {
            throw new Error("Risposta non valida: " + testo);
        }
        if (data.error_code && data.error) {
            console.error("[" + data.error_code + "] " + data.error);
        }
        return data;
    })
    .catch((err) => console.error(err.message));
});

const signupForm = document.querySelector("#signupForm");


//codice controllo password e email
const regmail = document.getElementById("regmail");
const confmail= document.getElementById('confmail');
const regpass= document.getElementById("regpass");
const confpass=document.getElementById("confpass");
const errorMessageEmail = document.getElementById("error-message-email");
const errorMessagePassword = document.getElementById("error-message-password");
signupForm.addEventListener("submit", function(e){
  console.log(confmail,regmail);
e.preventDefault();
if (regmail.value!=confmail.value){
  errorMessageEmail.style.display="block";
} else {
  errorMessageEmail.style.display="none";
  checkMail=true;
}
if(regpass.value!=confpass.value){
  errorMessagePassword.style.display="block";
}
else{
  errorMessagePassword.style.display="none";
  checkPassword=true;
}
});

signupForm.addEventListener("submit", function(e){
e.preventDefault();
const formData=new FormData(this);
if (checkMail && checkPassword){
  console.log("Invio dati al server...");
          fetch("connection_files/signup.php", {
              method: "POST",
              body: formData,
              cache: "no-cache"
          }).then(async (res) => {
              const testo = await res.text();
            console.log("Risposta dal server: " + testo);
          })
}
});

</script>
</html>
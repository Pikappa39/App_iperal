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
    <<!-- form di login, visibile solo se non loggato, altrimenti mostrare un messaggio di benvenuto e un pulsante per il logout, se non loggato è visibile il form di registrazione -->
    <div class="container d-flex flex-column align-items-center mt-5 invisible" id="login">
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



<<!-- form di registrazione -->
<div class="container d-flex flex-column align-items-center mt-5 invisible" id="signup">
  <h1>Registrazione</h1>
</div>
</body>
<script>
const form = document.querySelector("#loginForm");

form.addEventListener("submit", function(e) {
    e.preventDefault();

    const formData = new FormData(this);

    fetch("connection_files/signin.php", {
        method: "POST",
        body: formData,
        cache: "no-cache"
    })
    //.then(res => res.text())
    .then(data => data.json())
    .then(data => {
        console.log(data);
    });
});
</script>
</html>
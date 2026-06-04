<?php
//app per un calendario
session_start();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="sfera.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>La mia pagina</title>
</head>
<body>
<div class="container-fluid text-center mt-5">
  <h3 id="titolo">Anni</h3>
  



  
<nav class="navbar navbar-expand-lg navbar-light ">
  <div class="container-fluid justify-content-right">
    
    <?php 
    //var_dump($_SESSION);
    if (isset($_SESSION["user"])): ?>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNavAltMarkup" aria-controls="navbarNavAltMarkup" aria-expanded="false" aria-label="Toggle navigation">
             <img id="profileImg" src="img\default.png"
           width="40"
           height="40"
           class="rounded-circle"
           alt="Amenu">
    </button>
    <div class="collapse navbar-collapse" id="navbarNavAltMarkup">
      <div class="navbar-nav">
        <a class="nav-link active" aria-current="page" href="#">Profilo</a>
        <a class="nav-link" href="connection_files/logout.php">Logout</a>
        <a class="nav-link" href="#">Pricing</a>
        <a class="nav-link disabled" href="#" tabindex="-1" aria-disabled="true">Disabled</a>
      </div>
    </div>
  </div>
  <?php else: ?>
    <a href="login_reg.php" class="btn btn-primary">Login/Registrazione</a>
  <?php endif; ?>
  </nav>







  <div id="contenitore" class="calendario mt-4"></div>
  <button type="button" id="backbtn">Indietro</button>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="script.js"></script>
<script src="i_o_data.js"></script> 
<!--questo script prende il valore avatar da sessione e cambia 
l'immagine del profilo con quello avatar, se non è presente un avatar mostra un'immagine di default-->
<script>
    const avatar = "<?php echo $_SESSION['user']['avatar'] ?? 'img/avatar1.png'; ?>";
    const profileImg = document.querySelector("#profileImg");
    if (profileImg) {
        profileImg.src = "img/" + avatar + ".png";
    }
</script>
</body>
</html>
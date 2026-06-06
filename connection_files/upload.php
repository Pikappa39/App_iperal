<?php

// legge JSON inviato dal frontend
$data = file_get_contents("php://input");
$payload = json_decode($data, true);
// opzionale: verifica che non sia vuoto
if (!$payload) {
    die("Nessun dato ricevuto");
}
$settimana = $payload["settimana"] ?? "sconosciuta";
$orari = $payload["orari"] ?? [];
$filename =  $settimana . ".json";


// salva il JSON in un file
file_put_contents($filename, json_encode($orari));

echo "Salvato: " . $filename;
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo da riga di comando\n");
}

require __DIR__ . '/../app_config.php';
require __DIR__ . '/../connection_files/connection.php';
require __DIR__ . '/../connection_files/push_lib.php';

if (!$connessione || !($pdo instanceof PDO)) {
    throw new RuntimeException('Connessione al database non disponibile');
}

// Prima sostituiamo il segreto sul server, poi rendiamo inutilizzabili tutte
// le vecchie subscription. I browser con la nuova app le riconoscono e
// chiedono all'utente di riattivare le notifiche dalle impostazioni.
appPushRotateConfig();
$statement = $pdo->prepare(
    'UPDATE push_subscriptions SET active = 0, updated_at = CURRENT_TIMESTAMP WHERE active = 1'
);
$statement->execute();

echo 'Chiave VAPID sostituita. ' . $statement->rowCount()
    . " notifiche esistenti disattivate.\n";

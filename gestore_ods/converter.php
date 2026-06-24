<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo json_encode(['error' => 'Endpoint non disponibile via web']);
    exit;
}

// Gli orari devono transitare dall'upload autenticato: è l'unico percorso
// che crea una versione, aggiorna le associazioni e riconcilia le richieste.
fwrite(STDERR, "Conversione diretta disabilitata. Usa l'upload dall'app.\n");
exit(1);

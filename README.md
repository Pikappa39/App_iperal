# MyOrari

## Installazione e aggiornamento database

Configura le variabili in `app_local_env.php` oppure nell'ambiente del server, poi esegui una sola volta dal terminale del server:

```sh
php database/migrate.php
```

L'applicazione non modifica più lo schema database durante le richieste degli utenti. Esegui sempre un backup del database prima di una migrazione.

## Sicurezza dati

Le cartelle `storage`, `turni_json`, `note_json` e `xlms` devono restare non pubbliche. I turni sono erogati esclusivamente da `connection_files/schedule.php` agli utenti autenticati del rispettivo reparto.

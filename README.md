# MyOrari

## Installazione e aggiornamento database

Configura le variabili in `app_local_env.php` oppure nell'ambiente del server, poi esegui una sola volta dal terminale del server:

```sh
php database/migrate.php
```

L'applicazione non modifica più lo schema database durante le richieste degli utenti. Esegui sempre un backup del database prima di una migrazione.

## Sicurezza dati

Le cartelle `storage`, `turni_json`, `note_json` e `xlms` devono restare non pubbliche. I turni sono erogati esclusivamente da `connection_files/schedule.php` agli utenti autenticati del rispettivo reparto.

## Inviti account

Con `APP_ALLOW_SELF_REGISTRATION=0` gli account vengono creati tramite invito. I ruoli `capo=1` e `capo=3` possono aprire `addetti.php`, generare un link di attivazione e condividerlo manualmente con il dipendente. Il link resta valido per 7 giorni e, dopo l'attivazione, non può essere riutilizzato.

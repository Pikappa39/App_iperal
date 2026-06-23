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

Con `APP_ALLOW_SELF_REGISTRATION=0` gli account vengono creati tramite invito. I ruoli `capo=1` e `capo=3` possono aprire `addetti.php` e inviare via email un link personale di attivazione. Se l'invio SMTP non riesce, il responsabile può comunque copiare e condividere il link manualmente. Il link resta valido per 7 giorni e, dopo l'attivazione, non può essere riutilizzato.

## Versioni Git

Prima prepara con `git add` soltanto i file verificati. Poi il comando seguente incrementa `APP_VERSION`, crea un commit e aggiunge il tag Git corrispondente:

```sh
php scripts/release.php patch "Descrizione della correzione"
```

Usa `minor` per una nuova funzione e `major` per cambiamenti incompatibili. Aggiungi `--push` soltanto quando vuoi pubblicare commit e tag su `origin`:

```sh
php scripts/release.php minor "Nuova funzione" --push
```

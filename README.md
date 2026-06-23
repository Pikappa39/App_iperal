# MyOrari

## Installazione e aggiornamento database

Configura le variabili in `app_local_env.php` oppure nell'ambiente del server, poi esegui una sola volta dal terminale del server:

```sh
php database/migrate.php
```

L'applicazione non modifica più lo schema database durante le richieste degli utenti. Esegui sempre un backup del database prima di una migrazione.

## Sicurezza dati

Le cartelle `storage`, `turni_json`, `note_json` e `xlms` devono restare non pubbliche. I turni sono erogati esclusivamente da `connection_files/schedule.php` agli utenti autenticati del rispettivo reparto.

Su EC2 installa anche la configurazione Apache versionata, che protegge repository Git, dati, script e librerie anche nel caso in cui `.htaccess` non venga interpretato:

```sh
sudo cp deploy/apache/myorari-security.conf /etc/apache2/conf-available/myorari-security.conf
sudo a2enconf myorari-security
sudo a2enmod headers rewrite
sudo apache2ctl configtest
sudo systemctl reload apache2
```

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

## Backup automatici su Google Drive

Su EC2 configura prima il remote cifrato `myorari-crypt:` con rclone. Lo script `scripts/backup_myorari.sh` crea un dump MySQL coerente, archivia i file dell'applicazione (escludendo sessioni e `.git`), carica il risultato sul remote cifrato e rimuove i backup più vecchi di 30 giorni.

Esegui una prova manuale come utente `ubuntu`:

```sh
bash /var/www/html/App_iperal-1/scripts/backup_myorari.sh
```

Dopo una prova riuscita, pianifica l'esecuzione notturna alle 02:15 con `crontab -e`:

```cron
15 2 * * * /var/www/html/App_iperal-1/scripts/backup_myorari.sh >> /home/ubuntu/myorari-backup.log 2>&1
```

Il comando richiede che `ubuntu` possa eseguire `sudo mysqldump` senza password e che la configurazione rclone dell'utente `ubuntu` contenga il remote `myorari-crypt:`. Conserva con cura le credenziali rclone e la password del remote cifrato: servono per un eventuale ripristino.

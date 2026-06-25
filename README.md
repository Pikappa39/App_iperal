# MyOrari

Per i controlli prima e dopo un rilascio, consulta [TESTING.md](TESTING.md).

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

## Rotazione delle notifiche push

Se la chiave VAPID è stata esposta o va sostituita, distribuisci prima una versione dell'app che gestisce la rotazione. Poi, su EC2, esegui:

```sh
cd /var/www/html/App_iperal-1
php scripts/rotate_push_vapid.php
```

Il comando genera una nuova chiave privata sul server e disattiva le vecchie registrazioni. Al prossimo accesso l'app mostrerà le notifiche come disattivate: ciascun utente potrà riattivarle dalle impostazioni, senza dover reinstallare l'app.

## Inviti account

Con `APP_ALLOW_SELF_REGISTRATION=0` gli account vengono creati tramite invito. I ruoli `capo=1` e `capo=3` possono aprire `addetti.php` e inviare via email un link personale di attivazione. Se l'invio SMTP non riesce, il responsabile può comunque copiare e condividere il link manualmente. Il link resta valido per 7 giorni e, dopo l'attivazione, non può essere riutilizzato.

## Console admin

Gli utenti con `capo=3` possono accedere a `admin_console.php`, ma la sezione resta bloccata finché non viene inserito il codice console. Configura l'hash in `app_local_env.php` o nell'ambiente del server:

```sh
php -r "echo password_hash('codice-scelto', PASSWORD_DEFAULT) . PHP_EOL;"
```

Imposta il risultato come `APP_ADMIN_CONSOLE_CODE_HASH`. La sessione della console scade automaticamente dopo `APP_ADMIN_CONSOLE_TIMEOUT_SECONDS` secondi, con valore predefinito `900`.

La console registra le azioni amministrative in `admin_audit_log`; dopo il deploy esegui `php database/migrate.php` per creare la tabella se non esiste già.

Per mostrare in console l'ultimo backup, configura `APP_BACKUP_LOG_PATH` se il log non si trova nel percorso standard `/home/ubuntu/myorari-backup.log`.

## Versioni Git

Prima prepara con `git add` soltanto i file verificati. Poi il comando seguente incrementa `APP_VERSION`, crea un commit e aggiunge il tag Git corrispondente:

```sh
php scripts/release.php patch "Descrizione della correzione"
```

Usa `minor` per una nuova funzione e `major` per cambiamenti incompatibili. Aggiungi `--push` soltanto quando vuoi pubblicare commit e tag su `origin`:

```sh
php scripts/release.php minor "Nuova funzione" --push
```

## Verifica post-deploy

Dopo aver aggiornato il codice e ricaricato Apache su EC2, esegui il controllo HTTP automatico. Verifica home, login, CSS, manifest e service worker senza usare account o modificare dati. Per evitare che Cloudflare tratti il controllo eseguito da EC2 come un bot, per impostazione predefinita lo script contatta Apache in locale mantenendo hostname e HTTPS corretti:

```sh
cd /var/www/html/App_iperal-1
bash scripts/post_deploy_check.sh
```

Lo script termina con errore se un endpoint non risponde con `200` o con il tipo di contenuto previsto. Per verificare un ambiente diverso puoi passare l'URL completo come argomento:

```sh
bash scripts/post_deploy_check.sh https://staging.example.com
```

Per controllare il percorso pubblico attraverso Cloudflare, esegui lo script da una macchina esterna a EC2:

```sh
APP_HEALTHCHECK_PUBLIC=1 bash scripts/post_deploy_check.sh
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

### Verifica automatica del backup

Lo script seguente non scarica né modifica il backup: controlla sul remote cifrato che l'ultimo archivio esista, non sia vuoto e abbia al massimo 30 ore. Eseguilo come `ubuntu`:

```sh
bash /var/www/html/App_iperal-1/scripts/check_backup_myorari.sh
```

Pianificalo dopo il backup notturno, ad esempio alle 05:00:

```cron
0 5 * * * /var/www/html/App_iperal-1/scripts/check_backup_myorari.sh >> /home/ubuntu/myorari-backup-check.log 2>&1
```

Puoi cambiare il limite temporale senza modificare lo script:

```sh
MYORARI_BACKUP_MAX_AGE_HOURS=36 bash /var/www/html/App_iperal-1/scripts/check_backup_myorari.sh
```

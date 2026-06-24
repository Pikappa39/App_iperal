# Guida ai test operativi

Questa guida raccoglie le verifiche da eseguire senza modificare dati reali. I controlli automatici leggono lo stato dell'applicazione o del backup; le verifiche funzionali usano normali account dell'app.

## Prima del rilascio

Dalla cartella del progetto:

```sh
git status --short --branch
git diff --check
php -l app_config.php
php -l connection_files/schedule.php
node --check app_core.js
```

Esegui i controlli specifici dei file modificati. Per esempio, dopo una modifica al login:

```sh
php -l login_reg.php
php -l connection_files/signin.php
```

## Rilascio e controllo post-deploy

Su EC2, dopo il rilascio Git:

```sh
cd /var/www/html/App_iperal-1
git pull --ff-only origin main
sudo apache2ctl configtest
sudo systemctl reload apache2
bash scripts/post_deploy_check.sh
```

Il controllo post-deploy verifica in locale, senza attraversare Cloudflare, che home, login, CSS, manifest e service worker rispondano con `200` e con il tipo di contenuto previsto.

Per controllare invece il percorso pubblico attraverso Cloudflare, esegui da una macchina esterna a EC2:

```sh
APP_HEALTHCHECK_PUBLIC=1 bash scripts/post_deploy_check.sh https://myorari.it
```

## Verifica backup

Su EC2, come utente `ubuntu`:

```sh
cd /var/www/html/App_iperal-1
bash scripts/check_backup_myorari.sh
```

Il comando controlla che sul remote cifrato Google Drive esista un backup non vuoto e recente. Non scarica, non elimina e non modifica file.

Il backup notturno e la verifica possono essere programmati con `crontab -e`:

```cron
15 2 * * * /var/www/html/App_iperal-1/scripts/backup_myorari.sh >> /home/ubuntu/myorari-backup.log 2>&1
0 5 * * * /var/www/html/App_iperal-1/scripts/check_backup_myorari.sh >> /home/ubuntu/myorari-backup-check.log 2>&1
```

## Verifiche funzionali rapide

Esegui queste prove in una finestra anonima o con account separati:

1. Un admin accede e apre Gestione addetti.
2. Un addetto associato vede i propri turni.
3. Un addetto senza associazione vede il calendario ma solo `RIPOSO`.
4. Un nuovo invito viene attivato in un browser senza un altro account aperto.
5. Un caricamento con nominativi duplicati mostra varianti separate, ad esempio `GIORGIA A` e `GIORGIA B`.

## In caso di errore

Controlla gli ultimi errori Apache:

```sh
sudo tail -n 100 /var/log/apache2/error.log
```

Non incollare in chat o ticket password, segreti SMTP, chiavi VAPID, token rclone o dump completi del database. Gli hash password non sono password in chiaro, ma anche gli export che li contengono restano dati riservati.

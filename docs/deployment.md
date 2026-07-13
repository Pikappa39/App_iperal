# Deploy di MyOrari

## Obiettivo

La stessa struttura viene usata in locale con XAMPP e in produzione su EC2. In entrambi i casi la DocumentRoot deve puntare a `public/`, mentre il repository completo resta in una cartella non esposta.

Il dominio di produzione rimane `https://myorari.it`: il passaggio a `public/` non modifica DNS, certificato o URL visibili agli utenti.

## Prerequisiti

- PHP e le estensioni richieste dall'applicazione.
- MySQL raggiungibile con le credenziali configurate.
- Dipendenze Composer installate.
- Apache con `mod_rewrite` e `mod_headers`.
- `app_local_env.php` o variabili d'ambiente configurate.
- Backup recente prima di migrazioni o cambi strutturali.

## XAMPP locale

Nel VirtualHost locale impostare:

```apache
<VirtualHost *:80>
    ServerName myorari.local
    DocumentRoot "C:/xampp/htdocs/App_iperal-1/public"

    <Directory "C:/xampp/htdocs/App_iperal-1/public">
        Options -Indexes
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Se si usa `myorari.local`, aggiungere al file hosts di Windows:

```text
127.0.0.1 myorari.local
```

Riavviare Apache dal pannello XAMPP. In alternativa, mantenendo la configurazione predefinita di XAMPP, l'app può essere provata temporaneamente con il server PHP integrato:

```powershell
php -S 127.0.0.1:8099 -t public
```

Il server integrato serve solo per test locali e non sostituisce Apache in produzione.

## EC2 e myorari.it

Il repository resta in:

```text
/var/www/html/App_iperal-1
```

Nel VirtualHost HTTPS esistente cambiare soltanto la DocumentRoot e la relativa sezione `Directory`:

```apache
DocumentRoot /var/www/html/App_iperal-1/public

<Directory "/var/www/html/App_iperal-1/public">
    Options -Indexes
    AllowOverride All
    Require all granted
</Directory>
```

Le direttive esistenti per `ServerName myorari.it`, certificato TLS, log e redirect HTTP verso HTTPS restano invariate.

Installare o aggiornare anche la configurazione di sicurezza versionata:

```sh
cd /var/www/html/App_iperal-1
sudo cp deploy/apache/myorari-security.conf /etc/apache2/conf-available/myorari-security.conf
sudo a2enconf myorari-security
sudo a2enmod headers rewrite
```

## Procedura di rilascio

1. Verificare che il backup recente sia disponibile.
2. Pubblicare commit e tag dal computer di sviluppo.
3. Collegarsi a EC2 e aggiornare il repository.
4. Installare eventuali dipendenze.
5. Applicare le migrazioni.
6. Validare e ricaricare Apache.
7. Eseguire il controllo post-deploy.

Comandi di riferimento:

```sh
cd /var/www/html/App_iperal-1
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
php database/migrate.php
sudo apache2ctl configtest
sudo systemctl reload apache2
bash scripts/post_deploy_check.sh
```

`composer install` è necessario quando cambia `composer.lock`; eseguirlo comunque rende il deploy ripetibile. La migrazione deve essere preceduta dal backup del database.

## Verifiche post-deploy

Controllare almeno:

- apertura di `https://myorari.it`;
- login e logout;
- caricamento di CSS, JavaScript, immagini e favicon;
- manifest e registrazione del service worker;
- calendario personale e panoramica reparto;
- ferie personali, elenco ferie e campagna;
- comunicazioni, note, richieste ore e ordini;
- upload orari e console admin per i ruoli autorizzati;
- impossibilità di raggiungere `app_config.php`, `modules/`, `storage/` e `database/`.

Il controllo automatico senza autenticazione è:

```sh
bash scripts/post_deploy_check.sh
```

Da una macchina esterna, per attraversare Cloudflare:

```sh
APP_HEALTHCHECK_PUBLIC=1 bash scripts/post_deploy_check.sh https://myorari.it
```

Per problemi applicativi consultare:

```sh
sudo tail -n 100 /var/log/apache2/error.log
```

## Cache PWA

Dopo il deploy verificare che `APP_VERSION`, manifest e nome della cache del service worker corrispondano alla nuova release. Se un dispositivo conserva la versione precedente, chiudere e riaprire la PWA oppure usare la funzione di aggiornamento dell'app.

Non riutilizzare una versione già pubblicata per asset diversi: il service worker potrebbe mantenere file incompatibili.

## Rollback

Il rollback deve mantenere coerenti codice, database e DocumentRoot.

Per una normale correzione è preferibile creare un commit di revert, pubblicarlo e ripetere il deploy:

```sh
git revert <commit-da-annullare>
git push origin main
```

Su EC2:

```sh
cd /var/www/html/App_iperal-1
git pull --ff-only origin main
sudo apache2ctl configtest
sudo systemctl reload apache2
bash scripts/post_deploy_check.sh
```

Se si torna a una versione precedente alla migrazione `public/`, bisogna ripristinare anche la vecchia DocumentRoot nello stesso intervento. In caso contrario Apache non troverà gli entry point.

Le migrazioni database non devono essere annullate automaticamente. Prima di ripristinare uno schema o un backup, verificare la compatibilità dei dati e conservare una copia dello stato corrente.

## Checklist finale

- Codice e documentazione versionati.
- `APP_VERSION` e `release_meta.json` aggiornati.
- Backup verificato.
- DocumentRoot impostata su `public/`.
- Configurazione Apache valida.
- Migrazioni completate.
- Smoke test automatico superato.
- Flussi principali provati con i ruoli necessari.
- File privati non raggiungibili dal browser.

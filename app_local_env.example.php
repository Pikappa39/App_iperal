<?php

// Copia questo file in app_local_env.php sul server e inserisci i valori reali.
// app_local_env.php è escluso da Git: non archiviare mai password o chiavi nel repository.
return [
    'APP_DB_HOST' => '',
    'APP_DB_NAME' => '',
    'APP_DB_USER' => '',
    'APP_DB_PASSWORD' => '',
    // Per sicurezza resta disattivata finché non esiste un processo aziendale
    // di invito o approvazione degli account.
    'APP_ALLOW_SELF_REGISTRATION' => '0',
    'APP_TURNSTILE_SITE_KEY' => '',
    'APP_TURNSTILE_SECRET_KEY' => '',
    'APP_PUBLIC_URL' => 'https://myorari.it',
    'APP_SMTP_HOST' => 'smtps.aruba.it',
    'APP_SMTP_PORT' => '465',
    'APP_SMTP_USERNAME' => 'supporto@myorari.it',
    'APP_SMTP_PASSWORD' => '',
    'APP_SMTP_FROM_NAME' => 'MyOrari',
    // Genera il valore con:
    // php -r "echo password_hash('codice-scelto', PASSWORD_DEFAULT) . PHP_EOL;"
    'APP_ADMIN_CONSOLE_CODE_HASH' => '',
    'APP_ADMIN_CONSOLE_TIMEOUT_SECONDS' => '900',
    'APP_BACKUP_LOG_PATH' => '/home/ubuntu/myorari-backup.log',
];

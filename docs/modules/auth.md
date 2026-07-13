# Modulo Autenticazione e Account

## Scopo

Il modulo Auth raccoglie login, registrazione autonoma, stato sessione, logout, reset password e funzioni di identita account. Gli URL pubblici restano invariati per non modificare form e chiamate JavaScript esistenti.

Nota webroot: da `0.15.16` le pagine e gli asset serviti dal browser vivono in `public/`. Nei diagrammi, `connection_files/...` indica l'URL pubblico; il wrapper fisico si trova in `public/connection_files/...`.

## File principali

| Area | File | Responsabilita |
| --- | --- | --- |
| Bootstrap | `modules/auth/php/bootstrap.php` | Sessione, configurazione e connessione database. |
| Login | `modules/auth/php/endpoints/signin_endpoint.php` | Autenticazione, rate limit, Turnstile e creazione sessione. |
| Registrazione | `modules/auth/php/endpoints/signup_endpoint.php` | Registrazione autonoma quando abilitata. |
| Stato sessione | `modules/auth/php/endpoints/check_login_endpoint.php` | Verifica sessione lato frontend. |
| Logout | `modules/auth/php/endpoints/logout_endpoint.php` | Chiusura sessione con CSRF. |
| Richiesta reset | `modules/auth/php/endpoints/request_password_reset_endpoint.php` | Genera token e invia email reset. |
| Conferma reset | `modules/auth/php/endpoints/confirm_password_reset_endpoint.php` | Aggiorna password e invalida sessioni. |
| Identita | `modules/auth/php/identity/account_identity.php` | Generazione badge e identificativi tecnici. |
| Mail account | `modules/auth/php/mail/password_reset_mail.php` | SMTP/Brevo e template email invito/reset. |
| Contesti pagine | `modules/auth/php/pages/*.php` | Preparazione dati per login, reset password e accettazione invito. |
| UI auth | `public/assets/js/modules/auth/*.js` | Login, registrazione, richiesta reset e conferma reset senza JS inline nelle pagine. |
| CSS auth | `public/assets/css/modules/auth.css` | Stili specifici dei form account. |
| Wrapper pubblici | `public/connection_files/*.php` | Compatibilita con URL e include esistenti. |

## Flusso login

```mermaid
flowchart TD
    A["Utente invia login"] --> B["POST connection_files/signin.php"]
    B --> C["Wrapper carica modulo auth"]
    C --> D["Controlla CSRF e rate limit"]
    D --> E{"Turnstile attivo?"}
    E -- "Si" --> F["Verifica token Cloudflare"]
    E -- "No" --> G["Cerca utente"]
    F --> G
    G --> H["password_verify con hash reale o dummy"]
    H --> I{"Credenziali valide e account attivo?"}
    I -- "Si" --> J["Rigenera sessione e salva user"]
    I -- "No" --> K["Registra fallimento"]
```

## Flusso reset password

```mermaid
flowchart TD
    A["Utente richiede reset"] --> B["POST request_password_reset"]
    B --> C["Rate limit per email/IP"]
    C --> D["Crea token hash con scadenza 60 minuti"]
    D --> E["Invia email con link"]
    E --> F["Utente apre reset_password.php"]
    F --> G["POST confirm_password_reset"]
    G --> H["Valida token e password"]
    H --> I["Aggiorna password e session_version"]
```

## Note tecniche

- Gli endpoint pubblici non cambiano come URL: `connection_files/signin.php`, `signup.php`, `logout.php`, `check_login.php`, `request_password_reset.php`, `confirm_password_reset.php`.
- Le pagine pubbliche `login_reg.php`, `forgot_password.php`, `reset_password.php` e `accept_invite.php` stanno in `public/` come entry point, ma usano contesti del modulo auth.
- `account_identity.php` resta fuori dal DocumentRoot come wrapper PHP interno per compatibilita con inviti e registrazione.
- Le email account sono nel modulo auth, ma sono ancora usate anche dal modulo users per gli inviti.
- La cartella `modules/` e bloccata via HTTP: il browser deve passare dai wrapper pubblici.

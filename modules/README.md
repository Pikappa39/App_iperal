# Moduli applicativi

Questa cartella raccoglie il codice diviso per dominio funzionale.

## Regola generale

- `modules/<nome-modulo>/php`: endpoint, servizi, repository e regole specifiche del modulo.
- `assets/js/modules/<nome-modulo>`: codice frontend caricato solo quando la sezione viene aperta.
- Il codice condiviso tra moduli non deve vivere dentro un modulo specifico: va estratto in una futura area `shared`.

## Endpoint pubblici

Gli URL storici in `connection_files/` restano stabili e possono funzionare da wrapper verso il modulo.
Questo evita di rompere fetch frontend, notifiche, service worker o link gia distribuiti.

## Primo modulo

`holidays` e il primo modulo estratto:

- `connection_files/holidays.php` rimanda a `modules/holidays/php/department_holidays_endpoint.php`
- `connection_files/holiday_campaign.php` rimanda a `modules/holidays/php/holiday_campaign_endpoint.php`
- `modules/holidays/php/bootstrap.php` contiene il bootstrap condiviso degli endpoint ferie
- `modules/holidays/php/support` contiene risposta JSON e validazioni piccole
- `modules/holidays/php/permissions` contiene le regole di accesso ferie
- `modules/holidays/php/repositories` contiene query e accesso ai dati
- `modules/holidays/php/services` contiene la logica applicativa coordinata dagli endpoint
- `holiday_campaign_endpoint.php` e `department_holidays_endpoint.php` sono router sottili verso service e repository
- `assets/js/modules/holidays/common.js` contiene costanti, date e componenti UI condivisi
- `assets/js/modules/holidays/department.js` contiene la UI elenco ferie reparto
- `assets/js/modules/holidays/personal.js` contiene la UI ferie personali
- `assets/js/modules/holidays/campaign.js` contiene la UI campagna ferie

# Moduli applicativi

Questa cartella raccoglie il codice diviso per dominio funzionale.

## Regola generale

- `modules/<nome-modulo>/php`: endpoint, servizi, repository e regole specifiche del modulo.
- `public/assets/js/modules/<nome-modulo>`: codice frontend caricato solo quando la sezione viene aperta.
- Il codice condiviso tra moduli non deve vivere dentro un modulo specifico: va estratto in una futura area `shared`.

## Endpoint pubblici

Gli URL storici in `connection_files/` restano stabili e possono funzionare da wrapper verso il modulo.
Questo evita di rompere fetch frontend, notifiche, service worker o link gia distribuiti.
Fisicamente i wrapper pubblici stanno in `public/connection_files/`; la logica vera resta in `connection_files/` e `modules/`, fuori dal DocumentRoot.

## Moduli presenti

| Cartella | Responsabilità | Documentazione |
| --- | --- | --- |
| `adjustments` | Variazioni turno e richieste di ore | [Richieste ore](../docs/modules/adjustments.md) |
| `auth` | Login, registrazione, sessione e recupero password | [Autenticazione](../docs/modules/auth.md) |
| `communications` | Comunicazioni di reparto, destinatari e conferme | [Comunicazioni](../docs/modules/communications.md) |
| `customer-orders` | Ordini clienti e stati degli articoli | [Ordini clienti](../docs/modules/customer-orders.md) |
| `holidays` | Ferie ufficiali, preferenze e campagne | [Ferie](../docs/modules/holidays.md) |
| `notes` | Note giornaliere personali e di reparto | [Note](../docs/modules/notes.md) |
| `notifications` | Centro notifiche e push browser | [Notifiche](../docs/modules/notifications.md) |
| `schedules` | Calendari, upload, mapping e modifiche orari | [Orari](../docs/modules/schedules.md) |
| `users` | Addetti, inviti, profilo e console admin | [Utenti](../docs/modules/users.md) |

## Struttura interna

Un modulo usa soltanto i livelli necessari:

- `bootstrap.php` carica dipendenze e contesto condiviso.
- Gli endpoint o router traducono la richiesta HTTP in un caso d'uso.
- `permissions/` contiene le decisioni di autorizzazione.
- `services/` coordina la logica applicativa.
- `repositories/` contiene query e persistenza.
- `support/` raccoglie validazioni, formattatori e risposte interne al modulo.

Gli endpoint devono restare sottili. Le query non devono essere duplicate nei controller o nel frontend.

## Frontend

- Il JavaScript specifico vive in `public/assets/js/modules/<nome-modulo>/`.
- Il CSS specifico vive in `public/assets/css/modules/`.
- Gli script di pagina che coordinano più moduli vivono in `public/assets/js/pages/`.
- Gli asset condivisi devono avere una responsabilità trasversale chiara.

## Compatibilità

I wrapper in `connection_files/` conservano gli include PHP storici. I corrispondenti wrapper in `public/connection_files/` mantengono stabili gli URL usati dal browser.

Un wrapper non deve contenere regole di business: deve caricare l'endpoint interno corretto.

## Nuovo modulo

Quando viene introdotto un dominio:

1. Creare `modules/<nome>/php/`.
2. Separare endpoint, permessi, service e repository quando necessari.
3. Creare i wrapper interni e pubblici senza duplicare logica.
4. Collocare JS e CSS nelle cartelle pubbliche dedicate.
5. Aggiungere `docs/modules/<nome>.md`.
6. Aggiornare questa tabella e [l'indice della documentazione](../docs/README.md).
7. Documentare permessi, tabelle, stati, flussi e test.

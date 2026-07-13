# Documentazione MyOrari

Questa cartella raccoglie la documentazione tecnica e funzionale dell'applicazione.

## Documenti generali

| Documento | Contenuto |
| --- | --- |
| [Architettura](architecture.md) | Confini tra area pubblica, moduli PHP, wrapper, configurazione e dati. |
| [Deploy](deployment.md) | Configurazione XAMPP ed EC2, rilascio, verifiche e rollback. |
| [Test](../TESTING.md) | Controlli manuali e tecnici prima e dopo un rilascio. |
| [Moduli](../modules/README.md) | Convenzioni di modularizzazione e indice dei domini applicativi. |

## Moduli applicativi

| Modulo | Documento |
| --- | --- |
| Autenticazione e account | [auth.md](modules/auth.md) |
| Utenti, addetti e inviti | [users.md](modules/users.md) |
| Orari | [schedules.md](modules/schedules.md) |
| Richieste ore | [adjustments.md](modules/adjustments.md) |
| Ferie | [holidays.md](modules/holidays.md) |
| Comunicazioni | [communications.md](modules/communications.md) |
| Notifiche e push | [notifications.md](modules/notifications.md) |
| Note | [notes.md](modules/notes.md) |
| Ordini clienti | [customer-orders.md](modules/customer-orders.md) |

## Regola di manutenzione

Ogni modifica che introduce endpoint, tabelle, permessi, stati o flussi utente deve aggiornare il documento del modulo interessato. Le modifiche alla struttura delle cartelle, alla superficie pubblica o al processo di rilascio devono aggiornare anche i documenti generali.

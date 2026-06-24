<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/session_bootstrap.php';
require __DIR__ . '/app_config.php';
app_session_start();

if (!isset($_SESSION['user'])) {
    header('Location: login_reg.php');
    exit;
}

$role = (int) ($_SESSION['user']['capo'] ?? 0);
$roleLabel = match ($role) {
    1 => 'Caporeparto',
    2 => 'Vice',
    3 => 'Admin',
    default => 'Addetto',
};

$canManageUsers = $role === 3;
$canInvite = in_array($role, [1, 3], true);
$canViewDepartmentOverview = in_array($role, [1, 3], true);
$canUseOperations = in_array($role, [1, 2, 3], true);

function guidaEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guida utente</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="sfera.css?v=<?php echo rawurlencode(APP_VERSION); ?>">
</head>
<body>
<div class="app-shell">
    <header class="app-header mb-4">
        <div class="app-header__title">
            <h3 class="mb-1">Guida utente</h3>
            <p class="text-muted mb-0">Una panoramica rapida di cosa puoi fare nell’app e di cosa cambia per i ruoli più alti.</p>
        </div>
        <div class="app-header__actions">
            <a href="index.php" class="btn btn-outline-dark">Torna alla home</a>
        </div>
    </header>

    <main class="container-fluid px-0">
        <section class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
                    <div>
                        <h1 class="h4 mb-2">Come leggere i ruoli</h1>
                        <p class="mb-0 text-muted">
                            Il tuo account è riconosciuto come <strong><?php echo guidaEscape($roleLabel); ?></strong>.
                            Alcuni pulsanti compaiono solo se il ruolo ha i permessi necessari.
                        </p>
                    </div>
                    <div class="d-flex flex-wrap gap-2 align-items-start">
                        <span class="badge text-bg-secondary">Tutti gli utenti</span>
                        <span class="badge text-bg-primary">Caporeparto</span>
                        <span class="badge text-bg-info">Vice</span>
                        <span class="badge text-bg-danger">Admin</span>
                    </div>
                </div>
            </div>
        </section>

        <section class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5 mb-3">Cosa può fare ciascun ruolo</h2>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                        <tr>
                            <th>Funzione</th>
                            <th>Tutti</th>
                            <th>Caporeparto</th>
                            <th>Vice</th>
                            <th>Admin</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr>
                            <td>Vedere gli orari</td>
                            <td>✓</td>
                            <td>✓</td>
                            <td>✓</td>
                            <td>✓</td>
                        </tr>
                        <tr>
                            <td>Profilo, avatar e logout</td>
                            <td>✓</td>
                            <td>✓</td>
                            <td>✓</td>
                            <td>✓</td>
                        </tr>
                        <tr>
                            <td>Richiedere variazioni orario</td>
                            <td>✓</td>
                            <td>✓</td>
                            <td>✓</td>
                            <td>✓</td>
                        </tr>
                        <tr>
                            <td>Leggere le comunicazioni</td>
                            <td>✓</td>
                            <td>✓</td>
                            <td>✓</td>
                            <td>✓</td>
                        </tr>
                        <tr>
                            <td>Gestire gli addetti e le associazioni nominativi</td>
                            <td>—</td>
                            <td>✓</td>
                            <td>✓</td>
                            <td>✓</td>
                        </tr>
                        <tr>
                            <td>Caricare orari / aggiornare il reparto</td>
                            <td>—</td>
                            <td>✓</td>
                            <td>✓</td>
                            <td>✓</td>
                        </tr>
                        <tr>
                            <td>Invitare nuovi dipendenti</td>
                            <td>—</td>
                            <td>✓</td>
                            <td>—</td>
                            <td>✓</td>
                        </tr>
                        <tr>
                            <td>Panoramica reparto</td>
                            <td>—</td>
                            <td>✓</td>
                            <td>—</td>
                            <td>✓</td>
                        </tr>
                        <tr>
                            <td>Gestione account utenti</td>
                            <td>—</td>
                            <td>—</td>
                            <td>—</td>
                            <td>✓</td>
                        </tr>
                        <tr>
                            <td>Vedere l’ultima attività degli utenti</td>
                            <td>—</td>
                            <td>—</td>
                            <td>—</td>
                            <td>✓</td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="row g-4">
            <div class="col-12 col-lg-6">
                <section class="card shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h5">Tutti gli utenti</h2>
                        <ul class="mb-0">
                            <li>Vedere gli orari caricati.</li>
                            <li>Aprire il proprio profilo e cambiare avatar.</li>
                            <li>Controllare gli aggiornamenti dell’app.</li>
                            <li>Inviare richieste di variazione orario.</li>
                            <li>Leggere le comunicazioni del reparto.</li>
                            <li>Ricevere notifiche push, se abilitate.</li>
                            <li>Effettuare il logout in sicurezza.</li>
                        </ul>
                    </div>
                </section>
            </div>

            <div class="col-12 col-lg-6">
                <section class="card shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h5">Caporeparto</h2>
                        <ul class="mb-0">
                            <li>Tutto quello che può fare un utente normale.</li>
                            <li>Aprire la schermata <strong>Addetti</strong> del proprio reparto.</li>
                            <li>Caricare orari e aggiornare i nominativi associati.</li>
                            <li>Visualizzare e gestire le note del reparto.</li>
                            <li>Invitare nuovi dipendenti del proprio reparto.</li>
                            <li>Vedere la panoramica del reparto.</li>
                            <li>Approvare o rifiutare le richieste ore del reparto.</li>
                        </ul>
                    </div>
                </section>
            </div>

            <div class="col-12 col-lg-6">
                <section class="card shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h5">Vice</h2>
                        <ul class="mb-0">
                            <li>Può usare le funzioni operative del reparto.</li>
                            <li>Può vedere e gestire gli addetti del proprio reparto.</li>
                            <li>Può caricare e controllare gli orari.</li>
                            <li>Può lavorare sulle note del reparto.</li>
                            <li>Non gestisce gli inviti.</li>
                            <li>Non vede la gestione globale degli account.</li>
                            <li>Non vede la panoramica reparto dedicata a capo e admin.</li>
                        </ul>
                    </div>
                </section>
            </div>

            <div class="col-12 col-lg-6">
                <section class="card shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h5">Admin</h2>
                        <ul class="mb-0">
                            <li>Ha tutte le funzioni del reparto.</li>
                            <li>Può gestire gli addetti di tutti i reparti.</li>
                            <li>Può invitare nuovi utenti anche su reparti diversi.</li>
                            <li>Può attivare, disattivare o eliminare account.</li>
                            <li>Può vedere l’ultima attività degli utenti.</li>
                            <li>Può operare sulla panoramica reparto globale.</li>
                        </ul>
                    </div>
                </section>
            </div>
        </section>

        <section class="card shadow-sm mt-4">
            <div class="card-body">
                <h2 class="h5">Consigli pratici</h2>
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <div class="p-3 border rounded h-100">
                            <strong>Se non vedi un pulsante</strong>
                            <p class="mb-0 text-muted">Probabilmente il tuo ruolo non lo prevede. La guida sopra ti dice cosa dovrebbe comparire.</p>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="p-3 border rounded h-100">
                            <strong>Se lavori su orari o note</strong>
                            <p class="mb-0 text-muted">Ricorda che i ruoli manageriali possono vedere o modificare informazioni del reparto, non solo le proprie.</p>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="p-3 border rounded h-100">
                            <strong>Se fai logout</strong>
                            <p class="mb-0 text-muted">La sessione corrente viene chiusa, ma l’app resta pronta per un nuovo accesso.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>
</div>
</body>
</html>

<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require dirname(__DIR__) . '/session_bootstrap.php';
require dirname(__DIR__) . '/app_config.php';
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
$firstName = trim((string) ($_SESSION['user']['nome'] ?? ''));

function guidaEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$commonActions = [
    [
        'title' => 'Consulta i tuoi orari',
        'description' => 'Controlla turni, riposi e variazioni della settimana.',
        'hint' => 'Dalla home scegli Orari.',
        'href' => 'index.php',
        'cta' => 'Apri la home',
        'keywords' => 'orari turni riposi calendario',
        'icon' => 'calendar',
    ],
    [
        'title' => 'Richiedi una variazione',
        'description' => 'Invia una richiesta quando devi modificare ore o turni.',
        'hint' => 'Apri il menu del profilo e scegli Richieste ore.',
        'href' => 'index.php',
        'cta' => 'Vai al menu',
        'keywords' => 'richiesta ore variazione cambio turno',
        'icon' => 'clock',
    ],
    [
        'title' => 'Leggi le comunicazioni',
        'description' => 'Trova gli aggiornamenti e i messaggi del reparto.',
        'hint' => 'Apri il menu del profilo e scegli Comunicazioni.',
        'href' => 'index.php',
        'cta' => 'Vai al menu',
        'keywords' => 'comunicazioni messaggi avvisi',
        'icon' => 'message',
    ],
    [
        'title' => 'Profilo e notifiche',
        'description' => 'Cambia avatar, gestisci le notifiche e accedi alle impostazioni.',
        'hint' => 'Apri il menu del profilo in alto a destra.',
        'href' => 'index.php',
        'cta' => 'Apri il profilo',
        'keywords' => 'profilo avatar notifiche impostazioni logout',
        'icon' => 'user',
    ],
];

$roleActions = match ($role) {
    1 => [
        [
            'title' => 'Gestisci gli addetti',
            'description' => 'Consulta il tuo reparto, aggiorna i nominativi e lavora sugli orari.',
            'hint' => 'Questa sezione riguarda solo il tuo reparto.',
            'href' => 'addetti.php',
            'cta' => 'Apri Addetti',
            'keywords' => 'addetti reparto nominativi associazioni',
            'icon' => 'team',
        ],
        [
            'title' => 'Carica e controlla gli orari',
            'description' => 'Importa gli orari e verifica che gli addetti siano associati correttamente.',
            'hint' => 'La funzione si trova all’interno dell’area Addetti.',
            'href' => 'addetti.php',
            'cta' => 'Vai ad Addetti',
            'keywords' => 'carica importa excel orari',
            'icon' => 'upload',
        ],
        [
            'title' => 'Controlla il reparto',
            'description' => 'Leggi la panoramica settimanale e gestisci le richieste ricevute.',
            'hint' => 'Apri il menu del profilo: Panoramica reparto o Richieste ore.',
            'href' => 'index.php',
            'cta' => 'Apri il menu',
            'keywords' => 'panoramica reparto approva rifiuta richieste',
            'icon' => 'chart',
        ],
    ],
    2 => [
        [
            'title' => 'Gestisci gli addetti',
            'description' => 'Consulta gli addetti del reparto, gli orari caricati e le associazioni.',
            'hint' => 'Puoi operare sul tuo reparto.',
            'href' => 'addetti.php',
            'cta' => 'Apri Addetti',
            'keywords' => 'addetti reparto nominativi associazioni',
            'icon' => 'team',
        ],
        [
            'title' => 'Carica e controlla gli orari',
            'description' => 'Importa gli orari e lavora sulle note operative del reparto.',
            'hint' => 'Gli inviti e la panoramica reparto non sono disponibili per il ruolo Vice.',
            'href' => 'addetti.php',
            'cta' => 'Vai ad Addetti',
            'keywords' => 'carica importa excel orari note',
            'icon' => 'upload',
        ],
    ],
    3 => [
        [
            'title' => 'Gestisci gli addetti',
            'description' => 'Consulta e organizza gli addetti dei reparti disponibili.',
            'hint' => 'Dalla sezione Addetti puoi cambiare reparto.',
            'href' => 'addetti.php',
            'cta' => 'Apri Addetti',
            'keywords' => 'addetti reparto nominativi associazioni',
            'icon' => 'team',
        ],
        [
            'title' => 'Inviti e account',
            'description' => 'Gestisci gli account, gli inviti e lo stato degli utenti.',
            'hint' => 'Le funzioni amministrative compaiono nelle sezioni di gestione.',
            'href' => 'addetti.php',
            'cta' => 'Vai alla gestione',
            'keywords' => 'admin account inviti attivo utenti',
            'icon' => 'shield',
        ],
        [
            'title' => 'Panoramica dei reparti',
            'description' => 'Controlla la pianificazione settimanale e le richieste degli addetti.',
            'hint' => 'Apri il menu del profilo e scegli Panoramica reparto.',
            'href' => 'index.php',
            'cta' => 'Apri il menu',
            'keywords' => 'panoramica reparto richieste ore',
            'icon' => 'chart',
        ],
    ],
    default => [],
};

$allActions = array_merge($commonActions, $roleActions);
$roleSummary = match ($role) {
    1 => 'Puoi usare tutte le funzioni operative del tuo reparto, compresi inviti e panoramica.',
    2 => 'Puoi lavorare sugli addetti e sugli orari del tuo reparto.',
    3 => 'Puoi gestire funzioni e utenti su tutti i reparti disponibili.',
    default => 'Puoi consultare i tuoi orari, inviare richieste e rimanere aggiornato.',
};
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guida rapida</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="sfera.css?v=<?php echo rawurlencode(APP_VERSION); ?>">
    <style>
        .guide-page { max-width: 1180px; margin: 0 auto; padding-bottom: 40px; }
        .guide-hero { display: grid; grid-template-columns: auto 1fr auto; gap: 18px; align-items: center; padding: 24px; border: 1px solid #cfe0f8; border-radius: 22px; background: linear-gradient(125deg, #eef6ff, #fff 62%); box-shadow: 0 14px 30px rgba(16, 63, 124, .08); }
        .guide-hero__icon, .guide-icon { display: grid; place-items: center; flex: 0 0 auto; color: #0d5dcc; background: #dcebff; }
        .guide-hero__icon { width: 54px; height: 54px; border-radius: 16px; }
        .guide-hero__icon svg, .guide-icon svg { width: 25px; height: 25px; fill: none; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round; stroke-width: 1.8; }
        .guide-hero h1 { margin: 0 0 5px; color: #092b5b; font-size: clamp(1.35rem, 3vw, 1.8rem); }
        .guide-hero p { margin: 0; color: #52657e; }
        .guide-role { align-self: start; padding: 7px 10px; border-radius: 999px; color: #064d9e; background: #dcebff; font-size: .78rem; font-weight: 800; white-space: nowrap; }
        .guide-search { position: relative; margin: 22px 0; }
        .guide-search input { width: 100%; padding: 13px 42px 13px 15px; border: 1px solid #cdddf0; border-radius: 13px; background: #fff; color: #132f56; }
        .guide-search input:focus { outline: 3px solid rgba(13, 110, 253, .16); border-color: #0d6efd; }
        .guide-search svg { position: absolute; top: 14px; right: 15px; width: 20px; height: 20px; fill: none; stroke: #57708f; stroke-width: 2; }
        .guide-section { margin-top: 28px; }
        .guide-section__head { display: flex; justify-content: space-between; gap: 16px; align-items: end; margin-bottom: 13px; }
        .guide-section__head h2 { margin: 0; color: #0a2b59; font-size: 1.15rem; }
        .guide-section__head p { margin: 3px 0 0; color: #607189; font-size: .9rem; }
        .guide-result { color: #65768e; font-size: .83rem; font-weight: 700; }
        .guide-actions { display: grid; grid-template-columns: repeat(auto-fit, minmax(225px, 1fr)); gap: 14px; }
        .guide-action { display: flex; flex-direction: column; min-height: 238px; padding: 18px; border: 1px solid #dce7f3; border-radius: 16px; background: #fff; box-shadow: 0 8px 22px rgba(22, 52, 91, .05); transition: transform .16s ease, box-shadow .16s ease; }
        .guide-action:hover { transform: translateY(-2px); box-shadow: 0 13px 27px rgba(22, 52, 91, .1); }
        .guide-icon { width: 38px; height: 38px; margin-bottom: 13px; border-radius: 11px; }
        .guide-icon svg { width: 19px; height: 19px; }
        .guide-action h3 { margin: 0 0 7px; color: #0c2c59; font-size: 1rem; }
        .guide-action p { margin: 0; color: #596b84; font-size: .88rem; }
        .guide-action small { display: block; margin: 11px 0 auto; color: #5d7190; font-size: .78rem; }
        .guide-action a { margin-top: 15px; }
        .guide-faq { display: grid; gap: 10px; }
        .guide-faq details { border: 1px solid #dce7f3; border-radius: 12px; background: #fff; overflow: hidden; }
        .guide-faq summary { cursor: pointer; padding: 15px 42px 15px 16px; color: #123966; font-weight: 750; list-style: none; position: relative; }
        .guide-faq summary::-webkit-details-marker { display: none; }
        .guide-faq summary::after { content: '+'; position: absolute; right: 16px; color: #0d6efd; font-size: 1.25rem; font-weight: 500; }
        .guide-faq details[open] summary::after { content: '−'; }
        .guide-faq__answer { padding: 0 16px 16px; color: #566982; font-size: .9rem; }
        .guide-faq__answer p { margin: 0; }
        .guide-roles { display: grid; grid-template-columns: repeat(4, minmax(170px, 1fr)); gap: 12px; }
        .guide-role-card { padding: 15px; border: 1px solid #dce7f3; border-radius: 14px; background: #fff; color: #596b84; font-size: .84rem; }
        .guide-role-card h3 { margin: 0 0 6px; color: #153d6d; font-size: .95rem; }
        .guide-role-card p { margin: 0; }
        .guide-role-card.is-current { border-color: #81b4fb; background: #eef6ff; box-shadow: 0 0 0 2px rgba(13, 110, 253, .08); }
        .guide-hidden { display: none !important; }
        @media (max-width: 760px) { .guide-hero { grid-template-columns: auto 1fr; padding: 19px; } .guide-role { grid-column: 1 / -1; justify-self: start; } .guide-roles { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 430px) { .guide-page { padding-bottom: 25px; } .guide-roles { grid-template-columns: 1fr; } .guide-section__head { display: block; } .guide-result { display: block; margin-top: 5px; } }
    </style>
</head>
<body>
<div class="app-shell">
    <header class="app-header mb-4">
        <div class="app-header__title">
            <h3 class="mb-1">Guida rapida</h3>
            <p class="text-muted mb-0">Le funzioni essenziali, spiegate in modo semplice.</p>
        </div>
        <div class="app-header__actions">
            <a href="index.php" class="btn btn-outline-dark">Torna alla home</a>
        </div>
    </header>

    <main class="guide-page">
        <section class="guide-hero" aria-labelledby="guideWelcome">
            <div class="guide-hero__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z"/><path d="M9.5 9a2.5 2.5 0 1 1 4.3 1.7c-.9.8-1.8 1.3-1.8 2.8"/><path d="M12 17h.01"/></svg>
            </div>
            <div>
                <h1 id="guideWelcome">Ciao<?php echo $firstName !== '' ? ', ' . guidaEscape($firstName) : ''; ?>. Da dove vuoi iniziare?</h1>
                <p><?php echo guidaEscape($roleSummary); ?></p>
            </div>
            <span class="guide-role">Il tuo ruolo: <?php echo guidaEscape($roleLabel); ?></span>
        </section>

        <label class="guide-search" for="guideSearch">
            <span class="visually-hidden">Cerca nella guida</span>
            <input type="search" id="guideSearch" placeholder="Cerca: orari, richiesta, avatar, addetti..." autocomplete="off">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6"/><path d="m16 16 4 4"/></svg>
        </label>

        <section class="guide-section" aria-labelledby="guideActionsTitle">
            <div class="guide-section__head">
                <div><h2 id="guideActionsTitle">Le tue azioni più utili</h2><p>Solo le funzioni disponibili con il tuo ruolo.</p></div>
                <span class="guide-result" id="guideActionCount"></span>
            </div>
            <div class="guide-actions" id="guideActions">
                <?php foreach ($allActions as $action): ?>
                    <article class="guide-action" data-guide-search="<?php echo guidaEscape($action['title'] . ' ' . $action['description'] . ' ' . $action['hint'] . ' ' . $action['keywords']); ?>">
                        <div class="guide-icon" aria-hidden="true">
                            <?php if ($action['icon'] === 'calendar'): ?>
                                <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/></svg>
                            <?php elseif ($action['icon'] === 'clock'): ?>
                                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                            <?php elseif ($action['icon'] === 'message'): ?>
                                <svg viewBox="0 0 24 24"><path d="M20 15a4 4 0 0 1-4 4H9l-5 3V8a4 4 0 0 1 4-4h8a4 4 0 0 1 4 4Z"/><path d="M8 10h8M8 14h5"/></svg>
                            <?php elseif ($action['icon'] === 'user'): ?>
                                <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3"/><path d="M5 21a7 7 0 0 1 14 0"/></svg>
                            <?php elseif ($action['icon'] === 'team'): ?>
                                <svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M17 11a2.5 2.5 0 1 0-1.7-4.3M17 14a5 5 0 0 1 4 4.9"/></svg>
                            <?php elseif ($action['icon'] === 'upload'): ?>
                                <svg viewBox="0 0 24 24"><path d="M12 16V3M7 8l5-5 5 5M5 17v3a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-3"/></svg>
                            <?php elseif ($action['icon'] === 'chart'): ?>
                                <svg viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>
                            <?php else: ?>
                                <svg viewBox="0 0 24 24"><path d="M12 3 4 7v5c0 5 3.4 8 8 9 4.6-1 8-4 8-9V7l-8-4Z"/><path d="m9 12 2 2 4-4"/></svg>
                            <?php endif; ?>
                        </div>
                        <h3><?php echo guidaEscape($action['title']); ?></h3>
                        <p><?php echo guidaEscape($action['description']); ?></p>
                        <small><?php echo guidaEscape($action['hint']); ?></small>
                        <a href="<?php echo guidaEscape($action['href']); ?>" class="btn btn-outline-primary btn-sm"><?php echo guidaEscape($action['cta']); ?></a>
                    </article>
                <?php endforeach; ?>
            </div>
            <p id="guideNoResults" class="text-muted mt-3 mb-0 guide-hidden">Nessun risultato. Prova con parole come “orari”, “richiesta” o “profilo”.</p>
        </section>

        <section class="guide-section" aria-labelledby="guideFaqTitle">
            <div class="guide-section__head"><div><h2 id="guideFaqTitle">Come faccio a…</h2><p>Risposte rapide alle domande più frequenti.</p></div></div>
            <div class="guide-faq" id="guideFaq">
                <details data-guide-search="come vedo i miei orari turni riposi calendario"><summary>Vedere i miei orari e i riposi</summary><div class="guide-faq__answer"><p>Dalla home scegli <strong>Orari</strong>. Usa le frecce o il calendario per spostarti tra settimane e mesi.</p></div></details>
                <details data-guide-search="richiedere richiesta modifica cambio turno ore"><summary>Richiedere una modifica di ore o turno</summary><div class="guide-faq__answer"><p>Apri il menu del profilo in alto a destra e scegli <strong>Richieste ore</strong>. Inserisci i dati con attenzione e invia la richiesta.</p></div></details>
                <details data-guide-search="avatar profilo notifiche impostazioni"><summary>Cambiare avatar o gestire le notifiche</summary><div class="guide-faq__answer"><p>Apri il menu del profilo e scegli <strong>Profilo</strong> oppure <strong>Impostazioni</strong>. Le notifiche push sono facoltative e puoi disattivarle quando vuoi.</p></div></details>
                <details data-guide-search="non vedo pulsante funzione permessi ruolo"><summary>Non vedo un pulsante che vedono altri colleghi</summary><div class="guide-faq__answer"><p>Le funzioni dipendono dal ruolo assegnato al tuo account. In questa pagina vedi soltanto le azioni abilitate per te.</p></div></details>
                <details data-guide-search="logout esci sessione sicurezza"><summary>Effettuare il logout in sicurezza</summary><div class="guide-faq__answer"><p>Apri il menu del profilo e scegli <strong>Logout</strong>. La sessione del dispositivo viene chiusa e potrai accedere di nuovo quando necessario.</p></div></details>
            </div>
        </section>

        <section class="guide-section" aria-labelledby="guideRolesTitle">
            <div class="guide-section__head"><div><h2 id="guideRolesTitle">Cosa cambia tra i ruoli?</h2><p>Un riepilogo veloce, senza dover leggere una tabella lunga.</p></div></div>
            <div class="guide-roles">
                <article class="guide-role-card<?php echo $role === 0 ? ' is-current' : ''; ?>" data-guide-search="addetto orari richieste comunicazioni profilo"><h3>Addetto</h3><p>Consulta orari, comunicazioni, profilo e richieste personali.</p></article>
                <article class="guide-role-card<?php echo $role === 1 ? ' is-current' : ''; ?>" data-guide-search="caporeparto addetti inviti panoramica reparto"><h3>Caporeparto</h3><p>Gestisce il proprio reparto, inviti, richieste e panoramica.</p></article>
                <article class="guide-role-card<?php echo $role === 2 ? ' is-current' : ''; ?>" data-guide-search="vice addetti orari note reparto"><h3>Vice</h3><p>Lavora sugli addetti e sugli orari del proprio reparto.</p></article>
                <article class="guide-role-card<?php echo $role === 3 ? ' is-current' : ''; ?>" data-guide-search="admin account utenti reparti inviti"><h3>Admin</h3><p>Gestisce utenti e operazioni su tutti i reparti disponibili.</p></article>
            </div>
        </section>
    </main>
</div>
<script>
(function () {
    const search = document.getElementById('guideSearch');
    const searchable = Array.from(document.querySelectorAll('[data-guide-search]'));
    const actionCards = Array.from(document.querySelectorAll('.guide-action'));
    const actionCount = document.getElementById('guideActionCount');
    const noResults = document.getElementById('guideNoResults');

    function updateGuideSearch() {
        const query = search.value.trim().toLocaleLowerCase('it');
        searchable.forEach((element) => {
            const matches = !query || element.dataset.guideSearch.toLocaleLowerCase('it').includes(query);
            element.classList.toggle('guide-hidden', !matches);
        });

        const visibleActions = actionCards.filter((card) => !card.classList.contains('guide-hidden')).length;
        actionCount.textContent = query ? `${visibleActions} azioni trovate` : `${visibleActions} azioni disponibili`;
        noResults.classList.toggle('guide-hidden', visibleActions !== 0 || !query);
    }

    search.addEventListener('input', updateGuideSearch);
    updateGuideSearch();
}());
</script>
</body>
</html>

<?php
require __DIR__ . '/app_config.php';
require __DIR__ . '/session_bootstrap.php';
app_session_start();
require_once __DIR__ . '/connection_files/connection.php';
require __DIR__ . '/gestore_ods/orario_converter_lib.php';

$capo = (int) ($_SESSION['user']['capo'] ?? 0);
if (!isset($_SESSION['user']) || !in_array($capo, [1, 3], true)) {
    header('Location: index.php');
    exit;
}
$canViewLastSeen = $capo === 3;

function appAddettiEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function appAddettiLastSeenLabel($value): string
{
    if (!is_string($value) || trim($value) === '') {
        return 'Mai rilevato';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return 'Dato non valido';
    }

    $diff = time() - $timestamp;
    if ($diff < 0) {
        $diff = 0;
    }

    if ($diff < 120) {
        return 'Attivo adesso';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . ' min fa';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . ' h fa';
    }

    return date('d/m/Y H:i', $timestamp);
}

if (empty($_SESSION['schedule_mapping_csrf'])) {
    $_SESSION['schedule_mapping_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['schedule_mapping_csrf'];

$reparto = trim((string) ($_SESSION['user']['reparto'] ?? ''));
$repartoLabel = appDepartments()[$reparto] ?? 'non assegnato';
$users = [];
$mappings = [];
$databaseError = !$connessione || !($pdo instanceof PDO);

if (!$databaseError) {
    $userStatement = $pdo->prepare(
        'SELECT cod_fiscale, nome, cognome, last_seen
         FROM utenti
         WHERE reparto = ?
         ORDER BY cognome, nome, cod_fiscale'
    );
    $userStatement->execute([$reparto]);
    $users = $userStatement->fetchAll(PDO::FETCH_ASSOC);

    $mappingStatement = $pdo->prepare(
        'SELECT schedule_name, user_cf, updated_at
         FROM schedule_name_mappings
         WHERE reparto = ?
         ORDER BY schedule_name'
    );
    $mappingStatement->execute([$reparto]);
    $mappings = $mappingStatement->fetchAll(PDO::FETCH_ASSOC);
}

$usersByCf = [];
foreach ($users as $user) {
    $usersByCf[(string) $user['cod_fiscale']] = $user;
}

// Include anche i nominativi presenti negli orari storici, caricati prima
// dell'introduzione della tabella delle associazioni.
$scheduleNames = [];
$jsonFiles = glob(__DIR__ . '/turni_json/*-' . $reparto . '.json') ?: [];
foreach ($jsonFiles as $jsonFile) {
    $decoded = json_decode((string) @file_get_contents($jsonFile), true);
    if (!is_array($decoded)) {
        continue;
    }
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $displayName = trim((string) ($row['ADDETTO'] ?? ''));
        $key = normalizzaChiaveAddetto($displayName);
        if ($key !== '' && !isset($scheduleNames[$key])) {
            $scheduleNames[$key] = $displayName;
        }
    }
}

$namesByUser = [];
$scheduleOnlyRows = [];
$mappedKeys = [];
foreach ($mappings as $mapping) {
    $key = (string) $mapping['schedule_name'];
    $userCf = (string) $mapping['user_cf'];
    $mappedKeys[$key] = true;
    $scheduleName = $scheduleNames[$key] ?? $key;

    if (isset($usersByCf[$userCf])) {
        $namesByUser[$userCf][] = $scheduleName;
        continue;
    }

    $scheduleOnlyRows[] = [
        'key' => $key,
        'name' => $scheduleName,
        'status' => $userCf === '__UNREGISTERED__' ? 'Utente non registrato' : 'Associazione da verificare',
    ];
}

foreach ($scheduleNames as $key => $scheduleName) {
    if (isset($mappedKeys[$key])) {
        continue;
    }
    $scheduleOnlyRows[] = [
        'key' => $key,
        'name' => $scheduleName,
        'status' => 'Da associare',
    ];
}

usort($scheduleOnlyRows, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
$availableUsers = array_values(array_filter(
    $users,
    static fn (array $user): bool => empty($namesByUser[(string) $user['cod_fiscale']])
));
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione addetti</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="sfera.css?v=<?php echo rawurlencode(APP_VERSION); ?>">
</head>
<body>
<main class="app-shell">
    <header class="d-flex align-items-center justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Addetti</h1>
            <p class="text-muted mb-0">Reparto: <?php echo appAddettiEscape($repartoLabel); ?></p>
        </div>
        <a class="btn btn-outline-dark" href="index.php">Indietro</a>
    </header>

    <?php if (isset($_GET['updated'])): ?>
        <div class="alert alert-success">Associazione salvata. Aggiornate <?php echo (int) $_GET['updated']; ?> righe negli orari già caricati.</div>
    <?php elseif (isset($_GET['error'])): ?>
        <div class="alert alert-danger">Non è stato possibile salvare l'associazione. Riprova.</div>
    <?php endif; ?>

    <?php if ($databaseError): ?>
        <div class="alert alert-danger">Impossibile caricare gli addetti. Riprova più tardi.</div>
    <?php else: ?>
        <section class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5">Utenti registrati</h2>
                <p class="text-muted">Sono inclusi tutti gli utenti del reparto, anche se non hanno ancora un nominativo negli orari.</p>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Utente</th>
                            <th>Codice fiscale</th>
                            <?php if ($canViewLastSeen): ?>
                                <th>Ultima attività</th>
                            <?php endif; ?>
                            <th>Nominativi negli orari</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($users as $user): ?>
                            <?php $userCf = (string) $user['cod_fiscale']; $scheduleUserNames = $namesByUser[$userCf] ?? []; ?>
                            <tr>
                                <td><?php echo appAddettiEscape(trim((string) $user['nome'] . ' ' . (string) $user['cognome'])); ?></td>
                                <td><code><?php echo appAddettiEscape($userCf); ?></code></td>
                                <?php if ($canViewLastSeen): ?>
                                    <td><?php echo appAddettiEscape(appAddettiLastSeenLabel($user['last_seen'] ?? null)); ?></td>
                                <?php endif; ?>
                                <td><?php echo $scheduleUserNames === [] ? '<span class="text-muted">Nessuno</span>' : appAddettiEscape(implode(', ', $scheduleUserNames)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($users === []): ?>
                            <tr><td colspan="<?php echo $canViewLastSeen ? '4' : '3'; ?>" class="text-muted">Non ci sono utenti registrati in questo reparto.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Nominativi degli orari da gestire</h2>
                <p class="text-muted">Qui compaiono i nominativi non collegati a un utente registrato, inclusi quelli segnati come non registrati.</p>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Nominativo nell'orario</th><th>Stato</th><th>Associa a</th></tr></thead>
                        <tbody>
                        <?php foreach ($scheduleOnlyRows as $row): ?>
                            <tr>
                                <td><?php echo appAddettiEscape($row['name']); ?></td>
                                <td><?php echo appAddettiEscape($row['status']); ?></td>
                                <td>
                                    <?php if ($availableUsers === []): ?>
                                        <span class="text-muted">Tutti gli utenti sono già associati</span>
                                    <?php else: ?>
                                        <form action="connection_files/save_schedule_mapping.php" method="post" class="d-flex gap-2 align-items-center">
                                            <input type="hidden" name="csrf_token" value="<?php echo appAddettiEscape($csrfToken); ?>">
                                            <input type="hidden" name="schedule_name" value="<?php echo appAddettiEscape($row['key']); ?>">
                                            <select class="form-select form-select-sm" name="user_cf" required aria-label="Utente da associare a <?php echo appAddettiEscape($row['name']); ?>">
                                                <option value="">Seleziona utente…</option>
                                                <?php foreach ($availableUsers as $user): ?>
                                                    <?php $userCf = (string) $user['cod_fiscale']; ?>
                                                    <option value="<?php echo appAddettiEscape($userCf); ?>"><?php echo appAddettiEscape(trim((string) $user['nome'] . ' ' . (string) $user['cognome'])); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-primary btn-sm">Associa</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($scheduleOnlyRows === []): ?>
                            <tr><td colspan="3" class="text-muted">Non ci sono nominativi in attesa di associazione.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    <?php endif; ?>
</main>
</body>
</html>

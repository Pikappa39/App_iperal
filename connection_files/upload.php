<?php
use PhpOffice\PhpSpreadsheet\IOFactory;

header("Content-Type: application/json; charset=utf-8");

require __DIR__ . '/../session_bootstrap.php';
app_session_start();

$capo = (int) ($_SESSION["user"]["capo"] ?? 0);
if (!isset($_SESSION["user"]) || !in_array($capo, [1, 3], true)) {
    http_response_code(403);
    echo json_encode([
        "ok" => false,
        "error" => "Accesso negato",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!app_csrf_request_is_valid()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Richiesta non valida. Ricarica la pagina e riprova.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../gestore_ods/orario_converter_lib.php';
require __DIR__ . '/connection.php';
require __DIR__ . '/push_lib.php';

const APP_UPLOAD_UNREGISTERED_VALUE = '__UNREGISTERED__';

function appUploadFail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function appUploadDepartmentUsers(PDO $pdo, string $reparto): array
{
    $statement = $pdo->prepare(
        'SELECT cod_fiscale, nome, cognome, badge
         FROM utenti
         WHERE reparto = ?
         ORDER BY cognome, nome, cod_fiscale'
    );
    $statement->execute([$reparto]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function appUploadExistingMappings(PDO $pdo, string $reparto, array $nameKeys): array
{
    $nameKeys = array_values(array_unique(array_filter($nameKeys, static fn ($key) => $key !== '')));
    if ($nameKeys === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($nameKeys), '?'));
    $statement = $pdo->prepare(
        'SELECT schedule_name, user_cf
         FROM schedule_name_mappings
         WHERE reparto = ? AND schedule_name IN (' . $placeholders . ')'
    );
    $statement->execute(array_merge([$reparto], $nameKeys));

    $mappings = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $mappings[(string) $row['schedule_name']] = (string) $row['user_cf'];
    }

    return $mappings;
}

function appUploadReadFiles(array $files): array
{
    $names = is_array($files['name']) ? $files['name'] : [$files['name']];
    $tmpNames = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
    $errors = is_array($files['error']) ? $files['error'] : [$files['error']];
    $sizes = is_array($files['size'] ?? null) ? $files['size'] : [$files['size'] ?? 0];
    $convertedFiles = [];
    $uploadedWeeks = [];

    if (count($tmpNames) === 0 || count($tmpNames) > 5) {
        throw new RuntimeException('Puoi caricare da uno a cinque file alla volta.');
    }

    foreach ($tmpNames as $index => $tmpName) {
        $originalName = (string) ($names[$index] ?? '');
        $uploadError = $errors[$index] ?? UPLOAD_ERR_NO_FILE;

        if ($uploadError !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Errore upload per ' . $originalName . ': ' . $uploadError);
        }
        if (!is_uploaded_file($tmpName)) {
            throw new RuntimeException('File caricato non valido.');
        }
        if ((int) ($sizes[$index] ?? 0) < 1 || (int) ($sizes[$index] ?? 0) > 5 * 1024 * 1024) {
            throw new RuntimeException($originalName . ': la dimensione massima è 5 MB.');
        }
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException($originalName . ': formato non supportato, serve un file .xlsx');
        }

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($tmpName);
        if (!in_array($mimeType, [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/x-zip-compressed',
        ], true)) {
            throw new RuntimeException($originalName . ': il file non è un Excel .xlsx valido.');
        }

        $spreadsheet = IOFactory::load($tmpName);
        $converted = convertWorkbookToScheduleData($spreadsheet->getActiveSheet(), $originalName);
        if (isset($uploadedWeeks[$converted['settimana']])) {
            throw new RuntimeException('Hai selezionato più file per la settimana ' . $converted['settimana'] . '. Caricane uno solo per reparto.');
        }

        $uploadedWeeks[$converted['settimana']] = true;
        $convertedFiles[] = ['file' => $originalName, 'converted' => $converted];
    }

    return $convertedFiles;
}

$outputDir = __DIR__ . '/../turni_json';
$reparto = trim((string) ($_SESSION['user']['reparto'] ?? ''));
if (!appIsValidDepartment($reparto)) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => 'Al tuo profilo non è associato un reparto valido. Contatta un amministratore.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_dir($outputDir) && !mkdir($outputDir, 0750, true) && !is_dir($outputDir)) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "error" => "Impossibile creare la cartella JSON",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_FILES['excelFiles'])) {
    appUploadFail(400, 'Nessun file ricevuto');
}

$mode = (string) ($_POST['mode'] ?? 'upload');
if (!in_array($mode, ['preview', 'upload'], true)) {
    appUploadFail(400, 'Operazione non valida');
}

try {
    $convertedFiles = appUploadReadFiles($_FILES['excelFiles']);
} catch (Throwable $error) {
    appUploadFail(422, $error->getMessage());
}

$scheduleNames = [];
foreach ($convertedFiles as $fileData) {
    foreach ($fileData['converted']['data'] as $row) {
        $displayName = (string) ($row['ADDETTO'] ?? '');
        $key = normalizzaChiaveAddetto($displayName);
        if ($key !== '') {
            $scheduleNames[$key] = $displayName;
        }
    }
}

$existingMappings = appUploadExistingMappings($pdo, $reparto, array_keys($scheduleNames));
$departmentUsers = appUploadDepartmentUsers($pdo, $reparto);
$allowedUsers = [];
foreach ($departmentUsers as $user) {
    $allowedUsers[(string) $user['cod_fiscale']] = true;
}

if ($mode === 'preview') {
    $rows = [];
    foreach ($scheduleNames as $key => $displayName) {
        $savedValue = $existingMappings[$key] ?? null;
        if ($savedValue === APP_UPLOAD_UNREGISTERED_VALUE || isset($allowedUsers[$savedValue])) {
            continue;
        }
        $rows[] = [
            'key' => $key,
            'name' => $displayName,
            'userCf' => '',
        ];
    }

    echo json_encode([
        'ok' => true,
        'names' => $rows,
        'users' => $departmentUsers,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$submittedMappings = json_decode((string) ($_POST['mappings'] ?? '{}'), true);
if (!is_array($submittedMappings)) {
    appUploadFail(400, 'Associazioni non valide');
}

$mappings = [];
$unregisteredKeys = [];
foreach ($existingMappings as $key => $savedValue) {
    if ($savedValue === APP_UPLOAD_UNREGISTERED_VALUE) {
        $unregisteredKeys[$key] = true;
        continue;
    }
    $mappings[$key] = $savedValue;
}

foreach ($scheduleNames as $key => $_displayName) {
    if (array_key_exists($key, $submittedMappings)) {
        $selectedValue = trim((string) $submittedMappings[$key]);
        if ($selectedValue === APP_UPLOAD_UNREGISTERED_VALUE) {
            unset($mappings[$key]);
            $unregisteredKeys[$key] = true;
            continue;
        }
        $mappings[$key] = $selectedValue;
        unset($unregisteredKeys[$key]);
    }

    if (!empty($unregisteredKeys[$key])) {
        continue;
    }

    if (empty($mappings[$key]) || !isset($allowedUsers[$mappings[$key]])) {
        appUploadFail(422, 'Scegli un utente del tuo reparto oppure “Utente non registrato” per ogni nominativo del file.');
    }
}

try {
    $saveMapping = $pdo->prepare(
        'INSERT INTO schedule_name_mappings (reparto, schedule_name, user_cf, created_by_cf)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE user_cf = VALUES(user_cf), created_by_cf = VALUES(created_by_cf)'
    );
    $pdo->beginTransaction();
    foreach ($scheduleNames as $key => $_displayName) {
        if (!empty($unregisteredKeys[$key])) {
            // Conserviamo la scelta per non richiederla nuovamente a ogni upload.
            // Potrà essere sostituita da un'associazione utente nella gestione dedicata.
            $saveMapping->execute([$reparto, $key, APP_UPLOAD_UNREGISTERED_VALUE, (string) ($_SESSION['user']['cf'] ?? '')]);
            continue;
        }
        $saveMapping->execute([$reparto, $key, $mappings[$key], (string) ($_SESSION['user']['cf'] ?? '')]);
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Impossibile salvare le associazioni orari: ' . $error->getMessage());
    appUploadFail(500, 'Impossibile salvare le associazioni. Riprova più tardi.');
}

$results = [];

foreach ($convertedFiles as $fileData) {
    $originalName = $fileData['file'];
    try {
        $converted = $fileData['converted'];
        $converted['data'] = associaUtentiAlleRigheOrario($converted['data'], $mappings, $unregisteredKeys);

        $scheduleMeta = appPushExtractIsoWeekYear($originalName);
        if ($scheduleMeta['week'] !== (int) $converted['settimana']) {
            throw new RuntimeException('La settimana indicata nel nome file non coincide con quella contenuta nell\'Excel.');
        }
        $outputFile = $outputDir . DIRECTORY_SEPARATOR . $scheduleMeta['year'] . '-' . $converted['settimana'] . '-' . $reparto . '.json';
        $previousData = appPushDecodeJsonFile($outputFile);
        scriviJson($outputFile, $converted["data"]);

        $changeSet = appPushBuildChangeSet(
            $previousData,
            $converted["data"],
            $pdo,
            $scheduleMeta['year'],
            $scheduleMeta['week']
        );
        $batchId = bin2hex(random_bytes(16));
        $historySummary = [
            'batch' => $batchId,
            'stored' => 0,
            'errors' => [],
        ];
        $storedChangesByUser = [];

        foreach ($changeSet['targets'] as $userCf => $entries) {
            try {
                $storedChangesByUser[$userCf] = appPushStoreScheduleChanges(
                    $pdo,
                    $batchId,
                    (string) $userCf,
                    (string) ($_SESSION['user']['cf'] ?? ''),
                    $scheduleMeta['year'],
                    $scheduleMeta['week'],
                    $originalName,
                    $entries
                );
                $historySummary['stored'] += $storedChangesByUser[$userCf];
            } catch (Throwable $historyError) {
                $historySummary['errors'][$userCf] = $historyError->getMessage();
            }
        }

        $pushSummary = [
            'general' => null,
            'targets' => [],
        ];

        if ($changeSet['generalChanged']) {
            try {
                $pushSummary['general'] = appPushSendPayload($pdo, [
                    'title' => 'Nuovi orari caricati',
                    'body' => 'Gli orari sono stati aggiornati dal capo',
                    'url' => './index.php',
                ]);
            } catch (Throwable $pushError) {
                $pushSummary['general'] = [
                    'error' => $pushError->getMessage(),
                ];
            }
        }

        foreach ($changeSet['targets'] as $userCf => $entries) {
            if (!is_array($entries) || $entries === []) {
                continue;
            }

            $firstChange = $entries[0];
            $changeCount = count($entries);
            $body = $changeCount === 1
                ? 'Orario del ' . ($firstChange['dateLabel'] ?? 'giorno selezionato') . ' modificato da capo'
                : 'Hai ' . $changeCount . ' modifiche di orario';
            $title = $changeCount === 1
                ? 'Orario modificato'
                : 'Orari aggiornati';
            $changeUrl = !empty($storedChangesByUser[$userCf])
                ? './index.php?changes=1&batch=' . rawurlencode($batchId)
                : './index.php';

            try {
                $pushSummary['targets'][$userCf] = appPushSendPayload($pdo, [
                    'title' => $title,
                    'body' => $body,
                    'url' => $changeUrl,
                    'recipient_cf' => $userCf,
                ], $userCf);
            } catch (Throwable $pushError) {
                $pushSummary['targets'][$userCf] = [
                    'error' => $pushError->getMessage(),
                ];
            }
        }

        $results[] = [
            "file" => $originalName,
            "settimana" => $converted["settimana"],
            "reparto" => $reparto,
            "output" => basename($outputFile),
            "righe" => count($converted["data"]),
            "history" => $historySummary,
            "push" => $pushSummary,
        ];
    } catch (Throwable $e) {
        $results[] = [
            "file" => $originalName,
            "error" => $e->getMessage(),
        ];
    }
}

echo json_encode([
    "ok" => true,
    "results" => $results,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

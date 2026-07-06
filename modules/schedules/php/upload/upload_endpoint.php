<?php
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

header("Content-Type: application/json; charset=utf-8");

require __DIR__ . '/../../../../app_config.php';
require __DIR__ . '/../../../../session_bootstrap.php';
app_session_start();

$capo = (int) ($_SESSION["user"]["capo"] ?? 0);
if (!isset($_SESSION["user"]) || !in_array($capo, [1, 2, 3], true)) {
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

require __DIR__ . '/../../../../vendor/autoload.php';
require __DIR__ . '/../../../../gestore_ods/orario_converter_lib.php';
require __DIR__ . '/../../../../connection_files/connection.php';
require __DIR__ . '/../../../../connection_files/push_lib.php';
require __DIR__ . '/../shared/schedule_adjustment_lib.php';

const APP_UPLOAD_UNREGISTERED_VALUE = '__UNREGISTERED__';
const APP_UPLOAD_MAX_SCHEDULE_ROWS = 800;
const APP_UPLOAD_MAX_SCHEDULE_COLUMNS = 40;

final class AppUploadScheduleReadFilter implements IReadFilter
{
    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        if ($row < 1 || $row > APP_UPLOAD_MAX_SCHEDULE_ROWS) {
            return false;
        }

        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnAddress) <= APP_UPLOAD_MAX_SCHEDULE_COLUMNS;
    }
}

function appUploadFail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function appUploadTargetDepartment(int $capo): string
{
    $sessionDepartment = trim((string) ($_SESSION['user']['reparto'] ?? ''));
    $requestedDepartment = trim((string) ($_POST['reparto'] ?? ''));

    if ($capo === 3) {
        return $requestedDepartment;
    }

    return $sessionDepartment;
}

function appUploadDepartmentUsers(PDO $pdo, string $reparto): array
{
    $statement = $pdo->prepare(
        'SELECT cod_fiscale, nome, cognome, badge
         FROM utenti
         WHERE reparto = ? AND attivo = 1
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

function appUploadDepartmentNotificationRecipients(PDO $pdo, string $reparto): array
{
    $statement = $pdo->prepare(
        'SELECT cod_fiscale
         FROM utenti
         WHERE reparto = ?
           AND capo <> 3
           AND attivo = 1'
    );
    $statement->execute([$reparto]);

    return array_map(
        static fn (array $user): string => (string) $user['cod_fiscale'],
        $statement->fetchAll(PDO::FETCH_ASSOC)
    );
}

function appUploadAdminNotificationRecipients(PDO $pdo, string $uploaderCf): array
{
    $statement = $pdo->prepare(
        'SELECT cod_fiscale
         FROM utenti
         WHERE capo = 3
           AND attivo = 1
           AND cod_fiscale <> ?'
    );
    $statement->execute([$uploaderCf]);

    return array_map(
        static fn (array $user): string => (string) $user['cod_fiscale'],
        $statement->fetchAll(PDO::FETCH_ASSOC)
    );
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

        $reader = IOFactory::createReaderForFile($tmpName);
        $sheetNames = $reader->listWorksheetNames($tmpName);
        if ($sheetNames !== []) {
            $reader->setLoadSheetsOnly($sheetNames[0]);
        }
        $reader->setReadEmptyCells(false);
        $reader->setReadFilter(new AppUploadScheduleReadFilter());

        $spreadsheet = $reader->load($tmpName);
        try {
            $converted = convertWorkbookToScheduleData($spreadsheet->getActiveSheet(), $originalName);
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
            gc_collect_cycles();
        }
        if (isset($uploadedWeeks[$converted['settimana']])) {
            throw new RuntimeException('Hai selezionato più file per la settimana ' . $converted['settimana'] . '. Caricane uno solo per reparto.');
        }

        $uploadedWeeks[$converted['settimana']] = true;
        $convertedFiles[] = ['file' => $originalName, 'converted' => $converted];
    }

    return $convertedFiles;
}

$outputDir = __DIR__ . '/../../../../turni_json';
$reparto = appUploadTargetDepartment($capo);
if (!appIsValidDepartment($reparto)) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => $capo === 3
            ? 'Seleziona un reparto valido prima di caricare i file.'
            : 'Al tuo profilo non è associato un reparto valido. Contatta un amministratore.',
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
$autoUploadFromPreview = $mode === 'preview' && (string) ($_POST['auto_upload'] ?? '') === '1';

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

$submittedMappings = [];
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

    if ($rows !== [] || !$autoUploadFromPreview) {
        echo json_encode([
            'ok' => true,
            'names' => $rows,
            'users' => $departmentUsers,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($mode === 'upload') {
    $submittedMappings = json_decode((string) ($_POST['mappings'] ?? '{}'), true);
    if (!is_array($submittedMappings)) {
        appUploadFail(400, 'Associazioni non valide');
    }
}

$mappings = [];
$unregisteredKeys = [];
foreach ($existingMappings as $key => $savedValue) {
    if ($savedValue === APP_UPLOAD_UNREGISTERED_VALUE) {
        $unregisteredKeys[$key] = true;
        continue;
    }
    if ($savedValue === APP_SCHEDULE_MAPPING_IGNORED_VALUE) {
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
        appUploadFail(422, 'Scegli un utente del reparto selezionato oppure “Utente non registrato” per ogni nominativo del file.');
    }
}

$preparedSchedules = [];
try {
    foreach ($convertedFiles as $fileData) {
        $originalName = $fileData['file'];
        $converted = $fileData['converted'];
        $converted['data'] = associaUtentiAlleRigheOrario($converted['data'], $mappings, $unregisteredKeys);
        $scheduleMeta = appPushExtractIsoWeekYear($originalName, [
            'week' => (int) $converted['settimana'],
            'year' => (int) $converted['anno'],
        ]);
        if ($scheduleMeta['week'] !== (int) $converted['settimana']) {
            throw new RuntimeException('La settimana indicata nel nome file non coincide con quella contenuta nell\'Excel.');
        }

        $preparedSchedules[] = [
            'file' => $originalName,
            'converted' => $converted,
            'year' => (int) $scheduleMeta['year'],
            'week' => (int) $scheduleMeta['week'],
            'output' => $outputDir . DIRECTORY_SEPARATOR . $scheduleMeta['year'] . '-' . $scheduleMeta['week'] . '-' . $reparto . '.json',
            'batch' => bin2hex(random_bytes(16)),
        ];
    }

    usort($preparedSchedules, static fn (array $a, array $b): int => [$a['year'], $a['week']] <=> [$b['year'], $b['week']]);

    $saveMapping = $pdo->prepare(
        'INSERT INTO schedule_name_mappings (reparto, schedule_name, user_cf, created_by_cf)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE user_cf = VALUES(user_cf), created_by_cf = VALUES(created_by_cf)'
    );
    $pdo->beginTransaction();
    appScheduleAdjustmentLockDepartment($pdo, $reparto);
    foreach ($scheduleNames as $key => $_displayName) {
        $savedValue = !empty($unregisteredKeys[$key]) ? APP_UPLOAD_UNREGISTERED_VALUE : $mappings[$key];
        $saveMapping->execute([$reparto, $key, $savedValue, (string) ($_SESSION['user']['cf'] ?? '')]);
    }

    foreach ($preparedSchedules as &$schedule) {
        appScheduleAdjustmentLockWeek($pdo, $reparto, $schedule['year'], $schedule['week']);
        $previousData = appScheduleAdjustmentLoadCurrentScheduleRows($pdo, $reparto, $schedule['year'], $schedule['week']) ?? [];
        appScheduleAdjustmentStoreUploadVersion(
            $pdo,
            $schedule['batch'],
            $reparto,
            $schedule['year'],
            $schedule['week'],
            $schedule['file'],
            (string) ($_SESSION['user']['cf'] ?? ''),
            $schedule['converted']['data']
        );
        $schedule['requestsToReview'] = appScheduleAdjustmentReconcileUpload(
            $pdo,
            $reparto,
            $schedule['year'],
            $schedule['week'],
            $schedule['converted']['data']
        );
        $schedule['changeSet'] = appPushBuildChangeSet(
            $previousData,
            $schedule['converted']['data'],
            $pdo,
            $schedule['year'],
            $schedule['week']
        );
        $schedule['history'] = ['batch' => $schedule['batch'], 'stored' => 0, 'errors' => []];
        $schedule['storedChangesByUser'] = [];
        foreach ($schedule['changeSet']['targets'] as $userCf => $entries) {
            $schedule['storedChangesByUser'][$userCf] = appPushStoreScheduleChanges(
                $pdo,
                $schedule['batch'],
                (string) $userCf,
                (string) ($_SESSION['user']['cf'] ?? ''),
                $schedule['year'],
                $schedule['week'],
                $schedule['file'],
                $entries
            );
            $schedule['history']['stored'] += $schedule['storedChangesByUser'][$userCf];
        }
    }
    unset($schedule);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Upload orari annullato: ' . $error->getMessage());
    appUploadFail(500, 'Nessun orario è stato aggiornato. Riprova più tardi.');
}

$results = [];
foreach ($preparedSchedules as $schedule) {
    $cacheWarning = '';
    try {
        // Il JSON è una cache di compatibilità: l'app legge la versione attiva dal database.
        scriviJson($schedule['output'], $schedule['converted']['data']);
    } catch (Throwable $cacheError) {
        $cacheWarning = 'Cache JSON non aggiornata: ' . $cacheError->getMessage();
        error_log($cacheWarning);
    }

    $pushSummary = ['department' => [], 'admins' => [], 'targets' => [], 'reviews' => []];
    foreach ($schedule['requestsToReview'] as $userCf) {
        try {
            $pushSummary['reviews'][$userCf] = appPushSendPayload($pdo, [
                'type' => 'adjustment_review',
                'title' => 'Segnalazione da riesaminare',
                'body' => 'Il turno previsto è stato aggiornato dal capo. Verifica la tua segnalazione ore.',
                'url' => './index.php?adjustments=1',
                'recipient_cf' => $userCf,
                'tag' => 'adjustment-review',
            ], $userCf);
        } catch (Throwable $pushError) {
            $pushSummary['reviews'][$userCf] = ['error' => $pushError->getMessage()];
        }
    }

    if ($schedule['changeSet']['generalChanged']) {
        $uploaderCf = (string) ($_SESSION['user']['cf'] ?? '');
        $uploaderName = trim((string) ($_SESSION['user']['nome'] ?? '') . ' ' . (string) ($_SESSION['user']['cognome'] ?? ''));
        $departmentLabel = appDepartments()[$reparto] ?? $reparto;
        foreach (appUploadDepartmentNotificationRecipients($pdo, $reparto) as $recipientCf) {
            try {
                $pushSummary['department'][$recipientCf] = appPushSendPayload($pdo, [
                    'type' => 'schedule_uploaded',
                    'title' => 'Nuovi orari caricati',
                    'body' => 'Gli orari del reparto ' . $departmentLabel . ' sono stati aggiornati.',
                    'url' => './index.php?orari=1',
                    'recipient_cf' => $recipientCf,
                    'tag' => 'schedule-uploaded-' . $reparto,
                ], $recipientCf);
            } catch (Throwable $pushError) {
                $pushSummary['department'][$recipientCf] = ['error' => $pushError->getMessage()];
            }
        }
        foreach (appUploadAdminNotificationRecipients($pdo, $uploaderCf) as $recipientCf) {
            try {
                $pushSummary['admins'][$recipientCf] = appPushSendPayload($pdo, [
                    'type' => 'schedule_uploaded',
                    'title' => 'Orari aggiornati: ' . $departmentLabel,
                    'body' => ($uploaderName !== '' ? $uploaderName : 'Un responsabile') . ' ha caricato gli orari del reparto ' . $departmentLabel . '.',
                    'url' => './index.php?orari=1',
                    'recipient_cf' => $recipientCf,
                    'tag' => 'schedule-uploaded-' . $reparto,
                ], $recipientCf);
            } catch (Throwable $pushError) {
                $pushSummary['admins'][$recipientCf] = ['error' => $pushError->getMessage()];
            }
        }
    }

    foreach ($schedule['changeSet']['targets'] as $userCf => $entries) {
        if (!is_array($entries) || $entries === []) {
            continue;
        }
        $firstChange = $entries[0];
        $changeCount = count($entries);
        $title = $changeCount === 1 ? 'Orario modificato' : 'Orari aggiornati';
        $body = $changeCount === 1
            ? 'Orario del ' . ($firstChange['dateLabel'] ?? 'giorno selezionato') . ' modificato da capo'
            : 'Hai ' . $changeCount . ' modifiche di orario';
        $changeUrl = !empty($schedule['storedChangesByUser'][$userCf])
            ? './index.php?changes=1&batch=' . rawurlencode($schedule['batch'])
            : './index.php';
        try {
            $pushSummary['targets'][$userCf] = appPushSendPayload($pdo, [
                'type' => 'schedule_changed',
                'title' => $title,
                'body' => $body,
                'url' => $changeUrl,
                'recipient_cf' => $userCf,
                'tag' => 'schedule-changed-' . $schedule['batch'],
                'batch' => $schedule['batch'],
            ], $userCf);
        } catch (Throwable $pushError) {
            $pushSummary['targets'][$userCf] = ['error' => $pushError->getMessage()];
        }
    }

    $results[] = [
        'file' => $schedule['file'],
        'settimana' => $schedule['week'],
        'reparto' => $reparto,
        'output' => basename($schedule['output']),
        'righe' => count($schedule['converted']['data']),
        'history' => $schedule['history'],
        'push' => $pushSummary,
        'warning' => $cacheWarning,
    ];
}

echo json_encode(['ok' => true, 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

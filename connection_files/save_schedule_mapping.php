<?php
require __DIR__ . '/../app_config.php';
require __DIR__ . '/../session_bootstrap.php';
app_session_start();
require_once __DIR__ . '/connection.php';
require __DIR__ . '/../gestore_ods/orario_converter_lib.php';

function appScheduleMappingRedirect(string $query = ''): void
{
    header('Location: ../addetti.php' . $query, true, 303);
    exit;
}

function appScheduleMappingQuery(string $reparto, array $parameters = []): string
{
    if (appIsValidDepartment($reparto)) {
        $parameters = ['reparto' => $reparto] + $parameters;
    }

    return '?' . http_build_query($parameters);
}

function appScheduleMappingWriteJson(string $path, string $contents): void
{
    $temporaryPath = $path . '.tmp-' . bin2hex(random_bytes(8));
    if (@file_put_contents($temporaryPath, $contents, LOCK_EX) === false || !@rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        throw new RuntimeException('Impossibile aggiornare un orario già caricato.');
    }
}

$capo = (int) ($_SESSION['user']['capo'] ?? 0);
if (!isset($_SESSION['user']) || !in_array($capo, [1, 2, 3], true) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    appScheduleMappingRedirect('?error=1');
}

$sessionReparto = trim((string) ($_SESSION['user']['reparto'] ?? ''));
$requestedReparto = trim((string) ($_POST['reparto'] ?? ''));
$reparto = $capo === 3 ? $requestedReparto : $sessionReparto;

$csrfToken = (string) ($_POST['csrf_token'] ?? '');
if (empty($_SESSION['schedule_mapping_csrf']) || !hash_equals((string) $_SESSION['schedule_mapping_csrf'], $csrfToken)) {
    appScheduleMappingRedirect(appScheduleMappingQuery($reparto, ['error' => 1]));
}

$scheduleName = normalizzaChiaveAddetto((string) ($_POST['schedule_name'] ?? ''));
$userCf = trim((string) ($_POST['user_cf'] ?? ''));
if (!$connessione || !($pdo instanceof PDO) || !appIsValidDepartment($reparto) || $scheduleName === '' || $userCf === '') {
    appScheduleMappingRedirect(appScheduleMappingQuery($reparto, ['error' => 1]));
}

$updatedFiles = [];
try {
    $userStatement = $pdo->prepare('SELECT 1 FROM utenti WHERE cod_fiscale = ? AND reparto = ? LIMIT 1');
    $userStatement->execute([$userCf, $reparto]);
    if (!$userStatement->fetchColumn()) {
        throw new RuntimeException('Utente non valido per il reparto.');
    }

    $mappingStatement = $pdo->prepare(
        'SELECT 1 FROM schedule_name_mappings WHERE reparto = ? AND schedule_name = ? LIMIT 1'
    );
    $mappingStatement->execute([$reparto, $scheduleName]);
    $mappingExists = (bool) $mappingStatement->fetchColumn();

    $assignedUserStatement = $pdo->prepare(
        'SELECT 1
         FROM schedule_name_mappings
         WHERE reparto = ? AND user_cf = ? AND schedule_name <> ?
         LIMIT 1'
    );
    $assignedUserStatement->execute([$reparto, $userCf, $scheduleName]);
    if ($assignedUserStatement->fetchColumn()) {
        throw new RuntimeException('L’utente selezionato è già associato a un altro nominativo.');
    }

    $historicalRows = 0;
    $historicalNameFound = false;
    $jsonFiles = glob(__DIR__ . '/../turni_json/*-' . $reparto . '.json') ?: [];
    foreach ($jsonFiles as $jsonFile) {
        $originalContents = @file_get_contents($jsonFile);
        $decoded = is_string($originalContents) ? json_decode($originalContents, true) : null;
        if (!is_array($decoded)) {
            continue;
        }

        $changed = false;
        foreach ($decoded as &$row) {
            if (!is_array($row) || normalizzaChiaveAddetto((string) ($row['ADDETTO'] ?? '')) !== $scheduleName) {
                continue;
            }
            $historicalNameFound = true;
            $row['COD_FISCALE'] = $userCf;
            unset($row['UTENTE_NON_REGISTRATO']);
            $changed = true;
            $historicalRows++;
        }
        unset($row);

        if ($changed) {
            $newContents = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($newContents === false) {
                throw new RuntimeException('Impossibile preparare l’aggiornamento degli orari.');
            }
            $updatedFiles[] = [
                'path' => $jsonFile,
                'original' => $originalContents,
                'updated' => $newContents,
            ];
        }
    }

    if (!$mappingExists && !$historicalNameFound) {
        throw new RuntimeException('Nominativo non trovato.');
    }

    $pdo->beginTransaction();
    $saveMapping = $pdo->prepare(
        'INSERT INTO schedule_name_mappings (reparto, schedule_name, user_cf, created_by_cf)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE user_cf = VALUES(user_cf), created_by_cf = VALUES(created_by_cf)'
    );
    $saveMapping->execute([$reparto, $scheduleName, $userCf, (string) ($_SESSION['user']['cf'] ?? '')]);

    foreach ($updatedFiles as $updatedFile) {
        appScheduleMappingWriteJson($updatedFile['path'], $updatedFile['updated']);
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ($updatedFiles as $updatedFile) {
        if (isset($updatedFile['original']) && is_string($updatedFile['original'])) {
            try {
                appScheduleMappingWriteJson($updatedFile['path'], $updatedFile['original']);
            } catch (Throwable $restoreError) {
                error_log('Ripristino orario non riuscito: ' . $restoreError->getMessage());
            }
        }
    }
    error_log('Impossibile salvare associazione orario: ' . $error->getMessage());
    appScheduleMappingRedirect(appScheduleMappingQuery($reparto, ['error' => 1]));
}

appScheduleMappingRedirect(appScheduleMappingQuery($reparto, ['updated' => $historicalRows]));

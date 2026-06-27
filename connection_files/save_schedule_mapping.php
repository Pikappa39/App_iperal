<?php
require __DIR__ . '/../app_config.php';
require __DIR__ . '/../session_bootstrap.php';
app_session_start();
require_once __DIR__ . '/connection.php';
require __DIR__ . '/../gestore_ods/orario_converter_lib.php';
require __DIR__ . '/push_lib.php';
require __DIR__ . '/schedule_adjustment_lib.php';

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

function appScheduleMappingWeeks(PDO $pdo, string $department): array
{
    $weeks = [];
    $pattern = '/^(\d{4})-(\d{1,2})-' . preg_quote($department, '/') . '\.json$/';
    foreach (glob(__DIR__ . '/../turni_json/*-' . $department . '.json') ?: [] as $jsonFile) {
        if (!preg_match($pattern, basename($jsonFile), $matches)) {
            continue;
        }
        $weeks[(int) $matches[1] . ':' . (int) $matches[2]] = [
            'year' => (int) $matches[1],
            'week' => (int) $matches[2],
        ];
    }

    $statement = $pdo->prepare('SELECT iso_year, iso_week FROM schedule_active_versions WHERE reparto = ?');
    $statement->execute([$department]);
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $weeks[(int) $row['iso_year'] . ':' . (int) $row['iso_week']] = [
            'year' => (int) $row['iso_year'],
            'week' => (int) $row['iso_week'],
        ];
    }

    ksort($weeks, SORT_NATURAL);
    return array_values($weeks);
}

$capo = (int) ($_SESSION['user']['capo'] ?? 0);
if (!isset($_SESSION['user']) || !in_array($capo, [1, 2, 3], true) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    appScheduleMappingRedirect('?error=1');
}

$sessionReparto = trim((string) ($_SESSION['user']['reparto'] ?? ''));
$requestedReparto = trim((string) ($_POST['reparto'] ?? ''));
$reparto = $capo === 3 ? $requestedReparto : $sessionReparto;
$action = trim((string) ($_POST['action'] ?? 'save'));

$csrfToken = (string) ($_POST['csrf_token'] ?? '');
if (empty($_SESSION['schedule_mapping_csrf']) || !hash_equals((string) $_SESSION['schedule_mapping_csrf'], $csrfToken)) {
    appScheduleMappingRedirect(appScheduleMappingQuery($reparto, ['error' => 1]));
}

$scheduleName = normalizzaChiaveAddetto((string) ($_POST['schedule_name'] ?? ''));
$userCf = trim((string) ($_POST['user_cf'] ?? ''));
if (!$connessione || !($pdo instanceof PDO) || !appIsValidDepartment($reparto) || $scheduleName === '') {
    appScheduleMappingRedirect(appScheduleMappingQuery($reparto, ['error' => 1]));
}
if (!in_array($action, ['save', 'delete'], true)) {
    appScheduleMappingRedirect(appScheduleMappingQuery($reparto, ['error' => 1]));
}
if ($action === 'save' && $userCf === '') {
    appScheduleMappingRedirect(appScheduleMappingQuery($reparto, ['error' => 1]));
}

try {
    $mappingStatement = $pdo->prepare(
        'SELECT 1 FROM schedule_name_mappings WHERE reparto = ? AND schedule_name = ? LIMIT 1'
    );
    $mappingStatement->execute([$reparto, $scheduleName]);
    $mappingExists = (bool) $mappingStatement->fetchColumn();

    if ($action === 'save') {
        $userStatement = $pdo->prepare('SELECT 1 FROM utenti WHERE cod_fiscale = ? AND reparto = ? AND attivo = 1 LIMIT 1');
        $userStatement->execute([$userCf, $reparto]);
        if (!$userStatement->fetchColumn()) {
            throw new RuntimeException('Utente non valido per il reparto.');
        }
    }

    $pdo->beginTransaction();
    appScheduleAdjustmentLockDepartment($pdo, $reparto);

    $historicalRows = 0;
    $historicalNameFound = false;
    $updatedSchedules = [];
    $reviewUsers = [];
    foreach (appScheduleMappingWeeks($pdo, $reparto) as $scheduleWeek) {
        appScheduleAdjustmentLockWeek($pdo, $reparto, $scheduleWeek['year'], $scheduleWeek['week']);
        $rows = appScheduleAdjustmentLoadCurrentScheduleRows($pdo, $reparto, $scheduleWeek['year'], $scheduleWeek['week']);
        if ($rows === null) {
            continue;
        }

        $changed = false;
        foreach ($rows as &$row) {
            if (!is_array($row) || normalizzaChiaveAddetto((string) ($row['ADDETTO'] ?? '')) !== $scheduleName) {
                continue;
            }
            $historicalNameFound = true;
            $historicalRows++;
            if ($action === 'delete') {
                if ((string) ($row['COD_FISCALE'] ?? '') === '' && empty($row['UTENTE_NON_REGISTRATO'])) {
                    continue;
                }
                unset($row['COD_FISCALE'], $row['UTENTE_NON_REGISTRATO']);
                $changed = true;
                continue;
            }

            if ((string) ($row['COD_FISCALE'] ?? '') === $userCf && empty($row['UTENTE_NON_REGISTRATO'])) {
                continue;
            }
            $row['COD_FISCALE'] = $userCf;
            unset($row['UTENTE_NON_REGISTRATO']);
            $changed = true;
        }
        unset($row);

        if (!$changed) {
            continue;
        }

        $batchId = bin2hex(random_bytes(16));
        appScheduleAdjustmentStoreUploadVersion(
            $pdo,
            $batchId,
            $reparto,
            $scheduleWeek['year'],
            $scheduleWeek['week'],
            'Associazione addetto: ' . $scheduleName,
            (string) ($_SESSION['user']['cf'] ?? ''),
            $rows
        );
        foreach (appScheduleAdjustmentReconcileUpload($pdo, $reparto, $scheduleWeek['year'], $scheduleWeek['week'], $rows) as $reviewUserCf) {
            $reviewUsers[$reviewUserCf] = true;
        }
        $updatedSchedules[] = [
            'path' => __DIR__ . '/../turni_json/' . $scheduleWeek['year'] . '-' . $scheduleWeek['week'] . '-' . $reparto . '.json',
            'rows' => $rows,
        ];
    }

    if (!$mappingExists && !$historicalNameFound) {
        throw new RuntimeException('Nominativo non trovato.');
    }

    if ($action === 'delete') {
        $hideMapping = $pdo->prepare(
            'INSERT INTO schedule_name_mappings (reparto, schedule_name, user_cf, created_by_cf)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE user_cf = VALUES(user_cf), created_by_cf = VALUES(created_by_cf)'
        );
        $hideMapping->execute([
            $reparto,
            $scheduleName,
            APP_SCHEDULE_MAPPING_IGNORED_VALUE,
            (string) ($_SESSION['user']['cf'] ?? ''),
        ]);
    } else {
        $saveMapping = $pdo->prepare(
            'INSERT INTO schedule_name_mappings (reparto, schedule_name, user_cf, created_by_cf)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE user_cf = VALUES(user_cf), created_by_cf = VALUES(created_by_cf)'
        );
        $saveMapping->execute([$reparto, $scheduleName, $userCf, (string) ($_SESSION['user']['cf'] ?? '')]);
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Impossibile salvare associazione orario: ' . $error->getMessage());
    appScheduleMappingRedirect(appScheduleMappingQuery($reparto, ['error' => 1]));
}

foreach ($updatedSchedules as $updatedSchedule) {
    try {
        scriviJson($updatedSchedule['path'], $updatedSchedule['rows']);
    } catch (Throwable $cacheError) {
        error_log('Cache associazione orari non aggiornata: ' . $cacheError->getMessage());
    }
}
foreach (array_keys($reviewUsers) as $reviewUserCf) {
    try {
        appPushSendPayload($pdo, [
            'type' => 'adjustment_review',
            'title' => 'Segnalazione da riesaminare',
            'body' => $action === 'delete'
                ? 'L’associazione dell’orario è stata rimossa. Verifica la tua segnalazione ore.'
                : 'L’associazione dell’orario è stata aggiornata. Verifica la tua segnalazione ore.',
            'url' => './index.php?adjustments=1',
            'recipient_cf' => $reviewUserCf,
            'tag' => 'adjustment-review',
        ], $reviewUserCf);
    } catch (Throwable $pushError) {
        error_log('Push riesame associazione non inviata: ' . $pushError->getMessage());
    }
}

$resultKey = $action === 'delete' ? 'deleted' : 'updated';
appScheduleMappingRedirect(appScheduleMappingQuery($reparto, [$resultKey => $historicalRows]));

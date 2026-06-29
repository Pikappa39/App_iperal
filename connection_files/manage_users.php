<?php
declare(strict_types=1);

require __DIR__ . '/../app_config.php';
require __DIR__ . '/../session_bootstrap.php';
app_session_start();
require __DIR__ . '/connection.php';

function appUserManagementRedirect(string $department): void
{
    $query = appIsValidDepartment($department) ? '?reparto=' . rawurlencode($department) : '';
    header('Location: ../addetti.php' . $query, true, 303);
    exit;
}

function appUserManagementFlash(string $type, string $message): void
{
    $_SESSION['user_management_flash'] = ['type' => $type, 'message' => $message];
}

function appUserManagementNormalizeName(string $value): string
{
    $value = trim((string) preg_replace('/\s+/u', ' ', $value));
    return mb_strtoupper($value, 'UTF-8');
}

function appUserManagementRemoveNotes(array $userKeys, ?string $userName = null): void
{
    $directory = __DIR__ . '/../note_json';
    if (!is_dir($directory)) {
        return;
    }

    $files = glob($directory . '/*.json') ?: [];
    sort($files, SORT_STRING);
    $userKeys = array_fill_keys(array_filter(array_map('strval', $userKeys), static fn (string $key): bool => $key !== ''), true);
    $normalizedName = $userName !== null ? appUserManagementNormalizeName($userName) : '';
    $locks = [];
    $changes = [];

    try {
        foreach ($files as $file) {
            $lock = fopen($file . '.lock', 'c');
            if ($lock === false || !flock($lock, LOCK_EX)) {
                throw new RuntimeException('Impossibile bloccare le note dell’utente.');
            }
            $locks[] = $lock;
        }

        foreach ($files as $file) {
            $raw = file_get_contents($file);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($data) || !is_array($data['notes'] ?? null)) {
                continue;
            }

            $changed = false;
            foreach ($data['notes'] as $date => $entries) {
                if (!is_array($entries)) {
                    continue;
                }
                $filtered = array_values(array_filter($entries, static function ($entry) use ($userKeys, $normalizedName): bool {
                    if (!is_array($entry)) {
                        return true;
                    }

                    if (isset($userKeys[(string) ($entry['userKey'] ?? '')])) {
                        return false;
                    }

                    return $normalizedName === ''
                        || appUserManagementNormalizeName((string) ($entry['userName'] ?? '')) !== $normalizedName;
                }));
                if (count($filtered) !== count($entries)) {
                    $data['notes'][$date] = $filtered;
                    $changed = true;
                }
            }
            if (!$changed) {
                continue;
            }

            $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                throw new RuntimeException('Impossibile aggiornare le note dell’utente.');
            }
            $changes[] = ['file' => $file, 'original' => $raw, 'content' => $encoded . PHP_EOL];
        }

        $written = [];
        foreach ($changes as $change) {
            $temporary = $change['file'] . '.tmp-' . bin2hex(random_bytes(8));
            if (file_put_contents($temporary, $change['content'], LOCK_EX) === false || !rename($temporary, $change['file'])) {
                @unlink($temporary);
                throw new RuntimeException('Impossibile salvare le note aggiornate.');
            }
            $written[] = $change;
        }
    } catch (Throwable $error) {
        foreach (array_reverse($written ?? []) as $change) {
            $temporary = $change['file'] . '.restore-' . bin2hex(random_bytes(8));
            if (file_put_contents($temporary, $change['original'], LOCK_EX) !== false) {
                @rename($temporary, $change['file']);
            } else {
                @unlink($temporary);
            }
        }
        throw $error;
    } finally {
        foreach ($locks as $lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

$sessionUser = $_SESSION['user'] ?? null;
$department = trim((string) ($_POST['reparto'] ?? ''));
if (!is_array($sessionUser) || (int) ($sessionUser['capo'] ?? 0) !== 3 || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    appUserManagementRedirect($department);
}
if (!app_csrf_request_is_valid()) {
    appUserManagementFlash('danger', 'Richiesta non valida. Ricarica la pagina e riprova.');
    appUserManagementRedirect($department);
}
if (!$connessione || !($pdo instanceof PDO)) {
    appUserManagementFlash('danger', 'Database non disponibile. Riprova più tardi.');
    appUserManagementRedirect($department);
}

$action = (string) ($_POST['action'] ?? '');
$targetCf = trim((string) ($_POST['user_cf'] ?? ''));
$viewerCf = (string) ($sessionUser['cf'] ?? '');

try {
    if (!in_array($action, ['deactivate', 'reactivate', 'delete', 'set_box_info'], true) || $targetCf === '') {
        throw new RuntimeException('Operazione non valida.');
    }
    if (hash_equals($viewerCf, $targetCf)) {
        throw new RuntimeException('Non puoi modificare o eliminare il tuo account amministratore.');
    }

    $pdo->beginTransaction();
    $targetQuery = $pdo->prepare('SELECT cod_fiscale, nome, cognome, email, capo, reparto, attivo FROM utenti WHERE cod_fiscale = ? LIMIT 1 FOR UPDATE');
    $targetQuery->execute([$targetCf]);
    $target = $targetQuery->fetch(PDO::FETCH_ASSOC);
    if (!is_array($target)) {
        throw new RuntimeException('Utente non trovato.');
    }
    if ((int) ($target['capo'] ?? 0) === 3) {
        throw new RuntimeException('Gli account admin globali non possono essere gestiti da questa schermata.');
    }

    $name = trim((string) $target['nome'] . ' ' . (string) $target['cognome']);
    if ($action === 'set_box_info') {
        $hasImplicitBox = (string) ($target['reparto'] ?? '') === 'box'
            || ((int) ($target['capo'] ?? 0) === 1 && (string) ($target['reparto'] ?? '') === 'cs');
        if ($hasImplicitBox) {
            throw new RuntimeException('Questo account ha già l’abilitazione box automatica.');
        }

        $boxInfo = (int) ($_POST['box_info'] ?? 0) === 1 ? 1 : 0;
        $pdo->prepare('UPDATE utenti SET box_info = ?, session_version = session_version + 1 WHERE cod_fiscale = ?')
            ->execute([$boxInfo, $targetCf]);
        $pdo->commit();
        appUserManagementFlash('success', $boxInfo === 1 ? $name . ' è abilitato al box informazioni.' : $name . ' non è più abilitato al box informazioni.');
        appUserManagementRedirect($department);
    }

    if ($action === 'deactivate' || $action === 'reactivate') {
        $active = $action === 'reactivate' ? 1 : 0;
        $update = $pdo->prepare(
            'UPDATE utenti
             SET attivo = ?, session_version = session_version + 1, last_seen = CASE WHEN ? = 0 THEN NULL ELSE last_seen END
             WHERE cod_fiscale = ?'
        );
        $update->execute([$active, $active, $targetCf]);
        if ($active === 0) {
            $pdo->prepare('UPDATE push_subscriptions SET active = 0, updated_at = CURRENT_TIMESTAMP WHERE user_cf = ?')
                ->execute([$targetCf]);
        }
        $pdo->commit();
        appUserManagementFlash('success', $active === 1 ? $name . ' è stato riattivato.' : $name . ' è stato disattivato e scollegato.');
        appUserManagementRedirect($department);
    }

    $confirmation = trim((string) ($_POST['confirmation'] ?? ''));
    if (!hash_equals('ELIMINA ' . $targetCf, $confirmation)) {
        throw new RuntimeException('Per confermare digita esattamente: ELIMINA ' . $targetCf);
    }

    $noteKeys = [$targetCf];
    $inviteIdentityQuery = $pdo->prepare(
        'SELECT invited_cf, accepted_user_cf
         FROM user_invites
         WHERE LOWER(invited_email) = LOWER(?)'
    );
    $inviteIdentityQuery->execute([(string) $target['email']]);
    foreach ($inviteIdentityQuery->fetchAll(PDO::FETCH_ASSOC) as $inviteIdentity) {
        $noteKeys[] = (string) ($inviteIdentity['invited_cf'] ?? '');
        $noteKeys[] = (string) ($inviteIdentity['accepted_user_cf'] ?? '');
    }
    $sameNameQuery = $pdo->prepare('SELECT COUNT(*) FROM utenti WHERE nome = ? AND cognome = ?');
    $sameNameQuery->execute([(string) $target['nome'], (string) $target['cognome']]);
    $noteName = (int) $sameNameQuery->fetchColumn() === 1 ? $name : null;

    $pdo->prepare('DELETE FROM communication_recipients WHERE recipient_cf = ?')->execute([$targetCf]);
    $pdo->prepare('DELETE cr FROM communication_recipients cr JOIN communications c ON c.id = cr.communication_id WHERE c.author_cf = ?')->execute([$targetCf]);
    $pdo->prepare('DELETE FROM communications WHERE author_cf = ?')->execute([$targetCf]);
    $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_cf = ?')->execute([$targetCf]);
    $pdo->prepare('DELETE FROM push_subscriptions WHERE user_cf = ?')->execute([$targetCf]);
    $pdo->prepare('DELETE FROM schedule_change_log WHERE user_cf = ? OR changed_by_cf = ?')->execute([$targetCf, $targetCf]);
    $pdo->prepare('DELETE FROM schedule_adjustment_day_locks WHERE user_cf = ?')->execute([$targetCf]);
    $pdo->prepare('DELETE FROM schedule_adjustment_requests WHERE user_cf = ? OR decided_by_cf = ?')->execute([$targetCf, $targetCf]);
    $pdo->prepare('DELETE FROM extra_hour_requests WHERE user_cf = ?')->execute([$targetCf]);
    $pdo->prepare('UPDATE extra_hour_requests SET origin_decided_by_cf = NULL WHERE origin_decided_by_cf = ?')->execute([$targetCf]);
    $pdo->prepare('UPDATE extra_hour_requests SET target_decided_by_cf = NULL WHERE target_decided_by_cf = ?')->execute([$targetCf]);
    $pdo->prepare('UPDATE customer_orders SET taken_by_cf = NULL WHERE taken_by_cf = ?')->execute([$targetCf]);
    $pdo->prepare('UPDATE customer_order_events SET actor_cf = NULL WHERE actor_cf = ?')->execute([$targetCf]);
    $pdo->prepare('DELETE FROM customer_order_notifications WHERE recipient_cf = ?')->execute([$targetCf]);
    $pdo->prepare('UPDATE schedule_name_mappings SET created_by_cf = NULL WHERE created_by_cf = ?')->execute([$targetCf]);
    $pdo->prepare('DELETE FROM schedule_name_mappings WHERE user_cf = ?')->execute([$targetCf]);
    $pdo->prepare('DELETE FROM user_invites WHERE invited_by_cf = ? OR invited_cf = ? OR accepted_user_cf = ? OR LOWER(invited_email) = LOWER(?)')
        ->execute([$targetCf, $targetCf, $targetCf, (string) $target['email']]);
    $pdo->prepare('DELETE FROM utenti WHERE cod_fiscale = ?')->execute([$targetCf]);

    appUserManagementRemoveNotes($noteKeys, $noteName);
    $pdo->commit();
    appUserManagementFlash('success', $name . ' e i relativi dati personali sono stati eliminati definitivamente.');
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Gestione utente non riuscita: ' . $error->getMessage());
    appUserManagementFlash('danger', $error->getMessage());
}

appUserManagementRedirect($department);

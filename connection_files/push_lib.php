<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app_config.php';

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

const APP_PUSH_DAYS = [
    'lunedì' => 1,
    'martedì' => 2,
    'mercoledì' => 3,
    'giovedì' => 4,
    'venerdì' => 5,
    'sabato' => 6,
    'domenica' => 7,
];

function appPushStorageDir(): string
{
    return rtrim(PUSH_STORAGE_DIR, DIRECTORY_SEPARATOR);
}

function appPushConfigPath(): string
{
    return appPushStorageDir() . DIRECTORY_SEPARATOR . 'push_vapid.json';
}

function appPushEnsureStorageDir(): void
{
    $dir = appPushStorageDir();
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('Impossibile creare la cartella storage push');
    }
}

function appPushBase64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function appPushOpenSslConfigPath(): ?string
{
    $phpIni = php_ini_loaded_file();
    $phpDir = is_string($phpIni) && $phpIni !== '' ? dirname($phpIni) : null;
    $candidates = [
        getenv('OPENSSL_CONF') ?: null,
        $phpDir ? $phpDir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf' : null,
        $phpDir ? $phpDir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'openssl' . DIRECTORY_SEPARATOR . 'openssl.cnf' : null,
        PHP_BINDIR . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
        PHP_BINDIR . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'openssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
    ];

    foreach ($candidates as $path) {
        if (is_string($path) && $path !== '' && is_file($path)) {
            return $path;
        }
    }

    return null;
}

function appPushGenerateConfig(): array
{
    $options = [
        'curve_name' => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'private_key_bits' => 2048,
    ];
    $configPath = appPushOpenSslConfigPath();
    if ($configPath !== null) {
        // XAMPP on Windows does not always expose its OpenSSL config automatically.
        $options['config'] = $configPath;
    }

    $key = openssl_pkey_new($options);
    if ($key === false) {
        throw new RuntimeException('Impossibile generare le chiavi VAPID con OpenSSL');
    }

    $details = openssl_pkey_get_details($key);
    $ec = is_array($details) ? ($details['ec'] ?? null) : null;
    if (!is_array($ec) || !isset($ec['x'], $ec['y'], $ec['d'])) {
        throw new RuntimeException('OpenSSL non ha restituito una chiave EC valida per VAPID');
    }

    $publicKey = "\x04"
        . str_pad((string) $ec['x'], 32, "\0", STR_PAD_LEFT)
        . str_pad((string) $ec['y'], 32, "\0", STR_PAD_LEFT);
    $privateKey = str_pad((string) $ec['d'], 32, "\0", STR_PAD_LEFT);

    return [
        'subject' => PUSH_VAPID_SUBJECT,
        'publicKey' => appPushBase64UrlEncode($publicKey),
        'privateKey' => appPushBase64UrlEncode($privateKey),
        'createdAt' => gmdate(DateTimeInterface::ATOM),
    ];
}

function appPushLoadConfig(): array
{
    appPushEnsureStorageDir();
    $path = appPushConfigPath();

    if (is_file($path)) {
        $raw = file_get_contents($path);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded['publicKey']) && !empty($decoded['privateKey'])) {
                if (empty($decoded['subject'])) {
                    $decoded['subject'] = PUSH_VAPID_SUBJECT;
                }
                return $decoded;
            }
        }
    }

    $config = appPushGenerateConfig();
    $written = file_put_contents(
        $path,
        json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        LOCK_EX
    );

    if ($written === false) {
        throw new RuntimeException('Impossibile salvare la configurazione VAPID');
    }

    return $config;
}

function appPushPublicKey(): string
{
    $config = appPushLoadConfig();
    return (string) ($config['publicKey'] ?? '');
}

function appPushVapidAuth(): array
{
    $config = appPushLoadConfig();

    return [
        'VAPID' => [
            'subject' => (string) ($config['subject'] ?? PUSH_VAPID_SUBJECT),
            'publicKey' => (string) ($config['publicKey'] ?? ''),
            'privateKey' => (string) ($config['privateKey'] ?? ''),
        ],
    ];
}

function appPushWebPush(): WebPush
{
    return new WebPush(appPushVapidAuth());
}

function appPushNormalizeText(string $value): string
{
    $value = trim((string) preg_replace('/\s+/u', ' ', $value));
    if ($value === '') {
        return '';
    }

    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false) {
        $value = $ascii;
    }

    $value = mb_strtoupper($value, 'UTF-8');
    $value = preg_replace('/[^A-Z0-9 ]+/', ' ', $value) ?? $value;

    return trim((string) preg_replace('/\s+/u', ' ', $value));
}

function appPushNormalizeLabel(string $name): string
{
    return appPushNormalizeText($name);
}

function appPushUserLabels(array $user): array
{
    $nome = appPushNormalizeText((string) ($user['nome'] ?? ''));
    $cognome = appPushNormalizeText((string) ($user['cognome'] ?? ''));

    $labels = [];
    if ($nome !== '' && $cognome !== '') {
        $labels[] = $nome . ' ' . $cognome;
        $labels[] = $cognome . ' ' . $nome;
    } elseif ($nome !== '') {
        $labels[] = $nome;
    } elseif ($cognome !== '') {
        $labels[] = $cognome;
    }

    return array_values(array_unique(array_filter($labels, static fn ($label) => $label !== '')));
}

function appPushLoadUsers(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT cod_fiscale, nome, cognome FROM utenti');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $index = [];

    foreach ($rows as $row) {
        foreach (appPushUserLabels($row) as $label) {
            $index[$label][] = $row;
        }
    }

    return $index;
}

function appPushMatchUser(array $userIndex, string $scheduleName): ?array
{
    $label = appPushNormalizeLabel($scheduleName);
    if ($label === '' || empty($userIndex[$label])) {
        return null;
    }

    $matches = $userIndex[$label];
    if (!is_array($matches) || count($matches) !== 1) {
        return null;
    }

    return $matches[0];
}

function appPushWeekStartDate(int $isoYear, int $isoWeek): DateTimeImmutable
{
    return (new DateTimeImmutable())
        ->setISODate($isoYear, $isoWeek, 1)
        ->setTime(0, 0, 0);
}

function appPushDateLabel(DateTimeImmutable $date): string
{
    $months = [
        1 => 'gennaio',
        2 => 'febbraio',
        3 => 'marzo',
        4 => 'aprile',
        5 => 'maggio',
        6 => 'giugno',
        7 => 'luglio',
        8 => 'agosto',
        9 => 'settembre',
        10 => 'ottobre',
        11 => 'novembre',
        12 => 'dicembre',
    ];

    $day = (int) $date->format('j');
    $month = $months[(int) $date->format('n')] ?? $date->format('n');
    $year = $date->format('Y');

    return $day . ' ' . $month . ' ' . $year;
}

function appPushExtractIsoWeekYear(string $filename): array
{
    if (preg_match('/\((\d{1,2})-(\d{4})\)\.(xlsx|ods)$/i', $filename, $matches)) {
        return [
            'week' => (int) $matches[1],
            'year' => (int) $matches[2],
        ];
    }

    $now = new DateTimeImmutable('now');
    return [
        'week' => (int) $now->format('W'),
        'year' => (int) $now->format('o'),
    ];
}

function appPushWeekDayMap(int $isoYear, int $isoWeek): array
{
    $start = appPushWeekStartDate($isoYear, $isoWeek);
    $map = [];

    foreach (APP_PUSH_DAYS as $label => $dayNumber) {
        $map[$label] = $start->modify('+' . ($dayNumber - 1) . ' days');
    }

    return $map;
}

function appPushDecodeJsonFile(string $filePath): array
{
    if (!is_file($filePath)) {
        return [];
    }

    $raw = file_get_contents($filePath);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function appPushIndexRows(array $rows): array
{
    $indexed = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $isUnregistered = !empty($row['UTENTE_NON_REGISTRATO']);
        $userCf = appPushNormalizeText((string) ($row['COD_FISCALE'] ?? ''));
        $name = appPushNormalizeLabel((string) ($row['ADDETTO'] ?? ''));
        $key = $isUnregistered
            ? 'UNREGISTERED:' . $name
            : ($userCf !== '' ? 'CF:' . $userCf : 'NAME:' . $name);
        if ($key === 'NAME:' || $key === 'UNREGISTERED:') {
            continue;
        }

        $indexed[$key] = $row;
    }

    return $indexed;
}

function appPushBuildChangeSet(array $previousRows, array $currentRows, PDO $pdo, int $isoYear, int $isoWeek): array
{
    $previousIndex = appPushIndexRows($previousRows);
    $currentIndex = appPushIndexRows($currentRows);
    $userIndex = appPushLoadUsers($pdo);
    $days = appPushWeekDayMap($isoYear, $isoWeek);

    $generalChanged = $previousIndex === [] && $currentIndex !== [];
    $targets = [];

    foreach ($currentIndex as $scheduleKey => $currentRow) {
        $previousRow = $previousIndex[$scheduleKey] ?? null;
        $scheduleName = (string) ($currentRow['ADDETTO'] ?? '');
        $scheduleCf = trim((string) ($currentRow['COD_FISCALE'] ?? ''));
        $isUnregistered = !empty($currentRow['UTENTE_NON_REGISTRATO']);
        $scheduleUser = $isUnregistered
            ? null
            : ($scheduleCf !== ''
            ? ['cod_fiscale' => $scheduleCf]
            : appPushMatchUser($userIndex, $scheduleName));

        if (!is_array($previousRow)) {
            $generalChanged = true;
            if ($scheduleUser && $previousIndex !== []) {
                $targets[$scheduleUser['cod_fiscale']][] = [
                    'scheduleName' => $scheduleName,
                    'dayKey' => null,
                    'dateLabel' => null,
                    'old' => null,
                    'new' => 'nuovo orario',
                ];
            }
            continue;
        }

        foreach (APP_PUSH_DAYS as $dayLabel => $_dayNumber) {
            $oldValue = appPushNormalizeText((string) ($previousRow[$dayLabel] ?? ''));
            $newValue = appPushNormalizeText((string) ($currentRow[$dayLabel] ?? ''));

            if ($oldValue === $newValue) {
                continue;
            }

            $generalChanged = true;

            if (!$scheduleUser || !isset($days[$dayLabel]) || !($days[$dayLabel] instanceof DateTimeImmutable)) {
                continue;
            }

            $targets[$scheduleUser['cod_fiscale']][] = [
                'scheduleName' => $scheduleName,
                'dayKey' => $dayLabel,
                'scheduleDate' => $days[$dayLabel]->format('Y-m-d'),
                'dateLabel' => appPushDateLabel($days[$dayLabel]),
                'old' => (string) ($previousRow[$dayLabel] ?? ''),
                'new' => (string) ($currentRow[$dayLabel] ?? ''),
            ];
        }
    }

    foreach ($previousIndex as $scheduleKey => $_row) {
        if (!isset($currentIndex[$scheduleKey])) {
            $generalChanged = true;
        }
    }

    return [
        'generalChanged' => $generalChanged,
        'targets' => $targets,
        'userIndex' => $userIndex,
    ];
}

function appPushStoreScheduleChanges(
    PDO $pdo,
    string $batchId,
    string $userCf,
    string $changedByCf,
    int $isoYear,
    int $isoWeek,
    string $sourceFile,
    array $entries
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO schedule_change_log
            (batch_id, user_cf, changed_by_cf, iso_year, iso_week, schedule_date, day_name, previous_shift, new_shift, source_file)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stored = 0;

    foreach ($entries as $entry) {
        if (!is_array($entry) || empty($entry['scheduleDate']) || empty($entry['dayKey'])) {
            continue;
        }

        $stmt->execute([
            $batchId,
            $userCf,
            $changedByCf,
            $isoYear,
            $isoWeek,
            (string) $entry['scheduleDate'],
            (string) $entry['dayKey'],
            (string) ($entry['old'] ?? ''),
            (string) ($entry['new'] ?? ''),
            $sourceFile,
        ]);
        $stored++;
    }

    return $stored;
}

function appPushStoreSubscription(PDO $pdo, string $userCf, array $subscription, ?string $userAgent = null): void
{
    $endpoint = (string) ($subscription['endpoint'] ?? '');
    $keys = is_array($subscription['keys'] ?? null) ? $subscription['keys'] : [];
    $p256dh = (string) ($keys['p256dh'] ?? '');
    $auth = (string) ($keys['auth'] ?? '');
    $contentEncoding = (string) ($subscription['contentEncoding'] ?? 'aes128gcm');

    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        throw new InvalidArgumentException('Subscription push non valida');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO push_subscriptions (user_cf, endpoint, p256dh, auth_token, content_encoding, user_agent, active)
         VALUES (?, ?, ?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
            user_cf = VALUES(user_cf),
            p256dh = VALUES(p256dh),
            auth_token = VALUES(auth_token),
            content_encoding = VALUES(content_encoding),
            user_agent = VALUES(user_agent),
            active = 1,
            updated_at = CURRENT_TIMESTAMP'
    );

    $stmt->execute([
        $userCf,
        $endpoint,
        $p256dh,
        $auth,
        $contentEncoding,
        $userAgent,
    ]);
}

function appPushSubscriptionEndpoint(array $subscription): string
{
    return trim((string) ($subscription['endpoint'] ?? ''));
}

function appPushDeactivateSubscription(PDO $pdo, string $endpoint): void
{
    if ($endpoint === '') {
        return;
    }

    $statement = $pdo->prepare('UPDATE push_subscriptions SET active = 0, updated_at = CURRENT_TIMESTAMP WHERE endpoint = ?');
    $statement->execute([$endpoint]);
}

function appPushSubscriptionIsActiveForUser(PDO $pdo, string $userCf, string $endpoint): bool
{
    if ($userCf === '' || $endpoint === '') {
        return false;
    }

    $statement = $pdo->prepare('SELECT user_cf FROM push_subscriptions WHERE endpoint = ? AND active = 1 LIMIT 1');
    $statement->execute([$endpoint]);
    $ownerCf = $statement->fetchColumn();

    if ($ownerCf === false) {
        return false;
    }

    if (hash_equals((string) $ownerCf, $userCf)) {
        return true;
    }

    // Lo stesso browser è stato aperto con un altro account: non lasciare
    // che l'account precedente continui a ricevere notifiche su questo device.
    appPushDeactivateSubscription($pdo, $endpoint);
    return false;
}

function appPushSendPayload(PDO $pdo, array $payload, ?string $userCf = null): array
{
    $query = 'SELECT * FROM push_subscriptions WHERE active = 1';
    $params = [];

    if ($userCf !== null) {
        $query .= ' AND user_cf = ?';
        $params[] = $userCf;
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $webPush = appPushWebPush();
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payloadJson === false) {
        throw new RuntimeException('Impossibile codificare il payload push');
    }

    $sent = 0;
    $failed = 0;
    $expiredEndpoints = [];

    foreach ($subscriptions as $row) {
        $subscription = Subscription::create([
            'endpoint' => (string) $row['endpoint'],
            'keys' => [
                'p256dh' => (string) $row['p256dh'],
                'auth' => (string) $row['auth_token'],
            ],
            'contentEncoding' => (string) ($row['content_encoding'] ?: 'aes128gcm'),
        ]);

        try {
            $report = $webPush->sendOneNotification($subscription, $payloadJson, [
                'TTL' => 3600,
            ]);

            if ($report->isSuccess()) {
                $sent++;
                continue;
            }

            $failed++;
            if ($report->isSubscriptionExpired()) {
                $expiredEndpoints[] = (string) $row['endpoint'];
            }
        } catch (Throwable $e) {
            $failed++;
        }
    }

    foreach ($expiredEndpoints as $endpoint) {
        $cleanup = $pdo->prepare('UPDATE push_subscriptions SET active = 0 WHERE endpoint = ?');
        $cleanup->execute([$endpoint]);
    }

    return [
        'sent' => $sent,
        'failed' => $failed,
        'subscriptions' => count($subscriptions),
    ];
}

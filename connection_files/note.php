<?php
header("Content-Type: application/json; charset=utf-8");

ob_start();
require __DIR__ . '/../session_bootstrap.php';
app_session_start();

function jsonResponse(array $payload, int $status = 200): void {
    if (ob_get_length()) {
        ob_clean();
    }

    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

set_error_handler(static function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $storageDir = __DIR__ . '/../note_json';

    if (!is_dir($storageDir) && !mkdir($storageDir, 0750, true) && !is_dir($storageDir)) {
        jsonResponse([
            "ok" => false,
            "error" => "Impossibile creare la cartella delle note",
        ], 500);
    }

    if (!is_writable($storageDir)) {
        jsonResponse([
            "ok" => false,
            "error" => "Cartella note non scrivibile",
        ], 500);
    }

    $method = $_SERVER["REQUEST_METHOD"] ?? "GET";

    $sessionUser = $_SESSION["user"] ?? null;
    $hasSessionUser = is_array($sessionUser);
    $userName = $hasSessionUser
        ? trim(((string) ($sessionUser["nome"] ?? "")) . " " . ((string) ($sessionUser["cognome"] ?? "")))
        : "";
    $userKey = $hasSessionUser ? trim((string) ($sessionUser["cf"] ?? "")) : "";

    if ($userKey === "") {
        $userKey = $userName !== "" ? $userName : session_id();
    }

    if (!$hasSessionUser) {
        jsonResponse([
            "ok" => false,
            "error" => "Accesso richiesto",
        ], 401);
    }

    if ($method === 'POST' && !app_csrf_request_is_valid()) {
        jsonResponse(['ok' => false, 'error' => 'Richiesta non valida. Ricarica la pagina e riprova.'], 403);
    }
    app_session_write_close_if_active();

    require __DIR__ . '/connection.php';
    if (!$connessione || !($pdo instanceof PDO)) {
        jsonResponse([
            "ok" => false,
            "error" => "Servizio note temporaneamente non disponibile",
        ], 503);
    }

    $capo = (int) ($sessionUser['capo'] ?? 0);
    $viewerDepartment = trim((string) ($sessionUser['reparto'] ?? ''));
    $userDepartments = [];
    if (in_array($capo, [1, 2], true)) {
        $departmentQuery = $pdo->query('SELECT cod_fiscale, reparto FROM utenti WHERE attivo = 1');
        foreach ($departmentQuery->fetchAll(PDO::FETCH_ASSOC) as $userDepartment) {
            $userDepartments[(string) $userDepartment['cod_fiscale']] = (string) ($userDepartment['reparto'] ?? '');
        }
    }

    $canViewEntry = static function (array $entry) use ($userKey, $capo, $viewerDepartment, $userDepartments): bool {
        $entryUserKey = (string) ($entry['userKey'] ?? '');
        if ($entryUserKey === $userKey || $capo === 3) {
            return true;
        }

        return in_array($capo, [1, 2], true)
            && $viewerDepartment !== ''
            && ($userDepartments[$entryUserKey] ?? '') === $viewerDepartment;
    };

    $filterNotesForViewer = static function (array $notes) use ($canViewEntry): array {
        foreach ($notes as $dateKey => $entries) {
            if (!is_array($entries)) {
                $notes[$dateKey] = [];
                continue;
            }

            $notes[$dateKey] = array_values(array_filter($entries, $canViewEntry));
            if ($notes[$dateKey] === []) {
                unset($notes[$dateKey]);
            }
        }

        return $notes;
    };

    $normalizeDateKey = static function ($dateValue) {
        $dateValue = trim((string) $dateValue);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue)) {
            return null;
        }

        $date = DateTime::createFromFormat('Y-m-d', $dateValue);
        if (!$date || $date->format('Y-m-d') !== $dateValue) {
            return null;
        }

        return $dateValue;
    };

    $normalizeMonthKey = static function ($monthValue) {
        $monthValue = trim((string) $monthValue);
        if (!preg_match('/^\d{4}-\d{2}$/', $monthValue)) {
            return null;
        }

        return $monthValue;
    };

    $monthFilePath = static function ($storageDir, $monthKey) {
        return $storageDir . DIRECTORY_SEPARATOR . $monthKey . '.json';
    };

    $emptyPayload = static function ($monthKey) {
        return [
            "month" => $monthKey,
            "notes" => [],
        ];
    };

    $loadMonthNotes = static function ($filePath, $monthKey) use ($emptyPayload) {
        if (!is_file($filePath)) {
            return $emptyPayload($monthKey);
        }

        $raw = file_get_contents($filePath);
        if ($raw === false || trim($raw) === "") {
            return $emptyPayload($monthKey);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $emptyPayload($monthKey);
        }

        if (!isset($decoded["month"])) {
            $decoded["month"] = $monthKey;
        }

        if (!isset($decoded["notes"]) || !is_array($decoded["notes"])) {
            $decoded["notes"] = [];
        }

        return $decoded;
    };

    $normalizeNotesStructure = static function ($notes) {
        if (!is_array($notes)) {
            return [];
        }

        foreach ($notes as $dateKey => $entries) {
            if (!is_array($entries)) {
                $notes[$dateKey] = [];
                continue;
            }

            $notes[$dateKey] = array_values(array_filter($entries, static function ($entry) {
                return is_array($entry) && isset($entry["userKey"], $entry["userName"], $entry["note"]);
            }));
        }

        return $notes;
    };

    $noteEntryId = static function (string $dateKey, array $entry): string {
        return hash('sha256', implode("\n", [
            $dateKey,
            (string) ($entry['userKey'] ?? ''),
            (string) ($entry['updatedAt'] ?? ''),
            (string) ($entry['note'] ?? ''),
        ]));
    };

    $saveMonthNotes = static function ($filePath, array $payload) {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return [
                "ok" => false,
                "details" => "JSON non valido",
            ];
        }

        $temporaryPath = $filePath . '.tmp-' . bin2hex(random_bytes(8));
        $written = file_put_contents($temporaryPath, $json . PHP_EOL, LOCK_EX);
        if ($written === false || !rename($temporaryPath, $filePath)) {
            @unlink($temporaryPath);
            return [
                "ok" => false,
                "details" => "Scrittura fallita su " . $filePath,
            ];
        }

        return [
            "ok" => true,
        ];
    };

    $withMonthLock = static function (string $filePath, callable $callback) {
        $lockHandle = fopen($filePath . '.lock', 'c');
        if ($lockHandle === false) {
            throw new RuntimeException('Impossibile bloccare il file delle note');
        }

        try {
            if (!flock($lockHandle, LOCK_EX)) {
                throw new RuntimeException('Impossibile ottenere il blocco delle note');
            }

            return $callback();
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    };

    if ($method === "GET") {
        if (isset($_GET["all"]) && $_GET["all"] === "1") {
            if (!in_array($capo, [1, 2, 3], true)) {
                jsonResponse([
                    "ok" => false,
                    "error" => "Accesso negato",
                ], 403);
            }

            $monthFiles = glob($storageDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
            rsort($monthFiles, SORT_STRING);

            $months = [];
            foreach ($monthFiles as $monthFile) {
                $monthKey = basename($monthFile, '.json');
                $payload = $loadMonthNotes($monthFile, $monthKey);
                $payload["notes"] = $normalizeNotesStructure($payload["notes"]);
                $payload["notes"] = $filterNotesForViewer($payload["notes"]);

                $entries = [];
                foreach ($payload["notes"] as $entryDate => $dayEntries) {
                    foreach ($dayEntries as $dayEntry) {
                        $entries[] = [
                            "date" => $entryDate,
                            "entryId" => $noteEntryId((string) $entryDate, $dayEntry),
                            "userKey" => (string) ($dayEntry["userKey"] ?? ""),
                            "userName" => (string) ($dayEntry["userName"] ?? ""),
                            "note" => (string) ($dayEntry["note"] ?? ""),
                            "updatedAt" => (string) ($dayEntry["updatedAt"] ?? ""),
                        ];
                    }
                }

                usort($entries, static function ($left, $right) {
                    $dateCompare = strcmp((string) ($right["date"] ?? ""), (string) ($left["date"] ?? ""));
                    if ($dateCompare !== 0) {
                        return $dateCompare;
                    }

                    return strcmp((string) ($right["updatedAt"] ?? ""), (string) ($left["updatedAt"] ?? ""));
                });

                $months[] = [
                    "month" => $monthKey,
                    "entries" => $entries,
                ];
            }

            jsonResponse([
                "ok" => true,
                "months" => $months,
            ]);
        }

        $monthKey = $normalizeMonthKey($_GET["month"] ?? "");
        $dateKey = $normalizeDateKey($_GET["date"] ?? "");

        if ($monthKey === null && $dateKey !== null) {
            $monthKey = substr($dateKey, 0, 7);
        }

        if ($monthKey === null) {
            jsonResponse([
                "ok" => false,
                "error" => "Parametro month o date non valido",
            ], 400);
        }

        $filePath = $monthFilePath($storageDir, $monthKey);
        $payload = $loadMonthNotes($filePath, $monthKey);
        $payload["notes"] = $normalizeNotesStructure($payload["notes"]);
        $payload["notes"] = $filterNotesForViewer($payload["notes"]);

        $response = [
            "ok" => true,
            "month" => $monthKey,
            "notes" => $payload["notes"],
        ];

        if ($dateKey !== null) {
            $response["date"] = $dateKey;
            $response["dayNotes"] = array_values($payload["notes"][$dateKey] ?? []);
            $response["currentUserNote"] = "";

            foreach ($response["dayNotes"] as $entry) {
                if (($entry["userKey"] ?? "") === $userKey) {
                    $response["currentUserNote"] = (string) ($entry["note"] ?? "");
                    break;
                }
            }
        }

        jsonResponse($response);
    }

    if ($method !== "POST") {
        jsonResponse([
            "ok" => false,
            "error" => "Metodo non consentito",
        ], 405);
    }

    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'delete_admin') {
        if (!in_array($capo, [1, 2, 3], true)) {
            jsonResponse(['ok' => false, 'error' => 'Accesso negato'], 403);
        }

        $dateKey = $normalizeDateKey($_POST['date'] ?? '');
        $entryId = trim((string) ($_POST['entry_id'] ?? ''));
        if ($dateKey === null || !preg_match('/^[a-f0-9]{64}$/', $entryId)) {
            jsonResponse(['ok' => false, 'error' => 'Nota non valida'], 400);
        }

        $monthKey = substr($dateKey, 0, 7);
        $filePath = $monthFilePath($storageDir, $monthKey);
        $payload = $withMonthLock($filePath, static function () use (
            $filePath,
            $monthKey,
            $dateKey,
            $entryId,
            $canViewEntry,
            $noteEntryId,
            $loadMonthNotes,
            $normalizeNotesStructure,
            $saveMonthNotes
        ) {
            $payload = $loadMonthNotes($filePath, $monthKey);
            $payload['notes'] = $normalizeNotesStructure($payload['notes']);
            $entries = $payload['notes'][$dateKey] ?? [];
            $entryIndex = null;

            foreach ($entries as $index => $entry) {
                if (!is_array($entry) || !hash_equals($noteEntryId($dateKey, $entry), $entryId)) {
                    continue;
                }
                if (!$canViewEntry($entry)) {
                    throw new RuntimeException('Non puoi eliminare questa nota.');
                }
                $entryIndex = $index;
                break;
            }

            if ($entryIndex === null) {
                throw new RuntimeException('La nota non è più disponibile. Ricarica l’elenco.');
            }

            array_splice($entries, $entryIndex, 1);
            if ($entries === []) {
                unset($payload['notes'][$dateKey]);
            } else {
                $payload['notes'][$dateKey] = array_values($entries);
            }

            $saveResult = $saveMonthNotes($filePath, $payload);
            if (empty($saveResult['ok'])) {
                throw new RuntimeException('Impossibile eliminare la nota.');
            }

            return $payload;
        });

        jsonResponse([
            'ok' => true,
            'month' => $monthKey,
            'date' => $dateKey,
            'notes' => array_values($filterNotesForViewer([
                $dateKey => $payload['notes'][$dateKey] ?? [],
            ])[$dateKey] ?? []),
        ]);
    }
    if ($action !== 'save') {
        jsonResponse(['ok' => false, 'error' => 'Operazione non valida'], 400);
    }

    $dateKey = $normalizeDateKey($_POST["date"] ?? "");
    $note = trim((string) ($_POST["note"] ?? ""));

    if ($dateKey === null) {
        jsonResponse([
            "ok" => false,
            "error" => "Data non valida",
        ], 400);
    }
    if (mb_strlen($note) > 2000) {
        jsonResponse([
            "ok" => false,
            "error" => "La nota può contenere al massimo 2000 caratteri",
        ], 400);
    }

    $monthKey = substr($dateKey, 0, 7);
    $filePath = $monthFilePath($storageDir, $monthKey);
    $payload = $withMonthLock($filePath, static function () use (
        $filePath,
        $monthKey,
        $dateKey,
        $note,
        $userKey,
        $userName,
        $loadMonthNotes,
        $normalizeNotesStructure,
        $saveMonthNotes
    ) {
        // Ricarichiamo solo dopo aver ottenuto il lock: così due salvataggi
        // simultanei non si sovrascrivono a vicenda.
        $payload = $loadMonthNotes($filePath, $monthKey);
        $payload['notes'] = $normalizeNotesStructure($payload['notes']);

        if (!isset($payload['notes'][$dateKey]) || !is_array($payload['notes'][$dateKey])) {
            $payload['notes'][$dateKey] = [];
        }

        $entries = $payload['notes'][$dateKey];
        $entryIndex = null;
        foreach ($entries as $index => $entry) {
            if (($entry['userKey'] ?? '') === $userKey) {
                $entryIndex = $index;
                break;
            }
        }

        if ($note === '') {
            if ($entryIndex !== null) {
                array_splice($entries, $entryIndex, 1);
            }
        } else {
            $entry = [
                'userKey' => $userKey,
                'userName' => $userName !== '' ? $userName : $userKey,
                'note' => $note,
                'updatedAt' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
            ];

            if ($entryIndex !== null) {
                $entries[$entryIndex] = $entry;
            } else {
                $entries[] = $entry;
            }
        }

        if ($entries !== []) {
            $payload['notes'][$dateKey] = array_values($entries);
        } else {
            unset($payload['notes'][$dateKey]);
        }

        $saveResult = $saveMonthNotes($filePath, $payload);
        if (empty($saveResult['ok'])) {
            throw new RuntimeException('Impossibile salvare le note');
        }

        return $payload;
    });

    jsonResponse([
        "ok" => true,
        "month" => $monthKey,
        "date" => $dateKey,
        "notes" => array_values($filterNotesForViewer([
            $dateKey => $payload["notes"][$dateKey] ?? [],
        ])[$dateKey] ?? []),
    ]);
} catch (Throwable $e) {
    jsonResponse([
        "ok" => false,
        "error" => "Errore interno nel salvataggio note",
    ], 500);
} finally {
    restore_error_handler();
}

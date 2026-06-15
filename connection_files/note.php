<?php
header("Content-Type: application/json; charset=utf-8");

ob_start();
session_start();

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

    if (!is_dir($storageDir) && !mkdir($storageDir, 0777, true) && !is_dir($storageDir)) {
        jsonResponse([
            "ok" => false,
            "error" => "Impossibile creare la cartella delle note",
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

    $saveMonthNotes = static function ($filePath, array $payload) {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        return file_put_contents($filePath, $json . PHP_EOL, LOCK_EX) !== false;
    };

    if ($method === "GET") {
        if (isset($_GET["all"]) && $_GET["all"] === "1") {
            if (!$hasSessionUser || (int) ($sessionUser["capo"] ?? 0) !== 1) {
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

                $entries = [];
                foreach ($payload["notes"] as $entryDate => $dayEntries) {
                    foreach ($dayEntries as $dayEntry) {
                        $entries[] = [
                            "date" => $entryDate,
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

    if (!$hasSessionUser) {
        jsonResponse([
            "ok" => false,
            "error" => "Accesso richiesto",
        ], 401);
    }

    $dateKey = $normalizeDateKey($_POST["date"] ?? "");
    $note = trim((string) ($_POST["note"] ?? ""));

    if ($dateKey === null) {
        jsonResponse([
            "ok" => false,
            "error" => "Data non valida",
        ], 400);
    }

    $monthKey = substr($dateKey, 0, 7);
    $filePath = $monthFilePath($storageDir, $monthKey);
    $payload = $loadMonthNotes($filePath, $monthKey);
    $payload["notes"] = $normalizeNotesStructure($payload["notes"]);

    if (!isset($payload["notes"][$dateKey]) || !is_array($payload["notes"][$dateKey])) {
        $payload["notes"][$dateKey] = [];
    }

    $entries = $payload["notes"][$dateKey];
    $entryIndex = null;

    foreach ($entries as $index => $entry) {
        if (($entry["userKey"] ?? "") === $userKey) {
            $entryIndex = $index;
            break;
        }
    }

    if ($note === "") {
        if ($entryIndex !== null) {
            array_splice($entries, $entryIndex, 1);
        }
    } else {
        $entry = [
            "userKey" => $userKey,
            "userName" => $userName !== "" ? $userName : $userKey,
            "note" => $note,
            "updatedAt" => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
        ];

        if ($entryIndex !== null) {
            $entries[$entryIndex] = $entry;
        } else {
            $entries[] = $entry;
        }
    }

    if (count($entries) > 0) {
        $payload["notes"][$dateKey] = array_values($entries);
    } else {
        unset($payload["notes"][$dateKey]);
    }

    if (!$saveMonthNotes($filePath, $payload)) {
        jsonResponse([
            "ok" => false,
            "error" => "Impossibile salvare le note",
        ], 500);
    }

    jsonResponse([
        "ok" => true,
        "month" => $monthKey,
        "date" => $dateKey,
        "notes" => array_values($payload["notes"][$dateKey] ?? []),
    ]);
} catch (Throwable $e) {
    jsonResponse([
        "ok" => false,
        "error" => "Errore interno nel salvataggio note",
        "details" => $e->getMessage(),
    ], 500);
} finally {
    restore_error_handler();
}

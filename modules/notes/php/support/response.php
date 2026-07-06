<?php
declare(strict_types=1);

function noteResponse(array $payload, int $status = 200): void
{
    if (ob_get_length()) {
        ob_clean();
    }

    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
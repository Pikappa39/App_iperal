<?php
declare(strict_types=1);

if (!function_exists('appAuthPageEscape')) {
    function appAuthPageEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('appAuthLoginNextTarget')) {
    function appAuthLoginNextTarget(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) || str_contains($value, '\\')) {
            return 'index.php';
        }
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $value) || str_starts_with($value, '//')) {
            return 'index.php';
        }

        $parts = parse_url($value);
        if (!is_array($parts)) {
            return 'index.php';
        }

        $path = (string) ($parts['path'] ?? 'index.php');
        $basename = basename($path);
        if ($basename !== 'index.php' && $path !== '') {
            return 'index.php';
        }

        $next = 'index.php';
        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            $next .= '?' . $parts['query'];
        }

        return $next;
    }
}

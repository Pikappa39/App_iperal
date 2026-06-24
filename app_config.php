<?php

require_once __DIR__ . '/php_runtime.php';

if (!defined('APP_VERSION')) {
    define('APP_VERSION', '0.7.14');
}

if (!defined('APP_SCHEDULE_MAPPING_IGNORED_VALUE')) {
    define('APP_SCHEDULE_MAPPING_IGNORED_VALUE', '__IGNORED__');
}

if (!defined('PUSH_VAPID_SUBJECT')) {
    define('PUSH_VAPID_SUBJECT', 'https://myorari.it');
}

if (!defined('PUSH_STORAGE_DIR')) {
    define('PUSH_STORAGE_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'storage');
}

if (!function_exists('appLocalEnv')) {
    function appLocalEnv(): array
    {
        static $env = null;

        if (is_array($env)) {
            return $env;
        }

        $path = __DIR__ . '/app_local_env.php';
        if (is_file($path)) {
            $loaded = require $path;
            $env = is_array($loaded) ? $loaded : [];
        } else {
            $env = [];
        }

        return $env;
    }
}

if (!function_exists('appEnv')) {
    function appEnv(string $key): string
    {
        $value = getenv($key);
        if ($value !== false) {
            return trim((string) $value);
        }

        $localEnv = appLocalEnv();
        if (!array_key_exists($key, $localEnv)) {
            return '';
        }

        return trim((string) $localEnv[$key]);
    }
}

if (!function_exists('appHasEnv')) {
    function appHasEnv(string $key): bool
    {
        if (getenv($key) !== false) {
            return true;
        }

        $localEnv = appLocalEnv();
        return array_key_exists($key, $localEnv);
    }
}

if (!function_exists('appTurnstileSiteKey')) {
    function appTurnstileSiteKey(): string
    {
        return appEnv('APP_TURNSTILE_SITE_KEY');
    }
}

if (!function_exists('appTurnstileSecretKey')) {
    function appTurnstileSecretKey(): string
    {
        return appEnv('APP_TURNSTILE_SECRET_KEY');
    }
}

if (!function_exists('appTurnstileEnabled')) {
    function appTurnstileEnabled(): bool
    {
        return appTurnstileSiteKey() !== '' && appTurnstileSecretKey() !== '';
    }
}

if (!function_exists('appSelfRegistrationEnabled')) {
    function appSelfRegistrationEnabled(): bool
    {
        return appEnv('APP_ALLOW_SELF_REGISTRATION') === '1';
    }
}

if (!function_exists('appDepartments')) {
    function appDepartments(): array
    {
        return [
            'gro' => 'Grocery',
            'ls' => 'Freschi libero servizio',
            'orto' => 'Ortofrutta',
            'cs' => 'Casse',
            'box' => 'Box',
            'drv' => 'Drive',
            'gas' => 'Gastronomia/Panetteria',
            'mac' => 'Macelleria',
        ];
    }
}

if (!function_exists('appIsValidDepartment')) {
    function appIsValidDepartment(string $department): bool
    {
        return array_key_exists($department, appDepartments());
    }
}

if (!function_exists('appAvailableAvatars')) {
    function appAvailableAvatars(): array
    {
        $avatars = [];
        $files = glob(__DIR__ . '/img/*.png') ?: [];

        foreach ($files as $file) {
            $avatar = pathinfo($file, PATHINFO_FILENAME);
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $avatar) || str_starts_with($avatar, 'icon-')) {
                continue;
            }

            $avatars[] = $avatar;
        }

        $avatars = array_values(array_unique($avatars));
        usort($avatars, static function (string $left, string $right): int {
            if ($left === 'default') {
                return -1;
            }
            if ($right === 'default') {
                return 1;
            }

            return strnatcasecmp($left, $right);
        });

        return $avatars;
    }
}

if (!function_exists('appPublicUrl')) {
    function appPublicUrl(): string
    {
        return rtrim(appEnv('APP_PUBLIC_URL') ?: 'https://myorari.it', '/');
    }
}

if (!function_exists('appSmtpHost')) {
    function appSmtpHost(): string
    {
        return appEnv('APP_SMTP_HOST') ?: 'smtps.aruba.it';
    }
}

if (!function_exists('appSmtpPort')) {
    function appSmtpPort(): int
    {
        $port = (int) (appEnv('APP_SMTP_PORT') ?: '465');
        return $port > 0 && $port <= 65535 ? $port : 465;
    }
}

if (!function_exists('appSmtpUsername')) {
    function appSmtpUsername(): string
    {
        return appEnv('APP_SMTP_USERNAME') ?: 'supporto@myorari.it';
    }
}

if (!function_exists('appSmtpPassword')) {
    function appSmtpPassword(): string
    {
        return appEnv('APP_SMTP_PASSWORD');
    }
}

if (!function_exists('appSmtpFromName')) {
    function appSmtpFromName(): string
    {
        return appEnv('APP_SMTP_FROM_NAME') ?: 'MyOrari';
    }
}

<?php

const APP_VERSION = '0.2.13';
const PUSH_VAPID_SUBJECT = 'https://myorari.it';
const PUSH_STORAGE_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'storage';

function appEnv(string $key): string
{
    $value = getenv($key);
    if ($value === false) {
        return '';
    }

    return trim((string) $value);
}

function appTurnstileSiteKey(): string
{
    return appEnv('APP_TURNSTILE_SITE_KEY');
}

function appTurnstileSecretKey(): string
{
    return appEnv('APP_TURNSTILE_SECRET_KEY');
}

function appTurnstileEnabled(): bool
{
    return appTurnstileSiteKey() !== '' && appTurnstileSecretKey() !== '';
}

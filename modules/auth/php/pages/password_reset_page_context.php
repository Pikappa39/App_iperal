<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/app_config.php';
require_once __DIR__ . '/page_helpers.php';

function appAuthForgotPasswordPageContext(): array
{
    $turnstileEnabled = appTurnstileEnabled();

    return [
        'turnstileEnabled' => $turnstileEnabled,
        'turnstileSiteKey' => $turnstileEnabled ? appTurnstileSiteKey() : '',
    ];
}

function appAuthResetPasswordPageContext(): array
{
    $token = strtolower(trim((string) ($_GET['token'] ?? '')));

    return [
        'token' => $token,
        'tokenIsValid' => (bool) preg_match('/^[a-f0-9]{64}$/', $token),
    ];
}

<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/app_config.php';
require_once dirname(__DIR__, 4) . '/session_bootstrap.php';
require_once __DIR__ . '/page_helpers.php';

function appAuthLoginPageContext(): array
{
    app_session_start();

    $nextTarget = appAuthLoginNextTarget($_GET['next'] ?? 'index.php');
    if (isset($_SESSION['user'])) {
        header('Location: ' . $nextTarget, true, 302);
        exit;
    }

    $turnstileEnabled = appTurnstileEnabled();

    return [
        'csrfToken' => app_csrf_token(),
        'departments' => appDepartments(),
        'nextTarget' => $nextTarget,
        'selfRegistrationEnabled' => appSelfRegistrationEnabled(),
        'turnstileEnabled' => $turnstileEnabled,
        'turnstileSiteKey' => $turnstileEnabled ? appTurnstileSiteKey() : '',
    ];
}

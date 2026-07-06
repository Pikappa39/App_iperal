<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/app_config.php';
require_once dirname(__DIR__, 4) . '/session_bootstrap.php';

function appScheduleUploadEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function appScheduleUploadPageContext(): array
{
    app_session_start();

    $capo = (int) ($_SESSION['user']['capo'] ?? 0);
    if (!isset($_SESSION['user']) || !in_array($capo, [1, 2, 3], true)) {
        header('Location: index.php');
        exit;
    }

    $repartoCode = (string) ($_SESSION['user']['reparto'] ?? '');
    $departments = appDepartments();

    return [
        'capo' => $capo,
        'csrfToken' => app_csrf_token(),
        'departments' => $departments,
        'isGlobalAdmin' => $capo === 3,
        'repartoCode' => $repartoCode,
        'repartoLabel' => $departments[$repartoCode] ?? 'non assegnato',
    ];
}

<?php

require_once __DIR__ . '/../app_config.php';

function appInviteCanManage(array $sessionUser): bool
{
    return in_array((int) ($sessionUser['capo'] ?? 0), [1, 3], true);
}

function appInviteNormalizeEmail(string $email): string
{
    return strtolower(trim($email));
}

function appInviteNormalizeBadge(string $badge): string
{
    return strtoupper(trim($badge));
}

function appInviteNormalizeCf(string $cf): string
{
    return strtoupper(trim($cf));
}

function appInviteNormalizeName(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/\s+/u', ' ', $value);
    return is_string($value) ? $value : '';
}

function appInviteGenerateToken(): string
{
    return bin2hex(random_bytes(32));
}

function appInviteHashToken(string $token): string
{
    return hash('sha256', $token);
}

function appInviteBuildUrl(string $token): string
{
    return appPublicUrl() . '/accept_invite.php?token=' . rawurlencode($token);
}

function appInviteStatus(array $invite): string
{
    if (!empty($invite['revoked_at'])) {
        return 'revoked';
    }
    if (!empty($invite['accepted_at'])) {
        return 'accepted';
    }

    $expiresAt = strtotime((string) ($invite['expires_at'] ?? ''));
    if ($expiresAt !== false && $expiresAt < time()) {
        return 'expired';
    }

    return 'pending';
}

function appInviteStatusLabel(array $invite): string
{
    return match (appInviteStatus($invite)) {
        'accepted' => 'Attivato',
        'expired' => 'Scaduto',
        'revoked' => 'Revocato',
        default => 'In attesa',
    };
}

function appInviteDepartmentForManager(array $sessionUser, string $requestedDepartment): string
{
    $role = (int) ($sessionUser['capo'] ?? 0);
    if ($role === 3) {
        return appIsValidDepartment($requestedDepartment) ? $requestedDepartment : '';
    }

    $department = trim((string) ($sessionUser['reparto'] ?? ''));
    return appIsValidDepartment($department) ? $department : '';
}

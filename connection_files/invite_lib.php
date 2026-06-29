<?php

require_once __DIR__ . '/../app_config.php';
require_once __DIR__ . '/../account_identity.php';

function appInviteCanManage(array $sessionUser): bool
{
    return in_array((int) ($sessionUser['capo'] ?? 0), [1, 3], true);
}

function appInviteAllowedRoles(array $sessionUser): array
{
    return match ((int) ($sessionUser['capo'] ?? 0)) {
        3 => [0, 2, 1],
        1 => [0, 2],
        default => [],
    };
}

function appInviteRoleForManager(array $sessionUser, int $requestedRole): ?int
{
    return in_array($requestedRole, appInviteAllowedRoles($sessionUser), true) ? $requestedRole : null;
}

function appInviteRoleLabel(int $role): string
{
    return match ($role) {
        1 => 'Capo reparto',
        2 => 'Vice capo',
        default => 'Addetto',
    };
}

function appInviteCanAssignBoxInfo(array $sessionUser, string $department): bool
{
    if (!in_array($department, ['cs', 'box'], true)) {
        return false;
    }

    return (int) ($sessionUser['capo'] ?? 0) === 3
        || appUserHasBoxInfo($sessionUser)
        || ((int) ($sessionUser['capo'] ?? 0) === 1 && (string) ($sessionUser['reparto'] ?? '') === 'cs');
}

function appInviteHasBoxInfoPrivilege(array $invite): bool
{
    return appUserHasBoxInfo([
        'capo' => (int) ($invite['invited_capo'] ?? 0),
        'box_info' => (int) ($invite['invited_box_info'] ?? 0),
        'reparto' => (string) ($invite['reparto'] ?? ''),
    ]);
}

function appInviteNormalizeEmail(string $email): string
{
    return strtolower(trim($email));
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

function appInviteCanManageInvite(array $sessionUser, array $invite): bool
{
    $viewerRole = (int) ($sessionUser['capo'] ?? 0);
    if ($viewerRole === 3) {
        return true;
    }

    $managerDepartment = trim((string) ($sessionUser['reparto'] ?? ''));
    $managerCf = trim((string) ($sessionUser['cf'] ?? ''));
    return $viewerRole === 1
        && $managerDepartment !== ''
        && $managerCf !== ''
        && (string) ($invite['reparto'] ?? '') === $managerDepartment
        && (string) ($invite['invited_by_cf'] ?? '') === $managerCf;
}

function appInviteLoadForUpdate(PDO $pdo, int $inviteId, array $sessionUser): array
{
    if ($inviteId <= 0) {
        throw new RuntimeException('Invito non valido.');
    }

    $inviteQuery = $pdo->prepare('SELECT * FROM user_invites WHERE id = ? LIMIT 1 FOR UPDATE');
    $inviteQuery->execute([$inviteId]);
    $invite = $inviteQuery->fetch(PDO::FETCH_ASSOC);
    if (!is_array($invite)) {
        throw new RuntimeException('Invito non trovato.');
    }
    if (!appInviteCanManageInvite($sessionUser, $invite)) {
        throw new RuntimeException('Non puoi gestire questo invito.');
    }
    if (appInviteStatus($invite) === 'accepted') {
        throw new RuntimeException('L’account è già stato attivato.');
    }

    return $invite;
}

function appInviteRevokeLocked(PDO $pdo, int $inviteId, array $sessionUser): array
{
    $invite = appInviteLoadForUpdate($pdo, $inviteId, $sessionUser);
    $pdo->prepare('UPDATE user_invites SET revoked_at = NOW() WHERE id = ?')->execute([$inviteId]);

    return $invite;
}

function appInviteRegenerateLocked(PDO $pdo, int $inviteId, array $sessionUser): array
{
    $invite = appInviteLoadForUpdate($pdo, $inviteId, $sessionUser);
    $token = appInviteGenerateToken();
    $tokenHash = appInviteHashToken($token);
    $pdo->prepare(
        'UPDATE user_invites
         SET token_hash = ?, created_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL 7 DAY), revoked_at = NULL
         WHERE id = ?'
    )->execute([$tokenHash, $inviteId]);

    return [
        'invite' => $invite,
        'token' => $token,
        'link' => appInviteBuildUrl($token),
        'expires_at' => date('Y-m-d H:i:s', strtotime('+7 days')),
    ];
}

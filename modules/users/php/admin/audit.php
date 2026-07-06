<?php
declare(strict_types=1);

function appAdminAuditClientIpHash(): ?string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($ip === '') {
        return null;
    }

    return hash('sha256', $ip);
}

function appAdminAuditUserAgent(): ?string
{
    $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($userAgent === '') {
        return null;
    }

    return mb_substr($userAgent, 0, 255, 'UTF-8');
}

function appAdminAuditLog(
    ?PDO $pdo,
    array $actor,
    string $action,
    ?string $targetType = null,
    ?string $targetId = null,
    array $details = []
): void {
    if (!$pdo instanceof PDO) {
        return;
    }

    $actorCf = trim((string) ($actor['cf'] ?? ''));
    $action = trim($action);
    if ($actorCf === '' || $action === '') {
        return;
    }

    $detailsJson = null;
    if ($details !== []) {
        $encoded = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $detailsJson = is_string($encoded) ? $encoded : null;
    }

    try {
        $statement = $pdo->prepare(
            'INSERT INTO admin_audit_log (
                actor_cf, action, target_type, target_id, details_json,
                request_ip_hash, user_agent
             ) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $actorCf,
            mb_substr($action, 0, 80, 'UTF-8'),
            $targetType !== null ? mb_substr($targetType, 0, 80, 'UTF-8') : null,
            $targetId !== null ? mb_substr($targetId, 0, 191, 'UTF-8') : null,
            $detailsJson,
            appAdminAuditClientIpHash(),
            appAdminAuditUserAgent(),
        ]);
    } catch (Throwable $error) {
        error_log('Audit admin non registrato: ' . $error->getMessage());
    }
}

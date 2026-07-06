<?php
declare(strict_types=1);

function communicationInboxPayload(PDO $pdo, string $viewerCf): array
{
    $communications = communicationFetchInbox($pdo, $viewerCf);
    communicationMarkInboxRead($pdo, $viewerCf);
    return ['ok' => true, 'communications' => $communications];
}

function communicationUsersPayload(PDO $pdo, int $viewerRole, string $viewerDepartment): array
{
    return ['ok' => true, 'users' => communicationFetchManageableUsers($pdo, $viewerRole, $viewerDepartment)];
}

function communicationSentPayload(PDO $pdo, int $viewerRole, string $viewerCf): array
{
    return ['ok' => true, 'communications' => communicationFetchSent($pdo, $viewerRole, $viewerCf)];
}

function communicationAcknowledgePayload(PDO $pdo, int $communicationId, string $viewerCf): array
{
    if ($communicationId < 1) {
        throw new DomainException('Comunicazione non valida', 400);
    }

    return ['ok' => true, 'acknowledged' => communicationAcknowledge($pdo, $communicationId, $viewerCf)];
}

function communicationNormalizeSendInput(array $source): array
{
    $title = trim((string) ($source['title'] ?? ''));
    $message = trim((string) ($source['message'] ?? ''));
    $priority = (string) ($source['priority'] ?? 'normal');
    $targetType = (string) ($source['target_type'] ?? 'department');
    $targetUserCf = trim((string) ($source['recipient_cf'] ?? ''));
    $targetDepartment = trim((string) ($source['department'] ?? ''));

    if ($title === '' || mb_strlen($title) > 150 || $message === '' || mb_strlen($message) > 3000) {
        throw new DomainException('Titolo o testo non validi', 400);
    }
    if (!in_array($priority, ['normal', 'important'], true)) {
        throw new DomainException('Priorita non valida', 400);
    }

    return [
        'title' => $title,
        'message' => $message,
        'priority' => $priority,
        'target_type' => $targetType,
        'recipient_cf' => $targetUserCf,
        'department' => $targetDepartment,
    ];
}

function communicationResolveRecipients(PDO $pdo, array $input, int $viewerRole, string $viewerDepartment): array
{
    if ($input['target_type'] === 'user') {
        $targetUserCf = (string) $input['recipient_cf'];
        if ($targetUserCf === '' || !communicationCanManageUser($pdo, $targetUserCf, $viewerRole, $viewerDepartment)) {
            throw new DomainException('Destinatario non autorizzato', 403);
        }
        return [$targetUserCf];
    }

    if ($input['target_type'] === 'department') {
        $targetDepartment = (string) $input['department'];
        if (in_array($viewerRole, [1, 2], true)) {
            $targetDepartment = $viewerDepartment;
        }
        if (!communicationCanUseDepartment($viewerRole, $viewerDepartment, $targetDepartment)) {
            throw new DomainException('Reparto non valido', 400);
        }
        return communicationFetchDepartmentRecipients($pdo, $targetDepartment);
    }

    throw new DomainException('Destinazione non valida', 400);
}

function communicationSendPushes(PDO $pdo, array $recipients, int $communicationId, string $title, string $priority): void
{
    foreach ($recipients as $recipientCf) {
        try {
            appPushSendPayload($pdo, [
                'type' => 'communication',
                'title' => $priority === 'important' ? 'Comunicazione importante' : 'Nuova comunicazione',
                'body' => $title,
                'url' => './index.php?communications=1',
                'recipient_cf' => (string) $recipientCf,
                'tag' => 'communication-' . $communicationId,
                'communication_id' => $communicationId,
            ], (string) $recipientCf);
        } catch (Throwable $pushError) {
            error_log('Push comunicazione non inviata: ' . $pushError->getMessage());
        }
    }
}

function communicationSendPayload(PDO $pdo, array $source, string $viewerCf, int $viewerRole, string $viewerDepartment): array
{
    $input = communicationNormalizeSendInput($source);
    $recipients = communicationResolveRecipients($pdo, $input, $viewerRole, $viewerDepartment);
    if ($recipients === []) {
        throw new DomainException('Nessun destinatario trovato', 400);
    }

    $communicationId = communicationCreate($pdo, $viewerCf, $input['title'], $input['message'], $input['priority'], $recipients);
    communicationSendPushes($pdo, $recipients, $communicationId, $input['title'], $input['priority']);

    return ['ok' => true, 'communication_id' => $communicationId, 'recipients' => count($recipients)];
}
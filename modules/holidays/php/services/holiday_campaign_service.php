<?php
declare(strict_types=1);

function holidayCampaignFinalApproval(array $preference): bool
{
    return (int) ($preference['approved_by_director'] ?? 0) === 1
        && (
            (int) ($preference['approved_by_manager'] ?? 0) === 1
            || (int) ($preference['approved_by_admin'] ?? 0) === 1
        );
}

function holidayCampaignSummarizePreferences(array $preferences): array
{
    $summary = [
        'pending' => 0,
        'approved' => 0,
        'rejected' => 0,
        'cancelled' => 0,
        'total' => 0,
    ];
    foreach ($preferences as $preference) {
        $status = (string) ($preference['status'] ?? 'pending');
        if (!array_key_exists($status, $summary)) {
            $status = 'pending';
        }
        $summary[$status]++;
        $summary['total']++;
    }
    return $summary;
}

function holidayCampaignCanSubmitToDirector(?array $campaign, array $preferences): bool
{
    if (!is_array($campaign) || (string) ($campaign['status'] ?? '') !== 'open') {
        return false;
    }
    if ((int) ($campaign['submitted_to_director'] ?? 0) === 1) {
        return false;
    }

    $summary = holidayCampaignSummarizePreferences($preferences);
    return $summary['approved'] > 0 && $summary['pending'] === 0;
}

function holidayCampaignValidateSelection(array $campaign, int $year, int $week): array
{
    $rules = [];

    if ($year !== (int) $campaign['holiday_year']) {
        $rules[] = [
            'severity' => 'block',
            'code' => 'wrong_year',
            'message' => 'Puoi scegliere solo settimane dell\'anno ferie attivo.',
        ];
    }

    if ($week < 1 || $week > 53) {
        $rules[] = [
            'severity' => 'block',
            'code' => 'invalid_week',
            'message' => 'Settimana non valida.',
        ];
    }

    return [
        'ok' => !array_filter($rules, static fn (array $rule): bool => $rule['severity'] === 'block'),
        'rules' => $rules,
    ];
}

function holidayCampaignPayload(PDO $pdo, string $department, int $year, bool $canManage, bool $canReviewWeeks, string $viewerCf): array
{
    $campaign = holidayCampaignFetch($pdo, $department, $year);
    $preferences = $campaign ? holidayCampaignFetchPreferences($pdo, (int) $campaign['id']) : [];

    return [
        'ok' => true,
        'department' => $department,
        'department_label' => appDepartments()[$department] ?? $department,
        'year' => $year,
        'can_manage' => $canManage,
        'can_review_weeks' => $canReviewWeeks,
        'active' => is_array($campaign) && (string) $campaign['status'] === 'open',
        'campaign' => $campaign,
        'preferences' => $preferences,
        'summary' => holidayCampaignSummarizePreferences($preferences),
        'can_submit_to_director' => holidayCampaignCanSubmitToDirector($campaign, $preferences),
        'viewer_cf' => $viewerCf,
    ];
}

function holidayCampaignOpenPayload(PDO $pdo, string $department, int $year, string $viewerCf): array
{
    holidayCampaignOpen($pdo, $department, $year, $viewerCf);
    return ['ok' => true, 'campaign' => holidayCampaignFetch($pdo, $department, $year)];
}

function holidayCampaignClosePayload(PDO $pdo, string $department, int $year, string $viewerCf): array
{
    holidayCampaignClose($pdo, $department, $year, $viewerCf);
    return ['ok' => true, 'campaign' => holidayCampaignFetch($pdo, $department, $year)];
}

function holidayCampaignSubmitToDirectorPayload(PDO $pdo, array $campaign, array $viewer, string $department, int $year): array
{
    $preferences = holidayCampaignFetchPreferences($pdo, (int) $campaign['id']);
    if (!holidayCampaignCanSubmitToDirector($campaign, $preferences)) {
        throw new DomainException('Prima completa la revisione di tutte le richieste e approva almeno una settimana.', 409);
    }

    $viewerCf = (string) ($viewer['cf'] ?? '');
    $isDirector = (int) ($viewer['capo'] ?? 0) === 4;

    $pdo->beginTransaction();
    $published = holidayCampaignPublishApprovedPreferences($pdo, $campaign, $viewer);
    holidayCampaignMarkSubmitted($pdo, (int) $campaign['id'], $viewerCf, $isDirector);
    $pdo->commit();

    return [
        'ok' => true,
        'published' => $published,
        'simulated_director' => !$isDirector,
        'message' => !$isDirector
            ? 'Proposta inviata. Approvazione direttore simulata e ferie ufficiali aggiornate.'
            : 'Proposta approvata dal direttore e ferie ufficiali aggiornate.',
        'campaign' => holidayCampaignFetch($pdo, $department, $year),
    ];
}

function holidayCampaignReviewPreferencePayload(PDO $pdo, array $campaign, int $preferenceId, string $decision, array $viewer): array
{
    if ((string) ($campaign['status'] ?? '') !== 'open') {
        throw new DomainException('La campagna non e piu modificabile.', 409);
    }
    if ($preferenceId < 1 || !in_array($decision, ['approve', 'reject', 'reset'], true)) {
        throw new DomainException('Operazione non valida', 400);
    }

    $preference = holidayCampaignLoadPreference($pdo, $preferenceId, (int) $campaign['id']);
    if (!is_array($preference) || (string) ($preference['status'] ?? '') === 'cancelled') {
        throw new DomainException('Richiesta ferie non trovata', 404);
    }

    holidayCampaignApplyWeekDecision($pdo, $preference, $decision, $viewer);
    $preferences = holidayCampaignFetchPreferences($pdo, (int) $campaign['id']);

    return [
        'ok' => true,
        'preferences' => $preferences,
        'summary' => holidayCampaignSummarizePreferences($preferences),
    ];
}

function holidayCampaignTogglePreferencePayload(PDO $pdo, array $campaign, string $department, int $year, int $week, string $viewerCf, string $viewerName): array
{
    if ((string) ($campaign['status'] ?? '') !== 'open') {
        throw new DomainException('Attivita ferie non avviata', 409);
    }

    $validation = holidayCampaignValidateSelection($campaign, $year, $week);
    if (!$validation['ok']) {
        $message = $validation['rules'][0]['message'] ?? 'Scelta non valida';
        throw new DomainException($message, 422);
    }

    $campaignId = (int) $campaign['id'];
    $current = holidayCampaignLoadOwnPreference($pdo, $campaignId, $viewerCf, $year, $week);
    if (is_array($current) && in_array((string) $current['status'], ['pending', 'approved'], true)) {
        holidayCampaignCancelPreference($pdo, (int) $current['id'], $viewerCf);
        return [
            'ok' => true,
            'selected' => false,
            'preferences' => holidayCampaignFetchPreferences($pdo, $campaignId),
        ];
    }

    holidayCampaignUpsertPreference($pdo, $campaignId, $department, $year, $week, $viewerCf, $viewerName);
    return [
        'ok' => true,
        'selected' => true,
        'preferences' => holidayCampaignFetchPreferences($pdo, $campaignId),
        'rules' => $validation['rules'],
    ];
}
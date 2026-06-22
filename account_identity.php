<?php

function appGenerateUniqueValue(callable $generator, callable $exists, int $maxAttempts = 10): string
{
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $value = $generator();
        if (!$exists($value)) {
            return $value;
        }
    }

    throw new RuntimeException('Impossibile generare un identificativo univoco');
}

function appGenerateOpaqueBadge(): string
{
    return 'USR' . strtoupper(bin2hex(random_bytes(8)));
}

function appGenerateInvitePlaceholderCf(): string
{
    return 'INV' . strtoupper(substr(bin2hex(random_bytes(8)), 0, 13));
}

function appGenerateUniqueUserBadge(PDO $pdo): string
{
    $statement = $pdo->prepare('SELECT 1 FROM utenti WHERE badge = ? LIMIT 1');

    return appGenerateUniqueValue(
        static fn (): string => appGenerateOpaqueBadge(),
        static function (string $value) use ($statement): bool {
            $statement->execute([$value]);
            return (bool) $statement->fetchColumn();
        }
    );
}

function appGenerateUniqueInviteBadge(PDO $pdo): string
{
    $userStatement = $pdo->prepare('SELECT 1 FROM utenti WHERE badge = ? LIMIT 1');
    $inviteStatement = $pdo->prepare('SELECT 1 FROM user_invites WHERE invited_badge = ? LIMIT 1');

    return appGenerateUniqueValue(
        static fn (): string => appGenerateOpaqueBadge(),
        static function (string $value) use ($userStatement, $inviteStatement): bool {
            $userStatement->execute([$value]);
            if ($userStatement->fetchColumn()) {
                return true;
            }

            $inviteStatement->execute([$value]);
            return (bool) $inviteStatement->fetchColumn();
        }
    );
}

function appGenerateUniqueInvitePlaceholderCf(PDO $pdo): string
{
    $userStatement = $pdo->prepare('SELECT 1 FROM utenti WHERE cod_fiscale = ? LIMIT 1');
    $inviteStatement = $pdo->prepare('SELECT 1 FROM user_invites WHERE invited_cf = ? LIMIT 1');

    return appGenerateUniqueValue(
        static fn (): string => appGenerateInvitePlaceholderCf(),
        static function (string $value) use ($userStatement, $inviteStatement): bool {
            $userStatement->execute([$value]);
            if ($userStatement->fetchColumn()) {
                return true;
            }

            $inviteStatement->execute([$value]);
            return (bool) $inviteStatement->fetchColumn();
        }
    );
}

<?php

require_once __DIR__ . '/../app_config.php';

function smtpReadResponse($socket): array
{
    $lines = [];
    while (($line = fgets($socket, 515)) !== false) {
        $lines[] = rtrim($line, "\r\n");
        if (preg_match('/^\d{3} /', $line)) {
            break;
        }
    }

    if ($lines === []) {
        throw new RuntimeException('Il server SMTP non ha risposto');
    }

    $code = (int) substr($lines[count($lines) - 1], 0, 3);
    return [$code, implode("\n", $lines)];
}

function smtpExpect($socket, ?string $command, array $acceptedCodes): void
{
    if ($command !== null) {
        $written = fwrite($socket, $command . "\r\n");
        if ($written === false || $written < strlen($command) + 2) {
            throw new RuntimeException('Impossibile comunicare con il server SMTP');
        }
    }

    [$code] = smtpReadResponse($socket);
    if (!in_array($code, $acceptedCodes, true)) {
        throw new RuntimeException('Il server SMTP ha rifiutato la richiesta');
    }
}

function smtpSendPlainTextEmail(string $recipient, string $subject, string $body): void
{
    $username = appSmtpUsername();
    $password = appSmtpPassword();
    if ($username === '' || $password === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Configurazione SMTP incompleta');
    }

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => appSmtpHost(),
        ],
    ]);
    $socket = @stream_socket_client(
        'ssl://' . appSmtpHost() . ':' . appSmtpPort(),
        $errorNumber,
        $errorMessage,
        15,
        STREAM_CLIENT_CONNECT,
        $context
    );
    if ($socket === false) {
        throw new RuntimeException('Connessione al server SMTP non riuscita');
    }

    stream_set_timeout($socket, 15);
    try {
        smtpExpect($socket, null, [220]);
        smtpExpect($socket, 'EHLO myorari.it', [250]);
        smtpExpect($socket, 'AUTH LOGIN', [334]);
        smtpExpect($socket, base64_encode($username), [334]);
        smtpExpect($socket, base64_encode($password), [235]);
        smtpExpect($socket, 'MAIL FROM:<' . $username . '>', [250]);
        smtpExpect($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        smtpExpect($socket, 'DATA', [354]);

        $fromName = str_replace(["\r", "\n"], '', appSmtpFromName());
        $safeSubject = str_replace(["\r", "\n"], '', $subject);
        $safeBody = preg_replace('/(?m)^\./', '..', str_replace("\r\n", "\n", $body));
        $safeBody = str_replace("\n", "\r\n", (string) $safeBody);
        $message = "From: {$fromName} <{$username}>\r\n"
            . "To: <{$recipient}>\r\n"
            . 'Subject: =?UTF-8?B?' . base64_encode($safeSubject) . "?=\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $safeBody . "\r\n.";
        smtpExpect($socket, $message, [250]);
        smtpExpect($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function sendPasswordResetEmail(string $recipient, string $resetUrl): void
{
    $body = "Hai richiesto di reimpostare la password del tuo account MyOrari.\r\n\r\n"
        . "Apri questo link entro 60 minuti:\r\n" . $resetUrl . "\r\n\r\n"
        . "Se non hai richiesto tu questa operazione, puoi ignorare questa email.";
    smtpSendPlainTextEmail($recipient, 'Reimposta la password di MyOrari', $body);
}

function sendInvitationEmail(string $recipient, string $name, string $department, string $inviteUrl, string $expiresAt): void
{
    $expiry = strtotime($expiresAt);
    $expiryLabel = $expiry === false ? 'entro 7 giorni' : date('d/m/Y alle H:i', $expiry);
    $body = "Ciao {$name},\r\n\r\n"
        . "sei stato invitato ad attivare il tuo account MyOrari"
        . ($department !== '' ? " per il reparto {$department}" : '') . ".\r\n\r\n"
        . "Apri questo link entro {$expiryLabel}:\r\n{$inviteUrl}\r\n\r\n"
        . "Dovrai solo scegliere una password. Se non ti aspettavi questo invito, puoi ignorare questa email.";
    smtpSendPlainTextEmail($recipient, 'Invito a MyOrari', $body);
}

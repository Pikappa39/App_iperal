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

function sendPasswordResetEmail(string $recipient, string $resetUrl): void
{
    $username = appSmtpUsername();
    $password = appSmtpPassword();
    if ($username === '' || $password === '') {
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

        $subject = 'Reimposta la password di MyOrari';
        $body = "Hai richiesto di reimpostare la password del tuo account MyOrari.\r\n\r\n"
            . "Apri questo link entro 60 minuti:\r\n" . $resetUrl . "\r\n\r\n"
            . "Se non hai richiesto tu questa operazione, puoi ignorare questa email.";
        $message = "From: " . appSmtpFromName() . " <{$username}>\r\n"
            . "To: <{$recipient}>\r\n"
            . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $body . "\r\n.";
        smtpExpect($socket, $message, [250]);
        smtpExpect($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

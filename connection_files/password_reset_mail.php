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

function smtpExpect($socket, ?string $command, array $acceptedCodes): array
{
    if ($command !== null) {
        $written = fwrite($socket, $command . "\r\n");
        if ($written === false || $written < strlen($command) + 2) {
            throw new RuntimeException('Impossibile comunicare con il server SMTP');
        }
    }

    [$code, $response] = smtpReadResponse($socket);
    if (!in_array($code, $acceptedCodes, true)) {
        throw new RuntimeException('Il server SMTP ha rifiutato la richiesta');
    }

    return [$code, $response];
}

function smtpSanitizeHeaderValue(string $value): string
{
    return trim(str_replace(["\r", "\n"], '', $value));
}

function smtpAddressDomain(string $email): string
{
    $domain = substr(strrchr($email, '@') ?: '', 1);
    $domain = strtolower((string) preg_replace('/[^a-z0-9.-]/i', '', $domain));
    return $domain !== '' ? $domain : 'myorari.it';
}

function smtpMessageId(string $sender): string
{
    return sprintf(
        '<%s.%s.%s@%s>',
        gmdate('YmdHis'),
        bin2hex(random_bytes(8)),
        getmypid() ?: 'php',
        smtpAddressDomain($sender)
    );
}

function smtpEncodeHeader(string $value): string
{
    return '=?UTF-8?B?' . base64_encode(smtpSanitizeHeaderValue($value)) . '?=';
}

function smtpSendPlainTextEmail(string $recipient, string $subject, string $body): array
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

        $fromName = smtpSanitizeHeaderValue(appSmtpFromName());
        $safeSubject = smtpSanitizeHeaderValue($subject);
        $safeBody = preg_replace('/(?m)^\./', '..', str_replace("\r\n", "\n", $body));
        $safeBody = str_replace("\n", "\r\n", (string) $safeBody);
        $messageId = smtpMessageId($username);
        $message = 'Date: ' . date(DATE_RFC2822) . "\r\n"
            . 'Message-ID: ' . $messageId . "\r\n"
            . "From: " . smtpEncodeHeader($fromName) . " <{$username}>\r\n"
            . "Reply-To: " . smtpEncodeHeader($fromName) . " <{$username}>\r\n"
            . "Sender: <{$username}>\r\n"
            . "To: <{$recipient}>\r\n"
            . 'Subject: ' . smtpEncodeHeader($safeSubject) . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n"
            . "Importance: normal\r\n"
            . "X-Priority: 3\r\n"
            . "X-Mailer: MyOrari\r\n"
            . "Auto-Submitted: auto-generated\r\n\r\n"
            . quoted_printable_encode($safeBody) . "\r\n.";
        [$acceptedCode, $acceptedResponse] = smtpExpect($socket, $message, [250]);
        smtpExpect($socket, 'QUIT', [221]);

        return [
            'message_id' => $messageId,
            'smtp_code' => $acceptedCode,
            'smtp_response' => $acceptedResponse,
        ];
    } finally {
        fclose($socket);
    }
}

function sendPasswordResetEmail(string $recipient, string $resetUrl): array
{
    $body = "Ciao,\r\n\r\n"
        . "abbiamo ricevuto una richiesta per reimpostare la password del tuo account MyOrari.\r\n\r\n"
        . "Per scegliere una nuova password apri questo link entro 60 minuti:\r\n"
        . $resetUrl . "\r\n\r\n"
        . "Se non hai richiesto tu questa operazione, puoi ignorare questa email: la password attuale resterà invariata.\r\n\r\n"
        . "Grazie,\r\nIl team MyOrari";
    return smtpSendPlainTextEmail($recipient, 'Reimposta la password di MyOrari', $body);
}

function sendInvitationEmail(string $recipient, string $name, string $department, string $inviteUrl, string $expiresAt): array
{
    $expiry = strtotime($expiresAt);
    $expiryLabel = $expiry === false ? 'entro 7 giorni' : date('d/m/Y \a\l\l\e H:i', $expiry);
    $greetingName = trim($name) !== '' ? trim($name) : 'collega';
    $departmentText = $department !== '' ? " per il reparto {$department}" : '';
    $body = "Ciao {$greetingName},\r\n\r\n"
        . "ti abbiamo inviato un invito per attivare il tuo account MyOrari{$departmentText}.\r\n\r\n"
        . "Per completare l'attivazione apri questo link entro {$expiryLabel}:\r\n"
        . "{$inviteUrl}\r\n\r\n"
        . "Dovrai solo scegliere una password personale. Il link è individuale e non va inoltrato ad altre persone.\r\n\r\n"
        . "Se non ti aspettavi questo invito, puoi ignorare questa email.\r\n\r\n"
        . "Grazie,\r\nIl team MyOrari";
    return smtpSendPlainTextEmail($recipient, 'Attiva il tuo account MyOrari', $body);
}

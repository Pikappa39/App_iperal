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
        throw new RuntimeException('Il server SMTP ha rifiutato la richiesta: ' . $response);
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

function brevoApiSendPlainTextEmail(string $recipient, string $subject, string $body): array
{
    $apiKey = appBrevoApiKey();
    $fromEmail = appSmtpFromEmail();
    if ($apiKey === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Configurazione Brevo API incompleta');
    }

    $fromName = smtpSanitizeHeaderValue(appSmtpFromName());
    $safeSubject = smtpSanitizeHeaderValue($subject);
    $originMessageId = smtpMessageId($fromEmail);
    $payload = [
        'sender' => [
            'name' => $fromName,
            'email' => $fromEmail,
        ],
        'to' => [
            ['email' => $recipient],
        ],
        'replyTo' => [
            'name' => $fromName,
            'email' => $fromEmail,
        ],
        'subject' => $safeSubject,
        'textContent' => $body,
        'headers' => [
            'Auto-Submitted' => 'auto-generated',
            'Importance' => 'normal',
            'X-Mailer' => 'MyOrari',
            'X-MyOrari-Message-ID' => $originMessageId,
            'X-Priority' => '3',
        ],
        'trackOpens' => false,
        'trackClicks' => false,
    ];
    $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encodedPayload)) {
        throw new RuntimeException('Impossibile preparare la richiesta Brevo API');
    }

    $httpResponseHeaders = [];
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Accept: application/json',
                'Content-Type: application/json',
                'api-key: ' . $apiKey,
            ]),
            'content' => $encodedPayload,
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ]);
    $response = @file_get_contents('https://api.brevo.com/v3/smtp/email', false, $context);
    if (isset($http_response_header) && is_array($http_response_header)) {
        $httpResponseHeaders = $http_response_header;
    }

    $statusCode = 0;
    if (isset($httpResponseHeaders[0]) && preg_match('/\s(\d{3})\s/', (string) $httpResponseHeaders[0], $matches)) {
        $statusCode = (int) $matches[1];
    }
    $responseText = is_string($response) ? $response : '';
    if ($statusCode < 200 || $statusCode >= 300 || $response === false) {
        throw new RuntimeException('Brevo API ha rifiutato la richiesta: HTTP ' . $statusCode . ' ' . mb_substr($responseText, 0, 300, 'UTF-8'));
    }

    $decoded = json_decode($responseText, true);
    $brevoMessageId = is_array($decoded) ? (string) ($decoded['messageId'] ?? '') : '';

    return [
        'message_id' => $brevoMessageId !== '' ? $brevoMessageId : $originMessageId,
        'smtp_code' => $statusCode,
        'smtp_response' => $responseText,
        'transport' => 'brevo_api',
    ];
}

function smtpSendPlainTextEmail(string $recipient, string $subject, string $body): array
{
    $host = appSmtpHost();
    $port = appSmtpPort();
    $username = appSmtpUsername();
    $password = appSmtpPassword();
    $fromEmail = appSmtpFromEmail();
    if (
        $username === ''
        || $password === ''
        || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)
        || !filter_var($recipient, FILTER_VALIDATE_EMAIL)
    ) {
        throw new RuntimeException('Configurazione SMTP incompleta');
    }

    $implicitTls = $port === 465;
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
        ],
    ]);
    $socket = @stream_socket_client(
        ($implicitTls ? 'ssl://' : 'tcp://') . $host . ':' . $port,
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
        if (!$implicitTls) {
            smtpExpect($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Impossibile attivare STARTTLS sul server SMTP');
            }
            smtpExpect($socket, 'EHLO myorari.it', [250]);
        }
        smtpExpect($socket, 'AUTH LOGIN', [334]);
        smtpExpect($socket, base64_encode($username), [334]);
        smtpExpect($socket, base64_encode($password), [235]);
        smtpExpect($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        smtpExpect($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        smtpExpect($socket, 'DATA', [354]);

        $fromName = smtpSanitizeHeaderValue(appSmtpFromName());
        $safeSubject = smtpSanitizeHeaderValue($subject);
        $safeBody = preg_replace('/(?m)^\./', '..', str_replace("\r\n", "\n", $body));
        $safeBody = str_replace("\n", "\r\n", (string) $safeBody);
        $messageId = smtpMessageId($fromEmail);
        $message = 'Date: ' . date(DATE_RFC2822) . "\r\n"
            . 'Message-ID: ' . $messageId . "\r\n"
            . "From: " . smtpEncodeHeader($fromName) . " <{$fromEmail}>\r\n"
            . "Reply-To: " . smtpEncodeHeader($fromName) . " <{$fromEmail}>\r\n"
            . "Sender: <{$fromEmail}>\r\n"
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
            'transport' => 'smtp',
        ];
    } finally {
        fclose($socket);
    }
}

function appSendPlainTextEmail(string $recipient, string $subject, string $body): array
{
    if (appBrevoApiKey() !== '') {
        return brevoApiSendPlainTextEmail($recipient, $subject, $body);
    }

    return smtpSendPlainTextEmail($recipient, $subject, $body);
}

function sendPasswordResetEmail(string $recipient, string $resetUrl): array
{
    $body = "Ciao,\r\n\r\n"
        . "abbiamo ricevuto una richiesta per reimpostare la password del tuo account MyOrari.\r\n\r\n"
        . "Per scegliere una nuova password apri questo link entro 60 minuti:\r\n"
        . $resetUrl . "\r\n\r\n"
        . "Se non hai richiesto tu questa operazione, puoi ignorare questa email: la password attuale resterà invariata.\r\n\r\n"
        . "Grazie,\r\nIl team MyOrari";
    return appSendPlainTextEmail($recipient, 'Reimposta la password di MyOrari', $body);
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
    return appSendPlainTextEmail($recipient, 'Attiva il tuo account MyOrari', $body);
}

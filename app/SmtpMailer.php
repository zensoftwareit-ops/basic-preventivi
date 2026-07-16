<?php

declare(strict_types=1);

final class SmtpMailer
{
    private string $host;
    private int $port;
    private string $encryption;
    private string $username;
    private string $password;
    private string $fromEmail;
    private string $fromName;
    private int $timeout;
    private mixed $socket = null;

    public function __construct(array $config)
    {
        $this->host = trim((string) ($config['host'] ?? ''));
        $this->port = (int) ($config['port'] ?? 587);
        $this->encryption = strtolower(trim((string) ($config['encryption'] ?? 'tls')));
        $this->username = trim((string) ($config['username'] ?? ''));
        $this->password = (string) ($config['password'] ?? '');
        $this->fromEmail = trim((string) ($config['from_email'] ?? ''));
        $this->fromName = $this->sanitizeHeader((string) ($config['from_name'] ?? 'Basic Preventivi'));
        $this->timeout = max(5, min(60, (int) ($config['timeout'] ?? 15)));

        $aliases = ['starttls' => 'tls', 'smtps' => 'ssl'];
        $this->encryption = $aliases[$this->encryption] ?? $this->encryption;

        if ($this->host === '') {
            throw new RuntimeException('Host SMTP non configurato.');
        }
        if ($this->port < 1 || $this->port > 65535) {
            throw new RuntimeException('Porta SMTP non valida.');
        }
        if (!in_array($this->encryption, ['tls', 'ssl', 'none'], true)) {
            throw new RuntimeException('Cifratura SMTP non valida: usare tls, ssl oppure none.');
        }
        if ($this->username === '' || $this->password === '') {
            throw new RuntimeException('Credenziali SMTP mancanti.');
        }
        if (filter_var($this->fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Mittente SMTP non valido.');
        }
    }

    public function send(string $to, array $bcc, string $subject, string $body): void
    {
        $to = $this->validateEmail($to, 'Destinatario');
        $bcc = array_values(array_unique(array_map(
            fn (mixed $address): string => $this->validateEmail((string) $address, 'Indirizzo BCC'),
            $bcc
        )));
        $subject = $this->sanitizeHeader($subject);

        try {
            $this->connect();
            $this->expectResponse([220]);
            $this->command('EHLO ' . $this->helloName(), [250]);

            if ($this->encryption === 'tls') {
                $this->command('STARTTLS', [220]);
                $cryptoEnabled = stream_socket_enable_crypto(
                    $this->socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                );
                if ($cryptoEnabled !== true) {
                    throw new RuntimeException('Impossibile attivare STARTTLS sul collegamento SMTP.');
                }
                $this->command('EHLO ' . $this->helloName(), [250]);
            }

            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($this->username), [334]);
            $this->command(base64_encode($this->password), [235]);
            $this->command('MAIL FROM:<' . $this->fromEmail . '>', [250]);
            $this->command('RCPT TO:<' . $to . '>', [250, 251]);
            foreach ($bcc as $address) {
                $this->command('RCPT TO:<' . $address . '>', [250, 251]);
            }
            $this->command('DATA', [354]);
            $this->writeRaw($this->buildMessage($to, $subject, $body) . "\r\n.\r\n");
            $this->expectResponse([250]);
        } finally {
            if (is_resource($this->socket)) {
                try {
                    $this->command('QUIT', [221]);
                } catch (Throwable) {
                    // Il messaggio e' gia' stato accettato oppure l'errore originale e' piu' utile.
                }
                fclose($this->socket);
                $this->socket = null;
            }
        }
    }

    private function connect(): void
    {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $this->host,
                'SNI_enabled' => true,
            ],
        ]);
        $scheme = $this->encryption === 'ssl' ? 'tls' : 'tcp';
        $target = sprintf('%s://%s:%d', $scheme, $this->host, $this->port);
        $errorNumber = 0;
        $errorMessage = '';
        $socket = @stream_socket_client(
            $target,
            $errorNumber,
            $errorMessage,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($socket === false) {
            $detail = $errorMessage !== '' ? ': ' . $errorMessage : '';
            throw new RuntimeException('Connessione al server SMTP non riuscita' . $detail . '.');
        }
        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
    }

    private function buildMessage(string $to, string $subject, string $body): string
    {
        $fromName = mb_encode_mimeheader($this->fromName, 'UTF-8', 'B', "\r\n");
        $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
        $domain = substr(strrchr($this->fromEmail, '@') ?: '@localhost', 1);
        $messageId = bin2hex(random_bytes(16)) . '@' . $domain;
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $fromName . ' <' . $this->fromEmail . '>',
            'To: <' . $to . '>',
            'Subject: ' . $encodedSubject,
            'Message-ID: <' . $messageId . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        $body = preg_replace("/\r\n|\r|\n/", "\r\n", $body) ?? $body;
        $body = preg_replace('/(^|\r\n)\./', '$1..', $body) ?? $body;

        return implode("\r\n", $headers) . "\r\n\r\n" . rtrim($body, "\r\n");
    }

    private function command(string $command, array $expectedCodes): string
    {
        $this->writeRaw($command . "\r\n");
        return $this->expectResponse($expectedCodes);
    }

    private function expectResponse(array $expectedCodes): string
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('Connessione SMTP non disponibile.');
        }

        $response = '';
        for ($lineNumber = 0; $lineNumber < 100; $lineNumber++) {
            $line = fgets($this->socket, 515);
            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);
                $reason = !empty($meta['timed_out']) ? 'timeout' : 'connessione interrotta';
                throw new RuntimeException('Risposta SMTP non ricevuta (' . $reason . ').');
            }
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            $safeResponse = preg_replace('/[\r\n]+/', ' ', trim($response)) ?? '';
            throw new RuntimeException(
                'Risposta SMTP inattesa (' . $code . '): ' . mb_substr($safeResponse, 0, 400)
            );
        }

        return $response;
    }

    private function writeRaw(string $data): void
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('Connessione SMTP non disponibile.');
        }

        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $bytes = fwrite($this->socket, substr($data, $written));
            if ($bytes === false || $bytes === 0) {
                throw new RuntimeException('Scrittura sul server SMTP non riuscita.');
            }
            $written += $bytes;
        }
    }

    private function validateEmail(string $email, string $label): string
    {
        $email = trim($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException($label . ' non valido.');
        }
        return $email;
    }

    private function sanitizeHeader(string $value): string
    {
        return trim(str_replace(["\r", "\n"], '', $value));
    }

    private function helloName(): string
    {
        $hostname = preg_replace('/[^a-z0-9.-]/i', '', (string) gethostname()) ?? '';
        return $hostname !== '' ? $hostname : 'localhost';
    }
}

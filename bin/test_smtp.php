<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$recipient = trim((string) ($argv[1] ?? ''));
if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Uso: php bin/test_smtp.php destinatario@example.it" . PHP_EOL);
    exit(2);
}

try {
    $mailConfig = (array) config('mail', []);
    if (empty($mailConfig['enabled'])) {
        throw new RuntimeException('Invio email disattivato nella configurazione.');
    }

    $mailer = new SmtpMailer($mailConfig);
    $mailer->send(
        $recipient,
        [],
        'Test SMTP - Basic Preventivi',
        "La configurazione SMTP autenticata di Basic Preventivi funziona correttamente.\n\n"
            . 'Test eseguito: ' . date(DATE_ATOM) . "\n"
    );

    echo json_encode([
        'ok' => true,
        'recipient' => $recipient,
        'sent_at' => date(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, '[basic-preventivi] Test SMTP non riuscito: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

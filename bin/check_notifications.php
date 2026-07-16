<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

try {
    $repository = new QuoteRepository();
    $generated = $repository->generateNotifications();
    $email = $repository->dispatchNotificationEmails();

    echo json_encode([
        'ok' => true,
        'generated' => $generated,
        'email' => $email,
        'checked_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, '[basic-preventivi] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

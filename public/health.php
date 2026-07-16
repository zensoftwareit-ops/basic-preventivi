<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
try {
    Database::connection()->query('SELECT 1');
    $mail = (array) config('mail', []);
    $smtpConfigured = !empty($mail['host'])
        && !empty($mail['username'])
        && !empty($mail['password'])
        && filter_var((string) ($mail['from_email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false;
    echo json_encode([
        'status' => 'ok',
        'smtp_configured' => $smtpConfigured,
        'time' => date(DATE_ATOM),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(503);
    echo json_encode(['status' => 'error'], JSON_THROW_ON_ERROR);
}

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
    $push = (array) config('push', []);
    $pushConfigured = !empty($push['vapid_subject'])
        && !empty($push['vapid_public_key'])
        && !empty($push['vapid_private_key'])
        && extension_loaded('openssl')
        && extension_loaded('curl');
    echo json_encode([
        'status' => 'ok',
        'smtp_configured' => $smtpConfigured,
        'xlsx_available' => class_exists(ZipArchive::class),
        'pwa_available' => is_file(__DIR__ . '/manifest.webmanifest') && is_file(__DIR__ . '/sw.js'),
        'push_configured' => $pushConfigured,
        'time' => date(DATE_ATOM),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(503);
    echo json_encode(['status' => 'error'], JSON_THROW_ON_ERROR);
}

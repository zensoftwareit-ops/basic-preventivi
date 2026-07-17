<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
try {
    $pdo = Database::connection();
    $pdo->query('SELECT 1');
    $deviceSessionsAvailable = (bool) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'device_sessions'"
    )->fetchColumn();
    $mobilePairAvailable = (bool) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'mobile_activation_tokens'"
    )->fetchColumn()
        && is_file(__DIR__ . '/mobile_pair_create.php')
        && is_file(__DIR__ . '/mobile_activate.php')
        && is_file(__DIR__ . '/assets/vendor/qrcode.js');
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
        'device_login_available' => $deviceSessionsAvailable,
        'mobile_pair_available' => $mobilePairAvailable,
        'time' => date(DATE_ATOM),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(503);
    echo json_encode(['status' => 'error'], JSON_THROW_ON_ERROR);
}

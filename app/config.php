<?php

declare(strict_types=1);

function env_value(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

$config = [
    'app' => [
        'name' => env_value('APP_NAME', 'Basic - Gestione Preventivi'),
        'url' => rtrim((string) env_value('APP_URL', 'http://localhost:8080'), '/'),
        'timezone' => env_value('APP_TIMEZONE', 'Europe/Rome'),
        'shared_token' => env_value('APP_SHARED_TOKEN', ''),
        'session_name' => env_value('APP_SESSION_NAME', 'basic_preventivi'),
        'session_secure' => env_value('SESSION_COOKIE_SECURE', '0') === '1',
        'device_cookie_name' => env_value('APP_DEVICE_COOKIE_NAME', 'basic_preventivi_device'),
        'device_session_days' => max(1, (int) env_value('APP_DEVICE_SESSION_DAYS', '180')),
    ],
    'db' => [
        'host' => env_value('DB_HOST', '127.0.0.1'),
        'port' => (int) env_value('DB_PORT', '3306'),
        'name' => env_value('DB_DATABASE', 'basic_preventivi'),
        'user' => env_value('DB_USERNAME', 'basic'),
        'password' => env_value('DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
    ],
    'mail' => [
        'enabled' => env_value('MAIL_ENABLED', '1') === '1',
        'host' => env_value('SMTP_HOST', ''),
        'port' => (int) env_value('SMTP_PORT', '587'),
        'encryption' => env_value('SMTP_ENCRYPTION', 'tls'),
        'username' => env_value('SMTP_USERNAME', ''),
        'password' => env_value('SMTP_PASSWORD', ''),
        'from_email' => env_value('MAIL_FROM_EMAIL', ''),
        'from_name' => env_value('MAIL_FROM_NAME', 'Basic Preventivi'),
        'timeout' => (int) env_value('SMTP_TIMEOUT', '15'),
        'bcc' => [],
    ],
    'push' => [
        'enabled' => env_value('PUSH_ENABLED', '1') === '1',
        'vapid_subject' => env_value('PUSH_VAPID_SUBJECT', ''),
        'vapid_public_key' => env_value('PUSH_VAPID_PUBLIC_KEY', ''),
        'vapid_private_key' => str_replace('\\n', "\n", (string) env_value('PUSH_VAPID_PRIVATE_KEY', '')),
        'ttl' => (int) env_value('PUSH_TTL', '86400'),
        'timeout' => (int) env_value('PUSH_TIMEOUT', '20'),
    ],
];

$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    $overrides = require $localConfig;
    if (!is_array($overrides)) {
        throw new RuntimeException('app/config.local.php deve restituire un array.');
    }
    $config = array_replace_recursive($config, $overrides);
}

return $config;

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
    ],
    'db' => [
        'host' => env_value('DB_HOST', '127.0.0.1'),
        'port' => (int) env_value('DB_PORT', '3306'),
        'name' => env_value('DB_DATABASE', 'basic_preventivi'),
        'user' => env_value('DB_USERNAME', 'basic'),
        'password' => env_value('DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
    ],
    'reminders' => [
        'stale_after_hours' => (int) env_value('REMINDER_STALE_AFTER_HOURS', '72'),
        'email_enabled' => env_value('REMINDER_EMAIL_ENABLED', '0') === '1',
        'email_from' => env_value('REMINDER_EMAIL_FROM', ''),
        'operator_emails' => [],
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

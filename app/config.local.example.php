<?php

declare(strict_types=1);

return [
    'app' => [
        'url' => 'https://preventivi.example.it',
        'shared_token' => 'INSERIRE_UN_TOKEN_CASUALE_LUNGO',
        'session_secure' => true,
    ],
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'NOME_DATABASE_PLESK',
        'user' => 'UTENTE_DATABASE_PLESK',
        'password' => 'PASSWORD_DATABASE_PLESK',
    ],
    'reminders' => [
        'stale_after_hours' => 72,
        'email_enabled' => false,
        'email_from' => 'preventivi@example.it',
        'operator_emails' => [
            // 'mario.rossi' => 'mario.rossi@example.it',
        ],
    ],
];

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
    'mail' => [
        'enabled' => true,
        'host' => 'smtp.example.it',
        'port' => 587,
        'encryption' => 'tls',
        'username' => 'preventivi@example.it',
        'password' => 'PASSWORD_SMTP',
        'from_email' => 'preventivi@example.it',
        'from_name' => 'Basic Preventivi',
        'timeout' => 15,
        'bcc' => [
            'responsabile@example.it',
            // 'direzione@example.it',
        ],
    ],
];

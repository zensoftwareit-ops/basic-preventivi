<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';

date_default_timezone_set((string) $config['app']['timezone']);
ini_set('session.use_strict_mode', '1');
session_name((string) $config['app']['session_name']);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (bool) $config['app']['session_secure'],
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/QuoteRepository.php';
require_once __DIR__ . '/views.php';

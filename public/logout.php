<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

Auth::logout();
render_public_error('Sessione terminata', 'Per rientrare, apri nuovamente l’applicazione dal software aziendale.');

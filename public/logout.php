<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

Auth::logout();
render_public_error('Dispositivo scollegato', 'Per rientrare, apri nuovamente Preventivi dal software aziendale su questo dispositivo.');

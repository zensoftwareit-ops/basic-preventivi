<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (isset($_GET['error'])) {
    http_response_code(401);
    render_public_error('Accesso non autorizzato', 'Token non valido oppure utente inesistente o disattivato.');
    exit;
}

if ((string) config('app.shared_token', '') === '') {
    http_response_code(503);
    render_public_error('Servizio non configurato', 'Imposta APP_SHARED_TOKEN prima di usare il servizio di accesso.');
    exit;
}

$userId = (int) ($_POST['id'] ?? $_GET['id'] ?? $_POST['user_id'] ?? $_GET['user_id'] ?? 0);
$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');

if ($userId < 1) {
    http_response_code(422);
    render_public_error('Utente mancante', 'Passa il parametro id dell’utente che sta usando l’applicazione.');
    exit;
}

if ($token === '' && isset($_SERVER['HTTP_AUTHORIZATION']) && str_starts_with($_SERVER['HTTP_AUTHORIZATION'], 'Bearer ')) {
    $token = substr($_SERVER['HTTP_AUTHORIZATION'], 7);
}

try {
    if (!Auth::attemptBridgeLogin($userId, $token)) {
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $token !== '') {
            redirect(url('sso.php', ['error' => 'unauthorized']));
        }
        http_response_code(401);
        render_public_error('Accesso non autorizzato', 'Token non valido oppure utente inesistente o disattivato.');
        exit;
    }
    redirect(url('index.php'));
} catch (Throwable) {
    http_response_code(503);
    render_public_error('Servizio temporaneamente non disponibile', 'Impossibile completare l’accesso. Riprova tra poco.');
}

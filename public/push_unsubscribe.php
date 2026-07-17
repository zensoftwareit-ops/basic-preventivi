<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
Auth::requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo non consentito.']);
    exit;
}
$provided = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!hash_equals(csrf_token(), $provided)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'Sessione scaduta.']);
    exit;
}

try {
    $input = json_decode((string) file_get_contents('php://input'), true, 8, JSON_THROW_ON_ERROR);
    $endpoint = is_array($input) ? (string) ($input['endpoint'] ?? '') : '';
    (new QuoteRepository())->removePushSubscription((int) Auth::id(), $endpoint);
    echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Impossibile disattivare le notifiche push.'], JSON_THROW_ON_ERROR);
}

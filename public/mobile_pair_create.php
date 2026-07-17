<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

Auth::requireLogin();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo non consentito.']);
    exit;
}
$provided = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!hash_equals(csrf_token(), $provided)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'Sessione scaduta. Ricarica la pagina.']);
    exit;
}

$pdo = Database::connection();
try {
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $expiresAt = (new DateTimeImmutable('+10 minutes'))->format('Y-m-d H:i:s');
    $pdo->beginTransaction();
    $pdo->prepare(
        'UPDATE mobile_activation_tokens SET used_at = NOW()
         WHERE user_id = :user_id AND used_at IS NULL'
    )->execute(['user_id' => (int) Auth::id()]);
    $pdo->prepare(
        'DELETE FROM mobile_activation_tokens
         WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY) OR used_at < DATE_SUB(NOW(), INTERVAL 1 DAY)'
    )->execute();
    $pdo->prepare(
        'INSERT INTO mobile_activation_tokens (user_id, token_hash, expires_at)
         VALUES (:user_id, :token_hash, :expires_at)'
    )->execute([
        'user_id' => (int) Auth::id(),
        'token_hash' => hash('sha256', $token),
        'expires_at' => $expiresAt,
    ]);
    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'activation_url' => url('mobile_activate.php', ['code' => $token]),
        'expires_at' => (new DateTimeImmutable($expiresAt))->format(DATE_ATOM),
        'expires_in' => 600,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Impossibile generare il QR. Verifica la migration database.']);
}

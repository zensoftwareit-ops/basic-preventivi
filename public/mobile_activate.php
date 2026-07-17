<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$code = trim((string) ($_GET['code'] ?? ''));
if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $code)) {
    http_response_code(422);
    render_public_error('QR non valido', 'Genera un nuovo QR dalla versione desktop di Preventivi.');
    exit;
}

$pdo = Database::connection();
try {
    $pdo->beginTransaction();
    $statement = $pdo->prepare(
        'SELECT mat.id, mat.user_id
         FROM mobile_activation_tokens mat
         JOIN users u ON u.id = mat.user_id AND u.active = 1
         WHERE mat.token_hash = :token_hash
           AND mat.used_at IS NULL AND mat.expires_at > NOW()
         LIMIT 1 FOR UPDATE'
    );
    $statement->execute(['token_hash' => hash('sha256', $code)]);
    $activation = $statement->fetch();
    if (!$activation) {
        $pdo->rollBack();
        http_response_code(401);
        render_public_error('QR scaduto o già utilizzato', 'Dal computer premi nuovamente “Collega smartphone” e inquadra il nuovo QR.');
        exit;
    }
    $pdo->prepare('UPDATE mobile_activation_tokens SET used_at = NOW() WHERE id = :id')
        ->execute(['id' => (int) $activation['id']]);
    $pdo->commit();

    if (!Auth::completeMobileActivation((int) $activation['user_id'])) {
        throw new RuntimeException('Utente non disponibile.');
    }
    setcookie('basic_preventivi_setup', '1', [
        'expires' => time() + 86400,
        'path' => '/',
        'secure' => (bool) config('app.session_secure', false),
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
    redirect(url('index.php', ['mobile_setup' => 1]));
} catch (Throwable) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(503);
    render_public_error('Attivazione non completata', 'Genera un nuovo QR dal computer e riprova.');
}

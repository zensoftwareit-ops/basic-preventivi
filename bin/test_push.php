<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$userId = (int) ($argv[1] ?? 0);
if ($userId < 1) {
    fwrite(STDERR, "Uso: php bin/test_push.php <id_utente>\n");
    exit(1);
}

try {
    $sender = new WebPushSender((array) config('push', []));
    $statement = Database::connection()->prepare(
        'SELECT id, endpoint, p256dh, auth_token
         FROM push_subscriptions WHERE user_id = :user_id AND active = 1 ORDER BY id'
    );
    $statement->execute(['user_id' => $userId]);
    $subscriptions = $statement->fetchAll();
    if ($subscriptions === []) {
        throw new RuntimeException('Nessun dispositivo attivo per questo utente.');
    }

    $result = ['ok' => true, 'user_id' => $userId, 'sent' => 0, 'failed' => 0, 'expired' => 0];
    foreach ($subscriptions as $subscription) {
        $delivery = $sender->send($subscription, [
            'title' => 'Test Basic Preventivi',
            'body' => 'Le notifiche push sono configurate correttamente.',
            'url' => url('index.php'),
            'icon' => url('assets/icons/icon-192.png'),
            'badge' => url('assets/icons/badge-96.png'),
            'tag' => 'basic-push-test',
        ]);
        if ($delivery['success']) {
            Database::connection()->prepare(
                'UPDATE push_subscriptions SET last_success_at = NOW(), last_error = NULL, updated_at = NOW() WHERE id = :id'
            )->execute(['id' => $subscription['id']]);
            $result['sent']++;
        } elseif ($delivery['expired']) {
            Database::connection()->prepare(
                'UPDATE push_subscriptions SET active = 0, last_error = :error, updated_at = NOW() WHERE id = :id'
            )->execute(['error' => $delivery['error'], 'id' => $subscription['id']]);
            $result['expired']++;
        } else {
            Database::connection()->prepare(
                'UPDATE push_subscriptions SET last_error = :error, updated_at = NOW() WHERE id = :id'
            )->execute(['error' => $delivery['error'], 'id' => $subscription['id']]);
            $result['failed']++;
        }
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, '[basic-preventivi] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

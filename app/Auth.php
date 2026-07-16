<?php

declare(strict_types=1);

final class Auth
{
    public static function attemptBridgeLogin(string $operator, string $token): bool
    {
        $operator = self::normalizeOperator($operator);
        $expected = (string) config('app.shared_token', '');

        if ($expected === '' || !hash_equals($expected, $token)) {
            self::logAttempt($operator, false);
            return false;
        }
        if ($operator === '') {
            throw new InvalidArgumentException('Identificativo operatore mancante o non valido.');
        }

        $pdo = Database::connection();
        $displayName = self::displayName($operator);

        $statement = $pdo->prepare(
            'INSERT INTO users (username, display_name, active, last_login_at)
             VALUES (:username, :display_name, 1, NOW())
             ON DUPLICATE KEY UPDATE
                display_name = VALUES(display_name),
                last_login_at = NOW()'
        );
        $statement->execute([
            'username' => $operator,
            'display_name' => $displayName,
        ]);

        $userStatement = $pdo->prepare(
            'SELECT id, username, display_name, role FROM users WHERE username = :username AND active = 1'
        );
        $userStatement->execute(['username' => $operator]);
        $user = $userStatement->fetch();
        if (!$user) {
            self::logAttempt($operator, false);
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        $_SESSION['authenticated_at'] = time();
        self::logAttempt($operator, true, (int) $user['id']);

        return true;
    }

    public static function check(): bool
    {
        return isset($_SESSION['user']['id']);
    }

    public static function user(): ?array
    {
        return self::check() ? $_SESSION['user'] : null;
    }

    public static function id(): ?int
    {
        return self::check() ? (int) $_SESSION['user']['id'] : null;
    }

    public static function requireLogin(): void
    {
        if (self::check()) {
            return;
        }

        http_response_code(401);
        render_public_error(
            'Accesso richiesto',
            'Questa applicazione non usa un form di login. Aprila tramite il servizio SSO configurato nel software chiamante.'
        );
        exit;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    private static function normalizeOperator(string $operator): string
    {
        $operator = trim($operator);
        $operator = preg_replace('/[\x00-\x1F\x7F]/u', '', $operator) ?? '';
        if (mb_strlen($operator) < 2 || mb_strlen($operator) > 100) {
            return '';
        }
        return mb_strtolower($operator);
    }

    private static function displayName(string $username): string
    {
        $base = preg_replace('/@.*$/', '', $username) ?: $username;
        return ucwords(str_replace(['.', '_', '-'], ' ', $base));
    }

    private static function logAttempt(string $username, bool $success, ?int $userId = null): void
    {
        try {
            $statement = Database::connection()->prepare(
                'INSERT INTO auth_events (user_id, username_attempted, successful, ip_address, user_agent)
                 VALUES (:user_id, :username, :successful, :ip, :user_agent)'
            );
            $statement->execute([
                'user_id' => $userId,
                'username' => mb_substr($username, 0, 100),
                'successful' => $success ? 1 : 0,
                'ip' => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (Throwable) {
            // Il login non deve fallire solo perché la tabella di audit è temporaneamente indisponibile.
        }
    }
}

<?php

declare(strict_types=1);

final class Auth
{
    public static function attemptBridgeLogin(int $userId, string $token): bool
    {
        $expected = (string) config('app.shared_token', '');

        if ($expected === '' || !hash_equals($expected, $token)) {
            self::logAttempt($userId, false);
            return false;
        }
        if ($userId < 1) {
            return false;
        }

        $pdo = Database::connection();
        $userStatement = $pdo->prepare(
            "SELECT id, username, first_name, last_name, email, role,
                    TRIM(CONCAT(first_name, ' ', last_name)) AS display_name
             FROM users WHERE id = :id AND active = 1"
        );
        $userStatement->execute(['id' => $userId]);
        $user = $userStatement->fetch();
        if (!$user) {
            self::logAttempt($userId, false);
            return false;
        }

        $pdo->prepare('UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE id = :id')
            ->execute(['id' => $userId]);

        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        $_SESSION['authenticated_at'] = time();
        self::logAttempt($userId, true, (int) $user['id']);

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

    public static function isAdmin(): bool
    {
        return self::check() && (string) ($_SESSION['user']['role'] ?? 'operator') === 'admin';
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            http_response_code(403);
            render_public_error('Accesso non consentito', 'Questa funzione è riservata agli amministratori.');
            exit;
        }
    }

    public static function requireLogin(): void
    {
        if (self::check()) {
            $statement = Database::connection()->prepare('SELECT role, active FROM users WHERE id = :id');
            $statement->execute(['id' => self::id()]);
            $user = $statement->fetch();
            if ($user && (int) $user['active'] === 1) {
                $_SESSION['user']['role'] = (string) $user['role'];
                return;
            }
            $_SESSION = [];
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

    private static function logAttempt(int $attemptedUserId, bool $success, ?int $userId = null): void
    {
        try {
            $statement = Database::connection()->prepare(
                'INSERT INTO auth_events (user_id, user_id_attempted, successful, ip_address, user_agent)
                 VALUES (:user_id, :attempted_user_id, :successful, :ip, :user_agent)'
            );
            $statement->execute([
                'user_id' => $userId,
                'attempted_user_id' => $attemptedUserId,
                'successful' => $success ? 1 : 0,
                'ip' => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        } catch (Throwable) {
            // Il login non deve fallire solo perché la tabella di audit è temporaneamente indisponibile.
        }
    }
}

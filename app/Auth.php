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
        self::rememberDevice((int) $user['id']);

        return true;
    }

    public static function check(): bool
    {
        return isset($_SESSION['user']['id']) || self::restoreDeviceSession();
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
        if (!self::check()) {
            return false;
        }
        $role = (string) ($_SESSION['user']['role'] ?? 'operator');
        return in_array($role, ['admin', 'super'], true);
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
            'Per il primo accesso apri Preventivi dal software aziendale su questo stesso dispositivo. Dopo l’attivazione potrai usare direttamente l’icona installata.'
        );
        exit;
    }

    public static function logout(): void
    {
        self::forgetDevice();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    private static function restoreDeviceSession(): bool
    {
        $cookieName = self::deviceCookieName();
        $token = (string) ($_COOKIE[$cookieName] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            if ($token !== '') {
                self::clearDeviceCookie();
            }
            return false;
        }

        try {
            $pdo = Database::connection();
            $statement = $pdo->prepare(
                "SELECT ds.id AS device_session_id,
                        u.id, u.username, u.first_name, u.last_name, u.email, u.role,
                        TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS display_name
                 FROM device_sessions ds
                 JOIN users u ON u.id = ds.user_id
                 WHERE ds.token_hash = :token_hash
                   AND ds.expires_at > NOW() AND u.active = 1
                 LIMIT 1"
            );
            $statement->execute(['token_hash' => hash('sha256', $token)]);
            $user = $statement->fetch();
            if (!$user) {
                self::clearDeviceCookie();
                return false;
            }

            $deviceSessionId = (int) $user['device_session_id'];
            unset($user['device_session_id']);
            $expiresAt = (new DateTimeImmutable('now'))
                ->modify('+' . self::deviceSessionDays() . ' days')
                ->format('Y-m-d H:i:s');
            $pdo->prepare(
                'UPDATE device_sessions SET last_used_at = NOW(), expires_at = :expires_at WHERE id = :id'
            )->execute(['expires_at' => $expiresAt, 'id' => $deviceSessionId]);

            session_regenerate_id(true);
            $_SESSION['user'] = $user;
            $_SESSION['authenticated_at'] = time();
            $_SESSION['auth_source'] = 'device';
            $_SESSION['device_session_id'] = $deviceSessionId;
            self::writeDeviceCookie($token);
            return true;
        } catch (Throwable) {
            // Durante una migrazione incompleta resta disponibile il normale accesso SSO.
            return false;
        }
    }

    private static function rememberDevice(int $userId): void
    {
        try {
            $pdo = Database::connection();
            $oldToken = (string) ($_COOKIE[self::deviceCookieName()] ?? '');
            if (preg_match('/^[A-Za-z0-9_-]{43}$/', $oldToken)) {
                $pdo->prepare('DELETE FROM device_sessions WHERE token_hash = :token_hash')
                    ->execute(['token_hash' => hash('sha256', $oldToken)]);
            }

            $pdo->prepare('DELETE FROM device_sessions WHERE expires_at <= NOW()')->execute();
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $expiresAt = (new DateTimeImmutable('now'))
                ->modify('+' . self::deviceSessionDays() . ' days')
                ->format('Y-m-d H:i:s');
            $statement = $pdo->prepare(
                'INSERT INTO device_sessions
                 (user_id, token_hash, user_agent, ip_address, last_used_at, expires_at)
                 VALUES (:user_id, :token_hash, :user_agent, :ip_address, NOW(), :expires_at)'
            );
            $statement->execute([
                'user_id' => $userId,
                'token_hash' => hash('sha256', $token),
                'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                'ip_address' => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                'expires_at' => $expiresAt,
            ]);
            $_SESSION['device_session_id'] = (int) $pdo->lastInsertId();
            $_SESSION['auth_source'] = 'sso';
            self::writeDeviceCookie($token);
        } catch (Throwable) {
            // La sessione PHP ottenuta via SSO continua a funzionare anche se la tabella non è ancora migrata.
        }
    }

    private static function forgetDevice(): void
    {
        $token = (string) ($_COOKIE[self::deviceCookieName()] ?? '');
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            try {
                Database::connection()->prepare('DELETE FROM device_sessions WHERE token_hash = :token_hash')
                    ->execute(['token_hash' => hash('sha256', $token)]);
            } catch (Throwable) {
                // Il cookie viene eliminato comunque.
            }
        }
        self::clearDeviceCookie();
    }

    private static function writeDeviceCookie(string $token): void
    {
        setcookie(self::deviceCookieName(), $token, [
            'expires' => time() + self::deviceSessionDays() * 86400,
            'path' => '/',
            'secure' => (bool) config('app.session_secure', false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::deviceCookieName()] = $token;
    }

    private static function clearDeviceCookie(): void
    {
        setcookie(self::deviceCookieName(), '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => (bool) config('app.session_secure', false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::deviceCookieName()]);
    }

    private static function deviceCookieName(): string
    {
        return (string) config('app.device_cookie_name', 'basic_preventivi_device');
    }

    private static function deviceSessionDays(): int
    {
        return max(1, (int) config('app.device_session_days', 180));
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

SET NAMES utf8mb4;

-- Eseguire una sola volta dopo 20260716_users_sso_and_notifications.sql.
ALTER TABLE users
    ADD COLUMN role ENUM('operator', 'admin') NOT NULL DEFAULT 'operator' AFTER email,
    ADD INDEX idx_users_role_active (role, active);

-- Per evitare di lasciare l'applicazione senza amministratori, il primo utente attivo diventa admin.
-- Modificare successivamente i ruoli da phpMyAdmin in base alle responsabilita reali.
UPDATE users
SET role = 'admin'
WHERE id = (
    SELECT id FROM (
        SELECT id
        FROM users
        WHERE active = 1
        ORDER BY id ASC
        LIMIT 1
    ) AS first_active_user
);

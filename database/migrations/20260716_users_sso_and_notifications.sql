SET NAMES utf8mb4;

-- Eseguire una sola volta su un database creato con una versione precedente.
ALTER TABLE users
    ADD COLUMN first_name VARCHAR(80) NULL AFTER username,
    ADD COLUMN last_name VARCHAR(80) NULL AFTER first_name,
    ADD COLUMN email VARCHAR(180) NULL AFTER last_name;

UPDATE users
SET first_name = COALESCE(NULLIF(SUBSTRING_INDEX(display_name, ' ', 1), ''), username),
    last_name = CASE
        WHEN LOCATE(' ', display_name) > 0 THEN TRIM(SUBSTRING(display_name, LOCATE(' ', display_name) + 1))
        ELSE ''
    END,
    email = CONCAT('utente-', id, '@example.invalid');

ALTER TABLE users
    MODIFY first_name VARCHAR(80) NOT NULL,
    MODIFY last_name VARCHAR(80) NOT NULL,
    MODIFY email VARCHAR(180) NOT NULL,
    ADD INDEX idx_users_email (email),
    DROP COLUMN display_name,
    DROP COLUMN role;

ALTER TABLE auth_events
    DROP INDEX idx_auth_events_username,
    CHANGE COLUMN username_attempted user_id_attempted BIGINT UNSIGNED NOT NULL,
    ADD INDEX idx_auth_events_user_attempted (user_id_attempted, created_at);

CREATE TABLE IF NOT EXISTS operator_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quote_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    notification_type VARCHAR(30) NOT NULL,
    sequence_number INT NOT NULL DEFAULT 1,
    status_id_snapshot BIGINT UNSIGNED NULL,
    status_changed_at_snapshot DATETIME NULL,
    dedupe_key VARCHAR(180) NOT NULL,
    due_at DATETIME NOT NULL,
    triggered_at DATETIME NOT NULL,
    acknowledged_at DATETIME NULL,
    resolved_at DATETIME NULL,
    email_sent_at DATETIME NULL,
    email_error VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    CONSTRAINT fk_notifications_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE,
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_notifications_status FOREIGN KEY (status_id_snapshot) REFERENCES statuses(id) ON DELETE SET NULL,
    UNIQUE KEY uq_notification_dedupe (quote_id, dedupe_key),
    INDEX idx_notifications_queue (user_id, acknowledged_at, resolved_at),
    INDEX idx_notifications_email (email_sent_at, resolved_at),
    INDEX idx_notifications_type_due (notification_type, due_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE quotes
SET quote_deadline = DATE_ADD(created_at, INTERVAL 24 HOUR),
    next_followup_at = DATE_ADD(status_changed_at, INTERVAL 3 DAY);

-- Sostituire gli indirizzi @example.invalid con le email reali prima di attivare gli invii.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS status_reminders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quote_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    status_id BIGINT UNSIGNED NOT NULL,
    status_changed_at_snapshot DATETIME NOT NULL,
    triggered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    acknowledged_at DATETIME NULL,
    resolved_at DATETIME NULL,
    email_sent_at DATETIME NULL,
    email_error VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_status_reminders_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE,
    CONSTRAINT fk_status_reminders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_status_reminders_status FOREIGN KEY (status_id) REFERENCES statuses(id),
    UNIQUE KEY uq_status_reminder_phase (quote_id, user_id, status_id, status_changed_at_snapshot),
    INDEX idx_status_reminders_queue (user_id, acknowledged_at, resolved_at),
    INDEX idx_status_reminders_email (email_sent_at, resolved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

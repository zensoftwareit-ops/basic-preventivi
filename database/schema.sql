SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    email VARCHAR(180) NOT NULL,
    role ENUM('operator', 'admin', 'super') NOT NULL DEFAULT 'operator',
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    INDEX idx_users_active (active),
    INDEX idx_users_email (email),
    INDEX idx_users_role_active (role, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS services (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    sort_order INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS channels (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    sort_order INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS priorities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    color CHAR(7) NOT NULL DEFAULT '#64748b',
    weight INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS statuses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL UNIQUE,
    color CHAR(7) NOT NULL DEFAULT '#64748b',
    is_closed TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS outcomes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL UNIQUE,
    is_success TINYINT(1) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quotes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    practice_code VARCHAR(24) NULL UNIQUE,
    request_date DATE NOT NULL,
    request_time TIME NULL,
    customer_name VARCHAR(180) NOT NULL,
    customer_contact VARCHAR(180) NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(180) NULL,
    channel_id BIGINT UNSIGNED NULL,
    service_id BIGINT UNSIGNED NOT NULL,
    request_description TEXT NULL,
    received_by_user_id BIGINT UNSIGNED NULL,
    responsible_user_id BIGINT UNSIGNED NOT NULL,
    priority_id BIGINT UNSIGNED NOT NULL,
    status_id BIGINT UNSIGNED NOT NULL,
    quote_deadline DATETIME NOT NULL,
    date_sent DATETIME NULL,
    estimated_value DECIMAL(14,2) NOT NULL DEFAULT 0,
    probability TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_update_at DATETIME NOT NULL,
    next_followup_at DATETIME NULL,
    outcome_id BIGINT UNSIGNED NULL,
    loss_notes TEXT NULL,
    external_link VARCHAR(500) NULL,
    status_changed_at DATETIME NOT NULL,
    archived_at DATETIME NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    CONSTRAINT chk_quotes_probability CHECK (probability BETWEEN 0 AND 100),
    CONSTRAINT fk_quotes_channel FOREIGN KEY (channel_id) REFERENCES channels(id),
    CONSTRAINT fk_quotes_service FOREIGN KEY (service_id) REFERENCES services(id),
    CONSTRAINT fk_quotes_receiver FOREIGN KEY (received_by_user_id) REFERENCES users(id),
    CONSTRAINT fk_quotes_responsible FOREIGN KEY (responsible_user_id) REFERENCES users(id),
    CONSTRAINT fk_quotes_priority FOREIGN KEY (priority_id) REFERENCES priorities(id),
    CONSTRAINT fk_quotes_status FOREIGN KEY (status_id) REFERENCES statuses(id),
    CONSTRAINT fk_quotes_outcome FOREIGN KEY (outcome_id) REFERENCES outcomes(id),
    CONSTRAINT fk_quotes_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    INDEX idx_quotes_operational (archived_at, status_id, quote_deadline),
    INDEX idx_quotes_responsible (responsible_user_id, status_id),
    INDEX idx_quotes_customer (customer_name),
    INDEX idx_quotes_sent (date_sent),
    INDEX idx_quotes_followup (next_followup_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quote_activities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quote_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    activity_type VARCHAR(40) NOT NULL,
    note TEXT NOT NULL,
    old_status_id BIGINT UNSIGNED NULL,
    new_status_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_activities_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE,
    CONSTRAINT fk_activities_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_activities_old_status FOREIGN KEY (old_status_id) REFERENCES statuses(id),
    CONSTRAINT fk_activities_new_status FOREIGN KEY (new_status_id) REFERENCES statuses(id),
    INDEX idx_activities_quote (quote_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    endpoint TEXT NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,
    p256dh VARCHAR(180) NOT NULL,
    auth_token VARCHAR(100) NOT NULL,
    content_encoding VARCHAR(30) NOT NULL DEFAULT 'aes128gcm',
    user_agent VARCHAR(255) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_success_at DATETIME NULL,
    last_error VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    CONSTRAINT fk_push_subscriptions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_push_endpoint_hash (endpoint_hash),
    INDEX idx_push_user_active (user_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    notification_id BIGINT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED NOT NULL,
    response_code SMALLINT UNSIGNED NOT NULL,
    sent_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_push_deliveries_notification FOREIGN KEY (notification_id) REFERENCES operator_notifications(id) ON DELETE CASCADE,
    CONSTRAINT fk_push_deliveries_subscription FOREIGN KEY (subscription_id) REFERENCES push_subscriptions(id) ON DELETE CASCADE,
    UNIQUE KEY uq_push_delivery (notification_id, subscription_id),
    INDEX idx_push_delivery_sent (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    user_id_attempted BIGINT UNSIGNED NOT NULL,
    successful TINYINT(1) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_auth_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_auth_events_created (created_at),
    INDEX idx_auth_events_user_attempted (user_id_attempted, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    user_agent VARCHAR(255) NULL,
    ip_address VARCHAR(45) NULL,
    last_used_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_device_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_device_session_token (token_hash),
    INDEX idx_device_session_user (user_id),
    INDEX idx_device_session_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mobile_activation_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mobile_activation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_mobile_activation_token (token_hash),
    INDEX idx_mobile_activation_user (user_id, used_at),
    INDEX idx_mobile_activation_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO services (name, sort_order) VALUES
('Noleggio food truck', 10),
('Camion vela', 20),
('Decorazione veicolo', 30),
('Tour promozionale', 40),
('Hospitality', 50),
('Trasporto / logistica', 60),
('Personale / staff', 70),
('Permessi / autorizzazioni', 80),
('Altro', 90);

INSERT IGNORE INTO channels (name, sort_order) VALUES
('Email', 10), ('Telefono', 20), ('WhatsApp', 30), ('Sito', 40), ('Passaparola', 50), ('Altro', 60);

INSERT IGNORE INTO priorities (name, color, weight) VALUES
('Alta', '#dc3d4b', 30), ('Media', '#d88616', 20), ('Bassa', '#16865b', 10);

INSERT IGNORE INTO statuses (code, name, color, is_closed, sort_order) VALUES
('NEW', 'Nuova richiesta', '#e0a600', 0, 10),
('PREPARING', 'In preparazione', '#d99b18', 0, 20),
('WAITING_CUSTOMER', 'In attesa dati cliente', '#e57b25', 0, 30),
('READY', 'Pronto da inviare', '#4b79c9', 0, 40),
('SENT', 'Preventivo inviato', '#29a66f', 0, 50),
('NEGOTIATING', 'In trattativa', '#7a5bd5', 0, 60),
('CONFIRMED', 'Confermato', '#16865b', 1, 70),
('LOST', 'Perso', '#6c7788', 1, 80),
('CANCELLED', 'Annullato', '#9b6268', 1, 90);

INSERT IGNORE INTO outcomes (code, name, is_success, sort_order) VALUES
('OPEN', 'Aperto', NULL, 10), ('WON', 'Vinto', 1, 20), ('LOST', 'Perso', 0, 30), ('CANCELLED', 'Annullato', 0, 40);

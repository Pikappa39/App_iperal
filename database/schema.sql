CREATE TABLE IF NOT EXISTS utenti (
    cod_fiscale VARCHAR(16) PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    cognome VARCHAR(100) NOT NULL,
    badge VARCHAR(20) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    avatar VARCHAR(50) NOT NULL DEFAULT 'default',
    capo TINYINT UNSIGNED NOT NULL DEFAULT 0,
    reparto VARCHAR(20) NULL DEFAULT NULL,
    session_version INT UNSIGNED NOT NULL DEFAULT 0,
    last_seen DATETIME NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS schedule_name_mappings (
    reparto VARCHAR(20) NOT NULL,
    schedule_name VARCHAR(191) NOT NULL,
    user_cf VARCHAR(16) NOT NULL,
    created_by_cf VARCHAR(16) NULL DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (reparto, schedule_name),
    INDEX idx_schedule_name_mappings_user (user_cf)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_cf VARCHAR(16) NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL DEFAULT NULL,
    requested_ip VARCHAR(45) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_password_reset_user_created (user_cf, created_at),
    INDEX idx_password_reset_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS communications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    author_cf VARCHAR(16) NOT NULL,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    priority ENUM('normal', 'important') NOT NULL DEFAULT 'normal',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_communications_author_created (author_cf, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS communication_recipients (
    communication_id BIGINT UNSIGNED NOT NULL,
    recipient_cf VARCHAR(16) NOT NULL,
    read_at DATETIME NULL DEFAULT NULL,
    acknowledged_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (communication_id, recipient_cf),
    INDEX idx_communication_recipients_inbox (recipient_cf, acknowledged_at, communication_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_cf VARCHAR(16) NOT NULL,
    endpoint VARCHAR(512) NOT NULL,
    p256dh VARCHAR(255) NOT NULL,
    auth_token VARCHAR(255) NOT NULL,
    content_encoding VARCHAR(32) NOT NULL DEFAULT 'aes128gcm',
    user_agent VARCHAR(512) NULL DEFAULT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_push_subscriptions_endpoint (endpoint),
    INDEX idx_push_subscriptions_user_active (user_cf, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS schedule_change_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    batch_id CHAR(32) NOT NULL,
    user_cf VARCHAR(16) NOT NULL,
    changed_by_cf VARCHAR(16) NOT NULL,
    iso_year SMALLINT UNSIGNED NOT NULL,
    iso_week TINYINT UNSIGNED NOT NULL,
    schedule_date DATE NOT NULL,
    day_name VARCHAR(20) NOT NULL,
    previous_shift VARCHAR(255) NOT NULL DEFAULT '',
    new_shift VARCHAR(255) NOT NULL DEFAULT '',
    source_file VARCHAR(255) NOT NULL,
    read_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_schedule_change_user_read (user_cf, read_at, id),
    INDEX idx_schedule_change_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

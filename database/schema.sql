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
    box_info TINYINT(1) NOT NULL DEFAULT 0,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
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

-- Conserva esclusivamente l'impronta dell'indirizzo usato nei tentativi
-- falliti. Serve a rallentare i tentativi ripetuti senza aggiungere dati
-- personali in chiaro al database.
CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email_hash CHAR(64) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_email_time (email_hash, attempted_at),
    INDEX idx_login_attempts_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS admin_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    actor_cf VARCHAR(16) NOT NULL,
    action VARCHAR(80) NOT NULL,
    target_type VARCHAR(80) NULL DEFAULT NULL,
    target_id VARCHAR(191) NULL DEFAULT NULL,
    details_json TEXT NULL DEFAULT NULL,
    request_ip_hash CHAR(64) NULL DEFAULT NULL,
    user_agent VARCHAR(255) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_audit_created (created_at),
    INDEX idx_admin_audit_actor_created (actor_cf, created_at),
    INDEX idx_admin_audit_action_created (action, created_at),
    INDEX idx_admin_audit_target (target_type, target_id)
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

CREATE TABLE IF NOT EXISTS schedule_upload_versions (
    id CHAR(32) NOT NULL PRIMARY KEY,
    reparto VARCHAR(20) NOT NULL,
    iso_year SMALLINT UNSIGNED NOT NULL,
    iso_week TINYINT UNSIGNED NOT NULL,
    source_file VARCHAR(255) NOT NULL,
    uploaded_by_cf VARCHAR(16) NOT NULL,
    schedule_snapshot MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_schedule_upload_versions_week (reparto, iso_year, iso_week, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS schedule_active_versions (
    reparto VARCHAR(20) NOT NULL,
    iso_year SMALLINT UNSIGNED NOT NULL,
    iso_week TINYINT UNSIGNED NOT NULL,
    version_id CHAR(32) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (reparto, iso_year, iso_week),
    INDEX idx_schedule_active_versions_version (version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS schedule_week_locks (
    reparto VARCHAR(20) NOT NULL,
    iso_year SMALLINT UNSIGNED NOT NULL,
    iso_week TINYINT UNSIGNED NOT NULL,
    touched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (reparto, iso_year, iso_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS schedule_department_locks (
    reparto VARCHAR(20) NOT NULL PRIMARY KEY,
    touched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS schedule_adjustment_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_cf VARCHAR(16) NOT NULL,
    reparto VARCHAR(20) NOT NULL,
    iso_year SMALLINT UNSIGNED NOT NULL,
    iso_week TINYINT UNSIGNED NOT NULL,
    schedule_date DATE NOT NULL,
    day_name VARCHAR(20) NOT NULL,
    base_upload_id CHAR(32) NULL DEFAULT NULL,
    original_shift VARCHAR(255) NOT NULL,
    current_original_shift VARCHAR(255) NOT NULL,
    requested_shift VARCHAR(255) NOT NULL,
    request_note VARCHAR(1000) NULL DEFAULT NULL,
    status ENUM('pending', 'review', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    review_reason VARCHAR(255) NULL DEFAULT NULL,
    decision_note VARCHAR(1000) NULL DEFAULT NULL,
    decided_by_cf VARCHAR(16) NULL DEFAULT NULL,
    decided_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_schedule_adjustment_user_date (user_cf, schedule_date, status),
    INDEX idx_schedule_adjustment_manage (reparto, status, schedule_date),
    INDEX idx_schedule_adjustment_week (reparto, iso_year, iso_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS schedule_adjustment_day_locks (
    user_cf VARCHAR(16) NOT NULL,
    schedule_date DATE NOT NULL,
    touched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_cf, schedule_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS extra_hour_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    request_kind VARCHAR(20) NOT NULL,
    user_cf VARCHAR(16) NOT NULL,
    origin_reparto VARCHAR(20) NOT NULL,
    target_reparto VARCHAR(20) NULL DEFAULT NULL,
    store_name VARCHAR(120) NULL DEFAULT NULL,
    schedule_date DATE NOT NULL,
    minutes SMALLINT UNSIGNED NOT NULL,
    request_note VARCHAR(1000) NULL DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    origin_status VARCHAR(20) NULL DEFAULT NULL,
    origin_decided_by_cf VARCHAR(16) NULL DEFAULT NULL,
    origin_decision_note VARCHAR(1000) NULL DEFAULT NULL,
    origin_decided_at DATETIME NULL DEFAULT NULL,
    target_status VARCHAR(20) NULL DEFAULT NULL,
    target_decided_by_cf VARCHAR(16) NULL DEFAULT NULL,
    target_decision_note VARCHAR(1000) NULL DEFAULT NULL,
    target_decided_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_extra_hour_user_date (user_cf, schedule_date, status),
    INDEX idx_extra_hour_origin_manage (origin_reparto, status, schedule_date),
    INDEX idx_extra_hour_target_manage (target_reparto, status, schedule_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS customer_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    target_reparto VARCHAR(20) NOT NULL,
    source_type VARCHAR(20) NOT NULL DEFAULT 'department',
    source_reparto VARCHAR(20) NOT NULL,
    customer_name VARCHAR(100) NOT NULL,
    customer_surname VARCHAR(100) NOT NULL,
    customer_phone VARCHAR(40) NOT NULL,
    general_note VARCHAR(2000) NULL DEFAULT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'registered',
    taken_by_cf VARCHAR(16) NULL DEFAULT NULL,
    taken_by_name VARCHAR(220) NOT NULL,
    taken_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer_orders_target_status (target_reparto, status, taken_at),
    INDEX idx_customer_orders_taken_by (taken_by_cf, taken_at),
    INDEX idx_customer_orders_status (status, taken_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS customer_order_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    article_name VARCHAR(255) NOT NULL,
    ean VARCHAR(64) NULL DEFAULT NULL,
    internal_code VARCHAR(64) NULL DEFAULT NULL,
    quantity VARCHAR(80) NOT NULL,
    price_at_order DECIMAL(10,2) NULL DEFAULT NULL,
    item_note VARCHAR(1000) NULL DEFAULT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'registered',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer_order_items_order (order_id, status),
    INDEX idx_customer_order_items_ean (ean),
    INDEX idx_customer_order_items_internal_code (internal_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS customer_order_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NULL DEFAULT NULL,
    actor_cf VARCHAR(16) NULL DEFAULT NULL,
    actor_name VARCHAR(220) NOT NULL,
    event_type VARCHAR(60) NOT NULL,
    from_status VARCHAR(30) NULL DEFAULT NULL,
    to_status VARCHAR(30) NULL DEFAULT NULL,
    details_json TEXT NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer_order_events_order (order_id, created_at),
    INDEX idx_customer_order_events_item (item_id, created_at),
    INDEX idx_customer_order_events_actor (actor_cf, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS customer_order_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    recipient_cf VARCHAR(16) NOT NULL,
    event_type VARCHAR(60) NOT NULL,
    title VARCHAR(150) NOT NULL,
    body VARCHAR(255) NOT NULL,
    read_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer_order_notifications_recipient (recipient_cf, read_at, created_at),
    INDEX idx_customer_order_notifications_order (order_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS user_invites (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invited_by_cf VARCHAR(16) NOT NULL,
    invited_email VARCHAR(255) NOT NULL,
    invited_badge VARCHAR(20) NOT NULL,
    invited_cf VARCHAR(16) NOT NULL,
    invited_nome VARCHAR(100) NOT NULL,
    invited_cognome VARCHAR(100) NOT NULL,
    invited_capo TINYINT UNSIGNED NOT NULL DEFAULT 0,
    invited_box_info TINYINT(1) NOT NULL DEFAULT 0,
    reparto VARCHAR(20) NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    accepted_at DATETIME NULL DEFAULT NULL,
    revoked_at DATETIME NULL DEFAULT NULL,
    accepted_user_cf VARCHAR(16) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_invites_status (reparto, expires_at, accepted_at, revoked_at),
    INDEX idx_user_invites_email (invited_email),
    INDEX idx_user_invites_cf (invited_cf),
    INDEX idx_user_invites_badge (invited_badge)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

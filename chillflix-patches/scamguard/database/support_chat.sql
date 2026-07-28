-- Support chat: visitor widget + bot first-line + admin inbox
CREATE TABLE IF NOT EXISTS support_conversations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_token CHAR(64) NOT NULL,
    visitor_token CHAR(64) NOT NULL,
    user_id INT UNSIGNED NULL,
    visitor_name VARCHAR(80) NULL,
    visitor_email VARCHAR(190) NULL,
    status ENUM('bot','waiting','active','closed') NOT NULL DEFAULT 'bot',
    assigned_admin_id INT UNSIGNED NULL,
    assigned_admin_name VARCHAR(80) NULL,
    page_url VARCHAR(500) NULL,
    user_agent VARCHAR(500) NULL,
    ip_hash CHAR(64) NULL,
    subject VARCHAR(160) NULL,
    rating TINYINT UNSIGNED NULL,
    rating_comment VARCHAR(500) NULL,
    unread_admin INT UNSIGNED NOT NULL DEFAULT 0,
    unread_visitor INT UNSIGNED NOT NULL DEFAULT 0,
    last_message_at DATETIME NULL,
    closed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_support_public_token (public_token),
    KEY idx_support_visitor_token (visitor_token),
    KEY idx_support_status_last (status, last_message_at),
    KEY idx_support_user (user_id),
    KEY idx_support_unread_admin (unread_admin, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    sender_type ENUM('visitor','bot','admin','system') NOT NULL,
    sender_name VARCHAR(80) NULL,
    sender_user_id INT UNSIGNED NULL,
    body TEXT NOT NULL,
    is_quick_action TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_support_msg_conv (conversation_id, id),
    CONSTRAINT fk_support_msg_conv
        FOREIGN KEY (conversation_id) REFERENCES support_conversations(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO site_settings (setting_key, setting_value) VALUES
    ('support_chat_enabled', '1'),
    ('support_chat_greeting', 'Hi — I''m the ScamGuard helper. Ask about checks, scores, reports, or accounts. If I can''t help, you can talk to an admin.'),
    ('support_chat_offline', 'Admins may be offline right now. Leave a message and we''ll reply here as soon as someone is available.')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

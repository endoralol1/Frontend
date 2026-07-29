-- ScamGuard Database Schema
-- Import this file into a fresh MySQL/MariaDB database before using the site.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------
-- Admin users (separate from any future public user accounts)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('superadmin','moderator') NOT NULL DEFAULT 'moderator',
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- No default admin is inserted here on purpose.
-- After importing this file, visit /setup.php in your browser once to
-- create your first admin account (it hashes the password securely at
-- that time, using your server's own PHP). setup.php locks itself once
-- an admin account exists.

-- ---------------------------------------------------------------
-- Core domain records
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL UNIQUE,
    trust_score INT NOT NULL DEFAULT 50,
    status enum('unknown','safe','caution','risky','scam','whitelisted','blacklisted','unavailable') NOT NULL DEFAULT 'unknown',
    first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_checked DATETIME NULL,
    check_count INT NOT NULL DEFAULT 0,
    search_count INT NOT NULL DEFAULT 0,

    -- WHOIS / registration data
    whois_registrar VARCHAR(255) NULL,
    whois_created_at DATE NULL,
    whois_expires_at DATE NULL,
    whois_privacy_protected TINYINT(1) NULL,
    domain_age_days INT NULL,
    registration_length_days INT NULL,

    -- SSL data
    ssl_valid TINYINT(1) NULL,
    ssl_issuer VARCHAR(255) NULL,
    ssl_expires_at DATE NULL,

    -- Hosting / network data
    ip_address VARCHAR(45) NULL,
    asn VARCHAR(32) NULL,
    asn_org VARCHAR(255) NULL,
    host_country VARCHAR(2) NULL,
    nameservers TEXT NULL,

    -- Content-level signals (plain HTML fetch, no browser)
    has_contact_info TINYINT(1) NULL,
    has_privacy_policy TINYINT(1) NULL,
    redirect_count INT NULL,
    suspicious_keyword_hits INT NULL,

    -- Threat feed results
    threat_feed_hit TINYINT(1) NOT NULL DEFAULT 0,
    threat_feed_sources TEXT NULL,

    -- Admin overrides
    manual_override TINYINT(1) NOT NULL DEFAULT 0,
    admin_notes TEXT NULL,

    discovered_via VARCHAR(64) NULL, -- 'search','ct_log','threat_feed','user_report','manual'

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_score (trust_score),
    INDEX idx_status (status),
    INDEX idx_last_checked (last_checked)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Historical score snapshots (for score-over-time graphs)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS domain_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    trust_score INT NOT NULL,
    status VARCHAR(32) NOT NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE,
    INDEX idx_domain_time (domain_id, checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- User-submitted scam reports
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NULL,
    domain_text VARCHAR(255) NOT NULL,
    reporter_email VARCHAR(255) NULL,
    category ENUM('phishing','fake_shop','crypto_scam','tech_support_scam','identity_theft','other') NOT NULL DEFAULT 'other',
    description TEXT NULL,
    status ENUM('pending','approved','rejected','merged') NOT NULL DEFAULT 'pending',
    admin_id INT NULL,
    ip_hash VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE SET NULL,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Discovery pipeline sources & run log
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS discovery_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(64) NOT NULL UNIQUE,
    label VARCHAR(128) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    config TEXT NULL, -- JSON blob for source-specific settings
    last_run_at DATETIME NULL,
    last_run_status VARCHAR(32) NULL,
    last_run_found INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO discovery_sources (name, label, enabled) VALUES
('ct_logs', 'Certificate Transparency Logs (crt.sh)', 1),
('threat_feeds', 'Public Threat Feeds (PhishTank / URLhaus / OpenPhish)', 1),
('user_reports', 'User-Submitted Reports', 1)
ON DUPLICATE KEY UPDATE name = name;

CREATE TABLE IF NOT EXISTS discovery_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source_name VARCHAR(64) NOT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    domains_found INT NOT NULL DEFAULT 0,
    domains_queued INT NOT NULL DEFAULT 0,
    status VARCHAR(32) NOT NULL DEFAULT 'running',
    log_output TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Scoring configuration (admin-editable weights & thresholds)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS scoring_config (
    config_key VARCHAR(64) PRIMARY KEY,
    config_value VARCHAR(255) NOT NULL,
    description VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO scoring_config (config_key, config_value, description) VALUES
('weight_domain_age', '20', 'Max points awarded/deducted based on domain age'),
('weight_registration_length', '10', 'Max points for long vs short registration period'),
('weight_ssl', '15', 'Max points for valid/trusted SSL certificate'),
('weight_hosting', '10', 'Max points for hosting/ASN reputation'),
('weight_content', '15', 'Max points for contact info / privacy policy / keyword signals'),
('weight_threat_feed', '30', 'Points deducted if domain appears on a threat feed'),
('threshold_safe', '80', 'Score at or above this = Safe'),
('threshold_caution', '50', 'Score at or above this (below safe) = Caution'),
('threshold_risky', '25', 'Score at or above this (below caution) = Risky'),
('recheck_interval_hours', '72', 'How often a cached domain is automatically re-checked'),
('new_domain_threshold_days', '180', 'Domains younger than this are considered "new" for scoring')
ON DUPLICATE KEY UPDATE config_key = config_key;

-- ---------------------------------------------------------------
-- Site settings (general, non-scoring)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_settings (
    setting_key VARCHAR(64) PRIMARY KEY,
    setting_value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO site_settings (setting_key, setting_value) VALUES
('site_name', 'ScamGuard'),
('site_tagline', 'Know before you click.'),
('announcement_banner', ''),
('announcement_enabled', '0'),
('discovery_batch_size', '50'),
('discovery_rate_limit_per_hour', '500')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- ---------------------------------------------------------------
-- API keys (for future public API access)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_key VARCHAR(64) NOT NULL UNIQUE,
    label VARCHAR(128) NULL,
    owner_email VARCHAR(255) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    requests_made INT NOT NULL DEFAULT 0,
    rate_limit_per_day INT NOT NULL DEFAULT 1000,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS api_usage_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_key_id INT NOT NULL,
    endpoint VARCHAR(128) NOT NULL,
    domain_queried VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Admin activity log (audit trail for overrides/blacklists/etc.)
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NULL,
    action VARCHAR(128) NOT NULL,
    target VARCHAR(255) NULL,
    details TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- Phone / crypto / IBAN checks + community reports
CREATE TABLE IF NOT EXISTS entity_checks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('phone','crypto','iban') NOT NULL,
    entity_value VARCHAR(255) NOT NULL,
    display_value VARCHAR(255) NOT NULL,
    trust_score INT NOT NULL DEFAULT 50,
    status ENUM('unknown','safe','caution','risky','scam','whitelisted','blacklisted') NOT NULL DEFAULT 'unknown',
    verdict VARCHAR(32) NULL,
    facts_json MEDIUMTEXT NULL,
    signals_json MEDIUMTEXT NULL,
    check_count INT NOT NULL DEFAULT 0,
    search_count INT NOT NULL DEFAULT 0,
    first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_checked DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_entity (entity_type, entity_value),
    INDEX idx_entity_status (status),
    INDEX idx_entity_checked (last_checked)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS entity_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('phone','crypto','iban') NOT NULL,
    entity_value VARCHAR(255) NOT NULL,
    reporter_email VARCHAR(255) NULL,
    category VARCHAR(64) NOT NULL DEFAULT 'other',
    description TEXT NULL,
    status ENUM('pending','approved','rejected','merged') NOT NULL DEFAULT 'pending',
    admin_id INT NULL,
    ip_hash VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    INDEX idx_entity_reports (entity_type, entity_value),
    INDEX idx_entity_report_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

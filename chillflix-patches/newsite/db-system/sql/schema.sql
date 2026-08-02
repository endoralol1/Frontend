-- Chillflix newsite schema
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
  id CHAR(36) NOT NULL,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(120) NOT NULL,
  role ENUM('admin','moderator','user') NOT NULL DEFAULT 'user',
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  language VARCHAR(10) NOT NULL DEFAULT 'en',
  autoplay_enabled TINYINT(1) NOT NULL DEFAULT 0,
  auto_next_enabled TINYINT(1) NOT NULL DEFAULT 1,
  watchlist_enabled TINYINT(1) NOT NULL DEFAULT 1,
  continue_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  last_login_at BIGINT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role),
  KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  id CHAR(64) NOT NULL,
  user_id CHAR(36) NOT NULL,
  expires_at BIGINT NOT NULL,
  created_at BIGINT NOT NULL,
  user_agent VARCHAR(512) NULL,
  ip VARCHAR(64) NULL,
  PRIMARY KEY (id),
  KEY idx_sessions_user (user_id),
  KEY idx_sessions_expires (expires_at),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS favorites (
  user_id CHAR(36) NOT NULL,
  media_type ENUM('movie','tv') NOT NULL,
  tmdb_id INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL DEFAULT '',
  poster VARCHAR(512) NULL,
  backdrop VARCHAR(512) NULL,
  year VARCHAR(8) NULL,
  updated_at BIGINT NOT NULL,
  PRIMARY KEY (user_id, media_type, tmdb_id),
  KEY idx_fav_updated (user_id, updated_at),
  CONSTRAINT fk_fav_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS continue_watching (
  user_id CHAR(36) NOT NULL,
  media_key VARCHAR(64) NOT NULL,
  media_type ENUM('movie','tv') NOT NULL,
  tmdb_id INT UNSIGNED NOT NULL,
  season INT UNSIGNED NULL,
  episode INT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL DEFAULT '',
  poster VARCHAR(512) NULL,
  backdrop VARCHAR(512) NULL,
  year VARCHAR(8) NULL,
  position_sec DOUBLE NOT NULL DEFAULT 0,
  duration_sec DOUBLE NOT NULL DEFAULT 0,
  updated_at BIGINT NOT NULL,
  PRIMARY KEY (user_id, media_key),
  KEY idx_cw_updated (user_id, updated_at),
  CONSTRAINT fk_cw_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sources (
  id VARCHAR(64) NOT NULL,
  name VARCHAR(128) NOT NULL,
  public_label VARCHAR(64) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 100,
  notes VARCHAR(512) NULL,
  updated_at BIGINT NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sources_order (enabled, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS source_test_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_id VARCHAR(64) NOT NULL,
  media_type ENUM('movie','tv') NOT NULL,
  tmdb_id INT UNSIGNED NOT NULL,
  season INT UNSIGNED NULL,
  episode INT UNSIGNED NULL,
  ok TINYINT(1) NOT NULL DEFAULT 0,
  source_count INT NOT NULL DEFAULT 0,
  message VARCHAR(512) NULL,
  tested_by CHAR(36) NULL,
  created_at BIGINT NOT NULL,
  PRIMARY KEY (id),
  KEY idx_stl_source (source_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

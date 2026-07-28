-- Community feedback: up/down votes on threads and comments.
-- Run once:  mysql scamguard < database/community_votes.sql

CREATE TABLE IF NOT EXISTS forum_votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    subject_type ENUM('thread','comment') NOT NULL,
    subject_id INT NOT NULL,
    vote TINYINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_vote (user_id, subject_type, subject_id),
    INDEX idx_votes_subject (subject_type, subject_id),
    CONSTRAINT fk_votes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

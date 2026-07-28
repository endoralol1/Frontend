-- Admin announcement posts in the community forum.
-- Run once:  mysql scamguard < database/community_announcements.sql

ALTER TABLE forum_threads
    MODIFY subject_type ENUM('website','phone','crypto','iban','card','announcement') NOT NULL DEFAULT 'website',
    ADD COLUMN is_announcement TINYINT(1) NOT NULL DEFAULT 0 AFTER category,
    ADD INDEX idx_threads_announcement (is_announcement, last_activity_at);

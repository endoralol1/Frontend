-- Community roles: moderators/admins are normal community accounts with a role.
-- Run once:  mysql scamguard < database/community_roles.sql

ALTER TABLE users
    ADD COLUMN role ENUM('user','moderator','admin') NOT NULL DEFAULT 'user' AFTER password_hash;

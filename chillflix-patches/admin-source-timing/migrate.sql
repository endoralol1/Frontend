ALTER TABLE source_test_logs
  ADD COLUMN IF NOT EXISTS elapsed_ms int unsigned NULL DEFAULT NULL AFTER source_count,
  ADD COLUMN IF NOT EXISTS playable tinyint(1) NOT NULL DEFAULT 0 AFTER ok;

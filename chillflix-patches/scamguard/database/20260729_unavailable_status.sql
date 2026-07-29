ALTER TABLE domains
  MODIFY status ENUM('unknown','safe','caution','risky','scam','whitelisted','blacklisted','unavailable')
  NOT NULL DEFAULT 'unknown';

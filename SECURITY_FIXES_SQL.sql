-- FACTHub 2 Security Audit Fixes — SQL Schema Updates
-- Run these commands immediately after code fixes are deployed
-- Date: May 9, 2026

-- 1. Create rate_limits table for brute force protection
CREATE TABLE IF NOT EXISTS rate_limits (
    `key` VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_key_time (`key`, created_at),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Update password_resets table to track used tokens
ALTER TABLE password_resets ADD COLUMN used_at DATETIME DEFAULT NULL AFTER expires_at;
ALTER TABLE password_resets ADD INDEX idx_used_at (used_at);

-- 3. Create unsubscribe_tokens table (randomized tokens)
CREATE TABLE IF NOT EXISTS unsubscribe_tokens (
    token VARCHAR(64) PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Optional: Add idempotency key to job_queue (prevents duplicate execution)
ALTER TABLE job_queue ADD COLUMN idempotency_key VARCHAR(64) UNIQUE DEFAULT NULL;

-- 5. Ensure audit_log has all necessary indexes
ALTER TABLE audit_log ADD INDEX idx_actor (actor_email);
ALTER TABLE audit_log ADD INDEX idx_action (action);
ALTER TABLE audit_log ADD INDEX idx_time (created_at);
ALTER TABLE audit_log ADD INDEX idx_email (target_email);

-- Verify tables exist
SHOW TABLES LIKE 'rate_limits';
SHOW TABLES LIKE 'unsubscribe_tokens';
DESCRIBE password_resets;

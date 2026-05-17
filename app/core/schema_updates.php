<?php
/**
 * Auto-apply critical schema updates on first run.
 * This ensures all security fixes are in place.
 */

function apply_security_schema_updates(mysqli $conn): void {
    // Suppress errors to avoid 500s during table/column creation
    $oldErrorMode = $conn->sql_mode ?? '';

    // Check if rate_limits table exists
    $result = @$conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME='rate_limits' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("
            CREATE TABLE IF NOT EXISTS rate_limits (
                `key` VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_key_time (`key`, created_at),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // Add used_at column to password_resets if it doesn't exist
    $result = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='password_resets' AND COLUMN_NAME='used_at' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("ALTER TABLE password_resets ADD COLUMN used_at DATETIME DEFAULT NULL AFTER expires_at");
        @$conn->query("ALTER TABLE password_resets ADD INDEX idx_used_at (used_at)");
    }

    // Create unsubscribe_tokens table if it doesn't exist
    $result = @$conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME='unsubscribe_tokens' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("
            CREATE TABLE IF NOT EXISTS unsubscribe_tokens (
                token VARCHAR(64) PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // Add idempotency_key to job_queue if it doesn't exist
    $result = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='job_queue' AND COLUMN_NAME='idempotency_key' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("ALTER TABLE job_queue ADD COLUMN idempotency_key VARCHAR(64) UNIQUE DEFAULT NULL");
    }

    // Ensure audit_log has all necessary indexes (skip if table doesn't exist)
    $result = @$conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME='audit_log' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if ($result && $result->num_rows > 0) {
        @$conn->query("ALTER TABLE audit_log ADD INDEX idx_actor (actor_email)");
        @$conn->query("ALTER TABLE audit_log ADD INDEX idx_action (action)");
        @$conn->query("ALTER TABLE audit_log ADD INDEX idx_time (created_at)");
        @$conn->query("ALTER TABLE audit_log ADD INDEX idx_email (target_email)");
    }

    // ════════════════════════════════════════════════════════════════
    // Account Lifecycle System — Soft Delete, Status Control & Trash
    // ════════════════════════════════════════════════════════════════

    // 1a. Extend users.status ENUM to include active/inactive/deleted
    //     (Two-step process to handle existing 'verified' rows)
    $result = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='users' AND COLUMN_NAME='status' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if ($result && $result->num_rows > 0) {
        // Step 1: Expand ENUM to include all old + new values
        @$conn->query("ALTER TABLE users MODIFY COLUMN status ENUM('verified','unverified','active','inactive','deleted') NOT NULL DEFAULT 'active'");
        // Step 2: Migrate 'verified' → 'active'
        @$conn->query("UPDATE users SET status = 'active' WHERE status = 'verified'");
        // Step 3: Remove old 'verified' value
        @$conn->query("ALTER TABLE users MODIFY COLUMN status ENUM('active','inactive','deleted','unverified') NOT NULL DEFAULT 'active'");
    }

    // 1a. Add lifecycle columns to users table
    $columns = ['session_token', 'deleted_at', 'deactivated_at', 'restored_at', 'last_status_change_at', 'status_changed_by', 'deletion_reason'];
    foreach ($columns as $col) {
        $res = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='users' AND COLUMN_NAME='$col' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
        if (!$res || $res->num_rows === 0) {
            if ($col === 'session_token') {
                @$conn->query("ALTER TABLE users ADD COLUMN $col VARCHAR(64) NULL DEFAULT NULL");
            } elseif ($col === 'deletion_reason') {
                @$conn->query("ALTER TABLE users ADD COLUMN $col VARCHAR(500) NULL DEFAULT NULL");
            } elseif ($col === 'status_changed_by') {
                @$conn->query("ALTER TABLE users ADD COLUMN $col VARCHAR(150) NULL DEFAULT NULL");
            } else {
                @$conn->query("ALTER TABLE users ADD COLUMN $col TIMESTAMP NULL DEFAULT NULL");
            }
        }
    }

    // Add indexes on new users columns
    @$conn->query("ALTER TABLE users ADD INDEX IF NOT EXISTS idx_status (status)");
    @$conn->query("ALTER TABLE users ADD INDEX IF NOT EXISTS idx_deleted_at (deleted_at)");
    @$conn->query("ALTER TABLE users ADD INDEX IF NOT EXISTS idx_session_token (session_token)");

    // 1b. Add user_id FK + lifecycle columns to researchers
    $res = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='researchers' AND COLUMN_NAME='user_id' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$res || $res->num_rows === 0) {
        @$conn->query("ALTER TABLE researchers ADD COLUMN user_id INT NULL DEFAULT NULL AFTER id");
    }

    $res = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='researchers' AND COLUMN_NAME='status' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$res || $res->num_rows === 0) {
        @$conn->query("ALTER TABLE researchers ADD COLUMN status ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active' AFTER user_id");
    }

    foreach (['deleted_at', 'deactivated_at', 'restored_at'] as $col) {
        $res = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='researchers' AND COLUMN_NAME='$col' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
        if (!$res || $res->num_rows === 0) {
            @$conn->query("ALTER TABLE researchers ADD COLUMN $col TIMESTAMP NULL DEFAULT NULL");
        }
    }

    @$conn->query("ALTER TABLE researchers ADD INDEX IF NOT EXISTS idx_user_id (user_id)");
    @$conn->query("ALTER TABLE researchers ADD INDEX IF NOT EXISTS idx_researcher_status (status)");

    // Backfill user_id for researchers (by email join)
    @$conn->query("UPDATE researchers r JOIN users u ON u.email = r.email SET r.user_id = u.id WHERE r.user_id IS NULL");

    // 1c. Add user_id FK + lifecycle columns to funders
    $res = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='funders' AND COLUMN_NAME='user_id' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$res || $res->num_rows === 0) {
        @$conn->query("ALTER TABLE funders ADD COLUMN user_id INT NULL DEFAULT NULL AFTER id");
    }

    $res = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='funders' AND COLUMN_NAME='status' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$res || $res->num_rows === 0) {
        @$conn->query("ALTER TABLE funders ADD COLUMN status ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active' AFTER user_id");
    }

    foreach (['deleted_at', 'deactivated_at', 'restored_at'] as $col) {
        $res = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='funders' AND COLUMN_NAME='$col' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
        if (!$res || $res->num_rows === 0) {
            @$conn->query("ALTER TABLE funders ADD COLUMN $col TIMESTAMP NULL DEFAULT NULL");
        }
    }

    @$conn->query("ALTER TABLE funders ADD INDEX IF NOT EXISTS idx_user_id (user_id)");
    @$conn->query("ALTER TABLE funders ADD INDEX IF NOT EXISTS idx_funder_status (status)");

    // Backfill user_id for funders (by email join)
    @$conn->query("UPDATE funders f JOIN users u ON u.email = f.email SET f.user_id = u.id WHERE f.user_id IS NULL");

    // 1d. Extend audit_log with old_status, new_status, reason columns
    foreach (['old_status', 'new_status'] as $col) {
        $res = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='audit_log' AND COLUMN_NAME='$col' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
        if (!$res || $res->num_rows === 0) {
            @$conn->query("ALTER TABLE audit_log ADD COLUMN $col VARCHAR(30) NULL DEFAULT NULL");
        }
    }

    $res = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='audit_log' AND COLUMN_NAME='reason' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$res || $res->num_rows === 0) {
        @$conn->query("ALTER TABLE audit_log ADD COLUMN reason VARCHAR(500) NULL DEFAULT NULL");
    }

    // Add soft delete columns to funding_calls
    foreach (['deleted_at', 'deleted_by'] as $col) {
        $res = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='funding_calls' AND COLUMN_NAME='$col' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
        if (!$res || $res->num_rows === 0) {
            if ($col === 'deleted_by') {
                @$conn->query("ALTER TABLE funding_calls ADD COLUMN $col VARCHAR(150) NULL DEFAULT NULL");
            } else {
                @$conn->query("ALTER TABLE funding_calls ADD COLUMN $col TIMESTAMP NULL DEFAULT NULL");
            }
        }
    }

    @$conn->query("ALTER TABLE funding_calls ADD INDEX IF NOT EXISTS idx_deleted_at (deleted_at)");
}

?>

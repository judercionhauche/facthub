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
        foreach (['idx_actor' => 'actor_email', 'idx_action' => 'action', 'idx_time' => 'created_at', 'idx_email' => 'target_email'] as $indexName => $col) {
            $idxCheck = @$conn->query("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_NAME='audit_log' AND INDEX_NAME='$indexName' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
            if (!$idxCheck || $idxCheck->num_rows === 0) {
                @$conn->query("ALTER TABLE audit_log ADD INDEX $indexName ($col)");
            }
        }
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
    foreach (['idx_status' => 'status', 'idx_deleted_at' => 'deleted_at', 'idx_session_token' => 'session_token'] as $idxName => $col) {
        $idxCheck = @$conn->query("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_NAME='users' AND INDEX_NAME='$idxName' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
        if (!$idxCheck || $idxCheck->num_rows === 0) {
            @$conn->query("ALTER TABLE users ADD INDEX $idxName ($col)");
        }
    }

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

    foreach (['idx_user_id' => 'user_id', 'idx_researcher_status' => 'status'] as $idxName => $col) {
        $idxCheck = @$conn->query("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_NAME='researchers' AND INDEX_NAME='$idxName' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
        if (!$idxCheck || $idxCheck->num_rows === 0) {
            @$conn->query("ALTER TABLE researchers ADD INDEX $idxName ($col)");
        }
    }

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

    // Add organization profile columns to funders if missing
    foreach (['organization_name' => 'VARCHAR(255)', 'contact_name' => 'VARCHAR(255)', 'department' => 'VARCHAR(255)'] as $col => $type) {
        $res = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='funders' AND COLUMN_NAME='$col' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
        if (!$res || $res->num_rows === 0) {
            @$conn->query("ALTER TABLE funders ADD COLUMN $col $type NULL DEFAULT NULL");
        }
    }

    foreach (['deleted_at', 'deactivated_at', 'restored_at'] as $col) {
        $res = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='funders' AND COLUMN_NAME='$col' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
        if (!$res || $res->num_rows === 0) {
            @$conn->query("ALTER TABLE funders ADD COLUMN $col TIMESTAMP NULL DEFAULT NULL");
        }
    }

    foreach (['idx_user_id' => 'user_id', 'idx_funder_status' => 'status'] as $idxName => $col) {
        $idxCheck = @$conn->query("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_NAME='funders' AND INDEX_NAME='$idxName' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
        if (!$idxCheck || $idxCheck->num_rows === 0) {
            @$conn->query("ALTER TABLE funders ADD INDEX $idxName ($col)");
        }
    }

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

    $idxCheck = @$conn->query("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_NAME='funding_calls' AND INDEX_NAME='idx_deleted_at' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$idxCheck || $idxCheck->num_rows === 0) {
        @$conn->query("ALTER TABLE funding_calls ADD INDEX idx_deleted_at (deleted_at)");
    }

    // Create saved_opportunities table if it doesn't exist
    $result = @$conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME='saved_opportunities' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("
            CREATE TABLE IF NOT EXISTS saved_opportunities (
                id INT AUTO_INCREMENT PRIMARY KEY,
                researcher_email VARCHAR(255) NOT NULL,
                researcher_name VARCHAR(255),
                funding_call_id INT NOT NULL,
                funding_call_title VARCHAR(255),
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_researcher_email (researcher_email),
                INDEX idx_funding_call_id (funding_call_id),
                UNIQUE KEY uq_researcher_funding (researcher_email, funding_call_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // ════════════════════════════════════════════════════════════════
    // AI & Matching System Tables
    // ════════════════════════════════════════════════════════════════

    // match_scores table
    $result = @$conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME='match_scores' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("
            CREATE TABLE IF NOT EXISTS match_scores (
                id INT AUTO_INCREMENT PRIMARY KEY,
                funding_call_id INT NOT NULL,
                researcher_id INT NOT NULL,
                score_ai DECIMAL(5,2) DEFAULT NULL,
                score_keyword INT DEFAULT 0,
                explanation VARCHAR(1000) DEFAULT NULL,
                notified_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_funding (funding_call_id),
                INDEX idx_researcher (researcher_id),
                INDEX idx_notified (notified_at),
                UNIQUE KEY unique_match (funding_call_id, researcher_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // ai_summaries table
    $result = @$conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME='ai_summaries' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("
            CREATE TABLE IF NOT EXISTS ai_summaries (
                id INT AUTO_INCREMENT PRIMARY KEY,
                entity_type VARCHAR(50) NOT NULL,
                entity_id INT NOT NULL,
                summary LONGTEXT NOT NULL,
                model_used VARCHAR(100) DEFAULT NULL,
                prompt_hash VARCHAR(64) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_entity (entity_type, entity_id),
                UNIQUE KEY unique_entity (entity_type, entity_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // email_verifications table
    $result = @$conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME='email_verifications' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("
            CREATE TABLE IF NOT EXISTS email_verifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                token VARCHAR(255) NOT NULL,
                expires_at DATETIME NOT NULL,
                verified_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_email (email),
                INDEX idx_token (token),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // tags table
    $result = @$conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME='tags' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("
            CREATE TABLE IF NOT EXISTS tags (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                tag_type VARCHAR(50) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_name (name),
                INDEX idx_type (tag_type),
                UNIQUE KEY unique_tag (name, tag_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // api_usage table
    $result = @$conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME='api_usage' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("
            CREATE TABLE IF NOT EXISTS api_usage (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                endpoint VARCHAR(255) NOT NULL,
                method VARCHAR(10) NOT NULL,
                tokens_used INT DEFAULT 0,
                cost_usd DECIMAL(10,4) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                INDEX idx_endpoint (endpoint),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // api_balances table
    $result = @$conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME='api_balances' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("
            CREATE TABLE IF NOT EXISTS api_balances (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL UNIQUE,
                balance_usd DECIMAL(10,4) DEFAULT 0,
                status ENUM('active','paused','emergency','suspended') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } else {
        @$conn->query("ALTER TABLE api_balances MODIFY COLUMN status ENUM('active','paused','emergency','suspended') DEFAULT 'active'");
    }

    // balance_alerts table
    $result = @$conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME='balance_alerts' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("
            CREATE TABLE IF NOT EXISTS balance_alerts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                alert_type VARCHAR(50) NOT NULL,
                threshold_usd DECIMAL(10,4) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                dismissed_at TIMESTAMP NULL DEFAULT NULL,
                INDEX idx_user (user_id),
                INDEX idx_type (alert_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // search_logs table
    $result = @$conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME='search_logs' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("
            CREATE TABLE IF NOT EXISTS search_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                search_query VARCHAR(500) NOT NULL,
                filters JSON DEFAULT NULL,
                results_count INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // Ensure job_queue table exists with all necessary fields
    $result = @$conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME='job_queue' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("
            CREATE TABLE IF NOT EXISTS job_queue (
                id INT AUTO_INCREMENT PRIMARY KEY,
                job_type VARCHAR(100) NOT NULL,
                payload JSON DEFAULT NULL,
                status ENUM('pending','running','failed','done') DEFAULT 'pending',
                attempts INT DEFAULT 0,
                max_attempts INT DEFAULT 3,
                locked_by VARCHAR(255) DEFAULT NULL,
                locked_at TIMESTAMP NULL DEFAULT NULL,
                run_after TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                failed_reason VARCHAR(1000) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_run_after (run_after),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // search_sessions table for conversational AI search
    $result = @$conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME='search_sessions' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("
            CREATE TABLE IF NOT EXISTS search_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                session_key VARCHAR(64) NOT NULL,
                user_id INT NOT NULL,
                turns JSON DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_session_key (session_key),
                INDEX idx_user_id (user_id),
                UNIQUE KEY unique_session (session_key, user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

?>

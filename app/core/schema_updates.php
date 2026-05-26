<?php
/**
 * Auto-apply critical schema updates on first run.
 * This ensures all security fixes are in place.
 */

function apply_security_schema_updates(mysqli $conn): void {
    try {
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

    // Add used_at column to password_resets if table exists
    $tblCheck = @$conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME='password_resets' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if ($tblCheck && $tblCheck->num_rows > 0) {
        $result = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='password_resets' AND COLUMN_NAME='used_at' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
        if (!$result || $result->num_rows === 0) {
            @$conn->query("ALTER TABLE password_resets ADD COLUMN used_at DATETIME DEFAULT NULL AFTER expires_at");
            @$conn->query("ALTER TABLE password_resets ADD INDEX idx_used_at (used_at)");
        }
    }

    // Add missing columns to email_verifications table
    $tblCheck = @$conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME='email_verifications' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if ($tblCheck && $tblCheck->num_rows > 0) {
        $emailVerifCols = [
            'verified_at' => 'TIMESTAMP NULL DEFAULT NULL',
            'resend_count' => 'INT DEFAULT 0',
            'last_resent_at' => 'TIMESTAMP NULL DEFAULT NULL',
            'used_at' => 'TIMESTAMP NULL DEFAULT NULL'
        ];
        foreach ($emailVerifCols as $col => $type) {
            $result = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='email_verifications' AND COLUMN_NAME='$col' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
            if (!$result || $result->num_rows === 0) {
                @$conn->query("ALTER TABLE email_verifications ADD COLUMN $col $type");
            }
        }
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

    // Add/fix job_queue worker columns
    $jobQueueCols = [
        'status' => "ENUM('pending','processing','completed','failed') DEFAULT 'pending'",
        'locked_at' => 'TIMESTAMP NULL DEFAULT NULL',
        'locked_by' => 'VARCHAR(255) NULL DEFAULT NULL',
        'attempts' => 'INT DEFAULT 0',
        'max_attempts' => 'INT DEFAULT 3',
        'run_after' => 'TIMESTAMP NULL DEFAULT NULL',
        'last_error' => 'TEXT NULL DEFAULT NULL'
    ];
    foreach ($jobQueueCols as $col => $type) {
        $result = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='job_queue' AND COLUMN_NAME='$col' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
        if (!$result || $result->num_rows === 0) {
            @$conn->query("ALTER TABLE job_queue ADD COLUMN $col $type");
        } elseif ($col === 'status') {
            // Fix status column if it exists but isn't the right ENUM
            @$conn->query("ALTER TABLE job_queue MODIFY COLUMN status ENUM('pending','processing','completed','failed') DEFAULT 'pending'");
        }
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
        @$conn->query("ALTER TABLE users MODIFY COLUMN status ENUM('verified','unverified','active','inactive','deleted','pending_approval') NOT NULL DEFAULT 'active'");
        // Step 2: Migrate 'verified' → 'active'
        @$conn->query("UPDATE users SET status = 'active' WHERE status = 'verified'");
        // Step 3: Remove old 'verified' value
        @$conn->query("ALTER TABLE users MODIFY COLUMN status ENUM('active','inactive','deleted','unverified','pending_approval') NOT NULL DEFAULT 'active'");
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
        @$conn->query("ALTER TABLE researchers ADD COLUMN status ENUM('active','pending_approval','inactive','deleted') NOT NULL DEFAULT 'active' AFTER user_id");
    } else {
        // Update existing enum to include pending_approval if not already present
        @$conn->query("ALTER TABLE researchers MODIFY COLUMN status ENUM('active','pending_approval','inactive','deleted') NOT NULL DEFAULT 'active'");
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

    // Add notify_matches and profile columns to researchers if missing
    $researchersProfileCols = ['notify_matches' => 'TINYINT DEFAULT 1', 'focus_area' => 'VARCHAR(255)', 'focus_area_detail' => 'VARCHAR(1000)', 'co_advising' => 'TINYINT DEFAULT 0', 'co_advising_details' => 'VARCHAR(255)'];
    foreach ($researchersProfileCols as $col => $type) {
        $res = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='researchers' AND COLUMN_NAME='$col' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
        if (!$res || $res->num_rows === 0) {
            @$conn->query("ALTER TABLE researchers ADD COLUMN $col $type DEFAULT NULL");
        }
    }

    // Increase focus_area_detail column size to accommodate longer descriptions
    $res = @$conn->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_NAME='researchers' AND COLUMN_NAME='focus_area_detail' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if ($res && ($row = $res->fetch_assoc())) {
        $currentType = $row['COLUMN_TYPE'];
        if ($currentType !== 'varchar(1000)' && $currentType !== 'text') {
            @$conn->query("ALTER TABLE researchers MODIFY COLUMN focus_area_detail VARCHAR(1000)");
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
        @$conn->query("ALTER TABLE funders ADD COLUMN status ENUM('active','pending_approval','inactive','deleted') NOT NULL DEFAULT 'active' AFTER user_id");
    } else {
        // Update existing enum to include pending_approval if not already present
        @$conn->query("ALTER TABLE funders MODIFY COLUMN status ENUM('active','pending_approval','inactive','deleted') NOT NULL DEFAULT 'active'");
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

    // Ensure funding_calls has all required columns
    $fundingCallsCols = ['funder' => 'VARCHAR(255) DEFAULT NULL', 'deleted_at' => 'TIMESTAMP NULL DEFAULT NULL', 'deleted_by' => 'VARCHAR(150) DEFAULT NULL'];
    foreach ($fundingCallsCols as $col => $type) {
        $res = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='funding_calls' AND COLUMN_NAME='$col' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
        if (!$res || $res->num_rows === 0) {
            $alterSql = "ALTER TABLE funding_calls ADD COLUMN `$col` $type";
            $result = @$conn->query($alterSql);
            if (!$result && $conn->error) {
                error_log("[Schema] Failed to add $col to funding_calls: " . $conn->error);
            }
        }
    }

    // Add FULLTEXT index for search if not present
    $ftCheck = @$conn->query("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_NAME='funding_calls' AND INDEX_NAME='ft_funding_search' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$ftCheck || $ftCheck->num_rows === 0) {
        @$conn->query("ALTER TABLE funding_calls ADD FULLTEXT INDEX ft_funding_search (title, description, topics, geography)");
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
                model_used VARCHAR(100) DEFAULT NULL,
                computed_at TIMESTAMP NULL DEFAULT NULL,
                notified_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_funding (funding_call_id),
                INDEX idx_researcher (researcher_id),
                INDEX idx_notified (notified_at),
                UNIQUE KEY unique_match (funding_call_id, researcher_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } else {
        // Add missing columns if they don't exist
        foreach (['model_used' => 'VARCHAR(100)', 'computed_at' => 'TIMESTAMP NULL'] as $col => $type) {
            $check = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='match_scores' AND COLUMN_NAME='$col' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
            if (!$check || $check->num_rows === 0) {
                @$conn->query("ALTER TABLE match_scores ADD COLUMN $col $type DEFAULT NULL");
            }
        }
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
                token_input INT DEFAULT 0,
                token_output INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_entity (entity_type, entity_id),
                UNIQUE KEY unique_entity (entity_type, entity_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } else {
        // Add missing token columns if they don't exist
        foreach (['token_input' => 'INT DEFAULT 0', 'token_output' => 'INT DEFAULT 0'] as $col => $type) {
            $check = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='ai_summaries' AND COLUMN_NAME='$col' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
            if (!$check || $check->num_rows === 0) {
                @$conn->query("ALTER TABLE ai_summaries ADD COLUMN $col $type");
            }
        }
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
    } else {
        // If tags table exists but is missing tag_type column, add it
        $hasTagType = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='tags' AND COLUMN_NAME='tag_type' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
        if (!$hasTagType || $hasTagType->num_rows === 0) {
            @$conn->query("ALTER TABLE tags ADD COLUMN tag_type VARCHAR(50) NOT NULL DEFAULT 'topic'");
            @$conn->query("ALTER TABLE tags ADD INDEX idx_type (tag_type)");
            // Try to create unique constraint, ignore if it fails (might not be compatible with existing data)
            @$conn->query("ALTER TABLE tags ADD UNIQUE KEY unique_tag (name, tag_type)");
        }
    }

    // api_usage table
    $result = @$conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME='api_usage' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("
            CREATE TABLE IF NOT EXISTS api_usage (
                id INT AUTO_INCREMENT PRIMARY KEY,
                model VARCHAR(100) DEFAULT NULL,
                purpose VARCHAR(100) DEFAULT NULL,
                user_id INT DEFAULT NULL,
                endpoint VARCHAR(255) DEFAULT NULL,
                method VARCHAR(10) DEFAULT NULL,
                token_input INT DEFAULT 0,
                token_output INT DEFAULT 0,
                tokens_used INT DEFAULT 0,
                cost_usd DECIMAL(10,4) DEFAULT 0,
                duration_ms INT DEFAULT 0,
                status VARCHAR(50) DEFAULT NULL,
                error_code VARCHAR(100) DEFAULT NULL,
                triggered_by VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                INDEX idx_endpoint (endpoint),
                INDEX idx_created (created_at),
                INDEX idx_model (model)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } else {
        // Add missing columns if they don't exist
        $cols = ['user_id' => 'INT DEFAULT NULL', 'model' => 'VARCHAR(100) DEFAULT NULL', 'purpose' => 'VARCHAR(100) DEFAULT NULL', 'token_input' => 'INT DEFAULT 0',
                 'token_output' => 'INT DEFAULT 0', 'cost_usd' => 'DECIMAL(10,4) DEFAULT 0', 'duration_ms' => 'INT DEFAULT 0', 'status' => 'VARCHAR(50) DEFAULT NULL',
                 'error_code' => 'VARCHAR(100) DEFAULT NULL', 'triggered_by' => 'VARCHAR(255) DEFAULT NULL', 'endpoint' => 'VARCHAR(255) DEFAULT NULL',
                 'method' => 'VARCHAR(10) DEFAULT NULL', 'tokens_used' => 'INT DEFAULT 0'];
        foreach ($cols as $col => $type) {
            $check = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='api_usage' AND COLUMN_NAME='$col' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
            if (!$check || $check->num_rows === 0) {
                @$conn->query("ALTER TABLE api_usage ADD COLUMN $col $type");
            }
        }
        // Modify columns to ensure they have defaults
        @$conn->query("ALTER TABLE api_usage MODIFY COLUMN user_id INT DEFAULT NULL");
        @$conn->query("ALTER TABLE api_usage MODIFY COLUMN endpoint VARCHAR(255) DEFAULT NULL");
        @$conn->query("ALTER TABLE api_usage MODIFY COLUMN cost_usd DECIMAL(10,4) DEFAULT 0");
    }

    // api_balances table
    $result = @$conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME='api_balances' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("
            CREATE TABLE IF NOT EXISTS api_balances (
                id INT AUTO_INCREMENT PRIMARY KEY,
                provider VARCHAR(50) NOT NULL UNIQUE,
                total_budget DECIMAL(12,2) DEFAULT 0,
                remaining_balance DECIMAL(12,2) DEFAULT 0,
                status ENUM('active','paused','emergency','suspended') DEFAULT 'active',
                last_checked_at TIMESTAMP NULL DEFAULT NULL,
                last_check_error TEXT NULL DEFAULT NULL,
                checked_by VARCHAR(100) DEFAULT 'system',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_provider (provider)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } else {
        @$conn->query("ALTER TABLE api_balances MODIFY COLUMN status ENUM('active','paused','emergency','suspended') DEFAULT 'active'");
        // Add missing columns if they don't exist
        $balancesCols = [
            'provider' => 'VARCHAR(50) NOT NULL UNIQUE',
            'total_budget' => 'DECIMAL(12,2) DEFAULT 0',
            'remaining_balance' => 'DECIMAL(12,2) DEFAULT 0',
            'last_checked_at' => 'TIMESTAMP NULL DEFAULT NULL',
            'last_check_error' => 'TEXT NULL DEFAULT NULL',
            'checked_by' => 'VARCHAR(100) DEFAULT "system"'
        ];
        foreach ($balancesCols as $col => $type) {
            $colCheck = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='api_balances' AND COLUMN_NAME='$col' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
            if (!$colCheck || $colCheck->num_rows === 0) {
                @$conn->query("ALTER TABLE api_balances ADD COLUMN $col $type");
            }
        }
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
                status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
                attempts INT DEFAULT 0,
                max_attempts INT DEFAULT 3,
                locked_by VARCHAR(255) DEFAULT NULL,
                locked_at TIMESTAMP NULL DEFAULT NULL,
                run_after TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_error TEXT DEFAULT NULL,
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
    } else {
        // Ensure turns column has DEFAULT NULL for existing tables
        @$conn->query("ALTER TABLE search_sessions MODIFY COLUMN turns JSON DEFAULT NULL");
    }

    // Add results column to search_sessions if missing
    $result = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='search_sessions' AND COLUMN_NAME='results' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("ALTER TABLE search_sessions ADD COLUMN results JSON DEFAULT NULL");
    }

    // Add funding_call_id and funding_call_title to messages table
    $result = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='messages' AND COLUMN_NAME='funding_call_id' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("ALTER TABLE messages ADD COLUMN funding_call_id INT DEFAULT 0");
        @$conn->query("ALTER TABLE messages ADD COLUMN funding_call_title VARCHAR(255) DEFAULT NULL");
    }

    // Create researcher_publications table for ORCID publications
    $result = @$conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME='researcher_publications' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("
            CREATE TABLE IF NOT EXISTS researcher_publications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                researcher_id INT NOT NULL,
                orcid_id VARCHAR(50) NOT NULL,
                title VARCHAR(500) NOT NULL,
                publication_year INT,
                journal_name VARCHAR(255),
                doi VARCHAR(255),
                url VARCHAR(500),
                citation_count INT DEFAULT 0,
                fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_researcher (researcher_id),
                INDEX idx_orcid (orcid_id),
                INDEX idx_year (publication_year),
                FOREIGN KEY (researcher_id) REFERENCES researchers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // Add notify_frequency column to researchers table
    $result = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='researchers' AND COLUMN_NAME='notify_frequency' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("ALTER TABLE researchers ADD COLUMN notify_frequency ENUM('immediate','weekly','never') NOT NULL DEFAULT 'immediate'");
    }

    // Add notify_threshold column to researchers table for relevance filtering
    $result = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='researchers' AND COLUMN_NAME='notify_threshold' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("ALTER TABLE researchers ADD COLUMN notify_threshold INT NOT NULL DEFAULT 60");
    }

    // Add last_notification_sent_at column to researchers for weekly digest tracking
    $result = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='researchers' AND COLUMN_NAME='last_notification_sent_at' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("ALTER TABLE researchers ADD COLUMN last_notification_sent_at TIMESTAMP NULL DEFAULT NULL");
    }

    // Add quiet hours columns for researcher work-life balance
    foreach (['quiet_hours_start', 'quiet_hours_end'] as $col) {
        $result = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='researchers' AND COLUMN_NAME='$col' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
        if (!$result || $result->num_rows === 0) {
            @$conn->query("ALTER TABLE researchers ADD COLUMN $col TIME NULL DEFAULT NULL");
        }
    }

    // Create notification_queue table for batching weekly digests
    $result = @$conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME='notification_queue' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("
            CREATE TABLE IF NOT EXISTS notification_queue (
                id INT AUTO_INCREMENT PRIMARY KEY,
                researcher_email VARCHAR(255) NOT NULL,
                funding_call_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                sent_at TIMESTAMP NULL DEFAULT NULL,
                INDEX idx_email_sent (researcher_email, sent_at),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // Create password_resets table for password reset tokens
    $result = @$conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME='password_resets' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("
            CREATE TABLE IF NOT EXISTS password_resets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                token VARCHAR(64) NOT NULL UNIQUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NOT NULL,
                used_at TIMESTAMP NULL DEFAULT NULL,
                INDEX idx_email (email),
                INDEX idx_token (token),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // Create trusted_domains table for auto-approval of researchers from trusted institutions
    $result = @$conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME='trusted_domains' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("
            CREATE TABLE IF NOT EXISTS trusted_domains (
                id INT AUTO_INCREMENT PRIMARY KEY,
                domain VARCHAR(255) NOT NULL UNIQUE,
                institution_name VARCHAR(255) NOT NULL,
                country VARCHAR(100),
                tier ENUM('tier1','tier2','tier3') DEFAULT 'tier2',
                auto_approve TINYINT DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                created_by VARCHAR(255),
                INDEX idx_domain (domain),
                INDEX idx_tier (tier)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Seed with major research institutions
        $trustedDomains = [
            ['mit.edu', 'Massachusetts Institute of Technology', 'USA', 'tier1'],
            ['harvard.edu', 'Harvard University', 'USA', 'tier1'],
            ['stanford.edu', 'Stanford University', 'USA', 'tier1'],
            ['caltech.edu', 'California Institute of Technology', 'USA', 'tier1'],
            ['yale.edu', 'Yale University', 'USA', 'tier1'],
            ['princeton.edu', 'Princeton University', 'USA', 'tier1'],
            ['columbia.edu', 'Columbia University', 'USA', 'tier1'],
            ['upenn.edu', 'University of Pennsylvania', 'USA', 'tier1'],
            ['berkeley.edu', 'UC Berkeley', 'USA', 'tier1'],
            ['ucla.edu', 'UCLA', 'USA', 'tier1'],
            ['cornell.edu', 'Cornell University', 'USA', 'tier1'],
            ['northwestern.edu', 'Northwestern University', 'USA', 'tier1'],
            ['duke.edu', 'Duke University', 'USA', 'tier1'],
            ['cmu.edu', 'Carnegie Mellon University', 'USA', 'tier1'],
            ['jhu.edu', 'Johns Hopkins University', 'USA', 'tier1'],
            ['ox.ac.uk', 'University of Oxford', 'UK', 'tier1'],
            ['cam.ac.uk', 'University of Cambridge', 'UK', 'tier1'],
            ['ucl.ac.uk', 'UCL', 'UK', 'tier1'],
            ['imperial.ac.uk', 'Imperial College London', 'UK', 'tier1'],
            ['ethz.ch', 'ETH Zurich', 'Switzerland', 'tier1'],
            ['epfl.ch', 'EPFL', 'Switzerland', 'tier1'],
            ['universite-paris-saclay.fr', 'Université Paris-Saclay', 'France', 'tier2'],
            ['sorbonne-universite.fr', 'Sorbonne Université', 'France', 'tier2'],
            ['edu.sg', 'National University of Singapore', 'Singapore', 'tier2'],
            ['ac.jp', 'University of Tokyo', 'Japan', 'tier2'],
        ];

        foreach ($trustedDomains as [$domain, $name, $country, $tier]) {
            $check = $conn->prepare('SELECT 1 FROM trusted_domains WHERE domain = ? LIMIT 1');
            if ($check) {
                $check->bind_param('s', $domain);
                $check->execute();
                if ($check->get_result()->num_rows === 0) {
                    $insert = $conn->prepare('INSERT INTO trusted_domains (domain, institution_name, country, tier, auto_approve) VALUES (?, ?, ?, ?, 1)');
                    if ($insert) {
                        $insert->bind_param('ssss', $domain, $name, $country, $tier);
                        @$insert->execute();
                    }
                }
            }
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Session Management System — Device Fingerprinting & Remember Me
    // ════════════════════════════════════════════════════════════════

    // Add device fingerprinting columns to users table
    $deviceFingerCols = [
        'session_fingerprint' => 'VARCHAR(64) NULL DEFAULT NULL COMMENT "Device fingerprint from last login"',
        'session_ip' => 'VARCHAR(45) NULL DEFAULT NULL COMMENT "Last known IP address"',
        'session_user_agent' => 'VARCHAR(255) NULL DEFAULT NULL COMMENT "Last known browser/OS"',
        'session_created_at' => 'TIMESTAMP NULL DEFAULT NULL COMMENT "When current session began"'
    ];
    foreach ($deviceFingerCols as $col => $type) {
        $res = @$conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_NAME='users' AND COLUMN_NAME='$col' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
        if (!$res || $res->num_rows === 0) {
            @$conn->query("ALTER TABLE users ADD COLUMN $col $type");
        }
    }

    // Create trusted_devices table for device management
    $result = @$conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME='trusted_devices' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("
            CREATE TABLE IF NOT EXISTS trusted_devices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                device_fingerprint VARCHAR(64) NOT NULL,
                device_name VARCHAR(100) DEFAULT NULL,
                ip_address VARCHAR(45) NOT NULL,
                user_agent VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                is_active TINYINT(1) DEFAULT 1,
                revoked_at TIMESTAMP NULL DEFAULT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_device (user_id, device_fingerprint),
                INDEX idx_user_active (user_id, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // Create session_activity table for anomaly detection
    $result = @$conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME='session_activity' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("
            CREATE TABLE IF NOT EXISTS session_activity (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                action VARCHAR(50) NOT NULL COMMENT 'login|logout|page_view|api_call|suspicious',
                ip_address VARCHAR(45) NOT NULL,
                user_agent VARCHAR(255) NOT NULL,
                device_fingerprint VARCHAR(64) NULL,
                page_or_endpoint VARCHAR(255) NULL,
                suspicious_reason VARCHAR(100) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_action (user_id, action),
                INDEX idx_user_time (user_id, created_at),
                INDEX idx_suspicious (user_id, action, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // Create remember_tokens table for persistent login
    $result = @$conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME='remember_tokens' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        @$conn->query("
            CREATE TABLE IF NOT EXISTS remember_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token VARCHAR(64) NOT NULL UNIQUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NOT NULL,
                used_at TIMESTAMP NULL DEFAULT NULL,
                revoked_at TIMESTAMP NULL DEFAULT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_token (token),
                INDEX idx_user_expires (user_id, expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    } catch (Throwable $e) {
        error_log('[Schema Migration] Error: ' . $e->getMessage());
        // Continue anyway - some tables may not exist yet
    }
}

?>

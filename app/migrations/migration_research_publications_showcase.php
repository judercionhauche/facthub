<?php
/**
 * Migration: Create research projects and publications showcase tables
 * Purpose: Support "Projects in the field" section with research and publications
 * Features: Multi-member teams, optional funding, optional timeline
 */

function migrate_research_publications_showcase($conn) {
    $errors = [];

    // ────────────────────────────────────────────────────────────────
    // 1. Create research_projects table
    // ────────────────────────────────────────────────────────────────
    $sql = "CREATE TABLE IF NOT EXISTS `research_projects` (
        `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `title` VARCHAR(255) NOT NULL,
        `description` LONGTEXT,
        `status` ENUM('active', 'completed', 'paused') DEFAULT 'active',
        `funder_name` VARCHAR(255),
        `grant_amount` DECIMAL(15, 2),
        `grant_id` VARCHAR(100),
        `start_year` INT,
        `end_year` INT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `deleted_at` TIMESTAMP NULL,
        KEY `idx_status` (`status`),
        KEY `idx_deleted` (`deleted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    if (!$conn->query($sql)) {
        $errors[] = "Failed to create research_projects table: " . $conn->error;
    }

    // ────────────────────────────────────────────────────────────────
    // 2. Create research_project_team junction table
    // ────────────────────────────────────────────────────────────────
    $sql = "CREATE TABLE IF NOT EXISTS `research_project_team` (
        `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `research_project_id` INT NOT NULL,
        `researcher_id` INT NOT NULL,
        `display_order` INT DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_project` (`research_project_id`),
        KEY `idx_researcher` (`researcher_id`),
        FOREIGN KEY (`research_project_id`) REFERENCES `research_projects`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`researcher_id`) REFERENCES `researchers`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    if (!$conn->query($sql)) {
        $errors[] = "Failed to create research_project_team table: " . $conn->error;
    }

    // ────────────────────────────────────────────────────────────────
    // 3. Create publications_showcase table
    // ────────────────────────────────────────────────────────────────
    $sql = "CREATE TABLE IF NOT EXISTS `publications_showcase` (
        `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `title` VARCHAR(500) NOT NULL,
        `description` LONGTEXT,
        `url` VARCHAR(500) NOT NULL,
        `publication_year` INT,
        `funder_name` VARCHAR(255),
        `grant_amount` DECIMAL(15, 2),
        `grant_id` VARCHAR(100),
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `deleted_at` TIMESTAMP NULL,
        KEY `idx_year` (`publication_year`),
        KEY `idx_deleted` (`deleted_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    if (!$conn->query($sql)) {
        $errors[] = "Failed to create publications_showcase table: " . $conn->error;
    }

    // ────────────────────────────────────────────────────────────────
    // 4. Create publication_team junction table
    // ────────────────────────────────────────────────────────────────
    $sql = "CREATE TABLE IF NOT EXISTS `publication_team` (
        `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `publication_id` INT NOT NULL,
        `researcher_id` INT NOT NULL,
        `display_order` INT DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_publication` (`publication_id`),
        KEY `idx_researcher` (`researcher_id`),
        FOREIGN KEY (`publication_id`) REFERENCES `publications_showcase`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`researcher_id`) REFERENCES `researchers`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    if (!$conn->query($sql)) {
        $errors[] = "Failed to create publication_team table: " . $conn->error;
    }

    if (empty($errors)) {
        error_log('[Migration] research_publications_showcase completed successfully');
        return true;
    } else {
        foreach ($errors as $err) {
            error_log('[Migration Error] ' . $err);
        }
        return false;
    }
}

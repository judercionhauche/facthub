<?php
/**
 * Migration: Create researcher_orcid_cache table for real-time ORCID data
 * Run: php app/migrations/create_orcid_cache.php
 */

require_once __DIR__ . '/../../config/database.php';

$conn = new mysqli($dbConfig['db_host'], $dbConfig['db_user'], $dbConfig['db_pass'], $dbConfig['db_name']);
if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

$sql = "
CREATE TABLE IF NOT EXISTS researcher_orcid_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    researcher_id INT NOT NULL UNIQUE,
    orcid_id VARCHAR(50) NOT NULL,

    -- Activity metrics
    activity_score FLOAT DEFAULT 0,
    pub_count INT DEFAULT 0,
    affiliation_count INT DEFAULT 0,
    education_count INT DEFAULT 0,
    funding_count INT DEFAULT 0,
    peer_review_count INT DEFAULT 0,

    -- Full data (JSON for flexibility)
    publication_data JSON,
    affiliation_data JSON,
    education_data JSON,
    funding_data JSON,
    peer_review_data JSON,
    keywords JSON,

    -- Metadata
    is_active BOOLEAN DEFAULT FALSE,
    last_synced TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (researcher_id) REFERENCES researchers(id) ON DELETE CASCADE,
    INDEX (activity_score),
    INDEX (is_active),
    INDEX (last_synced)
)
";

if ($conn->query($sql)) {
    echo "✓ researcher_orcid_cache table created\n";
} else {
    echo "✗ Failed: " . $conn->error . "\n";
}

$conn->close();

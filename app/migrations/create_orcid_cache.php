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
    pub_count INT DEFAULT 0,
    publication_data JSON,
    keywords JSON,
    last_synced TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (researcher_id) REFERENCES researchers(id) ON DELETE CASCADE,
    INDEX (last_synced)
)
";

if ($conn->query($sql)) {
    echo "✓ researcher_orcid_cache table created\n";
} else {
    echo "✗ Failed: " . $conn->error . "\n";
}

$conn->close();

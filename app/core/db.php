<?php
/**
 * Database connection setup
 * Called by API endpoints and other standalone PHP files
 */

// Initialize session with proper configuration
require_once __DIR__ . '/session_manager.php';
init_session();

// Load database configuration
$dbConfig = require_once __DIR__ . '/../../config/database.php';

// Create database connection
$conn = new mysqli(
    $dbConfig['db_host'],
    $dbConfig['db_user'],
    $dbConfig['db_pass'],
    $dbConfig['db_name']
);

// Check connection
if ($conn->connect_error) {
    die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
}

// Set character set
$conn->set_charset('utf8mb4');

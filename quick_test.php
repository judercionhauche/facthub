<?php
require_once 'config/database.php';
$dbConfig = require 'config/database.php';
$conn = new mysqli($dbConfig['db_host'], $dbConfig['db_user'], $dbConfig['db_pass'], $dbConfig['db_name']);
if ($conn->connect_error) die("DB Error: " . $conn->connect_error);

// Check if columns exist
$result = $conn->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_NAME='researchers' AND COLUMN_NAME IN ('source', 'referrer_name')");
$cols = [];
while ($row = $result->fetch_assoc()) {
    $cols[] = $row['COLUMN_NAME'];
}

echo "Columns found: " . implode(", ", $cols) . "\n";

if (in_array('source', $cols) && in_array('referrer_name', $cols)) {
    echo "✓ Schema columns exist\n";
    
    // Add 4 test researchers
    $tests = [
        ['Dr. Greg', 'Harrison', 'greg.harrison@mit.edu', 'colleague', 'Dr. Jane Smith'],
        ['Dr. Sarah', 'Chen', 'sarah.chen@stanford.edu', 'organization', 'Stanford Sustainability Office'],
        ['Prof. Ahmed', 'Hassan', 'ahmed.hassan@berkeley.edu', 'conference', null],
        ['Dr. Lisa', 'Rodriguez', 'lisa.rodriguez@uchicago.edu', 'linkedin', null]
    ];
    
    foreach ($tests as list($fn, $ln, $em, $src, $ref)) {
        $inst = 'Test Institution';
        $stmt = $conn->prepare("INSERT INTO researchers (first_name, last_name, email, institution, source, referrer_name, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
        $stmt->bind_param('ssssss', $fn, $ln, $em, $inst, $src, $ref);
        if ($stmt->execute()) {
            echo "✓ Added: $fn $ln (source: $src)\n";
        } else {
            echo "✗ Failed: $fn $ln\n";
        }
    }
    
    // Verify
    echo "\nVerification:\n";
    $verify = $conn->query("SELECT id, first_name, last_name, source, referrer_name FROM researchers WHERE source IS NOT NULL ORDER BY id DESC LIMIT 5");
    while ($row = $verify->fetch_assoc()) {
        echo "  ID {$row['id']}: {$row['first_name']} {$row['last_name']} | Source: {$row['source']} | Referrer: " . ($row['referrer_name'] ?: 'N/A') . "\n";
    }
} else {
    echo "✗ Schema columns missing\n";
}
$conn->close();
?>

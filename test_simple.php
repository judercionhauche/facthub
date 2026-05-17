<?php
header('Content-Type: text/plain');
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "Starting test...\n";

try {
    echo "Loading database...\n";
    require_once 'config/database.php';
    echo "Database loaded\n";

    echo "Testing query...\n";
    $test = $conn->query("SELECT 1");
    if ($test) {
        echo "Query successful\n";
    } else {
        echo "Query failed: " . $conn->error . "\n";
    }

} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}

echo "Test complete\n";
?>

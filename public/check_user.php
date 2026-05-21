<?php
$conn = new mysqli('54.221.189.212', 'fact_user', '5310Judy####', 'fact_hub');

if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

header('Content-Type: text/plain');

$email = 'juderciojosenhauche@gmail.com';
$emailLower = strtolower($email);

echo "=== CHECKING USER: $email ===\n\n";

echo "--- USERS TABLE ---\n";
$uq = $conn->query("SELECT id, email, status, created_at FROM users WHERE LOWER(email) = '$emailLower' LIMIT 1");
if ($uq && $uq->num_rows > 0) {
    $uRow = $uq->fetch_assoc();
    echo "Found in users table:\n";
    echo "  ID: " . $uRow['id'] . "\n";
    echo "  Email: " . $uRow['email'] . "\n";
    echo "  Status: " . $uRow['status'] . "\n";
    echo "  Created: " . $uRow['created_at'] . "\n";
} else {
    echo "NOT FOUND in users table\n";
}

echo "\n--- RESEARCHERS TABLE ---\n";
$rq = $conn->query("SELECT id, email, status, user_id, first_name, last_name FROM researchers WHERE LOWER(email) = '$emailLower' LIMIT 1");
if ($rq && $rq->num_rows > 0) {
    $rRow = $rq->fetch_assoc();
    echo "Found in researchers table:\n";
    echo "  ID: " . $rRow['id'] . "\n";
    echo "  Email: " . $rRow['email'] . "\n";
    echo "  Status: " . $rRow['status'] . "\n";
    echo "  User ID: " . ($rRow['user_id'] ?: 'NULL') . "\n";
    echo "  Name: " . $rRow['first_name'] . " " . $rRow['last_name'] . "\n";
} else {
    echo "NOT FOUND in researchers table\n";
}

echo "\n--- ALL USERS WITH SIMILAR EMAIL ---\n";
$allUsers = $conn->query("SELECT id, email, status FROM users WHERE email LIKE '%judercion%' OR email LIKE '%judy%' LIMIT 10");
if ($allUsers && $allUsers->num_rows > 0) {
    echo "Found " . $allUsers->num_rows . " similar users:\n";
    while ($u = $allUsers->fetch_assoc()) {
        echo "  - " . $u['email'] . " (ID: " . $u['id'] . ", Status: " . $u['status'] . ")\n";
    }
} else {
    echo "No similar users found\n";
}

echo "\n--- ALL RESEARCHERS WITH SIMILAR EMAIL ---\n";
$allResearchers = $conn->query("SELECT id, email, status, user_id FROM researchers WHERE email LIKE '%judercion%' OR email LIKE '%judy%' LIMIT 10");
if ($allResearchers && $allResearchers->num_rows > 0) {
    echo "Found " . $allResearchers->num_rows . " similar researchers:\n";
    while ($r = $allResearchers->fetch_assoc()) {
        echo "  - " . $r['email'] . " (ID: " . $r['id'] . ", Status: " . $r['status'] . ", User ID: " . ($r['user_id'] ?: 'NULL') . ")\n";
    }
} else {
    echo "No similar researchers found\n";
}

$conn->close();
?>

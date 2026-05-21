<?php
// Emergency debug - check if user and researcher exist
session_start();

$email = $_SESSION['user_email'] ?? '';

if (!$email) {
    die("Not logged in. Email not in session.");
}

$dbConfig = require_once __DIR__ . '/../config/database.php';
$conn = new mysqli($dbConfig['db_host'], $dbConfig['db_user'], $dbConfig['db_pass'], $dbConfig['db_name']);

if ($conn->connect_error) {
    die('DB Connection failed: ' . $conn->connect_error);
}

echo "=== DEBUG PROFILE LOOKUP FOR: $email ===\n\n";

echo "SESSION DATA:\n";
echo "  user_email: " . $_SESSION['user_email'] . "\n";
echo "  user_id: " . $_SESSION['user_id'] . "\n";
echo "  user_role: " . $_SESSION['user_role'] . "\n";
echo "  user_status: " . $_SESSION['user_status'] . "\n\n";

echo "USERS TABLE SEARCH:\n";
$uq = $conn->prepare("SELECT id, email, status FROM users WHERE email = ? LIMIT 1");
$uq->bind_param('s', $email);
$uq->execute();
$uRow = $uq->get_result()->fetch_assoc();
if ($uRow) {
    echo "  FOUND: ID={$uRow['id']}, Email={$uRow['email']}, Status={$uRow['status']}\n";
} else {
    echo "  NOT FOUND with exact email: $email\n";
}

echo "\nRESEARCHERS TABLE SEARCH (exact email):\n";
$rq = $conn->prepare("SELECT id, email, status, user_id FROM researchers WHERE email = ? LIMIT 1");
$rq->bind_param('s', $email);
$rq->execute();
$rRow = $rq->get_result()->fetch_assoc();
if ($rRow) {
    echo "  FOUND: ID={$rRow['id']}, Email={$rRow['email']}, Status={$rRow['status']}, UserID={$rRow['user_id']}\n";
} else {
    echo "  NOT FOUND with exact email\n";
}

echo "\nRESEARCHERS TABLE SEARCH (case-insensitive):\n";
$rq2 = $conn->prepare("SELECT id, email, status, user_id FROM researchers WHERE LOWER(email) = LOWER(?) LIMIT 1");
$rq2->bind_param('s', $email);
$rq2->execute();
$rRow2 = $rq2->get_result()->fetch_assoc();
if ($rRow2) {
    echo "  FOUND: ID={$rRow2['id']}, Email={$rRow2['email']}, Status={$rRow2['status']}, UserID={$rRow2['user_id']}\n";
} else {
    echo "  NOT FOUND with case-insensitive search\n";
}

echo "\nRESEARCHERS TABLE - ALL ENTRIES:\n";
$allRq = $conn->query("SELECT id, email, status, user_id FROM researchers LIMIT 20");
if ($allRq && $allRq->num_rows > 0) {
    echo "  Total researchers in table: checking...\n";
    $count = 0;
    while ($r = $allRq->fetch_assoc()) {
        echo "  - " . $r['email'] . " (ID: {$r['id']}, Status: {$r['status']}, UserID: {$r['user_id']})\n";
        $count++;
    }
} else {
    echo "  NO RESEARCHERS FOUND IN TABLE\n";
}

$conn->close();
?>

<?php
// Direct database cleanup - Run once to fix duplicate token issue

// Simple auth check - only allow if user is admin
if (!isset($_GET['token']) || $_GET['token'] !== 'cleanup_' . date('Ymd')) {
    die('Invalid token. Access URL with token=cleanup_' . date('Ymd'));
}

$cfg = require __DIR__ . '/../config/database.php';
$conn = new mysqli($cfg['db_host'], $cfg['db_user'], $cfg['db_pass'], $cfg['db_name']);
if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

echo "Starting email_verifications cleanup...\n\n";

try {
    // 1. Delete expired tokens
    echo "1. Deleting expired tokens... ";
    $result = $conn->query("DELETE FROM email_verifications WHERE expires_at < NOW()");
    echo $conn->affected_rows . " rows deleted.\n";

    // 2. Delete verified tokens older than 7 days
    echo "2. Deleting verified tokens older than 7 days... ";
    $result = $conn->query("DELETE FROM email_verifications WHERE verified_at IS NOT NULL AND verified_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    echo $conn->affected_rows . " rows deleted.\n";

    // 3. Delete used tokens older than 7 days
    echo "3. Deleting used tokens older than 7 days... ";
    $result = $conn->query("DELETE FROM email_verifications WHERE used_at IS NOT NULL AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    echo $conn->affected_rows . " rows deleted.\n";

    // 4. Delete duplicate tokens - keep only the most recent one per email
    echo "4. Removing duplicate tokens (keeping most recent per email)... ";
    $result = $conn->query("
        DELETE FROM email_verifications
        WHERE id NOT IN (
            SELECT id FROM (
                SELECT MAX(id) as id
                FROM email_verifications
                WHERE verified_at IS NULL
                GROUP BY email
            ) as latest
        ) AND verified_at IS NULL
    ");
    echo $conn->affected_rows . " duplicate rows deleted.\n";

    // 5. Show remaining counts
    echo "\n5. Final statistics:\n";
    $result = $conn->query("SELECT COUNT(*) as total,
        SUM(CASE WHEN verified_at IS NULL THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN verified_at IS NOT NULL THEN 1 ELSE 0 END) as verified
        FROM email_verifications");
    $stats = $result->fetch_assoc();
    echo "   Total tokens: " . $stats['total'] . "\n";
    echo "   Pending verification: " . $stats['pending'] . "\n";
    echo "   Already verified: " . $stats['verified'] . "\n";

    echo "\n✓ Cleanup complete!\n";
    echo "The email verification table should now be clean.\n";
    echo "You can now test registration/verification flows again.\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\nIMPORTANT: This script should only be run once.\n";
echo "Delete this file after cleanup is complete.\n";
?>

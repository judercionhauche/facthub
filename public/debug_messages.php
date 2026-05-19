<?php
// DEBUG ENDPOINT - shows message count diagnostics
// Remove this file after debugging

if ($_SERVER['REQUEST_METHOD'] !== 'GET' || empty($_GET['token']) || $_GET['token'] !== 'debug_fact_2026') {
    http_response_code(403);
    exit('Forbidden');
}

$dbConfig = require_once __DIR__ . '/../config/database.php';
$conn = new mysqli($dbConfig['db_host'], $dbConfig['db_user'], $dbConfig['db_pass'], $dbConfig['db_name']);
if ($conn->connect_error) {
    die('DB Error: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

// Allow specifying user email via ?user=email
$user_email = $_GET['user'] ?? 'juderciojosenhauche@gmail.com';

echo "<h2>MESSAGE COUNT DIAGNOSTICS</h2>";
echo "<p>User: <strong>$user_email</strong></p>";

// 1. Query that should appear in inbox (root messages)
echo "<h3>1. Messages in Inbox (root messages only):</h3>";
$inbox_sql = "
    SELECT id, thread_id, sender_email, recipient_email, subject, is_read, is_deleted, created_at
    FROM messages
    WHERE (thread_id = id OR thread_id IS NULL)
      AND sender_email != ?
      AND is_deleted = 0
      AND (recipient_type = 'network' OR recipient_email = ?)
    ORDER BY created_at DESC
";
$stmt = $conn->prepare($inbox_sql);
$stmt->bind_param('ss', $user_email, $user_email);
$stmt->execute();
$result = $stmt->get_result();
$inbox_count = 0;
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Thread ID</th><th>From</th><th>Subject</th><th>Read</th><th>Deleted</th></tr>";
while ($row = $result->fetch_assoc()) {
    $inbox_count++;
    echo "<tr><td>{$row['id']}</td><td>{$row['thread_id']}</td><td>{$row['sender_email']}</td><td>{$row['subject']}</td><td>" . ($row['is_read'] ? 'YES' : 'NO') . "</td><td>" . ($row['is_deleted'] ? 'YES' : 'NO') . "</td></tr>";
}
echo "</table>";
echo "<p><strong>Inbox Total: $inbox_count messages</strong></p>";

// 2. Unread count (inbox items only)
echo "<h3>2. Unread Count (root messages only):</h3>";
$unread_sql = "
    SELECT COUNT(*) as cnt
    FROM messages
    WHERE (thread_id = id OR thread_id IS NULL)
      AND sender_email != ?
      AND is_read = 0
      AND is_deleted = 0
      AND (recipient_type = 'network' OR recipient_email = ?)
";
$stmt = $conn->prepare($unread_sql);
$stmt->bind_param('ss', $user_email, $user_email);
$stmt->execute();
$unread_count = (int)$stmt->get_result()->fetch_assoc()['cnt'];
echo "<p><strong>Unread: $unread_count</strong></p>";

// 3. All unread (including orphans)
echo "<h3>3. ALL Unread Messages (including orphaned replies):</h3>";
$all_unread_sql = "
    SELECT id, thread_id, sender_email, subject, is_read, is_deleted, created_at
    FROM messages
    WHERE sender_email != ?
      AND is_read = 0
      AND is_deleted = 0
      AND (recipient_type = 'network' OR recipient_email = ?)
    ORDER BY thread_id, created_at DESC
";
$stmt = $conn->prepare($all_unread_sql);
$stmt->bind_param('ss', $user_email, $user_email);
$stmt->execute();
$result = $stmt->get_result();
$all_unread_count = 0;
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Thread ID</th><th>From</th><th>Subject</th><th>Is Root?</th></tr>";
while ($row = $result->fetch_assoc()) {
    $all_unread_count++;
    $is_root = ($row['id'] == $row['thread_id'] || $row['thread_id'] === null) ? 'YES' : 'NO';
    echo "<tr><td>{$row['id']}</td><td>{$row['thread_id']}</td><td>{$row['sender_email']}</td><td>{$row['subject']}</td><td>$is_root</td></tr>";
}
echo "</table>";
echo "<p><strong>Total Unread: $all_unread_count (includes orphaned: " . ($all_unread_count - $unread_count) . ")</strong></p>";

// 4. Check for orphaned messages
echo "<h3>4. Orphaned Messages (replies to deleted threads):</h3>";
$orphaned_sql = "
    SELECT m.id, m.thread_id, m.subject
    FROM messages m
    LEFT JOIN messages root ON root.id = m.thread_id
    WHERE m.thread_id IS NOT NULL
      AND m.thread_id != m.id
      AND root.id IS NULL
    LIMIT 10
";
$result = $conn->query($orphaned_sql);
$orphaned_count = $result->num_rows;
echo "<p><strong>Found $orphaned_count orphaned messages</strong></p>";
if ($orphaned_count > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Thread ID</th><th>Subject</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr><td>{$row['id']}</td><td>{$row['thread_id']}</td><td>{$row['subject']}</td></tr>";
    }
    echo "</table>";
}

// 5. ALL MESSAGES SENT TO USER (regardless of filters)
echo "<h3>5. ALL Messages Sent To This User (unfiltered):</h3>";
$all_to_user_sql = "
    SELECT id, thread_id, sender_email, recipient_email, recipient_type, subject, is_read, is_deleted, created_at
    FROM messages
    WHERE recipient_email = ? OR recipient_type = 'network'
    ORDER BY created_at DESC
    LIMIT 50
";
$stmt = $conn->prepare($all_to_user_sql);
$stmt->bind_param('s', $user_email);
$stmt->execute();
$result = $stmt->get_result();
$total_to_user = $result->num_rows;
echo "<p><strong>Total messages for user: $total_to_user</strong></p>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Thread</th><th>From</th><th>To Email</th><th>Type</th><th>Read</th><th>Deleted</th><th>Created</th></tr>";
while ($row = $result->fetch_assoc()) {
    $created = substr($row['created_at'], 0, 16);
    echo "<tr style='background:" . ($row['is_deleted'] ? '#ffcccc' : ($row['is_read'] ? '#f0f0f0' : '#ffffcc')) . "'>";
    echo "<td>{$row['id']}</td>";
    echo "<td>{$row['thread_id']}</td>";
    echo "<td>{$row['sender_email']}</td>";
    echo "<td>" . ($row['recipient_email'] ?: 'NETWORK') . "</td>";
    echo "<td>{$row['recipient_type']}</td>";
    echo "<td>" . ($row['is_read'] ? 'YES' : 'NO') . "</td>";
    echo "<td>" . ($row['is_deleted'] ? 'YES' : 'NO') . "</td>";
    echo "<td>$created</td>";
    echo "</tr>";
}
echo "</table>";

// 6. Check session/user data
echo "<h3>6. Current User Info:</h3>";
echo "<table border='1' cellpadding='5'>";
if (session_status() === PHP_SESSION_ACTIVE) {
    echo "<tr><td>Session User Email</td><td>" . ($_SESSION['user_email'] ?? 'NOT SET') . "</td></tr>";
    echo "<tr><td>Session User ID</td><td>" . ($_SESSION['user_id'] ?? 'NOT SET') . "</td></tr>";
} else {
    echo "<tr><td colspan='2'>Session not active</td></tr>";
}
$user_check = $conn->prepare("SELECT email, status FROM users WHERE email = ?");
$user_check->bind_param('s', $user_email);
$user_check->execute();
$user_data = $user_check->get_result()->fetch_assoc();
if ($user_data) {
    echo "<tr><td>DB Email</td><td>" . $user_data['email'] . "</td></tr>";
    echo "<tr><td>DB Status</td><td>" . $user_data['status'] . "</td></tr>";
} else {
    echo "<tr><td colspan='2'>User not found in database</td></tr>";
}
echo "</table>";

$conn->close();
?>
<hr>
<p><small>Access: /debug_messages.php?token=debug_fact_2026</small></p>

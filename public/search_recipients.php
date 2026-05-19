<?php
/**
 * AJAX endpoint for searching message recipients by name/email.
 * GET /fact_hub2/public/search_recipients.php?q=QUERY
 * Returns JSON: [{email, name, role}, ...]
 */

require_once __DIR__ . '/../app/core/session_manager.php';
require_once __DIR__ . '/../app/core/helpers.php';
require_once __DIR__ . '/../app/services/RateLimiter.php';

// Initialize session before authentication checks
init_session();

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Initialize database connection
$dbConfig = require_once __DIR__ . '/../config/database.php';
$conn = new mysqli($dbConfig['db_host'], $dbConfig['db_user'], $dbConfig['db_pass'], $dbConfig['db_name']);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}
$conn->set_charset('utf8mb4');

$user = current_user();

// Rate limiting: 60 searches per hour per user
$rateLimiter = new RateLimiter($conn);
$userId = (int)$user['id'];
if (!$rateLimiter->check('search_recipients_' . $userId, 60, 3600)) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limited']);
    exit;
}

$query = trim($_GET['q'] ?? '');

// Require minimum 1 character search (prevent dumping all users)
if (empty($query) || mb_strlen($query) < 1) {
    echo json_encode(['results' => []]);
    exit;
}

// Search in name and email (case-insensitive, partial match)
$searchTerm = '%' . str_replace('%', '\\%', $query) . '%';
$stmt = $conn->prepare(
    "SELECT email, name, role FROM users
     WHERE status = 'active'
     AND deleted_at IS NULL
     AND email != ?
     AND (name LIKE ? OR email LIKE ?)
     ORDER BY
       CASE role
         WHEN 'admin' THEN 1
         WHEN 'researcher' THEN 2
         WHEN 'funder' THEN 3
         ELSE 4
       END, name ASC
     LIMIT 50"
);

$stmt->bind_param('sss', $user['email'], $searchTerm, $searchTerm);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Format response
$formatted = array_map(function($r) {
    return [
        'email' => $r['email'],
        'name' => $r['name'] ?: $r['email'],
        'display' => ($r['name'] ?: $r['email'])
    ];
}, $results);

echo json_encode(['results' => $formatted]);
?>

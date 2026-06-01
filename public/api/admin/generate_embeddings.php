<?php
/**
 * Admin endpoint to batch generate embeddings for semantic search
 * GET /public/api/admin/generate_embeddings.php
 * Query params: limit=100, offset=0
 */

$dbConfig = require_once __DIR__ . '/../../../config/database.php';
$conn = new mysqli($dbConfig['db_host'], $dbConfig['db_user'], $dbConfig['db_pass'], $dbConfig['db_name']);
if ($conn->connect_error) {
    http_response_code(500);
    exit(json_encode(['error' => 'Database connection failed']));
}
$conn->set_charset('utf8mb4');

require_once __DIR__ . '/../../../app/core/session_manager.php';
require_once __DIR__ . '/../../../app/core/helpers.php';
require_once __DIR__ . '/../../../app/services/ClaudeService.php';
require_once __DIR__ . '/../../../app/services/EmbeddingService.php';

init_session();

// Restrict to admin
if (!is_admin()) {
    http_response_code(403);
    exit(json_encode(['error' => 'Admin access required']));
}

$limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
$offset = max(0, (int)($_GET['offset'] ?? 0));
$action = $_GET['action'] ?? 'researchers';

header('Content-Type: application/json; charset=utf-8');

$claudeService = new ClaudeService($conn, $_SESSION['email'] ?? 'admin');
$embeddingService = new EmbeddingService($conn, $claudeService);

if ($action === 'researchers') {
    // Batch generate researcher embeddings
    $stmt = $conn->prepare(
        "SELECT id FROM researchers WHERE deleted_at IS NULL ORDER BY id ASC LIMIT ? OFFSET ?"
    );
    $stmt->bind_param('ii', $limit, $offset);
    $stmt->execute();
    $researchers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];

    $success = 0;
    $failed = 0;
    $errors = [];

    foreach ($researchers as $r) {
        if ($embeddingService->generateResearcherEmbedding($r['id'], 'profile')) {
            $success++;
        } else {
            $failed++;
            $errors[] = "Researcher {$r['id']}";
        }
        // Rate limit: small delay between API calls
        usleep(100000);
    }

    http_response_code(200);
    exit(json_encode([
        'status' => 'ok',
        'action' => 'researchers',
        'limit' => $limit,
        'offset' => $offset,
        'total_processed' => $success + $failed,
        'success' => $success,
        'failed' => $failed,
        'errors' => array_slice($errors, 0, 10),
        'next_offset' => $offset + $limit,
        'message' => "$success researchers embedded, $failed failed"
    ]));

} elseif ($action === 'funding_calls') {
    // Batch generate funding call embeddings
    $stmt = $conn->prepare(
        "SELECT id FROM funding_calls WHERE deleted_at IS NULL ORDER BY id ASC LIMIT ? OFFSET ?"
    );
    $stmt->bind_param('ii', $limit, $offset);
    $stmt->execute();
    $calls = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];

    $success = 0;
    $failed = 0;
    $errors = [];

    foreach ($calls as $c) {
        if ($embeddingService->generateFundingCallEmbedding($c['id'], 'full')) {
            $success++;
        } else {
            $failed++;
            $errors[] = "Funding call {$c['id']}";
        }
        usleep(100000);
    }

    http_response_code(200);
    exit(json_encode([
        'status' => 'ok',
        'action' => 'funding_calls',
        'limit' => $limit,
        'offset' => $offset,
        'total_processed' => $success + $failed,
        'success' => $success,
        'failed' => $failed,
        'errors' => array_slice($errors, 0, 10),
        'next_offset' => $offset + $limit,
        'message' => "$success funding calls embedded, $failed failed"
    ]));

} elseif ($action === 'status') {
    // Get embedding generation status
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM researchers WHERE deleted_at IS NULL");
    $stmt->execute();
    $totalResearchers = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);

    $stmt = $conn->prepare("SELECT COUNT(*) c FROM researcher_embeddings WHERE embedding_type = 'profile'");
    $stmt->execute();
    $embeddedResearchers = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);

    $stmt = $conn->prepare("SELECT COUNT(*) c FROM funding_calls WHERE deleted_at IS NULL");
    $stmt->execute();
    $totalFundingCalls = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);

    $stmt = $conn->prepare("SELECT COUNT(*) c FROM funding_call_embeddings WHERE embedding_type = 'full'");
    $stmt->execute();
    $embeddedCalls = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);

    http_response_code(200);
    exit(json_encode([
        'status' => 'ok',
        'researchers' => [
            'total' => $totalResearchers,
            'embedded' => $embeddedResearchers,
            'percentage' => $totalResearchers > 0 ? round(($embeddedResearchers / $totalResearchers) * 100, 1) : 0
        ],
        'funding_calls' => [
            'total' => $totalFundingCalls,
            'embedded' => $embeddedCalls,
            'percentage' => $totalFundingCalls > 0 ? round(($embeddedCalls / $totalFundingCalls) * 100, 1) : 0
        ]
    ]));

} else {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid action. Use: researchers, funding_calls, or status']));
}
?>

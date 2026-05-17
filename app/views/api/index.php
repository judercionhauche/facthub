<?php
/**
 * JSON API layer for AI features.
 * Routes via $_GET['action'].
 * All responses are pure JSON (layout bypassed in public/index.php).
 *
 * $conn, helpers, mailer are available from index.php includes.
 */

require_once __DIR__ . '/../../services/ClaudeService.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$user   = current_user();

function api_error(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function api_ok(array $data): void {
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

switch ($action) {

    // GET ?page=api&action=match_scores&funding_call_id=X
    // Returns cached AI scores for all researchers for a given funding call
    case 'match_scores':
        $fcId = (int)($_GET['funding_call_id'] ?? 0);
        if (!$fcId) api_error('funding_call_id required');

        $stmt = $conn->prepare(
            'SELECT ms.researcher_id, ms.score_keyword, ms.score_ai, ms.explanation,
                    CONCAT(r.first_name," ",r.last_name) AS name, r.institution, r.email
             FROM match_scores ms
             JOIN researchers r ON r.id = ms.researcher_id
             WHERE ms.funding_call_id = ? AND r.status = 'active' AND r.deleted_at IS NULL
             ORDER BY COALESCE(ms.score_ai, ms.score_keyword) DESC'
        );
        $stmt->bind_param('i', $fcId); $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        api_ok(['scores' => $rows, 'funding_call_id' => $fcId]);
        break;

    // GET ?page=api&action=funding_matches&researcher_id=X
    // Returns top funding calls for a researcher (AI-scored if available)
    case 'funding_matches':
        $rid = (int)($_GET['researcher_id'] ?? 0);
        if (!$rid) api_error('researcher_id required');

        $stmt = $conn->prepare(
            'SELECT ms.funding_call_id, ms.score_keyword, ms.score_ai, ms.explanation,
                    fc.title, fc.funder, fc.deadline, fc.status, fc.amount
             FROM match_scores ms
             JOIN funding_calls fc ON fc.id = ms.funding_call_id
             WHERE ms.researcher_id = ? AND fc.deleted_at IS NULL
             ORDER BY COALESCE(ms.score_ai, ms.score_keyword) DESC
             LIMIT 20'
        );
        $stmt->bind_param('i', $rid); $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        api_ok(['matches' => $rows, 'researcher_id' => $rid]);
        break;

    // GET ?page=api&action=summary&entity_type=researcher&entity_id=X
    // Returns cached AI summary; queues generation job if not cached
    case 'summary':
        $entityType = in_array($_GET['entity_type'] ?? '', ['researcher','funding_call'])
                      ? $_GET['entity_type'] : null;
        $entityId   = (int)($_GET['entity_id'] ?? 0);
        if (!$entityType || !$entityId) api_error('entity_type and entity_id required');

        $stmt = $conn->prepare(
            'SELECT summary, model_used, created_at FROM ai_summaries
             WHERE entity_type = ? AND entity_id = ? LIMIT 1'
        );
        $stmt->bind_param('si', $entityType, $entityId); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if ($row) {
            api_ok(['summary' => $row['summary'], 'cached' => true,
                    'model' => $row['model_used'], 'generated_at' => $row['created_at']]);
        } else {
            // Queue background generation — return pending status
            $jobId = enqueue_job($conn, 'generate_summary', [
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
            ]);
            api_ok(['summary' => null, 'cached' => false, 'job_id' => $jobId,
                    'message' => 'Summary is being generated. Poll this endpoint again in 30 seconds.']);
        }
        break;

    // GET ?page=api&action=search&q=food+security+africa
    // Parses natural language query into structured filters
    case 'search':
        $q = trim($_GET['q'] ?? '');
        if ($q === '') api_error('q parameter required');
        if (mb_strlen($q) > 500) api_error('query too long (max 500 chars)');

        $claude   = new ClaudeService($conn, 'api:search:' . $user['email']);
        $parsed   = $claude->parseSearchQuery($q);

        if ($parsed === null) {
            // Claude failed — return the raw query so the caller can do plain text search
            api_ok(['parsed' => null, 'fallback' => true, 'raw_query' => $q]);
        } else {
            api_ok(['parsed' => $parsed, 'fallback' => false, 'raw_query' => $q]);
        }
        break;

    default:
        api_error('Unknown action. Valid actions: match_scores, funding_matches, summary, search', 400);
}
?>

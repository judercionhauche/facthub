<?php
/**
 * AJAX endpoint for real-time streaming conversational search.
 * Streams results via Server-Sent Events (SSE) for ChatGPT-like UX.
 * POST /fact_hub2/chat_search.php
 * Body: {q, session_key, filter_type, filter_status}
 * Response: SSE stream with events: results, token*, done|error
 */

// Guard: POST only (before any output)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Initialize session BEFORE sending any headers
$dbConfig = require_once __DIR__ . '/../config/database.php';
$conn = new mysqli($dbConfig['db_host'], $dbConfig['db_user'], $dbConfig['db_pass'], $dbConfig['db_name']);
if ($conn->connect_error) {
    die('Database connection failed');
}
$conn->set_charset('utf8mb4');

require_once __DIR__ . '/../app/core/session_manager.php';
require_once __DIR__ . '/../app/core/helpers.php';
require_once __DIR__ . '/../app/services/ClaudeService.php';

init_session();

// NOW send headers & buffering (after session init, before auth check)
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
@ob_end_clean();
ob_implicit_flush(true);
set_time_limit(90);

function sseEvent(array $data): void {
    echo 'data: ' . json_encode($data) . "\n\n";
    if (ob_get_level() > 0) ob_flush();
    flush();
}

// Debug logging
error_log('[chat_search] Session status: ' . session_status());
error_log('[chat_search] Session ID: ' . (session_id() ?: 'NONE'));
error_log('[chat_search] $_SESSION keys: ' . implode(',', array_keys($_SESSION)));
error_log('[chat_search] user_id: ' . ($_SESSION['user_id'] ?? 'NOT SET'));
error_log('[chat_search] is_user_logged_in(): ' . (is_user_logged_in() ? 'TRUE' : 'FALSE'));

// Session + CSRF check
if (!is_user_logged_in()) {
    error_log('[chat_search] Authorization failed - user not logged in');
    sseEvent(['t' => 'error', 'msg' => 'Unauthorized']);
    exit;
}

if (!is_csrf_valid()) {
    sseEvent(['t' => 'error', 'msg' => 'CSRF token invalid']);
    exit;
}

$user = current_user();

// ── Parse & validate request ──
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    sseEvent(['t' => 'error', 'msg' => 'Invalid JSON']);
    exit;
}

$q = trim(mb_substr($body['q'] ?? '', 0, 300));
$sessionKey = preg_replace('/[^a-f0-9]/', '', $body['session_key'] ?? '');
$filterType = in_array($body['filter_type'] ?? '', ['funding', 'researcher', 'funder', 'institution']) ? $body['filter_type'] : '';
$filterStatus = in_array($body['filter_status'] ?? '', ['open', 'rolling', 'upcoming', 'closed']) ? $body['filter_status'] : '';

if (empty($q)) {
    sseEvent(['t' => 'error', 'msg' => 'Query required']);
    exit;
}
if (!$sessionKey || mb_strlen($sessionKey) !== 32) {
    sseEvent(['t' => 'error', 'msg' => 'Invalid session_key']);
    exit;
}

// ── Load or create session ──
$userId = (int)$user['id'];
$stmt = $conn->prepare('SELECT id, turns FROM search_sessions WHERE session_key = ? AND user_id = ? LIMIT 1');
$stmt->bind_param('si', $sessionKey, $userId);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$history = [];
if ($session) {
    $history = json_decode($session['turns'], true) ?? [];
} else {
    $stmt = $conn->prepare('INSERT INTO search_sessions (session_key, user_id) VALUES (?, ?)');
    $stmt->bind_param('si', $sessionKey, $userId);
    $stmt->execute();
}

// Keep only last 3 turns for Claude context
$historyForClaude = array_slice(array_map(function($t) {
    return ['user' => $t['user'] ?? '', 'assistant' => $t['assistant'] ?? ''];
}, $history), -3);

// ── Search logic (same as chat_search_batch.php) ──
$SYNONYMS = [
    'agriculture' => ['farming', 'food security', 'agronomy', 'crops', 'livestock'],
    'food security' => ['agriculture', 'nutrition', 'food systems', 'food access', 'hunger'],
    'food systems' => ['food security', 'agriculture', 'nutrition', 'supply chain'],
    'nutrition' => ['food security', 'malnutrition', 'diet', 'micronutrients', 'stunting'],
    'water' => ['water security', 'wash', 'sanitation', 'hydrology', 'irrigation'],
    'climate' => ['climate change', 'climate adaptation', 'climate resilience', 'environment'],
    'climate change' => ['climate', 'environment', 'adaptation', 'mitigation', 'emissions'],
    'health' => ['public health', 'global health', 'disease', 'healthcare', 'wellbeing'],
    'malaria' => ['infectious disease', 'tropical disease', 'health', 'vector control'],
    'hiv' => ['hiv/aids', 'aids', 'infectious disease', 'health'],
    'education' => ['learning', 'literacy', 'schools', 'training', 'capacity building'],
    'gender' => ['women', 'girls', 'gender equality', 'women empowerment', 'inclusion'],
    'women' => ['gender', 'girls', 'women empowerment', 'gender equality'],
    'environment' => ['climate', 'biodiversity', 'conservation', 'ecology', 'sustainability'],
    'biodiversity' => ['environment', 'conservation', 'ecosystems', 'species'],
    'energy' => ['renewable energy', 'solar', 'clean energy', 'electrification'],
    'innovation' => ['technology', 'digital', 'research', 'ict', 'entrepreneurship'],
    'governance' => ['policy', 'institutions', 'rule of law', 'accountability'],
    'poverty' => ['economic development', 'livelihoods', 'income', 'inequality'],
    'economic' => ['economy', 'livelihoods', 'poverty', 'growth', 'development'],
    'resilience' => ['adaptation', 'climate resilience', 'food security', 'sustainability'],
    'africa' => ['sub-saharan africa', 'east africa', 'west africa', 'southern africa'],
    'sub-saharan africa' => ['africa', 'east africa', 'west africa', 'southern africa'],
    'east africa' => ['africa', 'kenya', 'tanzania', 'uganda', 'ethiopia', 'rwanda'],
    'west africa' => ['africa', 'nigeria', 'ghana', 'senegal', 'mali', 'cameroon'],
    'southern africa' => ['africa', 'mozambique', 'south africa', 'zimbabwe', 'zambia', 'malawi'],
    'americas' => ['latin america', 'caribbean', 'south america', 'central america'],
    'latin america' => ['americas', 'south america', 'central america', 'caribbean'],
    'asia' => ['south asia', 'southeast asia', 'east asia', 'india', 'bangladesh'],
];

$KNOWN_TERMS = [
    'agriculture', 'food security', 'climate', 'water', 'health', 'education', 'malaria',
    'nutrition', 'environment', 'energy', 'gender', 'poverty', 'economic', 'governance',
    'innovation', 'resilience', 'biodiversity', 'sanitation', 'irrigation', 'livestock',
    'africa', 'kenya', 'nigeria', 'ghana', 'ethiopia', 'mozambique', 'tanzania', 'uganda',
    'senegal', 'mali', 'cameroon', 'malawi', 'zambia', 'zimbabwe', 'rwanda', 'south africa',
    'india', 'bangladesh', 'pakistan', 'indonesia', 'philippines',
    'americas', 'asia', 'europe',
];

function correctTypo(string $word, array $known): string {
    if (mb_strlen($word) < 4) return $word;
    $word_l = strtolower($word);
    $best = $word_l;
    $bestDist = 3;
    foreach ($known as $term) {
        $d = levenshtein($word_l, $term);
        if ($d > 0 && $d < $bestDist) { $bestDist = $d; $best = $term; }
    }
    return $best;
}

function fetchCandidates(mysqli $conn, string $table, array $allTerms, string $statusFilter, string $ftField): array {
    if (empty($allTerms)) return [];
    $ftQuery = implode(' ', array_filter(array_map(fn($t) => preg_replace('/[^a-zA-Z0-9\-]/', ' ', $t), $allTerms)));
    $ftQuery = trim(preg_replace('/\s+/', ' ', $ftQuery));
    $results = [];

    // Try FULLTEXT first, fall back to LIKE if it fails
    if ($ftQuery) {
        try {
            $sql = "SELECT *, MATCH({$ftField}) AGAINST (? IN NATURAL LANGUAGE MODE) AS ft_relevance FROM {$table} WHERE MATCH({$ftField}) AGAINST (? IN NATURAL LANGUAGE MODE)";
            $params = [$ftQuery, $ftQuery];
            $types = 'ss';
            if ($statusFilter) { $sql .= ' AND status = ?'; $params[] = $statusFilter; $types .= 's'; }
            $sql .= ' LIMIT 60';
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            }
        } catch (Exception $e) {
            // FULLTEXT index not available, will fall back to LIKE below
            $results = [];
        }
    }

    // Fallback to LIKE search if FULLTEXT failed or returned nothing
    if (empty($results)) {
        try {
            $orClauses = []; $params = []; $types = '';
            $cols = explode(',', $ftField);
            foreach ($allTerms as $term) {
                $sub = [];
                foreach ($cols as $col) { $sub[] = trim($col) . ' LIKE ?'; $params[] = '%' . $term . '%'; $types .= 's'; }
                $orClauses[] = '(' . implode(' OR ', $sub) . ')';
            }
            $sql = "SELECT *, 0.0 AS ft_relevance FROM {$table} WHERE " . implode(' OR ', $orClauses);
            if ($statusFilter) { $sql .= ' AND status = ?'; $params[] = $statusFilter; $types .= 's'; }
            $sql .= ' LIMIT 60';
            $stmt = $conn->prepare($sql);
            if ($stmt && !empty($params)) { $stmt->bind_param($types, ...$params); $stmt->execute(); $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); }
        } catch (Exception $e) {
            // If LIKE search also fails, return empty results instead of crashing
            error_log("[fetchCandidates] LIKE fallback failed for {$table}: " . $e->getMessage());
            $results = [];
        }
    }
    return $results;
}

function scoreFC(array $fc, array $topicFilters, array $geoFilters, array $keywords, array $expandedTopics, array $expandedGeos, array $synonyms): float {
    $score = (float)($fc['ft_relevance'] ?? 0) * 5;
    $title = strtolower($fc['title'] ?? '');
    $body = strtolower(($fc['description'] ?? '') . ' ' . ($fc['funder'] ?? ''));
    $tags = parse_tags($fc['topics'] ?? '');
    $geos = parse_tags($fc['geography'] ?? '');
    foreach ($keywords as $kw) { $kw = strtolower($kw); if (strpos($title, $kw) !== false) $score += 3; elseif (strpos($body, $kw) !== false) $score += 1; }
    foreach ($topicFilters as $t) { if (in_array($t, $tags, true)) $score += 4; }
    foreach ($geoFilters as $g) { if (in_array($g, $geos, true)) $score += 3; }
    foreach ($expandedTopics as $t) { if (in_array($t, $tags, true)) $score += 1; }
    foreach ($expandedGeos as $g) { if (in_array($g, $geos, true)) $score += 0.5; }
    foreach ($synonyms as $syn) { if (in_array($syn, $tags, true) || strpos($title, $syn) !== false) $score += 0.5; }
    if (($fc['status'] ?? '') === 'open') $score += 2;
    if (($fc['status'] ?? '') === 'rolling') $score += 1;
    if (!empty($fc['updated_at']) && strtotime($fc['updated_at']) > strtotime('-30 days')) $score += 0.5;
    return $score;
}

function scoreResearcher(array $r, array $topicFilters, array $geoFilters, array $keywords, array $expandedTopics, array $expandedGeos, array $synonyms): float {
    $score = (float)($r['ft_relevance'] ?? 0) * 5;
    $name = strtolower(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
    $body = strtolower(($r['bio'] ?? '') . ' ' . ($r['institution'] ?? '') . ' ' . ($r['title'] ?? ''));
    $tags = parse_tags($r['topics'] ?? '');
    $geos = parse_tags($r['geography'] ?? '');
    foreach ($keywords as $kw) { $kw = strtolower($kw); if (strpos($name, $kw) !== false) $score += 3; elseif (strpos($body, $kw) !== false) $score += 1; }
    foreach ($topicFilters as $t) { if (in_array($t, $tags, true)) $score += 4; }
    foreach ($geoFilters as $g) { if (in_array($g, $geos, true)) $score += 3; }
    foreach ($expandedTopics as $t) { if (in_array($t, $tags, true)) $score += 1; }
    foreach ($expandedGeos as $g) { if (in_array($g, $geos, true)) $score += 0.5; }
    foreach ($synonyms as $syn) { if (in_array($syn, $tags, true) || strpos($name, $syn) !== false) $score += 0.5; }
    return $score;
}

function scoreFunder(array $f, array $topicFilters, array $geoFilters, array $keywords, array $expandedTopics, array $expandedGeos, array $synonyms): float {
    $score = (float)($f['ft_relevance'] ?? 0) * 5;
    $name = strtolower(($f['first_name'] ?? '') . ' ' . ($f['last_name'] ?? '') . ' ' . ($f['organization'] ?? ''));
    $body = strtolower(($f['bio'] ?? '') . ' ' . ($f['org_type'] ?? ''));
    $tags = parse_tags($f['topics'] ?? '');
    $geos = parse_tags($f['geography'] ?? '');
    foreach ($keywords as $kw) { $kw = strtolower($kw); if (strpos($name, $kw) !== false) $score += 3; elseif (strpos($body, $kw) !== false) $score += 1; }
    foreach ($topicFilters as $t) { if (in_array($t, $tags, true)) $score += 4; }
    foreach ($geoFilters as $g) { if (in_array($g, $geos, true)) $score += 3; }
    foreach ($expandedTopics as $t) { if (in_array($t, $tags, true)) $score += 1; }
    foreach ($expandedGeos as $g) { if (in_array($g, $geos, true)) $score += 0.5; }
    foreach ($synonyms as $syn) { if (in_array($syn, $tags, true) || strpos($name, $syn) !== false) $score += 0.5; }
    return $score;
}

function stripMarkdown(string $text): string {
    $text = preg_replace('/\*\*(.*?)\*\*/u', '$1', $text);
    $text = preg_replace('/\*(.*?)\*/u', '$1', $text);
    $text = preg_replace('/__(.*?)__/u', '$1', $text);
    $text = preg_replace('/_(.*?)_/u', '$1', $text);
    $text = preg_replace('/^#{1,6}\s+/mu', '', $text);
    $text = preg_replace('/\[([^\]]+)\]\([^\)]+\)/u', '$1', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

function getEntityUrl(string $entityType, int|string $entityId): string {
    $entityType = strtolower(trim($entityType));
    switch ($entityType) {
        case 'researcher':
            return 'index.php?page=researchers&view=' . (int)$entityId;
        case 'funding_call':
            return 'index.php?page=funding&view=' . (int)$entityId;
        case 'funder':
            return 'index.php?page=funders&id=' . (int)$entityId;
        case 'institution':
            return 'index.php?page=institutions&search=' . urlencode((string)$entityId);
        default:
            return '#';
    }
}

// Step 1: Typo correction
$rawWords = preg_split('/\s+/', $q);
$correctedWords = array_map(fn($w) => correctTypo($w, $KNOWN_TERMS), $rawWords);
$corrected = implode(' ', $correctedWords);

// Step 2: Claude parse
$claude = new ClaudeService($conn, 'search:' . $user['email']);
$parsed = $claude->parseSearchQuery($corrected ?: $q);
$fallback = !$parsed;

if (!$parsed) {
    $parsed = [
        'topics' => $correctedWords,
        'geographies' => [],
        'keywords' => [],
        'intent' => 'unknown',
        'synonyms' => [],
    ];
}

$topicFilters = array_map('strtolower', $parsed['topics'] ?? []);
$geoFilters = array_map('strtolower', $parsed['geographies'] ?? []);
$keywords = $parsed['keywords'] ?? ($fallback ? $correctedWords : []);
$synonyms = array_map('strtolower', $parsed['synonyms'] ?? []);

// Step 3: Synonym expansion
$expandedTopics = $topicFilters;
$expandedGeos = $geoFilters;
foreach ($topicFilters as $t) { if (isset($SYNONYMS[$t])) { $expandedTopics = array_unique(array_merge($expandedTopics, array_map('strtolower', $SYNONYMS[$t]))); } }
foreach ($geoFilters as $g) { if (isset($SYNONYMS[$g])) { $expandedGeos = array_unique(array_merge($expandedGeos, array_map('strtolower', $SYNONYMS[$g]))); } }
$allSearchTerms = array_unique(array_merge($expandedTopics, $expandedGeos, $keywords, $synonyms));

// Step 4: Fetch candidates
$fcCandidates = ($filterType !== 'researcher' && $filterType !== 'institution' && !empty($allSearchTerms)) ? fetchCandidates($conn, 'funding_calls', $allSearchTerms, $filterStatus, 'title,description,topics,geography') : [];
$rCandidates = ($filterType !== 'funding' && $filterType !== 'institution' && !empty($allSearchTerms)) ? fetchCandidates($conn, 'researchers', $allSearchTerms, '', 'first_name,last_name,institution,bio,topics,geography') : [];
$funderCandidates = ($filterType !== 'researcher' && $filterType !== 'funding' && !empty($allSearchTerms)) ? fetchCandidates($conn, 'funders', $allSearchTerms, '', 'first_name,last_name,organization,bio,topics,geography') : [];

// Institution grouping: GROUP BY institution + aggregate metadata
$institutionCandidates = [];
if (($filterType === '' || $filterType === 'institution') && !empty($allSearchTerms)) {
    $likeTerms = array_slice($allSearchTerms, 0, 5);
    $orParts = []; $params = []; $types = '';
    foreach ($likeTerms as $t) {
        $orParts[] = 'institution LIKE ?';
        $params[] = '%' . $t . '%';
        $types .= 's';
    }
    if ($orParts) {
        $sql = "SELECT institution, COUNT(*) as researcher_count,
                       GROUP_CONCAT(DISTINCT topics ORDER BY topics SEPARATOR ', ') as all_topics,
                       GROUP_CONCAT(DISTINCT geography ORDER BY geography SEPARATOR ', ') as all_geos
                FROM researchers WHERE " . implode(' OR ', $orParts) . "
                GROUP BY institution ORDER BY researcher_count DESC LIMIT 5";
        $stmt = $conn->prepare($sql);
        if ($stmt && !empty($params)) { $stmt->bind_param($types, ...$params); $stmt->execute(); $institutionCandidates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); }
    }
}

// Step 5: Score results
$fcResults = [];
foreach ($fcCandidates as $fc) {
    $s = scoreFC($fc, $topicFilters, $geoFilters, $keywords, $expandedTopics, $expandedGeos, $synonyms);
    if ($s >= 0.5) $fcResults[] = ['score' => $s, 'fc' => $fc];
}
usort($fcResults, fn($a, $b) => $b['score'] <=> $a['score']);

$rResults = [];
foreach ($rCandidates as $r) {
    $s = scoreResearcher($r, $topicFilters, $geoFilters, $keywords, $expandedTopics, $expandedGeos, $synonyms);
    if ($s >= 0.5) $rResults[] = ['score' => $s, 'r' => $r];
}
usort($rResults, fn($a, $b) => $b['score'] <=> $a['score']);

$funderResults = [];
foreach ($funderCandidates as $f) {
    $s = scoreFunder($f, $topicFilters, $geoFilters, $keywords, $expandedTopics, $expandedGeos, $synonyms);
    if ($s >= 0.5) $funderResults[] = ['score' => $s, 'f' => $f];
}
usort($funderResults, fn($a, $b) => $b['score'] <=> $a['score']);

// Step 6: Smart pivot searches (if results sparse, auto-search related categories)
$pivotFcResults = [];
$pivotReason = '';
$PIVOT_TOPICS = [
    'climate' => ['environment', 'energy', 'sustainability'],
    'health' => ['disease', 'medical', 'public health'],
    'agriculture' => ['food security', 'farming', 'nutrition'],
    'water' => ['sanitation', 'environment', 'health'],
    'food security' => ['agriculture', 'nutrition', 'livelihoods'],
];

if (count($fcResults) <= 1 && !empty($topicFilters)) {
    $primaryTopic = strtolower($topicFilters[0] ?? '');
    $pivotTopics = $PIVOT_TOPICS[$primaryTopic] ?? [];

    if (!empty($pivotTopics)) {
        foreach (array_slice($pivotTopics, 0, 2) as $pivotTopic) {
            $pivotTerms = array_merge([$pivotTopic], (array_values(array_slice(parse_tags($pivotTopic), 0, 3))));
            $pivotCandidates = fetchCandidates($conn, 'funding_calls', $pivotTerms, $filterStatus, 'title,description,topics,geography');

            foreach ($pivotCandidates as $fc) {
                $s = scoreFC($fc, [$pivotTopic], $geoFilters, [], [$pivotTopic], $expandedGeos, []);
                if ($s >= 0.5 && count($pivotFcResults) < 3) {
                    $pivotFcResults[] = ['score' => $s, 'fc' => $fc, 'pivot_topic' => $pivotTopic];
                }
            }
        }
        if (!empty($pivotFcResults)) {
            usort($pivotFcResults, fn($a, $b) => $b['score'] <=> $a['score']);
            $pivotReason = "No active funding calls for " . $primaryTopic . " at the moment, but here are related opportunities in " . implode(' and ', $pivotTopics);
        }
    }
}

// ── Format results for first SSE event ──
$fcForResponse = array_slice($fcResults, 0, 6);
$rForResponse = array_slice($rResults, 0, 6);
$fForResponse = array_slice($funderResults, 0, 6);
$instForResponse = array_slice($institutionCandidates, 0, 5);

$fcJson = [];
foreach ($fcForResponse as $item) {
    $fc = $item['fc'];
    $fcJson[] = [
        'id' => (int)$fc['id'],
        'entity_type' => 'funding_call',
        'title' => h($fc['title'] ?? ''),
        'funder' => h($fc['funder'] ?? ''),
        'status' => h($fc['status'] ?? ''),
        'deadline' => format_deadline($fc['deadline'] ?? null),
        'topics' => parse_tags($fc['topics'] ?? ''),
        'geography' => parse_tags($fc['geography'] ?? ''),
        'destination_url' => getEntityUrl('funding_call', (int)$fc['id']),
    ];
}

$rJson = [];
foreach ($rForResponse as $item) {
    $r = $item['r'];
    $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
    $rJson[] = [
        'id' => (int)$r['id'],
        'entity_type' => 'researcher',
        'name' => h($name),
        'institution' => h($r['institution'] ?? ''),
        'topics' => parse_tags($r['topics'] ?? ''),
        'geography' => parse_tags($r['geography'] ?? ''),
        'destination_url' => getEntityUrl('researcher', (int)$r['id']),
    ];
}

$funderJson = [];
foreach ($fForResponse as $item) {
    $f = $item['f'];
    $name = trim(($f['first_name'] ?? '') . ' ' . ($f['last_name'] ?? ''));
    $funderJson[] = [
        'id' => (int)$f['id'],
        'entity_type' => 'funder',
        'name' => h($name),
        'organization' => h($f['organization'] ?? ''),
        'topics' => parse_tags($f['topics'] ?? ''),
        'geography' => parse_tags($f['geography'] ?? ''),
        'destination_url' => getEntityUrl('funder', (int)$f['id']),
    ];
}

$instJson = [];
foreach ($instForResponse as $inst) {
    $instJson[] = [
        'entity_type' => 'institution',
        'institution' => h($inst['institution'] ?? ''),
        'researcher_count' => (int)$inst['researcher_count'],
        'topics' => array_filter(array_map('trim', explode(',', $inst['all_topics'] ?? ''))),
        'geography' => array_filter(array_map('trim', explode(',', $inst['all_geos'] ?? ''))),
        'destination_url' => getEntityUrl('institution', $inst['institution'] ?? ''),
    ];
}

// Format pivot results if available
$pivotFcJson = [];
foreach (array_slice($pivotFcResults, 0, 3) as $item) {
    $fc = $item['fc'];
    $pivotFcJson[] = [
        'id' => (int)$fc['id'],
        'entity_type' => 'funding_call',
        'title' => h($fc['title'] ?? ''),
        'funder' => h($fc['funder'] ?? ''),
        'status' => h($fc['status'] ?? ''),
        'deadline' => format_deadline($fc['deadline'] ?? null),
        'topics' => parse_tags($fc['topics'] ?? ''),
        'geography' => parse_tags($fc['geography'] ?? ''),
        'pivot_topic' => $item['pivot_topic'] ?? '',
        'destination_url' => getEntityUrl('funding_call', (int)$fc['id']),
    ];
}

// Send results event immediately
sseEvent([
    't' => 'results',
    'fc' => $fcJson,
    'r' => $rJson,
    'f' => $funderJson,
    'inst' => $instJson,
    'pivot_fc' => $pivotFcJson,
    'pivot_reason' => $pivotReason,
    'intent' => $parsed['intent'] ?? 'unknown',
    'total' => [
        'funding_calls' => count($fcResults),
        'researchers' => count($rResults),
        'funders' => count($funderResults),
        'institutions' => count($institutionCandidates),
        'pivot_funding_calls' => count($pivotFcResults),
    ]
]);

// ── Build results summary for Claude ──
$resultsSummary = '';
$topN = array_slice($fcResults, 0, 3);
$resultsSummary .= "Funding Calls (" . count($fcResults) . " total):\n";
foreach ($topN as $item) {
    $fc = $item['fc'];
    $resultsSummary .= "- " . ($fc['title'] ?? 'Untitled') . " (Funder: " . ($fc['funder'] ?? 'Unknown') . ", Status: " . ($fc['status'] ?? 'unknown') . ")\n";
}
if (count($fcResults) > 3) $resultsSummary .= "- ... and " . (count($fcResults) - 3) . " more\n";

$topN = array_slice($rResults, 0, 3);
$resultsSummary .= "\nResearchers (" . count($rResults) . " total):\n";
foreach ($topN as $item) {
    $r = $item['r'];
    $resultsSummary .= "- " . trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? 'Unknown')) . " (" . ($r['institution'] ?? 'Unknown') . ")\n";
}
if (count($rResults) > 3) $resultsSummary .= "- ... and " . (count($rResults) - 3) . " more\n";

if (!empty($funderResults)) {
    $topN = array_slice($funderResults, 0, 2);
    $resultsSummary .= "\nFunders (" . count($funderResults) . " total):\n";
    foreach ($topN as $item) {
        $f = $item['f'];
        $resultsSummary .= "- " . trim(($f['first_name'] ?? '') . ' ' . ($f['last_name'] ?? '') . ' ' . ($f['organization'] ?? 'Unknown')) . "\n";
    }
    if (count($funderResults) > 2) $resultsSummary .= "- ... and " . (count($funderResults) - 2) . " more\n";
}

if (!empty($institutionCandidates)) {
    $topN = array_slice($institutionCandidates, 0, 2);
    $resultsSummary .= "\nInstitutions (" . count($institutionCandidates) . " total):\n";
    foreach ($topN as $inst) {
        $resultsSummary .= "- " . ($inst['institution'] ?? 'Unknown') . " (" . $inst['researcher_count'] . " researchers)\n";
    }
    if (count($institutionCandidates) > 2) $resultsSummary .= "- ... and " . (count($institutionCandidates) - 2) . " more\n";
}

// ── Stream Claude response (or auto-response fallback) ──
$fullResponse = '';
$inputTokens = 0;
$outputTokens = 0;

if ($claude->isAvailable()) {
    // Build prompt for streaming (narrative only, no JSON)
    $historyBlock = '';
    foreach ($historyForClaude as $i => $turn) {
        $historyBlock .= "Turn " . ($i + 1) . ": " . $turn['user'] . "\nAssistant: " . $turn['assistant'] . "\n\n";
    }

    $streamPrompt = "You are a discovery assistant for FACT Alliance Hub, a research collaboration platform. You help users find:
- Researchers: by name, institution, topic, geography
- Funding Calls: grants, fellowships, and open opportunities
- Funders: organizations that provide research funding
- Institutions: universities and research organizations

Conversation so far:
" . ($historyBlock ?: "(No prior conversation)") . "

User just asked: \"" . str_replace('"', '\\"', $q) . "\"

Results found across the platform:
" . $resultsSummary . "

" . (empty($pivotReason) ? "" : "Related opportunities (recommended):\n" . $pivotReason . "\n\n") . "

Write a SHORT, natural response (2-4 sentences):
- Directly tell the user what was found and where
- Mention the most relevant result by name (e.g., 'Found Dr. Judercio Nhauche at Ashesi University')
- If showing recommendations, explain why they're relevant
- Be direct and helpful, do not suggest searching or ask follow-up questions
- Plain text only: no markdown formatting, no asterisks, no bold, no italics, no bullet points, no special characters";

    // cURL with streaming
    $fullResponse = '';
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . getenv('ANTHROPIC_API_KEY'),
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'claude-haiku-4-5-20251001',
            'max_tokens' => 300,
            'stream' => true,
            'messages' => [['role' => 'user', 'content' => $streamPrompt]],
        ]),
        CURLOPT_WRITEFUNCTION => function($ch, $chunk) use (&$fullResponse, &$inputTokens, &$outputTokens) {
            foreach (explode("\n", $chunk) as $line) {
                $line = trim($line);
                if (!str_starts_with($line, 'data: ')) continue;
                $payload = json_decode(substr($line, 6), true);
                if (!is_array($payload)) continue;

                $type = $payload['type'] ?? '';
                if ($type === 'content_block_delta') {
                    $text = $payload['delta']['text'] ?? '';
                    if ($text !== '') {
                        $fullResponse .= $text;
                        sseEvent(['t' => 'token', 'v' => $text]);
                    }
                } elseif ($type === 'message_start') {
                    $inputTokens = (int)($payload['message']['usage']['input_tokens'] ?? 0);
                } elseif ($type === 'message_delta') {
                    $outputTokens = (int)($payload['usage']['output_tokens'] ?? 0);
                }
            }
            return strlen($chunk);
        },
    ]);
    curl_exec($ch);
    curl_close($ch);
} else {
    // Fallback: no Claude API key, generate simple response
    $autoResponse = 'Found ' . count($fcResults) . ' funding calls and ' . count($rResults) . ' researchers matching your query.';
    foreach (explode(' ', $autoResponse) as $word) {
        $fullResponse .= $word . ' ';
        sseEvent(['t' => 'token', 'v' => $word . ' ']);
        usleep(40000); // 40ms between words
    }
}

// ── Save session turn ──
$cleanedResponse = stripMarkdown($fullResponse ?: 'No response generated.');
$newTurn = [
    'user' => $q,
    'assistant' => $cleanedResponse,
    'parsed' => [
        'topics' => $topicFilters,
        'geographies' => $geoFilters,
        'intent' => $parsed['intent'] ?? 'unknown',
    ]
];
$history[] = $newTurn;
if (count($history) > 10) array_shift($history);
$historyJson = json_encode($history);

$stmt = $conn->prepare('UPDATE search_sessions SET turns = ?, updated_at = NOW() WHERE session_key = ? AND user_id = ?');
$stmt->bind_param('ssi', $historyJson, $sessionKey, $userId);
$stmt->execute();

// ── Log search ──
$stmt = $conn->prepare(
    'INSERT INTO search_logs (user_id, search_query, filters, results_count)
     VALUES (?, ?, ?, ?)'
);
$logFilters = json_encode(['topics' => $topicFilters, 'geos' => $geoFilters, 'fallback' => $fallback]);
$logResultsCount = count($fcResults) + count($rResults);
$stmt->bind_param('issi', $userId, $q, $logFilters, $logResultsCount);
$stmt->execute();

// ── Send done event ──
sseEvent(['t' => 'done', 'sk' => $sessionKey]);
?>

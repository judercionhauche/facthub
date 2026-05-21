<?php
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $token = $_SESSION['csrf_token'];
    error_log("[CSRF TOKEN] Generated/retrieved: " . substr($token, 0, 10) . "...");
    return $token;
}

function csrf_input(): string {
    $token = csrf_token();
    $html = '<input type="hidden" name="_csrf" value="' . h($token) . '">';
    error_log("[CSRF INPUT] Output HTML: " . substr($html, 0, 80) . "...");
    return $html;
}

function verify_csrf(): bool {
    // Accept token from POST body or custom AJAX header
    $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return !empty($_SESSION['csrf_token'])
        && $token !== ''
        && hash_equals($_SESSION['csrf_token'], $token);
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function require_login() {
    if (!is_logged_in()) {
        init_session();
        if (!empty($_SERVER['QUERY_STRING'])) {
            $_SESSION['login_return'] = $_SERVER['QUERY_STRING'];
        }
        redirect_to('login');
    }
}

function current_user() {
    return [
        'id'    => $_SESSION['user_id']    ?? null,
        'email' => $_SESSION['user_email'] ?? '',
        'name'  => $_SESSION['user_name']  ?? '',
        'role'  => $_SESSION['user_role']  ?? 'researcher',
    ];
}

function is_admin() {
    return ($_SESSION['user_role'] ?? '') === 'admin';
}

function is_funder() {
    return ($_SESSION['user_role'] ?? '') === 'funder';
}

function is_approved() {
    return is_logged_in() && ($_SESSION['user_status'] ?? '') === 'active';
}

function require_admin() {
    if (!is_admin()) {
        set_flash('error', 'You do not have permission to do that.');
        redirect_to('researchers');
    }
}

function redirect_to($page, $extra = []) {
    $query = array_merge(['page' => $page], $extra);
    header('Location: index.php?' . http_build_query($query));
    exit;
}

function set_flash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash() {
    if (!isset($_SESSION['flash'])) return null;
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function parse_tags($tagString) {
    if (!$tagString) return [];
    $parts = array_map(function($t) {
        return strtolower(trim($t));
    }, explode(',', $tagString));
    $parts = array_filter($parts, function($t) { return $t !== ''; });
    return array_values(array_unique($parts));
}

function append_tag($current, $tag) {
    $tag = trim((string)$tag);
    if ($tag === '') return trim((string)$current);
    $existing = parse_tags($current);
    if (!in_array(strtolower($tag), $existing, true)) {
        $existing[] = strtolower($tag);
    }
    return implode(', ', $existing);
}

function compute_match_score($fundingTopics, $fundingGeo, $researcherTopics, $researcherGeo) {
    $matchedTopics = array_values(array_intersect($fundingTopics, $researcherTopics));
    $matchedGeo = array_values(array_intersect($fundingGeo, $researcherGeo));
    $topicMatches = count($matchedTopics);
    $geographyMatches = count($matchedGeo);
    return [
        'topicMatches' => $topicMatches,
        'geographyMatches' => $geographyMatches,
        'totalScore' => ($topicMatches * 2) + $geographyMatches,
        'matchedTopics' => $matchedTopics,
        'matchedGeographies' => $matchedGeo,
    ];
}

function format_deadline($deadline) {
    if (!$deadline) return 'No deadline';
    $ts = strtotime($deadline);
    if (!$ts) return $deadline;
    return date('M j, Y', $ts);
}

function status_class($status) {
    switch ($status) {
        case 'open': return 'status-open';
        case 'rolling': return 'status-rolling';
        case 'closed': return 'status-closed';
        case 'upcoming': return 'status-upcoming';
        default: return 'status-default';
    }
}

function audit(mysqli $conn, string $action, array $ctx = []): void {
    $user   = current_user();
    $actor  = $user['email'] ?: 'system';
    $role   = $user['role']  ?: 'admin';
    $tType  = $ctx['type']   ?? null;
    $tId    = isset($ctx['id'])    ? (int)$ctx['id']   : null;
    $tEmail = $ctx['email']  ?? null;
    $detail = isset($ctx['detail']) ? (string)$ctx['detail'] : null;
    $ip     = $_SERVER['REMOTE_ADDR'] ?? null;

    $stmt = $conn->prepare(
        'INSERT INTO audit_log (actor_email, action, target_type, target_id, target_email, detail, ip)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('ssissss', $actor, $action, $tType, $tId, $tEmail, $detail, $ip);
    @$stmt->execute();
}

function get_all_tags($conn, $type) {
    $stmt = $conn->prepare('SELECT name FROM tags WHERE tag_type = ? ORDER BY name ASC');
    $stmt->bind_param('s', $type);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        if (!empty($row['name'])) $out[] = $row['name'];
    }
    return $out;
}

function ensure_tags($conn, $csv, $type) {
    $tags = parse_tags($csv);
    foreach ($tags as $tag) {
        $name = ucwords($tag);
        $check = $conn->prepare('SELECT id FROM tags WHERE LOWER(name) = LOWER(?) AND tag_type = ? LIMIT 1');
        $check->bind_param('ss', $name, $type);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        if (!$exists) {
            $insert = $conn->prepare('INSERT INTO tags (name, tag_type) VALUES (?, ?)');
            $insert->bind_param('ss', $name, $type);
            $insert->execute();
        }
    }
}

function enqueue_job(mysqli $conn, string $jobType, array $payload, int $delaySec = 0): int {
    $allowed = ['compute_matches','generate_summary','send_notification','send_digest','check_balance','fetch_orcid_publications'];
    if (!in_array($jobType, $allowed, true)) {
        error_log('[enqueue_job] Invalid job type: ' . $jobType);
        return 0;
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        error_log('[enqueue_job] json_encode failed for type: ' . $jobType);
        return 0;
    }
    if ($delaySec > 0) {
        $runAfter = date('Y-m-d H:i:s', time() + $delaySec);
        $stmt = $conn->prepare('INSERT INTO job_queue (job_type, payload, run_after) VALUES (?, ?, ?)');
        $stmt->bind_param('sss', $jobType, $json, $runAfter);
    } else {
        $stmt = $conn->prepare('INSERT INTO job_queue (job_type, payload) VALUES (?, ?)');
        $stmt->bind_param('ss', $jobType, $json);
    }
    $stmt->execute();
    return (int)$conn->insert_id;
}

function revoke_user_session(mysqli $conn, int $userId): void {
    $stmt = $conn->prepare('UPDATE users SET session_token = NULL WHERE id = ?');
    $stmt->bind_param('i', $userId);
    @$stmt->execute();
}

function generate_researcher_summary(mysqli $conn, int $researcherId): void {
    // Get researcher profile data
    $q = $conn->prepare('SELECT first_name, last_name, title, institution, bio, focus_area, focus_area_detail, topics, geography FROM researchers WHERE id = ?');
    $q->bind_param('i', $researcherId);
    $q->execute();
    $researcher = $q->get_result()->fetch_assoc();

    if (!$researcher) return;

    // Build summary from profile
    $name = trim($researcher['first_name'] . ' ' . $researcher['last_name']);
    $summary = "{$name}";

    if ($researcher['title']) {
        $summary .= " is a {$researcher['title']}";
        if ($researcher['institution']) {
            $summary .= " at {$researcher['institution']}";
        }
    }

    if ($researcher['bio']) {
        $summary .= ". {$researcher['bio']}";
    }

    if ($researcher['focus_area']) {
        $areas = array_map('trim', explode('|', $researcher['focus_area']));
        $summary .= " Their research focuses on " . implode(', ', $areas) . ".";
    }

    if ($researcher['topics']) {
        $topics = parse_tags($researcher['topics']);
        if (!empty($topics)) {
            $summary .= " Key topics include: " . implode(', ', array_slice($topics, 0, 5)) . ".";
        }
    }

    // Check if summary already exists
    $check = $conn->prepare('SELECT id FROM ai_summaries WHERE entity_type = ? AND entity_id = ?');
    $entityType = 'researcher';
    $check->bind_param('si', $entityType, $researcherId);
    $check->execute();

    if ($check->get_result()->num_rows > 0) {
        // Update existing
        $update = $conn->prepare('UPDATE ai_summaries SET summary = ?, model_used = ? WHERE entity_type = ? AND entity_id = ?');
        $model = 'auto-generated';
        $update->bind_param('sssi', $summary, $model, $entityType, $researcherId);
        @$update->execute();
    } else {
        // Insert new
        $insert = $conn->prepare('INSERT INTO ai_summaries (entity_type, entity_id, summary, model_used, prompt_hash) VALUES (?, ?, ?, ?, ?)');
        $model = 'auto-generated';
        $promptHash = '';
        $insert->bind_param('sisss', $entityType, $researcherId, $summary, $model, $promptHash);
        @$insert->execute();
    }
}

function send_admin_notification_email(string $email, string $action, string $name, string $reason = ''): void {
    @$mailCfg = require __DIR__ . '/../../config/mail.php';
    if (!is_array($mailCfg)) $mailCfg = [];
    $appUrl = rtrim($mailCfg['app_url'] ?? 'http://localhost', '/');
    $firstName = explode(' ', trim($name))[0] ?: 'there';

    if ($action === 'approved') {
        $subject = 'Your FACT Alliance Hub account has been approved!';
        $html = "
            <p>Hi $firstName,</p>
            <p>Great news! Your account has been approved by our admin team.</p>
            <p>You can now access all platform features:</p>
            <ul>
                <li>Browse and save funding opportunities</li>
                <li>Chat with other researchers</li>
                <li>Get AI-powered research recommendations</li>
            </ul>
            <p><a href='$appUrl/index.php?page=login'>Log in now →</a></p>
            <p>Questions? Contact us at support@factalliance.org</p>
        ";
    } elseif ($action === 'rejected') {
        $subject = 'FACT Alliance Hub application decision';
        $html = "
            <p>Hi $firstName,</p>
            <p>Thank you for your interest in the FACT Alliance Hub.</p>
            <p>Unfortunately, your application was not approved at this time." .
            ($reason ? "<br/>Reason: " . htmlspecialchars($reason) : "") . "</p>
            <p>You're welcome to contact us at support@factalliance.org if you have questions.</p>
        ";
    } else {
        return;
    }

    @send_notification_email($email, $subject, $html);
}

function notify_admins_of_new_registration(string $email, string $name, string $institution = ''): void {
    global $conn;
    $admins = $conn->query("SELECT email FROM users WHERE role='admin' AND status='active' LIMIT 10");

    if ($admins && $admins->num_rows > 0) {
        @$mailCfg = require __DIR__ . '/../../config/mail.php';
        if (!is_array($mailCfg)) $mailCfg = [];
        $appUrl = rtrim($mailCfg['app_url'] ?? 'http://localhost', '/');

        while ($admin = $admins->fetch_assoc()) {
            $subject = "New registration pending approval: $name";
            $html = "
                <p>A new researcher has registered and needs approval.</p>
                <p><strong>Name:</strong> $name</p>
                <p><strong>Email:</strong> $email</p>
                <p><strong>Institution:</strong> " . htmlspecialchars($institution) . "</p>
                <p><a href='$appUrl/index.php?page=admin&section=users&utab=pending'>Review pending users →</a></p>
            ";
            @send_notification_email($admin['email'], $subject, $html);
        }
    }
}
?>


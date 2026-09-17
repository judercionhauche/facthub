<?php
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string {
    init_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_input(): string {
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function verify_csrf(): bool {
    // Accept token from POST body or custom AJAX header
    $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (empty($sessionToken)) {
        error_log('[CSRF] No session token found');
        return false;
    }
    if ($token === '') {
        error_log('[CSRF] No POST token provided');
        return false;
    }
    if (!hash_equals($sessionToken, $token)) {
        error_log('[CSRF] Token mismatch: session=' . substr($sessionToken, 0, 8) . '... post=' . substr($token, 0, 8) . '...');
        return false;
    }

    return true;
}

function generate_unique_token(mysqli $conn, int $maxRetries = 5): string {
    for ($i = 0; $i < $maxRetries; $i++) {
        // Use microtime, random bytes, and process ID for maximum uniqueness
        $token = bin2hex(hash('sha256', microtime(true) . getmypid() . random_bytes(32), true));
        $check = $conn->prepare('SELECT 1 FROM email_verifications WHERE token = ? LIMIT 1');
        $check->bind_param('s', $token);
        $check->execute();
        if ($check->get_result()->num_rows === 0) {
            return $token;
        }
    }
    // Fallback: use multiple sources of entropy for 64-char hex string
    return bin2hex(hash('sha256', uniqid(getmypid() . microtime(true), true) . random_bytes(32), true));
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

function format_geography_tag($tag) {
    $tag = trim($tag);
    if (empty($tag)) return '';
    return mb_convert_case($tag, MB_CASE_TITLE, "UTF-8");
}

function format_geography_tags($tagString) {
    $tags = parse_tags($tagString);
    return array_map('format_geography_tag', $tags);
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
    $allowed = ['compute_matches','generate_summary','send_notification','send_digest','fetch_orcid_publications','send_weekly_digests','generate_embedding'];
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
    $q = $conn->prepare('SELECT * FROM researchers WHERE id = ?');
    $q->bind_param('i', $researcherId);
    $q->execute();
    $researcher = $q->get_result()->fetch_assoc();

    if (!$researcher) return;

    // Prefer a real Claude-generated summary. ClaudeService::summarizeResearcher
    // writes straight into ai_summaries (upsert), so on success we're done.
    // Falls through to the plain template below if the API key isn't available
    // in this context (e.g. a CLI run without it) or the call fails.
    try {
        require_once __DIR__ . '/../services/ClaudeService.php';
        $claude = new ClaudeService($conn, 'auto:researcher_summary');
        if ($claude->isAvailable()) {
            $aiSummary = $claude->summarizeResearcher($researcherId, $researcher);
            if (is_string($aiSummary) && trim($aiSummary) !== '') {
                return;
            }
        }
    } catch (\Throwable $e) {
        error_log('[generate_researcher_summary] Claude path failed, using template: ' . $e->getMessage());
    }

    // Fallback: build a plain template summary from profile fields
    $name = trim($researcher['first_name'] . ' ' . $researcher['last_name']);
    $summary = "{$name}";

    if ($researcher['title']) {
        $article = (preg_match('/^[aeiou]/i', trim($researcher['title'])) ? 'an' : 'a');
        $summary .= " is {$article} {$researcher['title']}";
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
            <p>Questions? Contact us at factalliance@mit.edu</p>
        ";
    } elseif ($action === 'rejected') {
        $subject = 'FACT Alliance Hub application decision';
        $html = "
            <p>Hi $firstName,</p>
            <p>Thank you for your interest in the FACT Alliance Hub.</p>
            <p>Unfortunately, your application was not approved at this time." .
            ($reason ? "<br/>Reason: " . htmlspecialchars($reason) : "") . "</p>
            <p>You're welcome to contact us at factalliance@mit.edu if you have questions.</p>
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

function is_in_quiet_hours(string $quietStart = null, string $quietEnd = null): bool {
    if (!$quietStart || !$quietEnd) return false;

    $currentTime = date('H:i', time());

    // If quiet hours span midnight (e.g. 22:00 to 08:00)
    if ($quietStart > $quietEnd) {
        return $currentTime >= $quietStart || $currentTime < $quietEnd;
    }

    // Normal quiet hours (e.g. 22:00 to 06:00)
    return $currentTime >= $quietStart && $currentTime < $quietEnd;
}

function is_trusted_domain(mysqli $conn, string $email): bool {
    if (!preg_match('/@(.+)$/', strtolower(trim($email)), $m)) {
        return false;
    }
    $domain = $m[1];

    $stmt = $conn->prepare('SELECT 1 FROM trusted_domains WHERE domain = ? AND auto_approve = 1 LIMIT 1');
    if (!$stmt) return false;

    $stmt->bind_param('s', $domain);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0;
}

/**
 * Member-institution stats derived from the trusted domains settings list.
 * Returns ['institutions' => int, 'countries' => int] — distinct institution
 * names and distinct non-empty countries across trusted_domains.
 */
function count_member_institutions(mysqli $conn): array {
    $out = ['institutions' => 0, 'countries' => 0];
    try {
        $r = @$conn->query("
            SELECT COUNT(DISTINCT institution_name) AS i,
                   COUNT(DISTINCT NULLIF(TRIM(country), '')) AS c
            FROM trusted_domains
        ");
        if ($r && ($row = $r->fetch_assoc())) {
            $out['institutions'] = (int)$row['i'];
            $out['countries']    = (int)$row['c'];
        }
    } catch (Throwable $e) {
        error_log('[Metrics] trusted_domains count error: ' . $e->getMessage());
    }
    return $out;
}

/* ═══════════════════════════════════════════════════════════════════════
 * INSTITUTION NAME NORMALISATION
 *
 * The researchers.institution column is free text, and it gets filled from
 * three places that disagree: the registration form, the ORCID sync (which
 * writes whatever an author put on a paper) and bulk imports. The result is
 * that one university shows up on the Institutions page several times over —
 * as a bare domain, as a faculty-qualified name, or as an abbreviation — so
 * colleagues end up in separate groups.
 *
 * These helpers resolve a raw value to one canonical name. The domain map in
 * trusted_domains is the authoritative source (admins can edit it in
 * Settings); the built-in table below only fills gaps.
 * ═══════════════════════════════════════════════════════════════════════ */

/**
 * Built-in domain → canonical institution name. Supplements trusted_domains
 * for institutions that aren't in the auto-approval list.
 */
function institution_builtin_domains(): array {
    return [
        'mcgill.ca'      => 'McGill University',
        'umontreal.ca'   => 'Université de Montréal',
        'utoronto.ca'    => 'University of Toronto',
        'ubc.ca'         => 'University of British Columbia',
        'uoguelph.ca'    => 'University of Guelph',
        'usask.ca'       => 'University of Saskatchewan',
        'ualberta.ca'    => 'University of Alberta',
        'uwaterloo.ca'   => 'University of Waterloo',
        'concordia.ca'   => 'Concordia University',
        'mit.edu'        => 'Massachusetts Institute of Technology',
        'wur.nl'         => 'Wageningen University & Research',
        'cgiar.org'      => 'CGIAR',
        'ifpri.org'      => 'IFPRI',
        'irri.org'       => 'International Rice Research Institute',
        'cimmyt.org'     => 'CIMMYT',
        'icrisat.org'    => 'ICRISAT',
        'fao.org'        => 'Food and Agriculture Organization',
        'ug.edu.gh'      => 'University of Ghana',
        'knust.edu.gh'   => 'Kwame Nkrumah University of Science and Technology',
        'uds.edu.gh'     => 'University for Development Studies',
        'ucc.edu.gh'     => 'University of Cape Coast',
        'uonbi.ac.ke'    => 'University of Nairobi',
        'ku.ac.ke'       => 'Kenyatta University',
        'makerere.ac.ug' => 'Makerere University',
        'up.ac.za'       => 'University of Pretoria',
        'uct.ac.za'      => 'University of Cape Town',
        'sun.ac.za'      => 'Stellenbosch University',
        'wits.ac.za'     => 'University of the Witwatersrand',
        'iisc.ac.in'     => 'Indian Institute of Science',
        'iitd.ac.in'     => 'Indian Institute of Technology Delhi',
        'iitb.ac.in'     => 'Indian Institute of Technology Bombay',
        'tsinghua.edu.cn'=> 'Tsinghua University',
        'pku.edu.cn'     => 'Peking University',
        'cau.edu.cn'     => 'China Agricultural University',
        'nus.edu.sg'     => 'National University of Singapore',
        'ntu.edu.sg'     => 'Nanyang Technological University',
        'u-tokyo.ac.jp'  => 'University of Tokyo',
        'kyoto-u.ac.jp'  => 'Kyoto University',
        'unimelb.edu.au' => 'University of Melbourne',
        'sydney.edu.au'  => 'University of Sydney',
        'anu.edu.au'     => 'Australian National University',
        'uq.edu.au'      => 'University of Queensland',
        'ed.ac.uk'       => 'University of Edinburgh',
        'leeds.ac.uk'    => 'University of Leeds',
        'reading.ac.uk'  => 'University of Reading',
        'sussex.ac.uk'   => 'University of Sussex',
        'wgtn.ac.nz'     => 'Victoria University of Wellington',
        'massey.ac.nz'   => 'Massey University',
        'usp.br'         => 'Universidade de São Paulo',
        'unicamp.br'     => 'Universidade Estadual de Campinas',
        'embrapa.br'     => 'Embrapa',
        'chapingo.mx'    => 'Universidad Autónoma Chapingo',
        'unam.mx'        => 'Universidad Nacional Autónoma de México',
    ];
}

/**
 * Abbreviation → canonical name. Without this, researchers who wrote "MIT"
 * and those whose record resolved to "Massachusetts Institute of Technology"
 * stay in two separate groups, which is the very thing we're fixing.
 */
function institution_aliases(): array {
    return [
        'MIT'      => 'Massachusetts Institute of Technology',
        'M.I.T.'   => 'Massachusetts Institute of Technology',
        'UBC'      => 'University of British Columbia',
        'U of T'   => 'University of Toronto',
        'UofT'     => 'University of Toronto',
        'UdeM'     => 'Université de Montréal',
        'UQAM'     => 'Université du Québec à Montréal',
        'WUR'      => 'Wageningen University & Research',
        'Wageningen University' => 'Wageningen University & Research',
        'Wageningen UR'         => 'Wageningen University & Research',
        'KNUST'    => 'Kwame Nkrumah University of Science and Technology',
        'UG'       => 'University of Ghana',
        'Legon'    => 'University of Ghana',
        'UCT'      => 'University of Cape Town',
        'Wits'     => 'University of the Witwatersrand',
        'IISc'     => 'Indian Institute of Science',
        'NUS'      => 'National University of Singapore',
        'NTU'      => 'Nanyang Technological University',
        'ANU'      => 'Australian National University',
        'UNAM'     => 'Universidad Nacional Autónoma de México',
        'USP'      => 'Universidade de São Paulo',
        'Oxford'   => 'University of Oxford',
        'Cambridge'=> 'University of Cambridge',
    ];
}

/**
 * Domain → canonical name, trusted_domains first (admin-editable) then the
 * built-in table. Cached per request; this is called once per researcher.
 */
function institution_domain_map(mysqli $conn): array {
    static $map = null;
    if ($map !== null) return $map;

    $map = institution_builtin_domains();
    try {
        $r = @$conn->query("SELECT domain, institution_name FROM trusted_domains WHERE TRIM(institution_name) <> ''");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $d = strtolower(trim($row['domain']));
                if ($d !== '') $map[$d] = trim($row['institution_name']);
            }
        }
    } catch (Throwable $e) {
        error_log('[Institutions] domain map load error: ' . $e->getMessage());
    }
    return $map;
}

/** Comparison key: lowercase, accents folded, punctuation and noise words dropped. */
function institution_key(string $name): string {
    $s = strtolower(trim($name));
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        if ($t !== false) $s = $t;
    }
    $s = preg_replace('~[^a-z0-9]+~', ' ', $s);
    // "univ."/"u" → "university" so abbreviations collapse onto the full name
    $s = preg_replace('~\buniv\b~', 'university', $s);
    $s = preg_replace('~\b(the|of|at|de|del|la|le|les|and)\b~', ' ', $s);
    return trim(preg_replace('~\s+~', ' ', $s));
}

/**
 * Resolve a hostname to a canonical institution, walking up the labels so
 * that faculty subdomains land on the parent. agr.mcgill.ca is not in the
 * map, but mcgill.ca is — and that is precisely the case that was splitting
 * colleagues at one university into separate groups.
 */
function institution_from_host(string $host, array $domainMap): string {
    $host = strtolower(trim($host));
    $host = preg_replace('~^https?://~', '', $host);
    $host = preg_replace('~^www\.~', '', $host);
    $host = explode('/', $host)[0];
    $host = explode('?', $host)[0];
    if ($host === '') return '';

    $labels = explode('.', $host);
    // Walk up: agr.mcgill.ca → mcgill.ca → ca. Stop before a bare public
    // suffix so "ac.uk" or "edu.gh" can never swallow every UK/Ghana address.
    for ($i = 0; $i < count($labels) - 1; $i++) {
        $candidate = implode('.', array_slice($labels, $i));
        if (substr_count($candidate, '.') < 1) break;
        if (isset($domainMap[$candidate])) return $domainMap[$candidate];
    }
    return '';
}

/** True when the value is a bare domain or URL rather than a written-out name. */
function institution_looks_like_domain(string $v): bool {
    $v = trim($v);
    if ($v === '' || strpos($v, ' ') !== false) return false;
    return (bool)preg_match('~^(https?://)?[a-z0-9.\-]+\.[a-z]{2,}(/.*)?$~i', $v);
}

/**
 * Strip a sub-unit qualifier so faculties, departments and schools fold into
 * the parent institution: "McGill University, Faculty of Agricultural and
 * Environmental Sciences" → "McGill University". Only the sub-unit side is
 * removed; a name that is nothing but a department is left alone.
 */
function institution_strip_subunit(string $name): string {
    $unit = '(faculty|department|dept\.?|school|college|institute|centre|center|division|laboratory|lab|programme|program|unit|chair)\b';

    // "Parent, Faculty of X" / "Parent - School of Y" — keep the parent
    if (preg_match('~^(.*?)[,\-–—]\s*(?:the\s+)?' . $unit . '.*$~i', $name, $m)) {
        $head = trim($m[1], " \t,-–—");
        if ($head !== '' && str_word_count($head) >= 2) return $head;
    }
    // "Faculty of X, Parent" — keep the trailing parent
    if (preg_match('~^(?:the\s+)?' . $unit . '[^,]*,\s*(.+)$~i', $name, $m)) {
        $tail = trim($m[1], " \t,-–—");
        if ($tail !== '' && str_word_count($tail) >= 2) return $tail;
    }
    return trim($name);
}

/**
 * Canonical institution for one researcher.
 *
 * Order of trust: an explicit name that matches a known institution, then the
 * domain embedded in the value, then the researcher's own email domain. The
 * raw value is returned unchanged when nothing matches, so unknown
 * institutions are never mangled into something wrong.
 */
function canonical_institution(string $raw, string $email, array $domainMap): string {
    $raw = trim(preg_replace('~\s+~', ' ', $raw));

    // Canonical names, indexed by comparison key, so variants collapse.
    static $byKey = null;
    if ($byKey === null || $byKey['_src'] !== count($domainMap)) {
        $byKey = ['_src' => count($domainMap)];
        foreach ($domainMap as $name) $byKey[institution_key($name)] = $name;
        // Aliases last so an abbreviation always wins over a literal match
        foreach (institution_aliases() as $alias => $name) $byKey[institution_key($alias)] = $name;
    }

    $emailHost = '';
    if ($email !== '' && strpos($email, '@') !== false) {
        $emailHost = strtolower(trim(substr(strrchr($email, '@'), 1)));
    }

    // 1. Nothing useful written down — fall back to the email domain.
    if ($raw === '' || strcasecmp($raw, 'Unknown Institution') === 0) {
        return institution_from_host($emailHost, $domainMap) ?: $raw;
    }

    // 2. The value is a domain or URL.
    if (institution_looks_like_domain($raw)) {
        $hit = institution_from_host($raw, $domainMap);
        if ($hit !== '') return $hit;
        $hit = institution_from_host($emailHost, $domainMap);
        if ($hit !== '') return $hit;
        // Unmapped domain: at least show the registrable part, not the URL.
        $host = preg_replace('~^(https?://)?(www\.)?~i', '', $raw);
        return explode('/', $host)[0];
    }

    // 3. Written-out name — match whole, then with the sub-unit removed.
    $key = institution_key($raw);
    if (isset($byKey[$key])) return $byKey[$key];

    $stripped = institution_strip_subunit($raw);
    if ($stripped !== $raw) {
        $sk = institution_key($stripped);
        if (isset($byKey[$sk])) return $byKey[$sk];
    }

    // 4. Unrecognised name, but the email domain is known and the written
    //    name contains it — trust the domain (catches "McGill Univ. - MacDonald
    //    Campus" style entries that no key match will ever hit).
    $fromEmail = institution_from_host($emailHost, $domainMap);
    if ($fromEmail !== '') {
        $rk = institution_key($fromEmail);
        if ($rk !== '' && strpos($key, explode(' ', $rk)[0]) !== false) return $fromEmail;
    }

    // 5. Leave it alone, minus any sub-unit qualifier.
    return $stripped;
}

function send_weekly_digest(mysqli $conn): void {
    // Find all researchers with weekly frequency who haven't been sent in the last 7 days
    $weekAgo = date('Y-m-d H:i:s', time() - (7 * 86400));

    $rq = $conn->prepare("
        SELECT DISTINCT r.id, r.email, r.first_name
        FROM researchers r
        WHERE r.status = 'active' AND r.deleted_at IS NULL
              AND r.notify_matches = 1 AND r.notify_frequency = 'weekly'
              AND (r.last_notification_sent_at IS NULL OR r.last_notification_sent_at < ?)
        LIMIT 100
    ");
    $rq->bind_param('s', $weekAgo);
    $rq->execute();
    $researcherResult = $rq->get_result();

    while ($researcher = $researcherResult->fetch_assoc()) {
        $rId = (int)$researcher['id'];
        $rEmail = trim($researcher['email'] ?? '');
        if (!$rEmail) continue;

        // Get all queued notifications for this researcher
        $nq = $conn->prepare("
            SELECT fc.title, fc.funder, fc.deadline, fc.status, fc.amount, fc.topics, fc.geography, nq.funding_call_id
            FROM notification_queue nq
            JOIN funding_calls fc ON fc.id = nq.funding_call_id
            WHERE nq.researcher_email = ? AND nq.sent_at IS NULL
            ORDER BY fc.deadline ASC, fc.created_at DESC
            LIMIT 50
        ");
        $nq->bind_param('s', $rEmail);
        $nq->execute();
        $notifResult = $nq->get_result();

        $notifications = [];
        while ($row = $notifResult->fetch_assoc()) {
            $notifications[] = $row;
        }

        if (!empty($notifications)) {
            // Build digest email
            @$mailCfg = require __DIR__ . '/../config/mail.php';
            if (!is_array($mailCfg)) $mailCfg = [];
            $appUrl = rtrim($mailCfg['app_url'] ?? ('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')), '/');
            $notifySecret = $mailCfg['notify_secret'] ?? '';

            $firstName = $researcher['first_name'] ?: 'Researcher';
            $unsubToken = generate_unsubscribe_token($rEmail, $notifySecret);
            $unsubUrl = $appUrl . '/index.php?page=unsubscribe&e=' . urlencode($rEmail) . '&t=' . $unsubToken;

            $html = "<p>Hi $firstName,</p><p>Here are your funding matches from this week:</p><ul>";

            foreach ($notifications as $n) {
                $title = htmlspecialchars($n['title'] ?? '');
                $funder = htmlspecialchars($n['funder'] ?? '');
                $deadline = format_deadline($n['deadline'] ?? '');
                $fundingUrl = $appUrl . '/index.php?page=funding&view=' . (int)$n['funding_call_id'];

                $html .= "<li><strong><a href='$fundingUrl'>$title</a></strong> — $funder ($deadline)</li>";
            }

            $html .= "</ul><p><a href='$unsubUrl'>Unsubscribe</a></p>";

            enqueue_job($conn, 'send_notification', [
                'to' => $rEmail,
                'subject' => 'Your weekly funding matches (' . count($notifications) . ' matches)',
                'html' => $html
            ]);

            // Mark as sent
            $upd = $conn->prepare("UPDATE notification_queue SET sent_at = NOW() WHERE researcher_email = ? AND sent_at IS NULL");
            $upd->bind_param('s', $rEmail);
            @$upd->execute();

            $updResearcher = $conn->prepare("UPDATE researchers SET last_notification_sent_at = NOW() WHERE id = ?");
            $updResearcher->bind_param('i', $rId);
            @$updResearcher->execute();
        }
    }
}
?>


<?php
// Set timezone to New York (Eastern Time)
date_default_timezone_set('America/New_York');

ob_start();

// ── Security: Enforce HTTPS & set security headers ──
if (getenv('APP_ENV') === 'production') {
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
        header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Security headers (all responses)
header('Strict-Transport-Security: max-age=31536000; includeSubDomains', true);
header('X-Content-Type-Options: nosniff', true);
header('X-Frame-Options: SAMEORIGIN', true);
header('X-XSS-Protection: 1; mode=block', true);
header('Referrer-Policy: strict-origin-when-cross-origin', true);
header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; font-src \'self\' https://fonts.gstatic.com; img-src \'self\' data: https:', true);

// Harden PHP error visibility in production — errors must never reach the browser
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);          // still log everything, just don't display it
ini_set('log_errors', '1');

$dbConfig = require_once __DIR__ . '/../config/database.php';
$conn = new mysqli($dbConfig['db_host'], $dbConfig['db_user'], $dbConfig['db_pass'], $dbConfig['db_name']);
if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

require_once __DIR__ . '/../app/core/session_manager.php';
require_once __DIR__ . '/../app/core/helpers.php';

// Initialize sessions in HTTP context (not CLI)
init_session();
require_once __DIR__ . '/../app/core/mailer.php';
require_once __DIR__ . '/../app/services/RateLimiter.php';
require_once __DIR__ . '/../app/core/schema_updates.php';
require_once __DIR__ . '/../app/core/device_fingerprint.php';

// Apply schema updates safely
try {
    apply_security_schema_updates($conn);
    apply_newsletter_schema($conn);
    apply_impact_data_schema($conn);
} catch (Throwable $e) {
    error_log('[Schema Updates Error] ' . $e->getMessage());
    // Continue anyway - schema might already be in place
}

// Auto-login from remember_token cookie if no current session
if (!is_logged_in() && isset($_COOKIE['remember_token'])) {
    $token = trim($_COOKIE['remember_token']);
    $stmt = $conn->prepare(
        'SELECT user_id, expires_at, used_at, revoked_at, id
         FROM remember_tokens
         WHERE token = ? AND expires_at > NOW() LIMIT 1'
    );
    if ($stmt) {
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $rtRow = $stmt->get_result()->fetch_assoc();

        if ($rtRow && $rtRow['used_at'] === null && $rtRow['revoked_at'] === null) {
            // Token valid — auto-login this user
            $uid = (int)$rtRow['user_id'];
            $userStmt = $conn->prepare('SELECT id, email, name, role, status FROM users WHERE id = ? AND status IN (?, ?) LIMIT 1');
            $active = 'active'; $pending = 'pending_approval';
            if ($userStmt) {
                $userStmt->bind_param('iss', $uid, $active, $pending);
                $userStmt->execute();
                $user = $userStmt->get_result()->fetch_assoc();

                if ($user) {
                    $sessionToken = bin2hex(random_bytes(32));
                    $deviceFingerprint = generate_device_fingerprint();
                    $clientIp = get_client_ip();
                    $userAgent = get_user_agent();
                    $now = date('Y-m-d H:i:s');

                    // Update users table
                    $upd = $conn->prepare(
                        'UPDATE users SET session_token = ?, session_fingerprint = ?,
                          session_ip = ?, session_user_agent = ?, session_created_at = ? WHERE id = ?'
                    );
                    if ($upd) {
                        $upd->bind_param('issssi', $sessionToken, $deviceFingerprint, $clientIp, $userAgent, $now, $uid);
                        $upd->execute();
                    }

                    // Mark remember token as used
                    $used = $conn->prepare('UPDATE remember_tokens SET used_at = NOW() WHERE id = ?');
                    if ($used) {
                        $used->bind_param('i', $rtRow['id']);
                        $used->execute();
                    }

                    // Create session
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['session_token'] = $sessionToken;
                    $_SESSION['device_fingerprint'] = $deviceFingerprint;
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['user_status'] = $user['status'];
                    $_SESSION['last_activity'] = time();

                    log_session_activity($conn, $uid, 'login', 'via_remember_token');
                }
            }
        }
    }
}

define('SESSION_TIMEOUT', 1800); // 30 minutes

$page = $_GET['page'] ?? (is_logged_in() ? 'impact' : 'landing');

$publicPages  = ['login', 'register', 'auth', 'forgot', 'reset', 'verify', 'unsubscribe', 'landing'];
$allowedPages = ['login', 'register', 'auth', 'forgot', 'reset', 'verify', 'unsubscribe', 'logout', 'landing',
                 'researchers', 'funding', 'funders', 'matching', 'search', 'institutions',
                 'messages', 'admin', 'api', 'profile', 'account', 'impact'];

// Inactivity timeout — kick idle sessions after SESSION_TIMEOUT seconds
if (is_logged_in()) {
    if (is_session_inactive(SESSION_TIMEOUT)) {
        expire_session('Your session expired. Please log in again.');
        header('Location: index.php?page=login');
        ob_end_clean();
        exit;
    }
    update_last_activity();
}

// Account Lifecycle — Live status check (catches admin deactivation/deletion of active sessions)
if (is_logged_in()) {
    if (!check_session_validity($conn)) {
        expire_session('Your account access has been changed. Please contact an administrator.');
        header('Location: index.php?page=login');
        ob_end_clean();
        exit;
    }
}

// Device fingerprinting/anomaly detection disabled on load-balanced deployments
// (Kept for future implementation in stable network environments)

// Lightweight JSON endpoint — returns unread message count for live polling
if ($page === 'ping' && is_logged_in()) {
    $em = $_SESSION['user_email'] ?? '';
    // Match the inbox query: count root messages that are either received or sent with replies
    $q  = $conn->prepare(
        "SELECT COUNT(DISTINCT m.id) c FROM messages m
         WHERE (m.thread_id = m.id OR m.thread_id IS NULL)
           AND m.is_read = 0
           AND m.is_deleted = 0
           AND (
             (m.sender_email != ? AND (m.recipient_type = 'network' OR m.recipient_email = ?))
             OR
             (m.sender_email = ? AND EXISTS (SELECT 1 FROM messages r WHERE r.thread_id = m.id AND r.id != m.id AND r.sender_email != ? AND r.is_deleted = 0))
           )"
    );
    $q->bind_param('ssss', $em, $em, $em, $em); $q->execute();
    $cnt = (int)($q->get_result()->fetch_assoc()['c'] ?? 0);
    header('Content-Type: application/json');
    echo json_encode(['unread' => $cnt]);
    ob_end_flush();
    exit;
}

// ── JSON API layer — bypasses layout entirely ──────────────────────
if ($page === 'api') {
    if (!is_logged_in()) {
        header('Content-Type: application/json', true, 401);
        echo json_encode(['error' => 'unauthenticated']);
        ob_end_clean();
        exit;
    }
    // POST requests must carry CSRF (GET requests are read-only, no CSRF needed)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf()) {
            header('Content-Type: application/json', true, 403);
            echo json_encode(['error' => 'csrf_invalid']);
            ob_end_clean();
            exit;
        }
    }
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    include __DIR__ . '/../app/views/api/index.php';
    ob_end_flush();
    exit;
}

// Allow researcher/funder registration (mode=add) without login
$isResearcherRegistration = ($page === 'researchers' &&
    (($_GET['mode'] === 'add' || $_POST['mode'] === 'add') && !is_logged_in()));
$isFunderRegistration = ($page === 'funders' &&
    (($_GET['mode'] === 'add' || $_POST['mode'] === 'add') && !is_logged_in()));

if (!in_array($page, $publicPages, true) && !is_logged_in() && !$isResearcherRegistration && !$isFunderRegistration) {
    // Preserve intended destination so we can redirect back after login
    $_SESSION['login_return'] = http_build_query($_GET);
    redirect_to('login');
}

if (!in_array($page, $allowedPages, true)) {
    $page = is_logged_in() ? 'researchers' : 'login';
}

// Prevent browsers from caching authenticated pages
if (!in_array($page, $publicPages, true)) {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}

if ($page === 'logout') {
    secure_logout();
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Location: index.php?page=landing');
    ob_end_clean();
    exit;
}

// ── CSRF validation — every POST must carry a valid token ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Skip CSRF check for public pages (login, register, etc.) — users don't have sessions yet
    // Also skip for unauthenticated researcher registration
    $skipCsrf = in_array($page, $publicPages, true)
        || ($page === 'researchers' && !is_logged_in() && ($_POST['mode'] ?? '') === 'add');

    if (!$skipCsrf && !is_csrf_valid()) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json', true, 403);
            echo json_encode(['error' => 'csrf_invalid']);
            ob_end_clean();
            exit;
        }
        set_flash('error', 'Your session expired or the request was invalid. Please try again.');
        header('Location: index.php?page=' . urlencode($page));
        ob_end_clean();
        exit;
    }
}

$flash = get_flash();
if ($page === 'login') {
    error_log("[LOGIN POST] About to include login view");
}
include __DIR__ . '/../app/views/layout/header.php';
$viewFile = __DIR__ . '/../app/views/' . $page . '/index.php';
if (file_exists($viewFile)) {
    include $viewFile;
}
include __DIR__ . '/../app/views/layout/footer.php';

ob_end_flush();

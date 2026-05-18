<?php
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
header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\'; style-src \'self\' \'unsafe-inline\'; img-src \'self\' data: https:', true);

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

// Initialize sessions in HTTP context (not CLI)
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

require_once __DIR__ . '/../app/core/helpers.php';
require_once __DIR__ . '/../app/core/mailer.php';
require_once __DIR__ . '/../app/services/RateLimiter.php';
require_once __DIR__ . '/../app/core/schema_updates.php';

// Apply schema updates safely
try {
    apply_security_schema_updates($conn);
} catch (Throwable $e) {
    error_log('[Schema Updates Error] ' . $e->getMessage());
    // Continue anyway - schema might already be in place
}

define('SESSION_TIMEOUT', 1800); // 30 minutes

$page = $_GET['page'] ?? (is_logged_in() ? 'researchers' : 'login');

$publicPages  = ['login', 'register', 'auth', 'forgot', 'reset', 'verify', 'unsubscribe'];
$allowedPages = ['login', 'register', 'auth', 'forgot', 'reset', 'verify', 'unsubscribe', 'logout',
                 'researchers', 'funding', 'funders', 'matching', 'search', 'institutions',
                 'messages', 'admin', 'api', 'profile', 'account'];

// Inactivity timeout — kick idle sessions after SESSION_TIMEOUT seconds
if (is_logged_in()) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        session_start();
        session_regenerate_id(true);
        set_flash('error', 'Your session expired. Please log in again.');
        header('Location: index.php?page=login');
        ob_end_clean();
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// Account Lifecycle — Live status check (catches admin deactivation/deletion of active sessions)
if (is_logged_in()) {
    $uid = (int)$_SESSION['user_id'];
    $tok = $_SESSION['session_token'] ?? null;
    $sq  = $conn->prepare('SELECT status, deleted_at, session_token FROM users WHERE id = ? LIMIT 1');
    $sq->bind_param('i', $uid);
    $sq->execute();
    $sv = $sq->get_result()->fetch_assoc();
    $valid = $sv
        && $sv['status'] === 'active'
        && $sv['deleted_at'] === null
        && ($tok === null || $sv['session_token'] === $tok);
    if (!$valid) {
        session_unset(); session_destroy();
        session_start(); session_regenerate_id(true);
        set_flash('error', 'Your account access has been changed. Please contact an administrator.');
        header('Location: index.php?page=login');
        ob_end_clean(); exit;
    }
}

// Lightweight JSON endpoint — returns unread message count for live polling
if ($page === 'ping' && is_logged_in()) {
    $em = $_SESSION['user_email'] ?? '';
    $q  = $conn->prepare("SELECT COUNT(*) c FROM messages WHERE sender_email != ? AND is_read = 0 AND is_deleted = 0 AND (recipient_type = 'network' OR recipient_email = ?)");
    $q->bind_param('ss', $em, $em); $q->execute();
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
    $oldCookieParams = session_get_cookie_params();
    session_unset();
    session_destroy();
    // Expire the session cookie in the browser
    setcookie(session_name(), '', time() - 3600, $oldCookieParams['path']);
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Location: index.php?page=login');
    ob_end_clean();
    exit;
}

// ── CSRF validation — every POST must carry a valid token ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($page === 'login') {
        error_log("[LOGIN POST] Page is login, publicPages check: " . (in_array($page, $publicPages, true) ? 'YES' : 'NO'));
    }
    // Skip CSRF check for public pages (login, register, etc.) — users don't have sessions yet
    if (!in_array($page, $publicPages, true)) {
        if (!verify_csrf()) {
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
}

$flash = get_flash();
if ($page === 'login') {
    error_log("[LOGIN POST] About to include login view");
}
include __DIR__ . '/../app/views/layout/header.php';
include __DIR__ . '/../app/views/' . $page . '/index.php';
include __DIR__ . '/../app/views/layout/footer.php';

ob_end_flush();

<?php
/**
 * Session Manager — Centralized session initialization and management.
 * All entry points (public/index.php, AJAX endpoints, etc.) should call init_session()
 * before accessing $_SESSION or calling authentication functions.
 */

/**
 * Initialize session with secure cookie parameters.
 * Call this in any PHP script that needs $_SESSION access.
 * Safe to call multiple times — checks session_status() first.
 */
function init_session(): void {
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,       // Browser cookie (expires when browser closes)
            'path'     => '/',     // Available on entire domain
            'secure'   => false,   // Set to true in production with HTTPS
            'httponly' => true,    // Prevent JavaScript access
            'samesite' => 'Lax',   // Allow same-site AJAX requests (Strict breaks fetch)
        ]);
        session_start();
    }
}

/**
 * Secure logout — destroys session and clears browser cookie.
 */
function secure_logout(): void {
    $oldCookieParams = session_get_cookie_params();
    session_unset();
    session_destroy();
    // Expire the session cookie in the browser
    setcookie(session_name(), '', time() - 3600, $oldCookieParams['path']);
}

/**
 * Expire current session due to inactivity/admin action/etc.
 * Creates flash message before destroying session.
 */
function expire_session(string $reason = 'Your session expired. Please log in again.'): void {
    init_session();
    set_flash('error', $reason);
    secure_logout();
    session_start();
    session_regenerate_id(true);
}

/**
 * Check if CSRF token is valid for a request.
 * Accepts token from:
 *   - POST body: $_POST['_csrf']
 *   - HTTP header: X-CSRF-TOKEN (for AJAX/JSON requests)
 */
function is_csrf_valid(): bool {
    init_session();
    $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return !empty($_SESSION['csrf_token'])
        && $token !== ''
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Get current CSRF token, generating one if needed.
 */
function get_csrf_token(): string {
    init_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Check if user is logged in and session is valid.
 * Does NOT check database status — use check_session_validity() for that.
 */
function is_user_logged_in(): bool {
    init_session();
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_email']);
}

/**
 * Verify session is still valid according to database.
 * Checks: user status, deleted_at, session_token revocation.
 * Call this on protected pages and AJAX endpoints.
 *
 * Returns true if valid, false if session should be invalidated.
 */
function check_session_validity(mysqli $conn): bool {
    if (!is_user_logged_in()) {
        return false;
    }

    $uid = (int)($_SESSION['user_id'] ?? 0);
    $tok = $_SESSION['session_token'] ?? null;

    if (!$uid) {
        return false;
    }

    $stmt = $conn->prepare('SELECT status, deleted_at, session_token FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        return false; // User deleted
    }

    // Validate status and lifecycle
    $valid = $row['status'] === 'active'
        && $row['deleted_at'] === null
        && ($tok === null || $row['session_token'] === $tok);

    return $valid;
}

/**
 * Update last activity timestamp to track session timeout.
 */
function update_last_activity(): void {
    init_session();
    if (is_user_logged_in()) {
        $_SESSION['last_activity'] = time();
    }
}

/**
 * Check for session inactivity timeout.
 * Returns true if session has expired due to inactivity.
 */
function is_session_inactive(int $timeoutSeconds = 1800): bool {
    init_session();
    if (!is_user_logged_in()) {
        return false;
    }

    if (!isset($_SESSION['last_activity'])) {
        $_SESSION['last_activity'] = time();
        return false;
    }

    return (time() - $_SESSION['last_activity']) > $timeoutSeconds;
}

/**
 * Regenerate session ID after privilege change.
 * Prevents session fixation attacks.
 */
function regenerate_session_id(): void {
    if (session_status() !== PHP_SESSION_NONE) {
        session_regenerate_id(true);
    }
}
?>

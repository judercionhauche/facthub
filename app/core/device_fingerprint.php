<?php
/**
 * Device Fingerprinting — Generate consistent fingerprints from User-Agent and IP
 * Prevents session reuse across different devices/browsers
 */

/**
 * Generate a fingerprint from current request headers.
 * Hash includes: User-Agent + IP + selective HTTP headers
 * Result is deterministic for the same device
 */
function generate_device_fingerprint(): string {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $ip = get_client_ip();

    // Accept-Language helps distinguish regional differences
    $lang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'unknown';

    $raw = $ua . '|' . $ip . '|' . $lang;
    return bin2hex(hash('sha256', $raw, true));
}

/**
 * Get IP address, handling proxies.
 * Check HTTP_X_FORWARDED_FOR first (if behind load balancer),
 * then REMOTE_ADDR (direct connection).
 */
function get_client_ip(): string {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Extract first IP (client IP is leftmost)
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Get User-Agent string (limited to 255 chars for storage).
 */
function get_user_agent(): string {
    return substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
}

/**
 * Check if current device fingerprint matches stored session fingerprint.
 * Returns: 'match' | 'mismatch' | 'new_device'
 */
function check_device_match(string $storedFingerprint = null): string {
    $current = generate_device_fingerprint();

    if ($storedFingerprint === null) {
        return 'new_device';
    }

    return ($current === $storedFingerprint) ? 'match' : 'mismatch';
}

/**
 * Log session activity for anomaly detection.
 */
function log_session_activity(mysqli $conn, int $userId, string $action, ?string $reason = null): void {
    $ip = get_client_ip();
    $ua = get_user_agent();
    $fp = generate_device_fingerprint();

    $stmt = $conn->prepare(
        'INSERT INTO session_activity (user_id, action, ip_address, user_agent, device_fingerprint, suspicious_reason)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    @$stmt->bind_param('isssss', $userId, $action, $ip, $ua, $fp, $reason);
    @$stmt->execute();
}
?>

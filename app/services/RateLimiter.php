<?php
/**
 * RateLimiter — prevent brute force attacks on critical endpoints.
 * Tracks attempts per key (login_IP, password_reset_EMAIL, search_USERID, etc.)
 */
class RateLimiter {
    private mysqli $conn;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    /**
     * Check if action is allowed within rate limit.
     * Returns false if limit exceeded, true if action is allowed.
     * Automatically logs the attempt.
     */
    public function check(string $key, int $maxAttempts, int $windowSeconds): bool {
        $now = time();
        $expiryTime = $now - $windowSeconds;

        // Clean up old entries periodically
        if (rand(1, 100) === 1) {  // 1% of calls
            $stmt = $this->conn->prepare('DELETE FROM rate_limits WHERE created_at < FROM_UNIXTIME(?)');
            $stmt->bind_param('i', $expiryTime);
            $stmt->execute();
        }

        // Count recent attempts for this key
        $stmt = $this->conn->prepare('SELECT COUNT(*) c FROM rate_limits WHERE `key` = ? AND created_at > FROM_UNIXTIME(?)');
        $stmt->bind_param('si', $key, $expiryTime);
        $stmt->execute();
        $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);

        // If limit exceeded, don't log this attempt (prevent timing attacks)
        if ($count >= $maxAttempts) {
            return false;
        }

        // Log this attempt
        $stmt = $this->conn->prepare('INSERT INTO rate_limits (`key`, created_at) VALUES (?, NOW())');
        $stmt->bind_param('s', $key);
        $stmt->execute();

        return true;
    }

    /**
     * Get remaining attempts before limit is hit.
     */
    public function getRemaining(string $key, int $maxAttempts, int $windowSeconds): int {
        $now = time();
        $expiryTime = $now - $windowSeconds;

        $stmt = $this->conn->prepare('SELECT COUNT(*) c FROM rate_limits WHERE `key` = ? AND created_at > FROM_UNIXTIME(?)');
        $stmt->bind_param('si', $key, $expiryTime);
        $stmt->execute();
        $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);

        return max(0, $maxAttempts - $count);
    }

    /**
     * Reset rate limit for a key (e.g., after successful auth).
     */
    public function reset(string $key): void {
        $stmt = $this->conn->prepare('DELETE FROM rate_limits WHERE `key` = ?');
        $stmt->bind_param('s', $key);
        $stmt->execute();
    }
}
?>

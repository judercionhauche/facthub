<?php
/**
 * Newsletter Management Helpers
 * Functions for managing newsletter subscriptions, preferences, and token generation
 */

/**
 * Generate a secure token for newsletter unsubscribe links
 * Tokens are HMAC-SHA256 hashed and tied to email address
 */
function generate_newsletter_unsubscribe_token(string $email, string $secret): string {
    return bin2hex(hash_hmac('sha256', strtolower(trim($email)) . '|newsletter_unsubscribe', $secret, true));
}

/**
 * Generate a secure token for newsletter preference management links
 * Allows non-logged-in users to manage preferences via email link
 */
function generate_newsletter_prefs_token(string $email, string $secret): string {
    return bin2hex(hash_hmac('sha256', strtolower(trim($email)) . '|newsletter_prefs', $secret, true));
}

/**
 * Verify a newsletter token against an email address
 * Returns true if token is valid and matches the email
 */
function verify_newsletter_token(string $email, string $token, string $secret, string $type = 'unsubscribe'): bool {
    if (empty($email) || empty($token) || empty($secret)) {
        return false;
    }

    $email = strtolower(trim($email));
    $suffix = $type === 'prefs' ? '|newsletter_prefs' : '|newsletter_unsubscribe';
    $expected = bin2hex(hash_hmac('sha256', $email . $suffix, $secret, true));

    return hash_equals($expected, $token);
}

/**
 * Get or create a newsletter subscription record
 * Returns subscription data, creating a default record if it doesn't exist
 */
function get_or_create_subscription($conn, string $email, string $role = 'both'): ?array {
    $email = strtolower(trim($email));

    // Try to fetch existing subscription
    $stmt = $conn->prepare("SELECT * FROM newsletter_subscribers WHERE email = ? LIMIT 1");
    if (!$stmt) {
        error_log('Prepare failed: ' . $conn->error);
        return null;
    }

    $stmt->bind_param('s', $email);
    if (!$stmt->execute()) {
        error_log('Execute failed: ' . $stmt->error);
        return null;
    }

    $subscription = $stmt->get_result()->fetch_assoc();

    // If subscription exists, return it
    if ($subscription) {
        return $subscription;
    }

    // Create new subscription with default values
    $stmt = $conn->prepare(
        "INSERT INTO newsletter_subscribers (email, status, role, frequency) VALUES (?, 'active', ?, 'weekly')"
    );
    if (!$stmt) {
        error_log('Prepare failed: ' . $conn->error);
        return null;
    }

    $stmt->bind_param('ss', $email, $role);
    if (!$stmt->execute()) {
        error_log('Execute failed: ' . $stmt->error);
        return null;
    }

    // Fetch and return the newly created subscription
    $stmt = $conn->prepare("SELECT * FROM newsletter_subscribers WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

/**
 * Update subscription status
 * Valid statuses: 'active', 'unsubscribed', 'bounced'
 */
function update_subscription_status($conn, string $email, string $status): bool {
    if (!in_array($status, ['active', 'unsubscribed', 'bounced'], true)) {
        return false;
    }

    $email = strtolower(trim($email));
    $stmt = $conn->prepare(
        "UPDATE newsletter_subscribers SET status = ?, updated_at = NOW()"
        . ($status === 'unsubscribed' ? ", unsubscribed_at = NOW()" : "")
        . " WHERE email = ?"
    );

    if (!$stmt) {
        error_log('Prepare failed: ' . $conn->error);
        return false;
    }

    $stmt->bind_param('ss', $status, $email);
    return $stmt->execute();
}

/**
 * Update email frequency preference
 * Valid frequencies: 'immediate', 'daily', 'weekly', 'never'
 */
function update_subscription_frequency($conn, string $email, string $frequency): bool {
    if (!in_array($frequency, ['immediate', 'daily', 'weekly', 'never'], true)) {
        return false;
    }

    $email = strtolower(trim($email));
    $stmt = $conn->prepare(
        "UPDATE newsletter_subscribers SET frequency = ?, updated_at = NOW() WHERE email = ?"
    );

    if (!$stmt) {
        error_log('Prepare failed: ' . $conn->error);
        return false;
    }

    $stmt->bind_param('ss', $frequency, $email);
    return $stmt->execute();
}

/**
 * Update content preferences
 * Stores topics, geography, institutions, and funding preferences
 */
function update_content_preferences($conn, string $email, array $preferences): bool {
    $email = strtolower(trim($email));

    $updates = [];
    $types = '';
    $params = [];

    $allowed_fields = ['research_interests', 'geography', 'institution', 'funding_preference'];

    foreach ($allowed_fields as $field) {
        if (isset($preferences[$field])) {
            $value = trim($preferences[$field]);
            $updates[] = "$field = ?";
            $params[] = $value;
            $types .= 's';
        }
    }

    if (empty($updates)) {
        return true; // No updates to make is not an error
    }

    // Add updated_at timestamp
    $updates[] = "updated_at = NOW()";
    $types .= 's';
    $params[] = $email;

    $sql = 'UPDATE newsletter_subscribers SET ' . implode(', ', $updates) . " WHERE email = ?";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log('Prepare failed: ' . $conn->error);
        return false;
    }

    $stmt->bind_param($types, ...$params);
    return $stmt->execute();
}

/**
 * Get subscribers matching frequency window
 * Used for batch email sending operations
 */
function get_subscribers_for_sending($conn, string $frequency): array {
    $stmt = $conn->prepare(
        "SELECT * FROM newsletter_subscribers WHERE status = 'active' AND frequency = ? ORDER BY email ASC"
    );

    if (!$stmt) {
        error_log('Prepare failed: ' . $conn->error);
        return [];
    }

    $stmt->bind_param('s', $frequency);
    if (!$stmt->execute()) {
        error_log('Execute failed: ' . $stmt->error);
        return [];
    }

    $result = $stmt->get_result();
    $subscribers = [];

    while ($row = $result->fetch_assoc()) {
        $subscribers[] = $row;
    }

    return $subscribers;
}

/**
 * Count active subscribers
 */
function count_active_subscribers($conn): int {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM newsletter_subscribers WHERE status = 'active'");
    if (!$stmt || !$stmt->execute()) {
        return 0;
    }

    $result = $stmt->get_result()->fetch_assoc();
    return (int)($result['count'] ?? 0);
}

/**
 * Get subscription statistics
 * Returns breakdown by status and frequency
 */
function get_subscription_stats($conn): array {
    $stats = [];

    // By status
    $stmt = $conn->prepare(
        "SELECT status, COUNT(*) as count FROM newsletter_subscribers GROUP BY status"
    );
    if ($stmt && $stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $stats['by_status'][$row['status']] = (int)$row['count'];
        }
    }

    // By frequency
    $stmt = $conn->prepare(
        "SELECT frequency, COUNT(*) as count FROM newsletter_subscribers WHERE status = 'active' GROUP BY frequency"
    );
    if ($stmt && $stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $stats['by_frequency'][$row['frequency']] = (int)$row['count'];
        }
    }

    // Total subscribers
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM newsletter_subscribers");
    if ($stmt && $stmt->execute()) {
        $result = $stmt->get_result()->fetch_assoc();
        $stats['total'] = (int)($result['count'] ?? 0);
    }

    return $stats;
}

/**
 * Delete inactive/bounced subscribers after retention period
 * (Optional: implement for data cleanup)
 */
function cleanup_inactive_subscribers($conn, int $days = 365): int {
    $cutoff_date = date('Y-m-d H:i:s', strtotime("-$days days"));

    $stmt = $conn->prepare(
        "DELETE FROM newsletter_subscribers WHERE status = 'bounced' AND unsubscribed_at < ?"
    );

    if (!$stmt) {
        error_log('Prepare failed: ' . $conn->error);
        return 0;
    }

    $stmt->bind_param('s', $cutoff_date);
    if ($stmt->execute()) {
        return $stmt->affected_rows;
    }

    error_log('Execute failed: ' . $stmt->error);
    return 0;
}

/**
 * Export subscribers matching criteria
 * Used for newsletter sending services
 */
function export_subscribers($conn, array $criteria = []): array {
    $sql = "SELECT email, research_interests, geography, institution, funding_preference, frequency FROM newsletter_subscribers WHERE status = 'active'";
    $types = '';
    $params = [];

    if (!empty($criteria['frequency'])) {
        $sql .= " AND frequency = ?";
        $types .= 's';
        $params[] = $criteria['frequency'];
    }

    $sql .= " ORDER BY email ASC";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log('Prepare failed: ' . $conn->error);
        return [];
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        error_log('Execute failed: ' . $stmt->error);
        return [];
    }

    $result = $stmt->get_result();
    $subscribers = [];

    while ($row = $result->fetch_assoc()) {
        $subscribers[] = $row;
    }

    return $subscribers;
}

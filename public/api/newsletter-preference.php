<?php
/**
 * Newsletter Preference API
 * Handles subscription/unsubscription preference updates for authenticated users
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../app/core/db.php';
require_once __DIR__ . '/../../app/core/helpers.php';
require_once __DIR__ . '/../../app/core/session_manager.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Require authentication
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Validate CSRF token
if (!is_csrf_valid()) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

try {
    $user = current_user();
    $subscribed = isset($_POST['newsletter_subscribed']) ? (bool)$_POST['newsletter_subscribed'] : false;

    // Update or insert newsletter subscriber
    if ($subscribed) {
        // Try with user_id first (new schema)
        $stmt = $conn->prepare("
            INSERT INTO newsletter_subscribers (user_id, status, subscribed_at)
            VALUES (?, 'active', NOW())
            ON DUPLICATE KEY UPDATE
                status = 'active',
                updated_at = NOW()
        ");

        if (!$stmt) {
            // Fallback: try with email (old schema) if user_id fails
            $stmt = $conn->prepare("
                INSERT INTO newsletter_subscribers (email, status, subscribed_at)
                VALUES (?, 'active', NOW())
                ON DUPLICATE KEY UPDATE
                    status = 'active',
                    updated_at = NOW()
            ");
            $stmt->bind_param('s', $user['email']);
        } else {
            $stmt->bind_param('i', $user['id']);
        }

        if (!$stmt->execute()) {
            throw new Exception('Subscribe failed: ' . $conn->error);
        }

        $message = 'You have been subscribed to our newsletter';
    } else {
        // Try to unsubscribe by user_id or email
        $stmt = $conn->prepare("
            UPDATE newsletter_subscribers
            SET status = 'unsubscribed', unsubscribed_at = NOW()
            WHERE user_id = ? OR email = ?
        ");
        $stmt->bind_param('is', $user['id'], $user['email']);

        if (!$stmt->execute()) {
            throw new Exception('Unsubscribe failed: ' . $conn->error);
        }

        $message = 'You have been unsubscribed from our newsletter';
    }

    // Log the action
    @audit($conn, 'newsletter_preference_change', [
        'type' => 'user',
        'id' => $user['id'],
        'email' => $user['email'],
        'detail' => $subscribed ? 'Subscribed to newsletter' : 'Unsubscribed from newsletter'
    ]);

    echo json_encode([
        'success' => true,
        'message' => $message,
        'subscribed' => $subscribed
    ]);

} catch (Exception $e) {
    error_log('[Newsletter API] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>

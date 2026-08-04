<?php
/**
 * Email suppression list.
 *
 * Addresses that hard-bounced or filed a spam complaint are recorded here so
 * the Hub never emails them again. Populated by public/ses_webhook.php (from
 * Amazon SNS bounce/complaint notifications) and checked before every send
 * that has a DB handle available.
 *
 * Note: Amazon SES also maintains its own account-level suppression list and
 * will refuse delivery to known bounces/complaints regardless — this table is
 * the Hub's local mirror so we can skip the send entirely and surface status.
 */

function apply_suppression_schema(mysqli $conn): void {
    $exists = @$conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME='email_suppressions' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
    if (!$exists || $exists->num_rows === 0) {
        @$conn->query("
            CREATE TABLE IF NOT EXISTS email_suppressions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                reason VARCHAR(32) NOT NULL,          -- bounce | complaint | manual
                detail VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_email (email),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

/** True if this address must not be emailed. */
function email_is_suppressed(mysqli $conn, string $email): bool {
    $norm = strtolower(trim($email));
    if ($norm === '') return false;
    $stmt = $conn->prepare('SELECT 1 FROM email_suppressions WHERE email = ? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('s', $norm);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_row();
}

/** Record (or refresh) a suppressed address. Idempotent. */
function email_add_suppression(mysqli $conn, string $email, string $reason, string $detail = ''): void {
    $norm = strtolower(trim($email));
    if ($norm === '' || !filter_var($norm, FILTER_VALIDATE_EMAIL)) return;
    $reason = in_array($reason, ['bounce', 'complaint', 'manual'], true) ? $reason : 'manual';
    $detail = mb_substr($detail, 0, 255);
    $stmt = $conn->prepare(
        'INSERT INTO email_suppressions (email, reason, detail) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE reason = VALUES(reason), detail = VALUES(detail)'
    );
    if (!$stmt) return;
    $stmt->bind_param('sss', $norm, $reason, $detail);
    $stmt->execute();

    // Mirror to newsletter subscriber status when present (best-effort).
    $upd = $conn->prepare("UPDATE newsletter_subscribers SET status='bounced' WHERE email = ?");
    if ($upd) { $upd->bind_param('s', $norm); @$upd->execute(); }
}

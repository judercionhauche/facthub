<?php
/**
 * Amazon SES → SNS bounce / complaint webhook.
 *
 * Wire an SNS topic to SES (Bounce + Complaint notifications), then add an
 * HTTPS subscription pointing at:  https://<your-domain>/public/ses_webhook.php
 *
 * On first delivery SNS sends a SubscriptionConfirmation, which this endpoint
 * auto-confirms. Thereafter it verifies each message's signature, and for
 * permanent bounces / complaints it records the address in email_suppressions
 * so the Hub never emails it again.
 *
 * Requires a PUBLIC HTTPS URL — SNS cannot reach localhost.
 */

header('Content-Type: text/plain');

require_once __DIR__ . '/../app/core/sns.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST only');
}

$raw = file_get_contents('php://input');
$msg = json_decode($raw, true);
if (!is_array($msg) || empty($msg['Type'])) {
    http_response_code(400);
    error_log('[SES webhook] Malformed body');
    exit('bad request');
}

// ── Verify the SNS signature (prevents forged bounce/complaint posts) ──
if (!sns_signature_valid($msg)) {
    http_response_code(403);
    error_log('[SES webhook] Signature verification FAILED for MessageId ' . ($msg['MessageId'] ?? '?'));
    exit('invalid signature');
}

// ── Bootstrap DB + suppression helpers ──
$dbConfig = require __DIR__ . '/../config/database.php';
$conn = new mysqli($dbConfig['db_host'], $dbConfig['db_user'], $dbConfig['db_pass'], $dbConfig['db_name']);
if ($conn->connect_error) {
    http_response_code(500);
    error_log('[SES webhook] DB connection failed');
    exit('db error');
}
$conn->set_charset('utf8mb4');
require_once __DIR__ . '/../app/core/suppression.php';
apply_suppression_schema($conn);

$type = $msg['Type'];

// ── 1. Confirm subscription automatically ──
if ($type === 'SubscriptionConfirmation' || $type === 'UnsubscribeConfirmation') {
    $subUrl = $msg['SubscribeURL'] ?? '';
    if (sns_url_is_aws($subUrl)) {
        $ch = curl_init($subUrl);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        curl_exec($ch);
        curl_close($ch);
        error_log('[SES webhook] Subscription ' . $type . ' confirmed for topic ' . ($msg['TopicArn'] ?? '?'));
    }
    http_response_code(200);
    exit('ok');
}

// ── 2. Process bounce / complaint notifications ──
if ($type === 'Notification') {
    $inner = json_decode($msg['Message'] ?? '', true);
    $kind  = is_array($inner) ? ($inner['notificationType'] ?? $inner['eventType'] ?? '') : '';

    if ($kind === 'Bounce') {
        $bounce = $inner['bounce'] ?? [];
        // Only permanent (hard) bounces are suppressed; transient ones may recover.
        if (($bounce['bounceType'] ?? '') === 'Permanent') {
            foreach ($bounce['bouncedRecipients'] ?? [] as $r) {
                if (!empty($r['emailAddress'])) {
                    email_add_suppression($conn, $r['emailAddress'], 'bounce',
                        ($bounce['bounceSubType'] ?? '') . ' ' . ($r['diagnosticCode'] ?? ''));
                    error_log('[SES webhook] Suppressed (bounce): ' . $r['emailAddress']);
                }
            }
        }
    } elseif ($kind === 'Complaint') {
        $complaint = $inner['complaint'] ?? [];
        foreach ($complaint['complainedRecipients'] ?? [] as $r) {
            if (!empty($r['emailAddress'])) {
                email_add_suppression($conn, $r['emailAddress'], 'complaint',
                    $complaint['complaintFeedbackType'] ?? '');
                error_log('[SES webhook] Suppressed (complaint): ' . $r['emailAddress']);
            }
        }
    }

    http_response_code(200);
    exit('ok');
}

http_response_code(200);
exit('ignored');

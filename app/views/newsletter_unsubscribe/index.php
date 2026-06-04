<?php
// Public Newsletter Unsubscribe Page
// Allows one-click unsubscribe from newsletter emails via secure token link
require_once __DIR__ . '/../../core/helpers.php';

$email = strtolower(trim($_GET['e'] ?? ''));
$token = trim($_GET['t'] ?? '');
$state = 'invalid'; // invalid | already | done

if ($email !== '' && $token !== '') {
    $mailCfg = require __DIR__ . '/../../../config/mail.php';
    $notifySecret = $mailCfg['notify_secret'] ?? '';
    $expected = bin2hex(hash_hmac('sha256', $email . '|newsletter_unsubscribe', $notifySecret, true));

    if (hash_equals($expected, $token)) {
        // Check if subscriber exists
        $q = $conn->prepare("SELECT id, status FROM newsletter_subscribers WHERE email = ? LIMIT 1");
        $q->bind_param('s', $email);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();

        if ($row) {
            if ($row['status'] === 'unsubscribed') {
                $state = 'already';
            } else {
                // Unsubscribe the user
                $upd = $conn->prepare("UPDATE newsletter_subscribers SET status = 'unsubscribed', unsubscribed_at = NOW() WHERE email = ?");
                $upd->bind_param('s', $email);
                if ($upd->execute()) {
                    $state = 'done';
                    audit($conn, 'newsletter_unsubscribe', ['email' => $email]);
                }
            }
        }
    }
}
?>

<style>
.unsub-card { max-width: 440px; margin: 0 auto; padding: 44px 38px; text-align: center; }
.unsub-icon { width: 54px; height: 54px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; }
.unsub-icon svg { width: 100%; height: 100%; }
</style>

<div class="unsub-card panel">
<?php if ($state === 'done'): ?>

    <div class="unsub-icon" style="background: #eaf6f0">
        <svg viewBox="0 0 24 24" fill="none" stroke="#1a6b5a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="20 6 9 17 4 12"></polyline>
        </svg>
    </div>
    <h1 style="font-size: 20px; font-weight: 800; color: #111; margin: 0 0 10px">Unsubscribed</h1>
    <p style="font-size: 14px; color: #666; line-height: 1.65; margin: 0 0 24px">
        You've been successfully unsubscribed from newsletter emails. You won't receive any more messages from us.
    </p>
    <p style="font-size: 13px; color: #9aaba4; margin: 0 0 24px">
        Changed your mind? You can re-enable your subscription anytime by logging in to your account and updating your preferences.
    </p>
    <a class="ghost-btn" href="index.php?page=login" style="display: inline-block">← Back to sign in</a>

<?php elseif ($state === 'already'): ?>

    <div class="unsub-icon" style="background: #f3f4f6">
        <svg viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="8" x2="12" y2="12"></line>
            <line x1="12" y1="16" x2="12.01" y2="16"></line>
        </svg>
    </div>
    <h1 style="font-size: 20px; font-weight: 800; color: #111; margin: 0 0 10px">Already Unsubscribed</h1>
    <p style="font-size: 14px; color: #666; line-height: 1.65; margin: 0 0 24px">
        This email address is already unsubscribed from our newsletter.
    </p>
    <p style="font-size: 13px; color: #9aaba4; margin: 0 0 24px">
        If you'd like to resubscribe, please log in to your account and update your preferences.
    </p>
    <a class="ghost-btn" href="index.php?page=login" style="display: inline-block">← Back to sign in</a>

<?php else: ?>

    <div class="unsub-icon" style="background: #fee2e2">
        <svg viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="15" y1="9" x2="9" y2="15"></line>
            <line x1="9" y1="9" x2="15" y2="15"></line>
        </svg>
    </div>
    <h1 style="font-size: 20px; font-weight: 800; color: #111; margin: 0 0 10px">Invalid Link</h1>
    <p style="font-size: 14px; color: #666; line-height: 1.65; margin: 0 0 24px">
        This unsubscribe link is invalid or has expired. Please use the link directly from the newsletter email.
    </p>
    <p style="font-size: 13px; color: #9aaba4; margin: 0 0 24px">
        Or, log in to your account to manage your newsletter preferences.
    </p>
    <a class="ghost-btn" href="index.php?page=login" style="display: inline-block">← Back to sign in</a>

<?php endif; ?>
</div>

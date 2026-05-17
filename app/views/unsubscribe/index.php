<?php
$email = strtolower(trim($_GET['e'] ?? ''));
$token = trim($_GET['t'] ?? '');
$state = 'invalid'; // invalid | already | done

if ($email !== '' && $token !== '') {
    $mailCfg      = require __DIR__ . '/../../../config/mail.php';
    $notifySecret = $mailCfg['notify_secret'] ?? '';
    $expected     = generate_unsubscribe_token($email, $notifySecret);

    if (hash_equals($expected, $token)) {
        // Check if researcher exists (active only)
        $q = $conn->prepare("SELECT id, notify_matches FROM researchers WHERE email = ? AND status = 'active' AND deleted_at IS NULL LIMIT 1");
        $q->bind_param('s', $email); $q->execute();
        $row = $q->get_result()->fetch_assoc();

        if ($row) {
            if ((int)$row['notify_matches'] === 0) {
                $state = 'already';
            } else {
                $upd = $conn->prepare("UPDATE researchers SET notify_matches = 0 WHERE email = ?");
                $upd->bind_param('s', $email); $upd->execute();
                $state = 'done';
            }
        }
    }
}
?>
<style>
.unsub-card{max-width:440px;margin:0 auto;padding:44px 38px;text-align:center}
.unsub-icon{width:54px;height:54px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px}
</style>

<div class="unsub-card panel">
<?php if ($state === 'done'): ?>

    <div class="unsub-icon" style="background:#eaf6f0">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#1a6b5a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="20 6 9 17 4 12"/>
        </svg>
    </div>
    <h1 style="font-size:20px;font-weight:800;color:#111;margin:0 0 10px">Unsubscribed</h1>
    <p style="font-size:14px;color:#666;line-height:1.65;margin:0 0 24px">
        You've been removed from funding match notifications. You won't receive these emails anymore.
    </p>
    <p style="font-size:13px;color:#9aaba4;margin:0 0 24px">
        Changed your mind? You can re-enable notifications anytime from your researcher profile settings.
    </p>
    <a class="ghost-btn" href="index.php?page=login" style="display:inline-block">← Back to sign in</a>

<?php elseif ($state === 'already'): ?>

    <div class="unsub-icon" style="background:#f3f4f6">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
    </div>
    <h1 style="font-size:20px;font-weight:800;color:#111;margin:0 0 10px">Already unsubscribed</h1>
    <p style="font-size:14px;color:#666;line-height:1.65;margin:0 0 24px">
        This email address is already opted out of funding match notifications.
    </p>
    <a class="ghost-btn" href="index.php?page=login" style="display:inline-block">← Back to sign in</a>

<?php else: ?>

    <div class="unsub-icon" style="background:#fee2e2">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"/>
            <line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
        </svg>
    </div>
    <h1 style="font-size:20px;font-weight:800;color:#111;margin:0 0 10px">Invalid link</h1>
    <p style="font-size:14px;color:#666;line-height:1.65;margin:0 0 24px">
        This unsubscribe link is invalid or has expired. Please use the link directly from the notification email.
    </p>
    <a class="ghost-btn" href="index.php?page=login" style="display:inline-block">← Back to sign in</a>

<?php endif; ?>
</div>

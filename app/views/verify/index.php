<?php
// States: pending | form | expired | invalid | resent | cooldown
$state        = isset($_GET['pending']) ? 'pending' : 'form';
$prefillEmail = trim($_GET['e'] ?? '');

/* ── Token validation (GET ?token=xxx) ─────────────────────────────── */
if (isset($_GET['token'])) {
    $token = trim($_GET['token']);

    $q = $conn->prepare('SELECT * FROM email_verifications WHERE token = ? LIMIT 1');
    $q->bind_param('s', $token); $q->execute();
    $ev = $q->get_result()->fetch_assoc();

    if (!$ev || $ev['used_at'] !== null) {
        $state = 'invalid';
    } elseif (strtotime($ev['expires_at']) < time()) {
        $prefillEmail = $ev['email'];
        $del = $conn->prepare('DELETE FROM email_verifications WHERE token = ?');
        $del->bind_param('s', $token); $del->execute();
        $state = 'expired';
    } else {
        // Valid — activate account
        $upd = $conn->prepare("UPDATE users SET status = 'active' WHERE email = ? AND status = 'unverified'");
        $upd->bind_param('s', $ev['email']); $upd->execute();
        $now = date('Y-m-d H:i:s');
        $mu  = $conn->prepare('UPDATE email_verifications SET used_at = ? WHERE token = ?');
        $mu->bind_param('ss', $now, $token); $mu->execute();
        set_flash('success', 'Your account has been verified. You can now sign in.');
        redirect_to('login');
    }
}

/* ── Resend POST ────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if ($email === '') {
        $state = 'form';
    } else {
        $uq = $conn->prepare('SELECT name, status FROM users WHERE email = ? LIMIT 1');
        $uq->bind_param('s', $email); $uq->execute();
        $uRow = $uq->get_result()->fetch_assoc();

        if (!$uRow || $uRow['status'] === 'active') {
            $state = 'resent'; // silent — don't reveal if email exists
        } else {
            $rl = $conn->prepare('SELECT last_resent_at, resend_count FROM email_verifications WHERE email = ? LIMIT 1');
            $rl->bind_param('s', $email); $rl->execute();
            $rlRow = $rl->get_result()->fetch_assoc();

            $resendCount = (int)($rlRow['resend_count'] ?? 0);
            $lastResent  = $rlRow['last_resent_at'] ?? null;
            $cooldown    = 60; // seconds

            if ($lastResent && (time() - strtotime($lastResent)) < $cooldown) {
                $state = 'cooldown';
                $prefillEmail = $email;
            } elseif ($resendCount >= 5) {
                $state = 'resent'; // max resends — silent fail
            } else {
                $newToken  = bin2hex(random_bytes(32));
                $expiry    = date('Y-m-d H:i:s', time() + 86400);
                $now       = date('Y-m-d H:i:s');

                if ($rlRow) {
                    $upd = $conn->prepare('UPDATE email_verifications SET token = ?, expires_at = ?, used_at = NULL, last_resent_at = ?, resend_count = resend_count + 1 WHERE email = ?');
                    $upd->bind_param('ssss', $newToken, $expiry, $now, $email); $upd->execute();
                } else {
                    $ins = $conn->prepare('INSERT INTO email_verifications (email, token, expires_at, last_resent_at) VALUES (?, ?, ?, ?)');
                    $ins->bind_param('ssss', $email, $newToken, $expiry, $now); $ins->execute();
                }

                @$mailCfg  = require __DIR__ . '/../../../config/mail.php';
                if (!is_array($mailCfg)) $mailCfg = [];
                $appUrl    = rtrim($mailCfg['app_url'] ?? ('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')), '/');
                $verifyUrl = $appUrl . '/index.php?page=verify&token=' . urlencode($newToken);
                $firstName = explode(' ', trim($uRow['name'] ?: 'there'))[0];
                send_notification_email($email, 'Verify your FACT Alliance Hub account',
                    mail_tpl_verify_email($verifyUrl, $firstName));

                $state = 'resent';
            }
        }
    }
}
?>
<style>
.verify-card{max-width:460px;margin:0 auto;padding:40px 38px;text-align:center}
.verify-icon{width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px}
.verify-icon.teal{background:#eaf6f0}
.verify-icon.amber{background:#fef3c7}
.verify-icon.red{background:#fee2e2}
.verify-icon.blue{background:#dbeafe}
.verify-h{font-size:20px;font-weight:800;color:#111;margin:0 0 10px;letter-spacing:-.02em}
.verify-p{font-size:14px;color:#666;line-height:1.65;margin:0 0 24px}
.verify-email-badge{display:inline-block;background:#f0f4f3;border:1px solid #d8e6e0;border-radius:6px;padding:5px 14px;font-size:13px;font-weight:600;color:#1a6b5a;margin-bottom:24px;word-break:break-all}
.verify-form{text-align:left;margin-top:4px}
.verify-form label{font-size:13px;font-weight:600;color:#374151;display:block;margin-bottom:6px}
.verify-form input{width:100%;box-sizing:border-box;padding:10px 14px;border:1.5px solid #d1d9d5;border-radius:8px;font-size:14px;outline:none;transition:border-color .15s}
.verify-form input:focus{border-color:#1a6b5a}
.verify-btn{display:block;width:100%;margin-top:12px;padding:11px;background:#1a6b5a;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;transition:opacity .15s}
.verify-btn:hover{opacity:.9}
.verify-link{font-size:13px;color:var(--muted);margin-top:18px}
.verify-link a{color:#1a6b5a;font-weight:600}
</style>

<div class="verify-card panel">
<?php if ($state === 'pending'): ?>

    <div class="verify-icon teal">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#1a6b5a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
            <polyline points="22,6 12,13 2,6"/>
        </svg>
    </div>
    <h1 class="verify-h">Check your inbox</h1>
    <p class="verify-p">
        We've sent a verification link to your email address.<br>
        Click it to activate your account — it expires in <strong>24 hours</strong>.
    </p>
    <p class="verify-p" style="font-size:13px;margin-bottom:20px">
        Didn't receive it? Check your spam folder, or
        <a href="index.php?page=verify" style="color:#1a6b5a;font-weight:600">request a new link</a>.
    </p>
    <a class="ghost-btn" href="index.php?page=login" style="display:inline-block;margin-top:4px">← Back to sign in</a>

<?php elseif ($state === 'expired'): ?>

    <div class="verify-icon amber">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
        </svg>
    </div>
    <h1 class="verify-h">Link has expired</h1>
    <p class="verify-p">Your verification link has expired. Request a new one and we'll send a fresh link to your email.</p>
    <form class="verify-form" method="post">
        <label>Your email address</label>
        <input type="email" name="email" required value="<?= h($prefillEmail) ?>" autofocus>
        <button class="verify-btn" type="submit">Send new verification link</button>
    </form>
    <p class="verify-link"><a href="index.php?page=login">← Back to sign in</a></p>

<?php elseif ($state === 'invalid'): ?>

    <div class="verify-icon red">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"/>
            <line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
        </svg>
    </div>
    <h1 class="verify-h">Invalid verification link</h1>
    <p class="verify-p">This link is invalid or has already been used. If you still need to verify your account, enter your email below to receive a new link.</p>
    <form class="verify-form" method="post">
        <label>Your email address</label>
        <input type="email" name="email" required placeholder="you@example.com" autofocus>
        <button class="verify-btn" type="submit">Send new verification link</button>
    </form>
    <p class="verify-link"><a href="index.php?page=login">← Back to sign in</a></p>

<?php elseif ($state === 'resent'): ?>

    <div class="verify-icon teal">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#1a6b5a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="20 6 9 17 4 12"/>
        </svg>
    </div>
    <h1 class="verify-h">Verification email sent</h1>
    <p class="verify-p">If that email is registered and unverified, a new verification link has been sent. Check your inbox — and your spam folder.</p>
    <a class="ghost-btn" href="index.php?page=login" style="display:inline-block;margin-top:4px">← Back to sign in</a>

<?php elseif ($state === 'cooldown'): ?>

    <div class="verify-icon amber">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
            <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
    </div>
    <h1 class="verify-h">Please wait a moment</h1>
    <p class="verify-p">You've requested a verification email very recently. Please wait at least <strong>60 seconds</strong> before trying again.</p>
    <form class="verify-form" method="post">
        <label>Your email address</label>
        <input type="email" name="email" required value="<?= h($prefillEmail) ?>">
        <button class="verify-btn" type="submit">Try again</button>
    </form>
    <p class="verify-link"><a href="index.php?page=login">← Back to sign in</a></p>

<?php else: /* default form state — also used for login redirect */ ?>

    <?php if ($prefillEmail !== ''): ?>
        <div class="verify-icon amber">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                <polyline points="22,6 12,13 2,6"/>
            </svg>
        </div>
        <h1 class="verify-h">Account not yet verified</h1>
        <p class="verify-p">You need to verify your email address before you can sign in. Click below to send a new verification link.</p>
        <div class="verify-email-badge"><?= h($prefillEmail) ?></div>
    <?php else: ?>
        <div class="verify-icon blue">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                <polyline points="22,6 12,13 2,6"/>
            </svg>
        </div>
        <h1 class="verify-h">Resend verification email</h1>
        <p class="verify-p">Enter your registered email address and we'll send you a new verification link.</p>
    <?php endif; ?>

    <form class="verify-form" method="post">
        <label>Email address</label>
        <input type="email" name="email" required
               value="<?= h($prefillEmail) ?>"
               placeholder="you@example.com"
               <?= $prefillEmail !== '' ? 'autofocus' : '' ?>>
        <button class="verify-btn" type="submit">Send verification link</button>
    </form>
    <p class="verify-link"><a href="index.php?page=login">← Back to sign in</a></p>

<?php endif; ?>
</div>

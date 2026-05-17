<?php
$token    = trim($_GET['token'] ?? '');
$invalid  = false;
$expired  = false;
$done     = false;
$tokenRow = null;

if ($token === '') {
    redirect_to('forgot');
}

// Look up token
$stmt = $conn->prepare('SELECT * FROM password_resets WHERE token = ? LIMIT 1');
$stmt->bind_param('s', $token); $stmt->execute();
$tokenRow = $stmt->get_result()->fetch_assoc();

if (!$tokenRow) {
    $invalid = true;
} elseif (strtotime($tokenRow['expires_at']) < time()) {
    $expired = true;
    // Don't delete — preserve for audit trail
} elseif ($tokenRow['used_at'] !== null) {
    // Token already used
    $invalid = true;
}

if (!$invalid && !$expired && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw   = $_POST['password']         ?? '';
    $conf = $_POST['confirm_password'] ?? '';

    if (strlen($pw) < 8) {
        set_flash('error', 'Password must be at least 8 characters.');
        redirect_to('reset', ['token' => $token]);
    }
    if ($pw !== $conf) {
        set_flash('error', 'Passwords do not match.');
        redirect_to('reset', ['token' => $token]);
    }

    $hash = password_hash($pw, PASSWORD_DEFAULT);

    $upd = $conn->prepare('UPDATE users SET password = ? WHERE email = ?');
    $upd->bind_param('ss', $hash, $tokenRow['email']); $upd->execute();

    // Mark token as used instead of deleting (preserves audit trail)
    $mark = $conn->prepare('UPDATE password_resets SET used_at = NOW() WHERE token = ?');
    $mark->bind_param('s', $token);
    $mark->execute();

    $done = true;
    set_flash('success', 'Password updated. You can now sign in with your new password.');
    redirect_to('login');
}
?>
<div class="auth-card panel narrow">
    <?php if ($invalid): ?>
        <div style="text-align:center;padding:8px 0">
            <div style="width:52px;height:52px;border-radius:50%;background:#fff0f0;display:flex;align-items:center;justify-content:center;margin:0 auto 16px">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#b54646" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </div>
            <h2>Invalid link</h2>
            <p class="muted" style="line-height:1.65">This password reset link is not valid. It may have already been used.</p>
            <a class="primary-btn" href="index.php?page=forgot" style="margin-top:18px;display:inline-flex">Request a new link</a>
        </div>

    <?php elseif ($expired): ?>
        <div style="text-align:center;padding:8px 0">
            <div style="width:52px;height:52px;border-radius:50%;background:#fff7e7;display:flex;align-items:center;justify-content:center;margin:0 auto 16px">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#b45309" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <h2>Link expired</h2>
            <p class="muted" style="line-height:1.65">This link expired after 1 hour. Please request a new one.</p>
            <a class="primary-btn" href="index.php?page=forgot" style="margin-top:18px;display:inline-flex">Request a new link</a>
        </div>

    <?php else: ?>
        <h1>Set new password</h1>
        <p class="muted">For <strong><?= h($tokenRow['email']) ?></strong></p>
        <form method="post" class="form-grid one" style="margin-top:18px">
            <div>
                <label>New password</label>
                <input type="password" name="password" required minlength="6" id="rp-pw" placeholder="At least 6 characters" autofocus>
            </div>
            <div>
                <label>Confirm new password</label>
                <input type="password" name="confirm_password" required id="rp-cpw" placeholder="Repeat password">
            </div>
            <div id="rp-msg" style="display:none;color:#b54646;font-size:13px;margin-top:-4px">Passwords do not match</div>
            <button class="primary-btn" type="submit" style="width:100%;padding:12px">Set New Password</button>
        </form>
        <p class="muted small" style="text-align:center;margin-top:14px">
            <a href="index.php?page=login">← Back to sign in</a>
        </p>
        <script>
        (function(){
            var pw=document.getElementById('rp-pw'), cp=document.getElementById('rp-cpw'), msg=document.getElementById('rp-msg');
            function check(){ msg.style.display=(cp.value && pw.value!==cp.value)?'block':'none'; }
            pw.addEventListener('input',check); cp.addEventListener('input',check);
        })();
        </script>
    <?php endif; ?>
</div>

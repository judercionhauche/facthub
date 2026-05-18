<?php
$submitted  = false;
$resetLink  = null;
$error_message = null;

// Catch any errors that occur during page processing
try {

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $error_message = 'Please enter your email address.';
    } else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address.';
    } else {
        try {
            // Rate limit: max 5 password reset requests per email per hour
            $rateLimiter = new RateLimiter($conn);
            if (!$rateLimiter->check('password_reset_' . $email, 5, 3600)) {
                // Always show same generic message to avoid enumeration
                $submitted = true;
                set_flash('success', 'If that email is registered, you\'ll receive a password reset link shortly.');
            } else {
                // Check if user exists
                $stmt = $conn->prepare('SELECT id, email, name FROM users WHERE email = ? LIMIT 1');
                if (!$stmt) {
                    throw new Exception('Database error: ' . $conn->error);
                }
                $stmt->bind_param('s', $email);
                if (!$stmt->execute()) {
                    throw new Exception('Query failed: ' . $stmt->error);
                }
                $user = $stmt->get_result()->fetch_assoc();

                $submitted = true;

                if ($user) {
                    // Delete old tokens for this email
                    $del = $conn->prepare('DELETE FROM password_resets WHERE email = ?');
                    $del->bind_param('s', $email);
                    $del->execute();

                    // Create new token
                    $token = bin2hex(random_bytes(32));
                    $expiresAt = date('Y-m-d H:i:s', time() + 3600);
                    $ins = $conn->prepare('INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)');
                    $ins->bind_param('sss', $email, $token, $expiresAt);
                    $ins->execute();

                    // Send email
                    @$mailCfg = require __DIR__ . '/../../../config/mail.php';
                    if (!is_array($mailCfg)) $mailCfg = [];
                    $appUrl = rtrim($mailCfg['app_url'] ?? ('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')), '/');
                    $resetUrl = $appUrl . '/index.php?page=reset&token=' . urlencode($token);

                    $html = mail_tpl_password_reset($resetUrl, $user['name']);
                    $sent = send_notification_email($email, 'Reset your FACT Alliance Hub password', $html);

                    // For dev environments where email isn't configured, expose the link
                    $resetLink = $resetUrl;

                    // Audit
                    @audit($conn, 'password_reset_requested', [
                        'type' => 'user',
                        'email' => $email,
                        'detail' => 'Password reset token sent'
                    ]);
                }

                // ALWAYS show same response (prevents user enumeration)
                set_flash('success', 'If that email is registered, you\'ll receive a password reset link shortly.');
            }
        } catch (Exception $e) {
            error_log('[Forgot Password Error] ' . $e->getMessage());
            $error_message = 'An error occurred. Please try again.';
        }
    }
}

} catch (Throwable $e) {
    $error_message = 'An unexpected error occurred: ' . $e->getMessage();
    error_log('[Forgot Page Error] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
}
?>
<div class="auth-card panel narrow">
    <?php if (!$submitted): ?>
        <h1>Forgot password?</h1>
        <p class="muted">Enter your email and we will send you a reset link.</p>

        <?php if ($error_message): ?>
        <div style="background:#fff5f5;border-left:4px solid #b54646;padding:12px 14px;margin-bottom:16px;border-radius:4px">
            <div style="color:#b54646;font-size:13px;line-height:1.5">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;margin-right:6px;vertical-align:-2px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?= h($error_message) ?>
            </div>
        </div>
        <?php endif; ?>

        <form method="post" class="form-grid one" style="margin-top:16px">
            <div>
                <label for="email">Email address</label>
                <input type="email" id="email" name="email" required placeholder="you@example.com" autofocus>
            </div>
            <button class="primary-btn" type="submit" style="width:100%;padding:12px">Send Reset Link</button>
        </form>
        <p class="muted small" style="text-align:center;margin-top:14px">
            <a href="index.php?page=login">← Back to sign in</a>
        </p>

    <?php else: ?>
        <div style="text-align:center;padding:8px 0 4px">
            <div style="width:52px;height:52px;border-radius:50%;background:#eaf6f0;display:flex;align-items:center;justify-content:center;margin:0 auto 16px">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#1a6b5a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            </div>
            <h2 style="margin-bottom:8px">Check your inbox</h2>
            <p class="muted" style="line-height:1.65;margin-top:0">
                If that email is registered, a reset link has been sent. Check your spam folder if it doesn't arrive within a few minutes.
            </p>

            <a class="primary-btn" href="index.php?page=login" style="margin-top:20px;display:inline-flex">
                ← Back to sign in
            </a>
        </div>
    <?php endif; ?>
</div>

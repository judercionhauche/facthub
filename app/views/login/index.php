<?php
$login_error = null;
$login_email = '';
$remaining_attempts = null;

try {

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $login_email = h($email);

    // Input validation
    if (empty($email) || empty($password)) {
        $login_error = 'Email and password are required.';
    } else {
        // Brute-force protection: rate limit by IP address
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $rateLimiter = new RateLimiter($conn);

        if (!$rateLimiter->check('login_' . $ip, 10, 900)) {  // 10 attempts per 15 minutes
            $login_error = 'Too many login attempts. Your IP is temporarily blocked. Try again in 15 minutes.';
        } else {
            try {
                $stmt = $conn->prepare('SELECT id, email, password, name, status, role FROM users WHERE email = ? LIMIT 1');
                if (!$stmt) {
                    throw new Exception('Database error: ' . $conn->error);
                }
                $stmt->bind_param('s', $email);
                if (!$stmt->execute()) {
                    throw new Exception('Query failed: ' . $stmt->error);
                }
                $userRow = $stmt->get_result()->fetch_assoc();

                if ($userRow && password_verify($password, $userRow['password'])) {
                    // Reset rate limit on successful login
                    $rateLimiter->reset('login_' . $ip);

                    // Check account status
                    if ($userRow['status'] === 'unverified') {
                        redirect_to('verify', ['e' => $userRow['email']]);
                    }
                    if (in_array($userRow['status'], ['inactive', 'deleted'], true) || $userRow['deleted_at'] !== null) {
                        $login_error = 'This account has been deactivated. Please contact your administrator.';
                    } else {
                        // Generate session token and store in database
                        $sessionToken = bin2hex(random_bytes(32));
                        $tStmt = $conn->prepare('UPDATE users SET session_token = ? WHERE id = ?');
                        if ($tStmt) {
                            $tStmt->bind_param('si', $sessionToken, $userRow['id']);
                            $tStmt->execute();
                        }

                        session_regenerate_id(true);
                        $_SESSION['user_id']       = $userRow['id'];
                        $_SESSION['session_token'] = $sessionToken;
                        $_SESSION['user_email']    = $userRow['email'];
                        $_SESSION['user_name']     = $userRow['name'] ?: $userRow['email'];
                        $_SESSION['user_role']     = $userRow['role'] ?? 'researcher';
                        $_SESSION['last_activity'] = time();

                        $firstName = explode(' ', trim($userRow['name'] ?: 'there'))[0];
                        set_flash('success', 'Welcome back, ' . h($firstName) . '!');

                        $loginReturn = $_SESSION['login_return'] ?? null;
                        unset($_SESSION['login_return']);
                        if ($loginReturn) {
                            $returnParams = [];
                            parse_str($loginReturn, $returnParams);
                            $safePages = ['researchers', 'funding', 'matching', 'institutions', 'messages'];
                            if (!empty($returnParams['page']) && in_array($returnParams['page'], $safePages, true)) {
                                $destPage = $returnParams['page'];
                                unset($returnParams['page']);
                                redirect_to($destPage, array_filter($returnParams, fn($v) => $v !== '' && $v !== null));
                            }
                        }
                        redirect_to(($userRow['role'] === 'funder') ? 'funding' : 'researchers');
                    }
                } else {
                    // Log the failed attempt (audit)
                    @audit($conn, 'login_failed', [
                        'type' => 'authentication',
                        'email' => $email,
                        'detail' => 'Invalid credentials from IP: ' . $ip
                    ]);

                    $remaining_attempts = $rateLimiter->getRemaining('login_' . $ip, 10, 900);
                    if ($remaining_attempts > 0) {
                        $login_error = "Invalid email or password. {$remaining_attempts} attempt(s) remaining.";
                    } else {
                        $login_error = 'Too many failed attempts. Your IP is temporarily blocked. Try again in 15 minutes.';
                    }
                }
            } catch (Exception $e) {
                error_log('[Login Error] ' . $e->getMessage());
                $login_error = 'An error occurred. Please try again.';
            }
        }
    }
}

} catch (Throwable $e) {
    $login_error = 'An unexpected error occurred: ' . $e->getMessage();
    error_log('[Login Page Error] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
}
?>
<div class="auth-card panel narrow">
    <h1>FACT Alliance Hub</h1>
    <p class="muted">Sign in to access researchers, funding calls, matching, institutions, and messages.</p>

    <?php if ($login_error): ?>
    <div style="background:#fff5f5;border-left:4px solid #b54646;padding:12px 14px;margin-bottom:16px;border-radius:4px">
        <div style="color:#b54646;font-size:13px;line-height:1.5">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;margin-right:6px;vertical-align:-2px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?= h($login_error) ?>
        </div>
    </div>
    <?php endif; ?>

    <form method="post" class="form-grid one">
        <div>
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= $login_email ?>" required autofocus placeholder="you@example.com">
        </div>
        <div style="position:relative">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required placeholder="Enter your password" style="padding-right:40px">
            <button type="button" id="toggle-password" style="position:absolute;right:12px;top:32px;background:none;border:none;cursor:pointer;padding:4px;color:#60706a;font-size:18px">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" id="eye-open">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                </svg>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" id="eye-closed" style="display:none">
                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                    <line x1="1" y1="1" x2="23" y2="23"></line>
                </svg>
            </button>
        </div>
        <script>
            document.getElementById('toggle-password').addEventListener('click', function(e) {
                e.preventDefault();
                var pwd = document.getElementById('password');
                var eyeOpen = document.getElementById('eye-open');
                var eyeClosed = document.getElementById('eye-closed');
                if (pwd.type === 'password') {
                    pwd.type = 'text';
                    eyeOpen.style.display = 'none';
                    eyeClosed.style.display = 'block';
                } else {
                    pwd.type = 'password';
                    eyeOpen.style.display = 'block';
                    eyeClosed.style.display = 'none';
                }
            });
        </script>
        <button class="primary-btn" type="submit" style="width:100%;padding:12px">Sign In</button>
    </form>

    <p class="muted small" style="text-align:right;margin-top:4px"><a href="index.php?page=forgot">Forgot password?</a></p>
    <hr style="border:none;border-top:1px solid var(--line);margin:18px 0">
    <p class="muted small" style="text-align:center">New to FACT Hub? <a href="index.php?page=researchers&mode=add"><strong>Register as a Researcher →</strong></a></p>
</div>

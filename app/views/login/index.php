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

                // Debug: Log password verification for admin
                if ($userRow && $userRow['email'] === 'factalliance@mit.edu') {
                    $pwd_result = password_verify($password, $userRow['password']);
                    error_log("LOGIN DEBUG [ADMIN]: pwd_verify=" . ($pwd_result ? 'TRUE' : 'FALSE') . ", status=" . $userRow['status']);
                }

                if ($userRow && password_verify($password, $userRow['password'])) {
                    error_log("[LOGIN SUCCESS] User: {$userRow['email']}, role: {$userRow['role']}, status: {$userRow['status']}");
                    // Reset rate limit on successful login
                    $rateLimiter->reset('login_' . $ip);

                    // Check account status
                    if ($userRow['status'] === 'unverified') {
                        error_log("[LOGIN] User unverified, redirecting to verify");
                        redirect_to('verify', ['e' => $userRow['email']]);
                    }
                    if (in_array($userRow['status'], ['inactive', 'deleted'], true) || ($userRow['deleted_at'] ?? null) !== null) {
                        error_log("[LOGIN] User deactivated/deleted");
                        $login_error = 'This account has been deactivated. Please contact your administrator.';
                    } else {
                        error_log("[LOGIN] Creating session");

                        // Generate session token
                        $sessionToken = bin2hex(random_bytes(32));
                        $tStmt = $conn->prepare('UPDATE users SET session_token = ? WHERE id = ?');
                        if ($tStmt) {
                            $tStmt->bind_param('si', $sessionToken, $userRow['id']);
                            $tStmt->execute();
                        }

                        session_regenerate_id(true);
                        $deviceFingerprint = generate_device_fingerprint();
                        $_SESSION['user_id']           = $userRow['id'];
                        $_SESSION['session_token']     = $sessionToken;
                        $_SESSION['device_fingerprint'] = $deviceFingerprint;
                        $_SESSION['user_email']        = $userRow['email'];
                        $_SESSION['user_name']         = $userRow['name'] ?: $userRow['email'];
                        $_SESSION['user_role']         = $userRow['role'] ?? 'researcher';
                        $_SESSION['user_status']       = $userRow['status'];
                        $_SESSION['last_activity']     = time();

                        // Handle "Remember me" checkbox (gracefully skip if table doesn't exist)
                        $rememberMe = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';
                        if ($rememberMe) {
                            try {
                                $rememberToken = bin2hex(random_bytes(32));
                                $expiresAt = date('Y-m-d H:i:s', time() + (30 * 24 * 3600));

                                $rStmt = $conn->prepare('INSERT INTO remember_tokens (user_id, token, expires_at) VALUES (?, ?, ?)');
                                if ($rStmt) {
                                    $rStmt->bind_param('iss', $userRow['id'], $rememberToken, $expiresAt);
                                    if ($rStmt->execute()) {
                                        // Set persistent HTTP-only cookie (30 days)
                                        setcookie('remember_token', $rememberToken, [
                                            'expires' => time() + (30 * 24 * 3600),
                                            'path'    => '/',
                                            'secure'  => (getenv('APP_ENV') === 'production'),
                                            'httponly' => true,
                                            'samesite' => 'Lax'
                                        ]);
                                    }
                                }
                            } catch (Exception $e) {
                                error_log("[LOGIN] Remember me error: " . $e->getMessage());
                                // Continue - feature is optional
                            }
                        }

                        $firstName = explode(' ', trim($userRow['name'] ?: 'there'))[0];
                        if ($userRow['status'] === 'pending_approval') {
                            set_flash('info', 'Welcome! Your account is pending admin approval. You can update your profile while you wait.');
                        } else {
                            set_flash('success', 'Welcome back, ' . h($firstName) . '!');
                        }

                        $loginReturn = $_SESSION['login_return'] ?? null;
                        unset($_SESSION['login_return']);
                        if ($loginReturn) {
                            $returnParams = [];
                            parse_str($loginReturn, $returnParams);
                            $safePages = ['researchers', 'funding', 'matching', 'institutions', 'messages'];
                            if (!empty($returnParams['page']) && in_array($returnParams['page'], $safePages, true)) {
                                $destPage = $returnParams['page'];
                                unset($returnParams['page']);
                                error_log("[LOGIN] Redirecting to return page: $destPage");
                                redirect_to($destPage, array_filter($returnParams, fn($v) => $v !== '' && $v !== null));
                            }
                        }
                        $redirectTo = ($userRow['role'] === 'funder') ? 'funding' : 'researchers';
                        error_log("[LOGIN] Redirecting to: $redirectTo");
                        redirect_to($redirectTo);
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
<div class="login-container" style="display:flex;height:100vh;width:100vw;position:fixed;top:0;left:0;background:#fff;font-family:'-apple-system', 'BlinkMacSystemFont', 'Segoe UI', sans-serif">

    <!-- Left Hero Section -->
    <div class="login-hero" style="flex:1;background:linear-gradient(135deg, rgba(45, 122, 106, 0.88) 0%, rgba(61, 143, 122, 0.92) 100%), url('../../../factbackground.jpg') center/cover no-repeat;display:flex;flex-direction:column;justify-content:center;align-items:center;padding:60px;color:#fff;position:relative;overflow:hidden">
        <div style="position:relative;z-index:1;text-align:center;max-width:420px">
            <h2 style="font-size:38px;font-weight:700;margin:0 0 20px 0;line-height:1.2">Transform Food & Climate Systems</h2>
            <p style="font-size:15px;line-height:1.6;opacity:0.9;margin:0;font-weight:300">
                Connect with researchers, funders, and institutions driving innovation in global food security and climate resilience.
            </p>
            <div style="border-top:1px solid rgba(255,255,255,0.2);padding-top:20px;margin-top:32px">
                <p style="font-size:12px;text-transform:uppercase;letter-spacing:0.08em;font-weight:600;opacity:0.85">Powered by MIT J-WAFS</p>
            </div>
        </div>
    </div>

    <!-- Right Login Form Section -->
    <div class="login-form-wrapper" style="flex:1;display:flex;flex-direction:column;justify-content:center;align-items:center;padding:60px;background:#fafbf9;overflow-y:auto;max-width:100%">
        <div style="width:100%;max-width:340px">
            <!-- Sign In Header -->
            <div style="margin-bottom:48px;text-align:center">
                <h1 style="font-size:32px;font-weight:700;color:#1a1a1a;margin:0;letter-spacing:-0.5px">Sign In</h1>
            </div>

            <!-- Error Message -->
            <?php if ($login_error): ?>
            <div style="background:#fef2f2;border-left:3px solid #dc2626;padding:12px 16px;border-radius:4px;margin-bottom:24px">
                <div style="color:#991b1b;font-size:13px;line-height:1.5"><?= h($login_error) ?></div>
            </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="post" style="display:flex;flex-direction:column;gap:20px">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

            <!-- Email Field -->
            <div>
                <label for="email" style="display:block;font-size:12px;font-weight:600;color:#1a1a1a;margin-bottom:8px;text-transform:uppercase;letter-spacing:0.05em">Email</label>
                <input type="email" id="email" name="email" value="<?= $login_email ?>" required autofocus placeholder="you@example.com" style="width:100%;padding:12px 14px;border:1px solid #e5e7eb;border-radius:4px;font-size:14px;font-family:inherit;transition:all 0.2s;box-sizing:border-box;background:#fff" onfocus="this.style.borderColor='#1a6b5a';this.style.boxShadow='0 0 0 2px rgba(26, 107, 90, 0.05)'" onblur="this.style.borderColor='#e5e7eb';this.style.boxShadow='none'">
            </div>

            <!-- Password Field -->
            <div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                    <label for="password" style="font-size:12px;font-weight:600;color:#1a1a1a;text-transform:uppercase;letter-spacing:0.05em">Password</label>
                    <a href="index.php?page=forgot" style="font-size:12px;color:#1a6b5a;text-decoration:none;font-weight:500">Forgot?</a>
                </div>
                <div style="position:relative;display:flex;align-items:center">
                    <input type="password" id="password" name="password" required placeholder="••••••••" style="width:100%;padding:12px 14px;border:1px solid #e5e7eb;border-radius:4px;font-size:14px;font-family:inherit;transition:all 0.2s;box-sizing:border-box;background:#fff" onfocus="this.style.borderColor='#1a6b5a';this.style.boxShadow='0 0 0 2px rgba(26, 107, 90, 0.05)'" onblur="this.style.borderColor='#e5e7eb';this.style.boxShadow='none'">
                    <button type="button" id="toggle-password" style="position:absolute;right:12px;background:none;border:none;cursor:pointer;padding:4px;color:#9ca3af;font-size:18px;transition:color 0.2s;display:flex;align-items:center" onmouseover="this.style.color='#6b7280'" onmouseout="this.style.color='#9ca3af'">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" id="eye-open">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" id="eye-closed" style="display:none">
                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                            <line x1="1" y1="1" x2="23" y2="23"></line>
                        </svg>
                    </button>
                </div>
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

            <!-- Remember Me -->
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;user-select:none">
                <input type="checkbox" name="remember_me" value="1" style="width:16px;height:16px;cursor:pointer;accent-color:#1a6b5a;border-radius:2px">
                <span style="font-size:13px;color:#4b5563">Remember for 30 days</span>
            </label>

            <!-- Sign In Button -->
            <button type="submit" style="width:100%;padding:12px 16px;margin-top:8px;background:#1a6b5a;color:#fff;border:none;border-radius:4px;font-size:14px;font-weight:600;cursor:pointer;transition:background-color 0.2s,transform 0.1s;letter-spacing:0.05em" onmouseover="this.style.backgroundColor='#145245'" onmouseout="this.style.backgroundColor='#1a6b5a'" onmousedown="this.style.transform='scale(0.99)'" onmouseup="this.style.transform='scale(1)'">
                Sign In
            </button>
        </form>

            <!-- Sign Up Link -->
            <p style="margin-top:24px;text-align:center;font-size:13px;color:#6b7280">New here? <a href="index.php?page=register" style="color:#1a6b5a;text-decoration:none;font-weight:600">Create account</a></p>
            </form>
        </div>
    </div>
</div>

<style>
    @media (max-width: 1024px) {
        .login-container { flex-direction: column; height: auto; }
        .login-hero { padding: 40px 30px; min-height: 280px; }
        .login-form-wrapper { padding: 40px 30px; }
        .login-hero h2 { font-size: 28px; }
    }

    @media (max-width: 640px) {
        .login-hero { padding: 30px 20px; min-height: 240px; }
        .login-form-wrapper { padding: 30px 20px; }
        .login-hero h2 { font-size: 22px; }
        .login-form-wrapper > div { max-width: 100%; }
    }
</style>

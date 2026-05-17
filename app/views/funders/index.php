<?php
// Allow unauthenticated access for registration (mode=add without login)
$mode = ($_GET['mode'] ?? $_POST['mode'] ?? '');
$isRegistering = ($mode === 'add' && !is_logged_in());

if (!$isRegistering) {
    require_login();
}

/* ── POST handler ─────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        try {
            $email            = trim($_POST['email'] ?? '');
            $password         = $_POST['password'] ?? '';
            $confirmPassword  = $_POST['confirm_password'] ?? '';
            $orgName          = trim($_POST['organization_name'] ?? '');
            $contactName      = trim($_POST['contact_name'] ?? '');
            $department       = trim($_POST['department'] ?? '');

            if ($orgName === '' || $contactName === '') {
                set_flash('error', 'Organization name and contact name are required.');
                redirect_to('funders', ['mode' => 'add']);
            }

            if ($password === '' || $confirmPassword === '') {
                set_flash('error', 'Password is required.');
                redirect_to('funders', ['mode' => 'add']);
            }

            if ($password !== $confirmPassword) {
                set_flash('error', 'Passwords do not match.');
                redirect_to('funders', ['mode' => 'add']);
            }

            if (strlen($password) < 8) {
                set_flash('error', 'Password must be at least 8 characters.');
                redirect_to('funders', ['mode' => 'add']);
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                set_flash('error', 'Please enter a valid email address.');
                redirect_to('funders', ['mode' => 'add']);
            }

            // Check if user already exists
            $checkUser = $conn->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?)');
            if (!$checkUser) throw new Exception('Prepare check email failed: ' . $conn->error);
            $checkUser->bind_param('s', $email);
            if (!$checkUser->execute()) throw new Exception('Error checking email: ' . $checkUser->error);
            if ($checkUser->get_result()->num_rows > 0) {
                set_flash('error', 'This email is already registered.');
                redirect_to('funders', ['mode' => 'add']);
            }

            // Create user account
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $userStmt = $conn->prepare('INSERT INTO users (email, password, name, role, status) VALUES (?, ?, ?, ?, ?)');
            if (!$userStmt) throw new Exception('Prepare user failed: ' . $conn->error);
            $role = 'funder';
            $status = 'unverified';
            $fullName = $contactName;
            $userStmt->bind_param('sssss', $email, $passwordHash, $fullName, $role, $status);
            if (!$userStmt->execute()) {
                throw new Exception('Error creating account: ' . $userStmt->error);
            }
            $userId = $conn->insert_id;

            // Create email verification token
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + 86400);
            $evStmt = $conn->prepare('INSERT INTO email_verifications (email, token, expires_at) VALUES (?, ?, ?)');
            if (!$evStmt) throw new Exception('Prepare email_verifications failed: ' . $conn->error);
            $evStmt->bind_param('sss', $email, $token, $expiresAt);
            if (!$evStmt->execute()) {
                throw new Exception('Error creating verification token: ' . $evStmt->error);
            }

            // Create funder profile linked to user
            $stmt = $conn->prepare('INSERT INTO funders (user_id, email, organization_name, contact_name, department, status) VALUES (?, ?, ?, ?, ?, ?)');
            if (!$stmt) throw new Exception('Prepare funders failed: ' . $conn->error);
            $status_funder = 'active';
            $stmt->bind_param('isssss', $userId, $email, $orgName, $contactName, $department, $status_funder);
            if (!$stmt->execute()) {
                throw new Exception('Error creating funder profile: ' . $stmt->error);
            }

            // Send verification email
            $mailCfg = require __DIR__ . '/../../config/mail.php';
            $appUrl = rtrim($mailCfg['app_url'] ?? 'http://localhost/facthub/public', '/');
            $verifyUrl = $appUrl . '/index.php?page=verify&token=' . urlencode($token);
            send_notification_email($email, 'Verify your FACT Alliance Hub account',
                mail_tpl_verify_email($verifyUrl, $contactName));

            audit($conn, 'funder_signup', ['type' => 'user', 'id' => $userId, 'email' => $email, 'detail' => "New funder registration: $orgName"]);
            set_flash('success', 'Account created! Check your email to verify your account.');
            redirect_to('verify', ['e' => $email, 'pending' => '1']);
        } catch (Exception $e) {
            error_log('[Funder Registration Error] ' . $e->getMessage());
            set_flash('error', 'Registration failed: ' . $e->getMessage());
            redirect_to('funders', ['mode' => 'add']);
        }
    }
}

/* ── Render ────────────────────────────────────────────────────────── */
$flash = get_flash();
?>

<?php if ($mode === 'add'): ?>
<div style="max-width: 500px; margin: 40px auto; padding: 0 20px;">
    <h1 style="text-align: center; font-size: 28px; color: #1a3d2a; margin-bottom: 10px;">Register as Funder</h1>
    <p style="text-align: center; color: #9aaba4; margin-bottom: 40px;">Post funding opportunities and discover researchers working on your priority topics.</p>

    <?php if ($flash): ?>
    <div style="background: <?= $flash['type'] === 'error' ? '#fee2e2' : '#dcfce7' ?>; border: 1px solid <?= $flash['type'] === 'error' ? '#fca5a5' : '#86efac' ?>; color: <?= $flash['type'] === 'error' ? '#b54646' : '#15803d' ?>; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 14px;">
        <?= h($flash['message']) ?>
    </div>
    <?php endif; ?>

    <form method="POST" style="background: #ffffff; border: 1px solid #dde6dd; border-radius: 10px; padding: 30px;">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="mode" value="add">

        <div style="margin-bottom: 24px;">
            <label style="display: block; font-weight: 600; color: #1a3d2a; margin-bottom: 8px; font-size: 14px;">Organization Name *</label>
            <input type="text" name="organization_name" required placeholder="Your organization" style="width: 100%; padding: 10px 12px; border: 1px solid #dde6dd; border-radius: 6px; font-size: 14px; box-sizing: border-box;">
        </div>

        <div style="margin-bottom: 24px;">
            <label style="display: block; font-weight: 600; color: #1a3d2a; margin-bottom: 8px; font-size: 14px;">Contact Name *</label>
            <input type="text" name="contact_name" required placeholder="Your name" style="width: 100%; padding: 10px 12px; border: 1px solid #dde6dd; border-radius: 6px; font-size: 14px; box-sizing: border-box;">
        </div>

        <div style="margin-bottom: 24px;">
            <label style="display: block; font-weight: 600; color: #1a3d2a; margin-bottom: 8px; font-size: 14px;">Department (optional)</label>
            <input type="text" name="department" placeholder="Department or team" style="width: 100%; padding: 10px 12px; border: 1px solid #dde6dd; border-radius: 6px; font-size: 14px; box-sizing: border-box;">
        </div>

        <div style="margin-bottom: 24px;">
            <label style="display: block; font-weight: 600; color: #1a3d2a; margin-bottom: 8px; font-size: 14px;">Email *</label>
            <input type="email" name="email" required placeholder="your@email.com" style="width: 100%; padding: 10px 12px; border: 1px solid #dde6dd; border-radius: 6px; font-size: 14px; box-sizing: border-box;">
        </div>

        <div style="margin-bottom: 24px;">
            <label style="display: block; font-weight: 600; color: #1a3d2a; margin-bottom: 8px; font-size: 14px;">Password *</label>
            <input type="password" name="password" required placeholder="At least 8 characters" style="width: 100%; padding: 10px 12px; border: 1px solid #dde6dd; border-radius: 6px; font-size: 14px; box-sizing: border-box;">
        </div>

        <div style="margin-bottom: 30px;">
            <label style="display: block; font-weight: 600; color: #1a3d2a; margin-bottom: 8px; font-size: 14px;">Confirm Password *</label>
            <input type="password" name="confirm_password" required placeholder="Re-enter password" style="width: 100%; padding: 10px 12px; border: 1px solid #dde6dd; border-radius: 6px; font-size: 14px; box-sizing: border-box;">
        </div>

        <button type="submit" style="width: 100%; padding: 12px; background: #1a6b5a; color: white; border: none; border-radius: 6px; font-weight: 600; font-size: 15px; cursor: pointer;">Create Account</button>

        <div style="text-align: center; margin-top: 24px; font-size: 14px; color: #9aaba4;">
            Already have an account? <a href="index.php?page=login" style="color: #1a6b5a; text-decoration: none; font-weight: 600;">Sign in →</a>
        </div>
    </form>
</div>

<?php else: ?>
<div style="text-align: center; padding: 60px 20px;">
    <h1 style="color: #1a3d2a; font-size: 24px;">Funders Section</h1>
    <p style="color: #9aaba4;">Manage your funding calls and browse researchers.</p>
</div>
<?php endif; ?>

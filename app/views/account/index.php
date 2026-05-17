<?php
// Account settings - email and password management
require_once __DIR__ . '/../../core/helpers.php';

$user = current_user();
if (!is_logged_in()) {
    redirect_to('login');
}

$tab = $_GET['tab'] ?? 'password';
$validTabs = ['password', 'email'];
if (!in_array($tab, $validTabs)) {
    $tab = 'password';
}

$error_message = null;
$success_message = null;

try {
    // Handle password change
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
        if (!verify_csrf()) {
            $error_message = 'Security token invalid. Please try again.';
        } else {
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                $error_message = 'All password fields are required.';
            } elseif ($new_password !== $confirm_password) {
                $error_message = 'New passwords do not match.';
            } elseif (strlen($new_password) < 8) {
                $error_message = 'Password must be at least 8 characters long.';
            } else {
                // Verify current password
                $stmt = $conn->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
                $stmt->bind_param('i', $user['id']);
                $stmt->execute();
                $userRow = $stmt->get_result()->fetch_assoc();

                if (!$userRow || !password_verify($current_password, $userRow['password'])) {
                    $error_message = 'Current password is incorrect.';
                } else {
                    // Update password
                    $newHash = password_hash($new_password, PASSWORD_DEFAULT);
                    $updateStmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
                    if (!$updateStmt) {
                        throw new Exception('Prepare failed: ' . $conn->error);
                    }
                    $updateStmt->bind_param('si', $newHash, $user['id']);
                    if (!$updateStmt->execute()) {
                        throw new Exception('Execute failed: ' . $updateStmt->error);
                    }

                    if ($updateStmt->affected_rows > 0) {
                        audit($conn, 'change_password', ['type' => 'user', 'id' => $user['id']]);
                        $success_message = 'Password changed successfully!';
                    } else {
                        $error_message = 'Failed to update password.';
                    }
                }
            }
        }
    }

    // Handle email change
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_email'])) {
        if (!verify_csrf()) {
            $error_message = 'Security token invalid. Please try again.';
        } else {
            $new_email = strtolower(trim($_POST['new_email'] ?? ''));
            $password = $_POST['password'] ?? '';

            if (empty($new_email) || empty($password)) {
                $error_message = 'Email and password are required.';
            } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
                $error_message = 'Invalid email address.';
            } elseif ($new_email === $user['email']) {
                $error_message = 'New email is the same as current email.';
            } else {
                // Verify password
                $stmt = $conn->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
                $stmt->bind_param('i', $user['id']);
                $stmt->execute();
                $userRow = $stmt->get_result()->fetch_assoc();

                if (!$userRow || !password_verify($password, $userRow['password'])) {
                    $error_message = 'Password is incorrect.';
                } else {
                    // Check if email is already taken
                    $checkStmt = $conn->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
                    $checkStmt->bind_param('si', $new_email, $user['id']);
                    $checkStmt->execute();
                    if ($checkStmt->get_result()->num_rows > 0) {
                        $error_message = 'Email address is already in use.';
                    } else {
                        // Update email in users table
                        $updateStmt = $conn->prepare('UPDATE users SET email = ? WHERE id = ?');
                        if (!$updateStmt) {
                            throw new Exception('Prepare failed: ' . $conn->error);
                        }
                        $updateStmt->bind_param('si', $new_email, $user['id']);
                        if (!$updateStmt->execute()) {
                            throw new Exception('Execute failed: ' . $updateStmt->error);
                        }

                        if ($updateStmt->affected_rows > 0) {
                            // Also update email in researcher/funder table if it exists
                            if ($user['role'] === 'researcher') {
                                $profileStmt = $conn->prepare('UPDATE researchers SET email = ? WHERE user_id = ?');
                                $profileStmt->bind_param('si', $new_email, $user['id']);
                                @$profileStmt->execute();
                            } elseif ($user['role'] === 'funder') {
                                $profileStmt = $conn->prepare('UPDATE funders SET email = ? WHERE user_id = ?');
                                $profileStmt->bind_param('si', $new_email, $user['id']);
                                @$profileStmt->execute();
                            }

                            // Update session
                            $_SESSION['user_email'] = $new_email;

                            audit($conn, 'change_email', ['type' => 'user', 'id' => $user['id'], 'detail' => "Changed from {$user['email']} to {$new_email}"]);
                            $success_message = 'Email changed successfully! Please use your new email to log in next time.';
                        } else {
                            $error_message = 'Failed to update email.';
                        }
                    }
                }
            }
        }
    }

} catch (Throwable $e) {
    error_log('[Account Error] ' . $e->getMessage());
    $error_message = 'An error occurred. Please try again.';
}
?>

<style>
.account-container { max-width: 600px; margin: 0 auto; }
.account-tabs { display: flex; gap: 0; border-bottom: 2px solid #dde6dd; margin-bottom: 28px; }
.account-tab { padding: 12px 20px; border: none; background: none; cursor: pointer; font-weight: 500; color: var(--muted); border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all .25s ease; }
.account-tab:hover { color: var(--text); }
.account-tab.active { color: var(--primary); border-bottom-color: var(--primary); }
.account-card { background: #f8fafb; border: 1px solid #dde6dd; border-radius: 8px; padding: 28px; margin-bottom: 20px; }
.form-field { margin-bottom: 18px; }
.form-field label { display: block; font-weight: 600; color: var(--text); margin-bottom: 8px; font-size: 14px; }
.form-field input { width: 100%; padding: 10px 12px; border: 1px solid #dde6dd; border-radius: 6px; font-family: inherit; font-size: 14px; color: var(--text); box-sizing: border-box; }
.form-field input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26, 107, 90, 0.1); }
.password-field-wrapper { position: relative; }
.password-toggle { position: absolute; right: 12px; top: 38px; background: none; border: none; cursor: pointer; color: var(--muted); padding: 4px; display: flex; align-items: center; justify-content: center; }
.password-toggle:hover { color: var(--text); }
button.submit-btn { background: var(--primary); color: white; padding: 12px 28px; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 14px; transition: all .25s ease; }
button.submit-btn:hover { background: #155043; transform: translateY(-1px); }
.alert { padding: 12px 14px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
.alert-success { background: #eef9f6; border-left: 4px solid #1a6b5a; color: #1a6b5a; }
.alert-error { background: #fff5f5; border-left: 4px solid #b54646; color: #b54646; }
</style>

<div class="account-container" style="margin-top: 20px">
    <h1 style="margin-bottom: 28px">Security Settings</h1>

    <?php if ($success_message): ?>
    <div class="alert alert-success"><?= h($success_message) ?></div>
    <?php endif; ?>
    <?php if ($error_message): ?>
    <div class="alert alert-error"><?= h($error_message) ?></div>
    <?php endif; ?>

    <div class="account-tabs">
        <button class="account-tab <?= $tab === 'password' ? 'active' : '' ?>" onclick="window.location='?page=account&tab=password'">Change Password</button>
        <button class="account-tab <?= $tab === 'email' ? 'active' : '' ?>" onclick="window.location='?page=account&tab=email'">Change Email</button>
    </div>

    <?php if ($tab === 'password'): ?>
    <div class="account-card">
        <h2 style="margin-top: 0; margin-bottom: 20px; font-size: 18px">Change Your Password</h2>
        <form method="post">
            <input type="hidden" name="change_password" value="1">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

            <div class="form-field password-field-wrapper">
                <label>Current Password</label>
                <input type="password" name="current_password" required>
                <button type="button" class="password-toggle" onclick="togglePasswordVisibility(this)">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                </button>
            </div>

            <div class="form-field password-field-wrapper">
                <label>New Password</label>
                <input type="password" name="new_password" required minlength="8" placeholder="At least 8 characters">
                <button type="button" class="password-toggle" onclick="togglePasswordVisibility(this)">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                </button>
            </div>

            <div class="form-field password-field-wrapper">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" required minlength="8">
                <button type="button" class="password-toggle" onclick="togglePasswordVisibility(this)">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                </button>
            </div>

            <button type="submit" class="submit-btn">Update Password</button>
        </form>
    </div>

    <?php elseif ($tab === 'email'): ?>
    <div class="account-card">
        <h2 style="margin-top: 0; margin-bottom: 20px; font-size: 18px">Change Your Email</h2>
        <p style="color: var(--muted); margin-bottom: 20px; font-size: 14px">
            Current email: <strong><?= h($user['email']) ?></strong>
        </p>
        <form method="post">
            <input type="hidden" name="change_email" value="1">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

            <div class="form-field">
                <label>New Email Address</label>
                <input type="email" name="new_email" required placeholder="you@example.com">
            </div>

            <div class="form-field password-field-wrapper">
                <label>Password (for verification)</label>
                <input type="password" name="password" required>
                <button type="button" class="password-toggle" onclick="togglePasswordVisibility(this)">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                </button>
            </div>

            <button type="submit" class="submit-btn">Update Email</button>
        </form>
    </div>
    <?php endif; ?>
</div>

<script>
function togglePasswordVisibility(button) {
    const input = button.previousElementSibling;
    const isPassword = input.type === 'password';
    input.type = isPassword ? 'text' : 'password';
    button.style.color = isPassword ? 'var(--primary)' : 'var(--muted)';
}

// Auto-hide success messages after 5 seconds
document.querySelectorAll('.alert-success').forEach(function(alert) {
    setTimeout(function() {
        alert.style.transition = 'opacity 0.3s';
        alert.style.opacity = '0';
        setTimeout(function() { alert.remove(); }, 300);
    }, 5000);
});
</script>

<?php
// Profile page - show user's profile with edit capability
require_once __DIR__ . '/../../core/helpers.php';

$user = current_user();
if (!is_logged_in()) {
    redirect_to('login');
}

$success_message = null;
$error_message = null;
$tab = $_GET['tab'] ?? 'overview';
$validTabs = ['overview', 'settings', 'links', 'preferences'];
if (!in_array($tab, $validTabs)) {
    $tab = 'overview';
}

try {
    // Fetch detailed profile data based on user role
    if ($user['role'] === 'researcher') {
        $stmt = $conn->prepare("SELECT * FROM researchers WHERE email = ? AND status IN ('active', 'pending_approval') AND deleted_at IS NULL LIMIT 1");
        $stmt->bind_param('s', $user['email']);
        $stmt->execute();
        $profile = $stmt->get_result()->fetch_assoc();
        if (!$profile) {
            set_flash('error', 'Your researcher profile was not found. Please contact an administrator.');
            redirect_to('researchers');
        }
    } elseif ($user['role'] === 'funder') {
        $stmt = $conn->prepare("SELECT * FROM funders WHERE email = ? AND status IN ('active', 'pending_approval') AND deleted_at IS NULL LIMIT 1");
        $stmt->bind_param('s', $user['email']);
        $stmt->execute();
        $profile = $stmt->get_result()->fetch_assoc();
        if (!$profile) {
            set_flash('error', 'Your funder profile was not found. Please contact an administrator.');
            redirect_to('funding');
        }
    }

    // Handle profile updates
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
        if (!verify_csrf()) {
            $error_message = 'Security token invalid. Please try again.';
        } else {
            $updates = [];
            $types = '';
            $params = [];

            if ($user['role'] === 'researcher') {
                // Update researcher profile
                $fields = [
                    'first_name' => 's',
                    'last_name' => 's',
                    'institution' => 's',
                    'department' => 's',
                    'title' => 's',
                    'bio' => 's',
                    'topics' => 's',
                    'geography' => 's',
                    'profile_url' => 's',
                    'website_url' => 's',
                    'orcid_id' => 's',
                    'google_scholar_url' => 's',
                ];

                foreach ($fields as $field => $type) {
                    if (isset($_POST[$field])) {
                        $value = trim($_POST[$field]);
                        $updates[] = "$field = ?";
                        $params[] = $value;
                        $types .= $type;
                    }
                }

                if (!empty($updates)) {
                    $types .= 's'; // for email in WHERE clause
                    $params[] = $user['email'];
                    $sql = 'UPDATE researchers SET ' . implode(', ', $updates) . " WHERE email = ? AND status IN ('active', 'pending_approval') AND deleted_at IS NULL";
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        throw new Exception('Prepare failed: ' . $conn->error);
                    }
                    $stmt->bind_param($types, ...$params);
                    if (!$stmt->execute()) {
                        throw new Exception('Execute failed: ' . $stmt->error);
                    }

                    if ($stmt->affected_rows >= 0) {
                        $success_message = 'Profile updated successfully!';
                        audit($conn, 'update_profile', ['type' => 'researcher', 'id' => $profile['id']]);

                        // Refresh profile data
                        $stmt2 = $conn->prepare("SELECT * FROM researchers WHERE email = ? AND status IN ('active', 'pending_approval') AND deleted_at IS NULL LIMIT 1");
                        $stmt2->bind_param('s', $user['email']);
                        $stmt2->execute();
                        $profile = $stmt2->get_result()->fetch_assoc();

                        // Regenerate AI summary since profile content changed
                        if ($profile && $profile['id']) {
                            enqueue_job($conn, 'generate_summary', ['entity_type' => 'researcher', 'entity_id' => (int)$profile['id']]);
                        }
                    } else {
                        $error_message = 'Failed to update profile.';
                    }
                } else {
                    $error_message = 'No changes to save.';
                }
            } elseif ($user['role'] === 'funder') {
                // Update funder profile
                $fields = [
                    'first_name' => 's',
                    'last_name' => 's',
                    'organization' => 's',
                    'org_type' => 's',
                    'country' => 's',
                    'website_url' => 's',
                    'bio' => 's',
                    'topics' => 's',
                    'geography' => 's',
                ];

                foreach ($fields as $field => $type) {
                    if (isset($_POST[$field])) {
                        $value = trim($_POST[$field]);
                        $updates[] = "$field = ?";
                        $params[] = $value;
                        $types .= $type;
                    }
                }

                if (!empty($updates)) {
                    $types .= 's'; // for email in WHERE clause
                    $params[] = $user['email'];
                    $sql = 'UPDATE funders SET ' . implode(', ', $updates) . " WHERE email = ? AND status IN ('active', 'pending_approval') AND deleted_at IS NULL";
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        throw new Exception('Prepare failed: ' . $conn->error);
                    }
                    $stmt->bind_param($types, ...$params);
                    if (!$stmt->execute()) {
                        throw new Exception('Execute failed: ' . $stmt->error);
                    }

                    if ($stmt->affected_rows >= 0) {
                        $success_message = 'Profile updated successfully!';
                        audit($conn, 'update_profile', ['type' => 'funder', 'id' => $profile['id']]);
                        // Refresh profile data
                        $stmt2 = $conn->prepare("SELECT * FROM funders WHERE email = ? AND status IN ('active', 'pending_approval') AND deleted_at IS NULL LIMIT 1");
                        $stmt2->bind_param('s', $user['email']);
                        $stmt2->execute();
                        $profile = $stmt2->get_result()->fetch_assoc();
                    } else {
                        $error_message = 'Failed to update profile.';
                    }
                } else {
                    $error_message = 'No changes to save.';
                }
            }
        }
    }

} catch (Throwable $e) {
    error_log('[Profile Error] ' . $e->getMessage());
    $error_message = 'An error occurred. Please try again.';
}
?>

<style>
.profile-container { display: grid; grid-template-columns: 280px 1fr; gap: 24px; max-width: 1200px; margin: 0 auto; }
.profile-sidebar { background: transparent; border: none; padding: 0; height: fit-content; }
.profile-sidebar-item { padding: 12px 14px; margin: 6px 0; border-radius: 8px; color: var(--text); text-decoration: none; display: block; font-size: 14px; transition: all .25s ease; border-left: 3px solid transparent; position: relative; }
.profile-sidebar-item:hover { background: var(--primary-2); color: var(--primary); border-left-color: var(--primary); transform: translateX(4px); }
.profile-sidebar-item.active { background: var(--primary); color: white; font-weight: 600; border-left-color: transparent; }
.profile-header { display: flex; gap: 20px; margin-bottom: 28px; align-items: flex-start; }
.profile-avatar { width: 120px; height: 120px; border-radius: 50%; background: var(--primary); color: white; display: flex; align-items: center; justify-content: center; font-size: 48px; font-weight: 700; flex-shrink: 0; }
.profile-info h1 { margin: 0 0 4px; font-size: 28px; color: var(--text); }
.profile-info p { margin: 4px 0; color: var(--muted); font-size: 14px; }
.profile-section { margin-bottom: 32px; }
.profile-section h2 { font-size: 18px; font-weight: 700; margin-bottom: 16px; color: var(--text); border-bottom: 2px solid #dde6dd; padding-bottom: 8px; }
.form-field { margin-bottom: 16px; }
.form-field label { display: block; font-weight: 600; color: var(--text); margin-bottom: 6px; font-size: 14px; }
.form-field input, .form-field textarea, .form-field select { width: 100%; padding: 10px 12px; border: 1px solid #dde6dd; border-radius: 6px; font-family: inherit; font-size: 14px; color: var(--text); }
.form-field textarea { min-height: 100px; resize: vertical; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-row-full { grid-column: 1 / -1; }
button.save-btn { background: var(--primary); color: white; padding: 12px 28px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 14px; transition: all .25s ease; margin-top: 16px; box-shadow: 0 2px 8px rgba(26, 107, 90, 0.15); }
button.save-btn:hover { background: #155043; transform: translateY(-2px); box-shadow: 0 4px 14px rgba(26, 107, 90, 0.25); }
.alert { padding: 12px 14px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; }
.alert-success { background: #eef9f6; border-left: 4px solid #1a6b5a; color: #1a6b5a; }
.alert-error { background: #fff5f5; border-left: 4px solid #b54646; color: #b54646; }
.profile-container a { text-decoration: none !important; }
.profile-container a:hover { text-decoration: none !important; }
.profile-sidebar-item:hover { text-decoration: none !important; }
@media(max-width: 860px) {
    .profile-container { grid-template-columns: 1fr; }
    .profile-sidebar { display: flex; gap: 8px; flex-direction: row; padding: 12px; overflow-x: auto; }
    .form-row { grid-template-columns: 1fr; }
}
</style>

<div class="profile-container" style="margin-top: 20px">
    <aside class="profile-sidebar">
        <div style="text-align: center; margin-bottom: 20px; padding: 20px; background: linear-gradient(135deg, var(--primary-2) 0%, rgba(220, 236, 231, 0.5) 100%); border-radius: 10px;">
            <div class="profile-avatar" style="margin: 0 auto 12px;"><?= strtoupper(substr($user['name'], 0, 2)) ?></div>
            <p style="margin: 0 0 4px; font-weight: 700; color: var(--text); font-size: 14px"><?= h($user['name']) ?></p>
            <p style="margin: 0; color: var(--muted); font-size: 12px"><?= h($user['email']) ?></p>
        </div>
        <nav style="margin-bottom: 12px;">
            <a href="?page=profile&tab=overview" class="profile-sidebar-item <?= $tab === 'overview' ? 'active' : '' ?>">Overview</a>
            <a href="?page=profile&tab=settings" class="profile-sidebar-item <?= $tab === 'settings' ? 'active' : '' ?>">Edit Profile</a>
            <a href="?page=profile&tab=links" class="profile-sidebar-item <?= $tab === 'links' ? 'active' : '' ?>">Links & Social</a>
            <a href="?page=profile&tab=preferences" class="profile-sidebar-item <?= $tab === 'preferences' ? 'active' : '' ?>">Preferences</a>
        </nav>
        <a href="?page=logout" class="profile-sidebar-item" style="color: #b54646; border-radius: 8px; padding: 12px 14px;">Logout</a>
    </aside>

    <main>
        <?php if ($success_message): ?>
        <div class="alert alert-success"><?= h($success_message) ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
        <div class="alert alert-error"><?= h($error_message) ?></div>
        <?php endif; ?>

        <?php if ($tab === 'overview'): ?>
        <!-- Overview Tab -->
        <div class="profile-section">
            <h2>Profile Overview</h2>
            <div class="profile-header">
                <div class="profile-avatar" style="width: 80px; height: 80px; font-size: 32px"><?= strtoupper(substr($user['name'], 0, 2)) ?></div>
                <div class="profile-info">
                    <h1><?= h($user['name']) ?></h1>
                    <p><?= h($user['email']) ?></p>
                    <p style="text-transform: capitalize; margin-top: 8px"><strong><?= h($user['role']) ?></strong> on FACT Alliance Hub</p>
                </div>
            </div>
        </div>

        <?php if ($user['role'] === 'researcher' && $profile): ?>
        <div class="profile-section">
            <h2>Research Profile</h2>
            <div class="form-row">
                <div><strong>Institution:</strong><br><?= h($profile['institution'] ?? 'Not specified') ?></div>
                <div><strong>Department:</strong><br><?= h($profile['department'] ?? 'Not specified') ?></div>
            </div>
            <div style="margin-top: 16px"><strong>Title:</strong><br><?= h($profile['title'] ?? 'Not specified') ?></div>
            <?php if ($profile['bio']): ?>
            <div style="margin-top: 16px"><strong>Bio:</strong><br><?= h($profile['bio']) ?></div>
            <?php endif; ?>
            <?php if ($profile['topics']): ?>
            <div style="margin-top: 16px"><strong>Research Topics:</strong><br><?= h($profile['topics']) ?></div>
            <?php endif; ?>
            <?php if ($profile['geography']): ?>
            <div style="margin-top: 16px"><strong>Geographic Focus:</strong><br><?= h($profile['geography']) ?></div>
            <?php endif; ?>
        </div>
        <?php elseif ($user['role'] === 'funder' && $profile): ?>
        <div class="profile-section">
            <h2>Funder Profile</h2>
            <div class="form-row">
                <div><strong>Organization:</strong><br><?= h($profile['organization'] ?? 'Not specified') ?></div>
                <div><strong>Type:</strong><br><?= h($profile['org_type'] ?? 'Not specified') ?></div>
            </div>
            <div style="margin-top: 16px"><strong>Country:</strong><br><?= h($profile['country'] ?? 'Not specified') ?></div>
            <?php if ($profile['bio']): ?>
            <div style="margin-top: 16px"><strong>Bio:</strong><br><?= h($profile['bio']) ?></div>
            <?php endif; ?>
            <?php if ($profile['topics']): ?>
            <div style="margin-top: 16px"><strong>Funding Focus (Topics):</strong><br><?= h($profile['topics']) ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <a href="?page=profile&tab=settings" class="primary-btn" style="margin-top: 16px">Edit Profile →</a>

        <?php elseif ($tab === 'settings' && $profile): ?>
        <!-- Edit Profile Tab -->
        <div class="profile-section">
            <h2>Edit Your Profile</h2>
            <form method="post">
                <input type="hidden" name="update_profile" value="1">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"

                <?php if ($user['role'] === 'researcher'): ?>
                <div class="form-row">
                    <div class="form-field">
                        <label>First Name</label>
                        <input type="text" name="first_name" value="<?= h($profile['first_name'] ?? '') ?>">
                    </div>
                    <div class="form-field">
                        <label>Last Name</label>
                        <input type="text" name="last_name" value="<?= h($profile['last_name'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label>Institution</label>
                        <input type="text" name="institution" placeholder="e.g. MIT, University of Ghana" value="<?= h($profile['institution'] ?? '') ?>">
                    </div>
                    <div class="form-field">
                        <label>Department</label>
                        <input type="text" name="department" value="<?= h($profile['department'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-field">
                    <label>Title / Position</label>
                    <input type="text" name="title" placeholder="e.g. Associate Professor" value="<?= h($profile['title'] ?? '') ?>">
                </div>

                <div class="form-field">
                    <label>Bio</label>
                    <textarea name="bio" placeholder="Tell us about your research...">
                        <?= h($profile['bio'] ?? '') ?>
                    </textarea>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label>Research Topics (comma-separated)</label>
                        <input type="text" name="topics" placeholder="e.g. food security, climate resilience" value="<?= h($profile['topics'] ?? '') ?>">
                    </div>
                    <div class="form-field">
                        <label>Geographic Focus (comma-separated)</label>
                        <input type="text" name="geography" placeholder="e.g. East Africa, South Asia" value="<?= h($profile['geography'] ?? '') ?>">
                    </div>
                </div>

                <?php elseif ($user['role'] === 'funder'): ?>
                <div class="form-row">
                    <div class="form-field">
                        <label>First Name</label>
                        <input type="text" name="first_name" value="<?= h($profile['first_name'] ?? '') ?>">
                    </div>
                    <div class="form-field">
                        <label>Last Name</label>
                        <input type="text" name="last_name" value="<?= h($profile['last_name'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label>Organization</label>
                        <input type="text" name="organization" placeholder="e.g. Bill & Melinda Gates Foundation" value="<?= h($profile['organization'] ?? '') ?>">
                    </div>
                    <div class="form-field">
                        <label>Organization Type</label>
                        <select name="org_type">
                            <option value="">-- select type --</option>
                            <option value="Government Agency" <?= ($profile['org_type'] ?? '') === 'Government Agency' ? 'selected' : '' ?>>Government Agency</option>
                            <option value="Private Foundation" <?= ($profile['org_type'] ?? '') === 'Private Foundation' ? 'selected' : '' ?>>Private Foundation</option>
                            <option value="Corporate / Industry" <?= ($profile['org_type'] ?? '') === 'Corporate / Industry' ? 'selected' : '' ?>>Corporate / Industry</option>
                            <option value="International NGO" <?= ($profile['org_type'] ?? '') === 'International NGO' ? 'selected' : '' ?>>International NGO</option>
                            <option value="Academic Institution" <?= ($profile['org_type'] ?? '') === 'Academic Institution' ? 'selected' : '' ?>>Academic Institution</option>
                            <option value="Multilateral Organisation" <?= ($profile['org_type'] ?? '') === 'Multilateral Organisation' ? 'selected' : '' ?>>Multilateral Organisation</option>
                            <option value="Other" <?= ($profile['org_type'] ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>
                </div>

                <div class="form-field">
                    <label>Country</label>
                    <input type="text" name="country" placeholder="e.g. United States" value="<?= h($profile['country'] ?? '') ?>">
                </div>

                <div class="form-field">
                    <label>Bio</label>
                    <textarea name="bio" placeholder="Describe your organization's mission...">
                        <?= h($profile['bio'] ?? '') ?>
                    </textarea>
                </div>

                <div class="form-row">
                    <div class="form-field">
                        <label>Funding Focus - Topics (comma-separated)</label>
                        <input type="text" name="topics" placeholder="e.g. food security, climate resilience" value="<?= h($profile['topics'] ?? '') ?>">
                    </div>
                    <div class="form-field">
                        <label>Geographic Focus (comma-separated)</label>
                        <input type="text" name="geography" placeholder="e.g. Sub-Saharan Africa" value="<?= h($profile['geography'] ?? '') ?>">
                    </div>
                </div>
                <?php endif; ?>

                <button type="submit" class="save-btn">Save Changes</button>
            </form>
        </div>

        <?php elseif ($tab === 'links' && $profile && $user['role'] === 'researcher'): ?>
        <!-- Links Tab (Researcher only) -->
        <div class="profile-section">
            <h2>Links & Social</h2>
            <form method="post">
                <input type="hidden" name="update_profile" value="1">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"

                <div class="form-field">
                    <label>Personal Website</label>
                    <input type="url" name="website_url" placeholder="https://..." value="<?= h($profile['website_url'] ?? '') ?>">
                </div>

                <div class="form-field">
                    <label>ORCID ID</label>
                    <input type="text" name="orcid_id" placeholder="0000-0000-0000-0000" value="<?= h($profile['orcid_id'] ?? '') ?>">
                </div>

                <div class="form-field">
                    <label>Google Scholar URL</label>
                    <input type="url" name="google_scholar_url" placeholder="https://scholar.google.com/..." value="<?= h($profile['google_scholar_url'] ?? '') ?>">
                </div>

                <div class="form-field">
                    <label>Research Profile URL</label>
                    <input type="url" name="profile_url" placeholder="https://..." value="<?= h($profile['profile_url'] ?? '') ?>">
                </div>

                <button type="submit" class="save-btn">Save Links</button>
            </form>
        </div>

        <?php elseif ($tab === 'links' && $profile && $user['role'] === 'funder'): ?>
        <!-- Links Tab (Funder) -->
        <div class="profile-section">
            <h2>Links</h2>
            <form method="post">
                <input type="hidden" name="update_profile" value="1">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"

                <div class="form-field">
                    <label>Organization Website</label>
                    <input type="url" name="website_url" placeholder="https://..." value="<?= h($profile['website_url'] ?? '') ?>">
                </div>

                <button type="submit" class="save-btn">Save Links</button>
            </form>
        </div>

        <?php elseif ($tab === 'preferences'): ?>
        <!-- Preferences Tab -->
        <div class="profile-section">
            <h2>Security & Account Settings</h2>
            <p style="color: var(--muted); margin-bottom: 20px">Manage your account security and email</p>

            <div style="background: #f8fafb; border: 1px solid #dde6dd; border-radius: 8px; padding: 16px; margin-bottom: 16px">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <p style="margin: 0 0 4px; font-weight: 600; color: var(--text)">Email Address</p>
                        <p style="margin: 0; color: var(--muted); font-size: 13px"><?= h($user['email']) ?></p>
                    </div>
                    <a href="?page=account&tab=email" class="ghost-btn" style="font-size: 13px; padding: 6px 12px">Change</a>
                </div>
            </div>

            <div style="background: #f8fafb; border: 1px solid #dde6dd; border-radius: 8px; padding: 16px; margin-bottom: 28px">
                <div style="display: flex; justify-content: space-between; align-items: center">
                    <div>
                        <p style="margin: 0 0 4px; font-weight: 600; color: var(--text)">Password</p>
                        <p style="margin: 0; color: var(--muted); font-size: 13px">Manage your password for login security</p>
                    </div>
                    <a href="?page=account&tab=password" class="ghost-btn" style="font-size: 13px; padding: 6px 12px">Change</a>
                </div>
            </div>

            <div style="background: #fff5f5; border: 1px solid #f0d8d8; border-radius: 8px; padding: 16px">
                <div style="display: flex; gap: 12px; align-items: flex-start">
                    <div style="color: #b54646; flex-shrink: 0; margin-top: 2px">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    </div>
                    <div>
                        <p style="margin: 0 0 4px; font-weight: 600; color: #b54646">Account Status</p>
                        <p style="margin: 0; color: #b54646; font-size: 13px">Your account is active and in good standing.</p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </main>
</div>

<script>
// Auto-hide success messages after 5 seconds
document.querySelectorAll('.alert-success').forEach(function(alert) {
    setTimeout(function() {
        alert.style.transition = 'opacity 0.3s';
        alert.style.opacity = '0';
        setTimeout(function() { alert.remove(); }, 300);
    }, 5000);
});
</script>

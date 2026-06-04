<?php
// Newsletter Preferences Page
// Allows logged-in users to manage their newsletter subscription and content preferences
require_once __DIR__ . '/../../core/helpers.php';

$user = current_user();
if (!is_logged_in()) {
    redirect_to('login');
}

$success_message = null;
$error_message = null;
$subscription_data = null;

try {
    // Fetch or create newsletter subscription record
    $stmt = $conn->prepare("SELECT * FROM newsletter_subscribers WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $user['email']);
    $stmt->execute();
    $subscription_data = $stmt->get_result()->fetch_assoc();

    // If no subscription record exists, fetch basic info from user profile
    if (!$subscription_data) {
        // Determine role for new subscription
        $role = 'both';
        if ($user['role'] === 'researcher') {
            $role = 'researcher';
        } elseif ($user['role'] === 'funder') {
            $role = 'funder';
        }

        // Create default subscription record
        $stmt = $conn->prepare("INSERT INTO newsletter_subscribers (email, status, role) VALUES (?, 'active', ?)");
        $stmt->bind_param('ss', $user['email'], $role);
        $stmt->execute();

        // Fetch the newly created record
        $stmt = $conn->prepare("SELECT * FROM newsletter_subscribers WHERE email = ? LIMIT 1");
        $stmt->bind_param('s', $user['email']);
        $stmt->execute();
        $subscription_data = $stmt->get_result()->fetch_assoc();
    }

    // Handle preference updates
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_preferences'])) {
        if (!verify_csrf()) {
            $error_message = 'Security token invalid. Please try again.';
        } else {
            $updates = [];
            $types = '';
            $params = [];

            // Build dynamic update query based on submitted fields
            $fields = [
                'status' => 's',
                'research_interests' => 's',
                'geography' => 's',
                'institution' => 's',
                'funding_preference' => 's',
            ];

            foreach ($fields as $field => $type) {
                if (isset($_POST[$field])) {
                    $value = trim($_POST[$field]);
                    // Handle status field (must be valid enum)
                    if ($field === 'status') {
                        if (!in_array($value, ['active', 'unsubscribed', 'bounced'], true)) {
                            continue;
                        }
                    }
                    $updates[] = "$field = ?";
                    $params[] = $value;
                    $types .= $type;
                }
            }

            // Add updated_at timestamp
            $updates[] = "updated_at = NOW()";

            if (!empty($updates)) {
                $types .= 's'; // for email in WHERE clause
                $params[] = $user['email'];
                $sql = 'UPDATE newsletter_subscribers SET ' . implode(', ', $updates) . " WHERE email = ?";
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception('Prepare failed: ' . $conn->error);
                }
                $stmt->bind_param($types, ...$params);
                if (!$stmt->execute()) {
                    throw new Exception('Execute failed: ' . $stmt->error);
                }

                $success_message = 'Newsletter preferences updated successfully!';
                audit($conn, 'update_newsletter_preferences', ['email' => $user['email']]);

                // Refresh subscription data
                $stmt = $conn->prepare("SELECT * FROM newsletter_subscribers WHERE email = ? LIMIT 1");
                $stmt->bind_param('s', $user['email']);
                $stmt->execute();
                $subscription_data = $stmt->get_result()->fetch_assoc();
            } else {
                $error_message = 'No changes to save.';
            }
        }
    }

} catch (Throwable $e) {
    error_log('[Newsletter Preferences Error] ' . $e->getMessage());
    $error_message = 'An error occurred. Please try again.';
}

// Generate secure preference management token for non-logged-in access
$mailCfg = require __DIR__ . '/../../../config/mail.php';
$notifySecret = $mailCfg['notify_secret'] ?? '';
$preference_token = bin2hex(hash_hmac('sha256', $user['email'] . '|newsletter_prefs', $notifySecret, true));
?>

<style>
.newsletter-container { max-width: 800px; margin: 0 auto; padding: 20px; }
.newsletter-section { background: white; border: 1px solid #dde6dd; border-radius: 10px; padding: 24px; margin-bottom: 24px; }
.newsletter-section h2 { font-size: 18px; font-weight: 700; margin: 0 0 16px; color: var(--text); }
.newsletter-section h3 { font-size: 14px; font-weight: 600; margin: 16px 0 12px; color: var(--text); }
.newsletter-section p { color: var(--muted); font-size: 14px; margin: 0 0 12px; line-height: 1.5; }

.subscription-status { display: flex; align-items: center; gap: 16px; background: #f8fafb; padding: 16px; border-radius: 8px; margin-bottom: 20px; }
.subscription-status-icon { width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.subscription-status-icon.active { background: #eaf6f0; color: #1a6b5a; }
.subscription-status-icon.inactive { background: #fee2e2; color: #dc2626; }
.subscription-status-info { flex: 1; }
.subscription-status-info p { margin: 0; }
.subscription-status-info strong { display: block; color: var(--text); font-weight: 600; margin-bottom: 2px; }

.form-group { margin-bottom: 20px; }
.form-group label { display: block; font-weight: 600; color: var(--text); margin-bottom: 8px; font-size: 14px; }

.toggle-switch { display: flex; align-items: center; gap: 12px; background: #f8fafb; padding: 14px 16px; border-radius: 8px; border: 1px solid #dde6dd; cursor: pointer; user-select: none; }
.toggle-switch input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; accent-color: #1a6b5a; }
.toggle-switch label { margin: 0; cursor: pointer; flex: 1; }

.radio-group, .checkbox-group { display: flex; flex-direction: column; gap: 10px; }
.radio-item, .checkbox-item { display: flex; align-items: center; gap: 10px; padding: 8px 0; }
.radio-item input[type="radio"], .checkbox-item input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; accent-color: #1a6b5a; flex-shrink: 0; }
.radio-item label, .checkbox-item label { margin: 0; cursor: pointer; font-size: 14px; color: var(--text); }

.email-display { background: #f8fafb; padding: 12px 14px; border-radius: 6px; border: 1px solid #dde6dd; font-size: 14px; color: var(--text); display: flex; align-items: center; justify-content: space-between; }

.alert { padding: 12px 14px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
.alert-success { background: #eef9f6; border-left: 4px solid #1a6b5a; color: #1a6b5a; }
.alert-error { background: #fff5f5; border-left: 4px solid #b54646; color: #b54646; }

.save-btn { background: var(--primary); color: white; padding: 12px 28px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 14px; transition: all .25s ease; box-shadow: 0 2px 8px rgba(26, 107, 90, 0.15); }
.save-btn:hover { background: #155043; transform: translateY(-2px); box-shadow: 0 4px 14px rgba(26, 107, 90, 0.25); }

.preference-links { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 16px; }
.preference-link { padding: 12px; background: #f8fafb; border: 1px solid #dde6dd; border-radius: 6px; text-align: center; }
.preference-link label { margin: 0 0 6px; display: block; font-size: 12px; color: var(--muted); font-weight: 500; }
.preference-link code { display: block; font-size: 11px; background: white; padding: 6px; border-radius: 4px; margin: 6px 0 0; word-break: break-all; overflow-x: auto; color: #666; }
.preference-link a { color: var(--primary); text-decoration: none; font-size: 12px; font-weight: 600; display: block; margin-top: 6px; }

.privacy-info { background: #f8fafb; border: 1px solid #dde6dd; border-radius: 8px; padding: 14px; margin-top: 16px; }
.privacy-info p { font-size: 13px; margin: 0 0 8px; line-height: 1.6; }
.privacy-info a { color: var(--primary); text-decoration: none; }
.privacy-info a:hover { text-decoration: underline; }

.subscription-date { font-size: 12px; color: var(--muted); margin-top: 4px; }

@media(max-width: 640px) {
    .newsletter-container { padding: 16px; }
    .newsletter-section { padding: 16px; }
    .preference-links { grid-template-columns: 1fr; }
}
</style>

<div class="newsletter-container">
    <div style="margin-bottom: 28px;">
        <h1 style="margin: 0 0 8px; font-size: 28px; color: var(--text);">Newsletter Preferences</h1>
        <p style="margin: 0; color: var(--muted); font-size: 14px;">Manage your email subscriptions and content preferences</p>
    </div>

    <?php if ($success_message): ?>
    <div class="alert alert-success"><?= h($success_message) ?></div>
    <?php endif; ?>
    <?php if ($error_message): ?>
    <div class="alert alert-error"><?= h($error_message) ?></div>
    <?php endif; ?>

    <!-- Subscription Status Section -->
    <div class="newsletter-section">
        <h2>Subscription Status</h2>

        <?php if ($subscription_data): ?>
        <div class="subscription-status">
            <div class="subscription-status-icon <?= $subscription_data['status'] === 'active' ? 'active' : 'inactive' ?>">
                <?php if ($subscription_data['status'] === 'active'): ?>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
                <?php else: ?>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line>
                </svg>
                <?php endif; ?>
            </div>
            <div class="subscription-status-info">
                <strong><?= $subscription_data['status'] === 'active' ? 'Subscribed' : 'Unsubscribed' ?></strong>
                <p style="font-size: 13px; margin: 2px 0 0;">
                    <?php if ($subscription_data['status'] === 'active'): ?>
                        You are receiving newsletter emails
                    <?php else: ?>
                        You are not receiving newsletter emails
                    <?php endif; ?>
                </p>
                <?php if ($subscription_data['subscribed_at']): ?>
                <div class="subscription-date">
                    <?php if ($subscription_data['status'] === 'active'): ?>
                        Subscribed on <?= date('M d, Y', strtotime($subscription_data['subscribed_at'])) ?>
                    <?php else: ?>
                        Unsubscribed on <?= date('M d, Y', strtotime($subscription_data['unsubscribed_at'] ?? 'now')) ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <form method="post" style="margin-top: 20px;">
            <?= csrf_input() ?>
            <input type="hidden" name="update_preferences" value="1">

            <div class="form-group">
                <label style="margin-bottom: 12px;">Email Subscription</label>
                <div class="radio-group">
                    <div class="radio-item">
                        <input type="radio" id="status_active" name="status" value="active"
                               <?= $subscription_data['status'] === 'active' ? 'checked' : '' ?> required>
                        <label for="status_active"><strong>Subscribe</strong> - Receive newsletter emails</label>
                    </div>
                    <div class="radio-item">
                        <input type="radio" id="status_unsubscribed" name="status" value="unsubscribed"
                               <?= $subscription_data['status'] === 'unsubscribed' ? 'checked' : '' ?> required>
                        <label for="status_unsubscribed"><strong>Unsubscribe</strong> - Stop receiving emails</label>
                    </div>
                </div>
            </div>

            <!-- Email Frequency Section -->
            <div style="margin-top: 24px; padding-top: 24px; border-top: 1px solid #dde6dd;">
                <h3>Email Frequency</h3>
                <p style="margin-bottom: 16px;">Choose how often you receive newsletter emails</p>

                <div class="radio-group">
                    <div class="radio-item">
                        <input type="radio" id="freq_immediate" name="frequency" value="immediate"
                               <?= ($subscription_data['frequency'] ?? 'weekly') === 'immediate' ? 'checked' : '' ?>>
                        <label for="freq_immediate"><strong>Immediate</strong> - As soon as new content is published</label>
                    </div>
                    <div class="radio-item">
                        <input type="radio" id="freq_daily" name="frequency" value="daily"
                               <?= ($subscription_data['frequency'] ?? 'weekly') === 'daily' ? 'checked' : '' ?>>
                        <label for="freq_daily"><strong>Daily</strong> - Once per day in the morning</label>
                    </div>
                    <div class="radio-item">
                        <input type="radio" id="freq_weekly" name="frequency" value="weekly"
                               <?= ($subscription_data['frequency'] ?? 'weekly') === 'weekly' ? 'checked' : '' ?>>
                        <label for="freq_weekly"><strong>Weekly</strong> - Every Monday morning</label>
                    </div>
                    <div class="radio-item">
                        <input type="radio" id="freq_never" name="frequency" value="never"
                               <?= ($subscription_data['frequency'] ?? 'weekly') === 'never' ? 'checked' : '' ?>>
                        <label for="freq_never"><strong>Never</strong> - Turn off email notifications</label>
                    </div>
                </div>
            </div>

            <!-- Content Preferences Section -->
            <div style="margin-top: 24px; padding-top: 24px; border-top: 1px solid #dde6dd;">
                <h3>Content Preferences</h3>
                <p style="margin-bottom: 16px;">Select the types of content you're interested in receiving</p>

                <div style="background: #f8fafb; padding: 14px; border-radius: 6px; margin-bottom: 16px;">
                    <strong style="font-size: 13px; color: var(--text); display: block; margin-bottom: 10px;">Research Interests</strong>
                    <input type="text" name="research_interests" placeholder="e.g. climate change, agriculture, health"
                           value="<?= h($subscription_data['research_interests'] ?? '') ?>"
                           style="width: 100%; padding: 10px 12px; border: 1px solid #dde6dd; border-radius: 6px; font-size: 13px;">
                    <small style="color: var(--muted); display: block; margin-top: 6px;">Comma-separated list of topics you're interested in</small>
                </div>

                <div style="background: #f8fafb; padding: 14px; border-radius: 6px; margin-bottom: 16px;">
                    <strong style="font-size: 13px; color: var(--text); display: block; margin-bottom: 10px;">Geographic Regions</strong>
                    <input type="text" name="geography" placeholder="e.g. Sub-Saharan Africa, South Asia"
                           value="<?= h($subscription_data['geography'] ?? '') ?>"
                           style="width: 100%; padding: 10px 12px; border: 1px solid #dde6dd; border-radius: 6px; font-size: 13px;">
                    <small style="color: var(--muted); display: block; margin-top: 6px;">Regions you want to focus on</small>
                </div>

                <div style="background: #f8fafb; padding: 14px; border-radius: 6px; margin-bottom: 16px;">
                    <strong style="font-size: 13px; color: var(--text); display: block; margin-bottom: 10px;">Institution Types</strong>
                    <input type="text" name="institution" placeholder="e.g. universities, NGOs, government agencies"
                           value="<?= h($subscription_data['institution'] ?? '') ?>"
                           style="width: 100%; padding: 10px 12px; border: 1px solid #dde6dd; border-radius: 6px; font-size: 13px;">
                    <small style="color: var(--muted); display: block; margin-top: 6px;">Types of institutions you partner with</small>
                </div>

                <div style="background: #f8fafb; padding: 14px; border-radius: 6px;">
                    <strong style="font-size: 13px; color: var(--text); display: block; margin-bottom: 10px;">Funding Focus Areas</strong>
                    <input type="text" name="funding_preference" placeholder="e.g. early-stage research, capacity building, dissemination"
                           value="<?= h($subscription_data['funding_preference'] ?? '') ?>"
                           style="width: 100%; padding: 10px 12px; border: 1px solid #dde6dd; border-radius: 6px; font-size: 13px;">
                    <small style="color: var(--muted); display: block; margin-top: 6px;">Types of funding opportunities relevant to you</small>
                </div>
            </div>

            <!-- Email Address Section -->
            <div style="margin-top: 24px; padding-top: 24px; border-top: 1px solid #dde6dd;">
                <h3>Email Address</h3>
                <p style="margin-bottom: 16px;">Your current email for newsletter subscriptions</p>

                <div class="email-display">
                    <span><?= h($user['email']) ?></span>
                    <a href="?page=profile&tab=preferences" style="color: var(--primary); font-size: 12px; font-weight: 600; text-decoration: none;">Change</a>
                </div>
            </div>

            <button type="submit" class="save-btn" style="margin-top: 24px; width: 100%;">Save Preferences</button>
        </form>
        <?php endif; ?>
    </div>

    <!-- Privacy & Compliance Section -->
    <div class="newsletter-section">
        <h2>Privacy & Compliance</h2>
        <div class="privacy-info">
            <p>
                <strong>Data Usage:</strong> We use your email address and preferences exclusively to send you relevant content from the FACT Alliance Hub. Your data is encrypted, securely stored, and never shared with third parties without your consent.
            </p>
            <p>
                <strong>Unsubscribe Anytime:</strong> Every email includes an unsubscribe link that works with a single click. You can also manage your preferences at any time from this page.
            </p>
            <p>
                <strong>GDPR Compliance:</strong> We comply with GDPR, CAN-SPAM, and other privacy regulations. Your personal data is processed lawfully with your explicit consent.
            </p>
            <p>
                <a href="?page=privacy" target="_blank">Read our full Privacy Policy →</a>
            </p>
        </div>
    </div>

    <!-- Preference Management Links Section -->
    <div class="newsletter-section">
        <h2>Preference Management Links</h2>
        <p>Use these secure links to manage preferences without logging in:</p>

        <div class="preference-links">
            <div class="preference-link">
                <label>Unsubscribe Link</label>
                <p style="font-size: 12px; color: var(--muted); margin: 6px 0 0; line-height: 1.4;">
                    One-click secure unsubscribe
                </p>
                <code>index.php?page=newsletter_unsubscribe&e=<?= urlencode($user['email']) ?>&t=<?= h($preference_token) ?></code>
                <a href="index.php?page=newsletter_unsubscribe&e=<?= urlencode($user['email']) ?>&t=<?= h($preference_token) ?>">Test Unsubscribe Link →</a>
            </div>
            <div class="preference-link">
                <label>Manage Preferences Link</label>
                <p style="font-size: 12px; color: var(--muted); margin: 6px 0 0; line-height: 1.4;">
                    Manage without logging in
                </p>
                <code>index.php?page=newsletter_prefs&e=<?= urlencode($user['email']) ?>&t=<?= h($preference_token) ?></code>
                <a href="index.php?page=newsletter_prefs&e=<?= urlencode($user['email']) ?>&t=<?= h($preference_token) ?>">Test Preferences Link →</a>
            </div>
        </div>
    </div>
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

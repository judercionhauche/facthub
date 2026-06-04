<?php
/**
 * Admin Newsletter Dashboard
 * Manages email campaigns, templates, subscribers, and analytics
 * Requires admin-only access via require_admin()
 */
require_admin();

$adminUser = current_user();

// Get mail config
$mailCfg = @include __DIR__ . '/../../config/mail.php';
if (!is_array($mailCfg)) {
    $mailCfg = ['app_url' => 'http://localhost/facthub/public'];
}
$appUrl = rtrim($mailCfg['app_url'] ?? 'http://localhost/facthub/public', '/');

// Initialize database tables if needed (migrations would handle this in production)
ensure_newsletter_tables();

// Determine current tab
$nwlTab = in_array($_GET['tab'] ?? '', ['campaigns', 'subscribers', 'templates', 'analytics'])
    ? $_GET['tab'] : 'campaigns';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Create new campaign
    if ($action === 'create_campaign' && isset($_POST['csrf_token']) && verify_csrf($_POST['csrf_token'])) {
        $title = trim($_POST['title'] ?? '');
        $content = $_POST['content'] ?? '';
        $senderName = trim($_POST['sender_name'] ?? '');
        $senderEmail = trim($_POST['sender_email'] ?? '');

        if (!empty($title) && !empty($content)) {
            $stmt = $conn->prepare("INSERT INTO newsletter_campaigns (title, content, sender_name, sender_email, status, created_by, created_at) VALUES (?, ?, ?, ?, 'draft', ?, NOW())");
            $stmt->bind_param('sssss', $title, $content, $senderName, $senderEmail, $adminUser['email']);
            if ($stmt->execute()) {
                audit($conn, 'create_campaign', ['detail' => 'Campaign: ' . $title]);
                set_flash('success', 'Campaign created as draft.');
            }
        }
        redirect_to('admin/newsletter', ['tab' => 'campaigns']);
    }

    // Update campaign
    if ($action === 'update_campaign' && isset($_POST['csrf_token']) && verify_csrf($_POST['csrf_token'])) {
        $campaignId = (int)($_POST['campaign_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $content = $_POST['content'] ?? '';
        $senderName = trim($_POST['sender_name'] ?? '');
        $senderEmail = trim($_POST['sender_email'] ?? '');

        if ($campaignId && !empty($title)) {
            $stmt = $conn->prepare("UPDATE newsletter_campaigns SET title=?, content=?, sender_name=?, sender_email=?, updated_at=NOW() WHERE id=? AND status='draft'");
            $stmt->bind_param('ssssi', $title, $content, $senderName, $senderEmail, $campaignId);
            if ($stmt->execute()) {
                audit($conn, 'update_campaign', ['detail' => 'Campaign ID: ' . $campaignId]);
                set_flash('success', 'Campaign updated.');
            }
        }
        redirect_to('admin/newsletter', ['tab' => 'campaigns']);
    }

    // Send test email
    if ($action === 'send_test' && isset($_POST['csrf_token']) && verify_csrf($_POST['csrf_token'])) {
        $campaignId = (int)($_POST['campaign_id'] ?? 0);
        $testEmail = filter_var(trim($_POST['test_email'] ?? ''), FILTER_VALIDATE_EMAIL);

        if ($campaignId && $testEmail) {
            $campaign = get_campaign($conn, $campaignId);
            if ($campaign) {
                send_test_email($campaign, $testEmail);
                audit($conn, 'send_test_email', ['detail' => 'Campaign: ' . $campaign['title'] . ' to ' . $testEmail]);
                set_flash('success', 'Test email sent to ' . h($testEmail));
            }
        }
        redirect_to('admin/newsletter', ['tab' => 'campaigns', 'edit' => $campaignId]);
    }

    // Schedule campaign
    if ($action === 'schedule_campaign' && isset($_POST['csrf_token']) && verify_csrf($_POST['csrf_token'])) {
        $campaignId = (int)($_POST['campaign_id'] ?? 0);
        $scheduleDate = $_POST['schedule_date'] ?? '';
        $scheduleTime = $_POST['schedule_time'] ?? '';

        if ($campaignId && $scheduleDate && $scheduleTime) {
            $scheduledAt = $scheduleDate . ' ' . $scheduleTime;
            $stmt = $conn->prepare("UPDATE newsletter_campaigns SET scheduled_at=?, status='scheduled' WHERE id=?");
            $stmt->bind_param('si', $scheduledAt, $campaignId);
            if ($stmt->execute()) {
                audit($conn, 'schedule_campaign', ['detail' => 'Campaign ID: ' . $campaignId . ' for ' . $scheduledAt]);
                set_flash('success', 'Campaign scheduled for ' . $scheduledAt);
            }
        }
        redirect_to('admin/newsletter', ['tab' => 'campaigns']);
    }

    // Send campaign immediately
    if ($action === 'send_campaign' && isset($_POST['csrf_token']) && verify_csrf($_POST['csrf_token'])) {
        $campaignId = (int)($_POST['campaign_id'] ?? 0);
        if ($campaignId) {
            $campaign = get_campaign($conn, $campaignId);
            if ($campaign) {
                send_newsletter_campaign($conn, $campaign);
                audit($conn, 'send_campaign', ['detail' => 'Campaign: ' . $campaign['title']]);
                set_flash('success', 'Campaign sent to all subscribers.');
            }
        }
        redirect_to('admin/newsletter', ['tab' => 'campaigns']);
    }

    // Pause campaign
    if ($action === 'pause_campaign' && isset($_POST['csrf_token']) && verify_csrf($_POST['csrf_token'])) {
        $campaignId = (int)($_POST['campaign_id'] ?? 0);
        if ($campaignId) {
            $stmt = $conn->prepare("UPDATE newsletter_campaigns SET status='paused' WHERE id=?");
            $stmt->bind_param('i', $campaignId);
            if ($stmt->execute()) {
                audit($conn, 'pause_campaign', ['detail' => 'Campaign ID: ' . $campaignId]);
                set_flash('success', 'Campaign paused.');
            }
        }
        redirect_to('admin/newsletter', ['tab' => 'campaigns']);
    }

    // Resume campaign
    if ($action === 'resume_campaign' && isset($_POST['csrf_token']) && verify_csrf($_POST['csrf_token'])) {
        $campaignId = (int)($_POST['campaign_id'] ?? 0);
        if ($campaignId) {
            $stmt = $conn->prepare("UPDATE newsletter_campaigns SET status='sending' WHERE id=?");
            $stmt->bind_param('i', $campaignId);
            if ($stmt->execute()) {
                audit($conn, 'resume_campaign', ['detail' => 'Campaign ID: ' . $campaignId]);
                set_flash('success', 'Campaign resumed.');
            }
        }
        redirect_to('admin/newsletter', ['tab' => 'campaigns']);
    }

    // Delete campaign
    if ($action === 'delete_campaign' && isset($_POST['csrf_token']) && verify_csrf($_POST['csrf_token'])) {
        $campaignId = (int)($_POST['campaign_id'] ?? 0);
        if ($campaignId) {
            $stmt = $conn->prepare("DELETE FROM newsletter_campaigns WHERE id=? AND status='draft'");
            $stmt->bind_param('i', $campaignId);
            if ($stmt->execute()) {
                audit($conn, 'delete_campaign', ['detail' => 'Campaign ID: ' . $campaignId]);
                set_flash('success', 'Campaign deleted.');
            }
        }
        redirect_to('admin/newsletter', ['tab' => 'campaigns']);
    }

    // Create template
    if ($action === 'create_template' && isset($_POST['csrf_token']) && verify_csrf($_POST['csrf_token'])) {
        $templateName = trim($_POST['template_name'] ?? '');
        $templateContent = $_POST['template_content'] ?? '';

        if (!empty($templateName)) {
            $stmt = $conn->prepare("INSERT INTO newsletter_templates (name, content, created_by, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->bind_param('sss', $templateName, $templateContent, $adminUser['email']);
            if ($stmt->execute()) {
                audit($conn, 'create_template', ['detail' => 'Template: ' . $templateName]);
                set_flash('success', 'Template created.');
            }
        }
        redirect_to('admin/newsletter', ['tab' => 'templates']);
    }

    // Delete template
    if ($action === 'delete_template' && isset($_POST['csrf_token']) && verify_csrf($_POST['csrf_token'])) {
        $templateId = (int)($_POST['template_id'] ?? 0);
        if ($templateId) {
            $stmt = $conn->prepare("DELETE FROM newsletter_templates WHERE id=?");
            $stmt->bind_param('i', $templateId);
            if ($stmt->execute()) {
                audit($conn, 'delete_template', ['detail' => 'Template ID: ' . $templateId]);
                set_flash('success', 'Template deleted.');
            }
        }
        redirect_to('admin/newsletter', ['tab' => 'templates']);
    }

    // Unsubscribe user
    if ($action === 'unsubscribe_subscriber' && isset($_POST['csrf_token']) && verify_csrf($_POST['csrf_token'])) {
        $subscriberId = (int)($_POST['subscriber_id'] ?? 0);
        if ($subscriberId) {
            $stmt = $conn->prepare("UPDATE newsletter_subscribers SET status='unsubscribed', unsubscribed_at=NOW() WHERE id=?");
            $stmt->bind_param('i', $subscriberId);
            if ($stmt->execute()) {
                audit($conn, 'unsubscribe_subscriber', ['detail' => 'Subscriber ID: ' . $subscriberId]);
                set_flash('success', 'Subscriber unsubscribed.');
            }
        }
        redirect_to('admin/newsletter', ['tab' => 'subscribers']);
    }

    // Resubscribe user
    if ($action === 'resubscribe_subscriber' && isset($_POST['csrf_token']) && verify_csrf($_POST['csrf_token'])) {
        $subscriberId = (int)($_POST['subscriber_id'] ?? 0);
        if ($subscriberId) {
            $stmt = $conn->prepare("UPDATE newsletter_subscribers SET status='active', unsubscribed_at=NULL WHERE id=?");
            $stmt->bind_param('i', $subscriberId);
            if ($stmt->execute()) {
                audit($conn, 'resubscribe_subscriber', ['detail' => 'Subscriber ID: ' . $subscriberId]);
                set_flash('success', 'Subscriber reactivated.');
            }
        }
        redirect_to('admin/newsletter', ['tab' => 'subscribers']);
    }
}

// Get data for display
$campaigns = get_campaigns($conn);
$subscribers = get_subscribers($conn);
$templates = get_templates($conn);
$analytics = get_analytics($conn);
$campaignId = (int)($_GET['edit'] ?? 0);
$editCampaign = $campaignId ? get_campaign($conn, $campaignId) : null;

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Newsletter Dashboard - Admin</title>
    <style>
        :root {
            --primary: #0066cc;
            --success: #1a6b5a;
            --danger: #b54646;
            --warning: #b45309;
            --line: #e5e7eb;
            --bg: #f9fafb;
            --muted: #6b7280;
            --text: #111827;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.5;
        }

        .newsletter-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .page-header {
            margin-bottom: 24px;
        }

        .page-header h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .page-header p {
            color: var(--muted);
            font-size: 14px;
        }

        .tabs {
            display: flex;
            gap: 24px;
            border-bottom: 1px solid var(--line);
            margin-bottom: 24px;
        }

        .tab-link {
            padding: 12px 0;
            border-bottom: 3px solid transparent;
            color: var(--muted);
            text-decoration: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .tab-link.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .panel {
            background: white;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .panel-title {
            font-size: 18px;
            font-weight: 600;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: #0052a3;
        }

        .btn-ghost {
            background: white;
            color: var(--primary);
            border: 1px solid var(--line);
        }

        .btn-ghost:hover {
            background: var(--bg);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #9a3838;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #145348;
        }

        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: var(--bg);
        }

        th {
            padding: 12px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
            border-bottom: 1px solid var(--line);
        }

        td {
            padding: 12px;
            border-bottom: 1px solid var(--line);
        }

        tr:hover {
            background: var(--bg);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-draft {
            background: #f3f4f6;
            color: var(--muted);
        }

        .status-scheduled {
            background: #fef3c7;
            color: var(--warning);
        }

        .status-sending {
            background: #dbeafe;
            color: #0284c7;
        }

        .status-sent {
            background: #dcfce7;
            color: var(--success);
        }

        .status-paused {
            background: #fee2e2;
            color: var(--danger);
        }

        .status-active {
            background: #dcfce7;
            color: var(--success);
        }

        .status-unsubscribed {
            background: #f3f4f6;
            color: var(--muted);
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            font-size: 14px;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--line);
            border-radius: 6px;
            font-family: inherit;
            font-size: 14px;
        }

        .form-group textarea {
            min-height: 200px;
            resize: vertical;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-row.full {
            grid-template-columns: 1fr;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .editor-toolbar {
            display: flex;
            gap: 10px;
            padding: 10px;
            background: var(--bg);
            border: 1px solid var(--line);
            border-bottom: none;
            border-radius: 6px 6px 0 0;
        }

        .editor-button {
            padding: 6px 10px;
            background: white;
            border: 1px solid var(--line);
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }

        .editor-button:hover {
            background: var(--bg);
        }

        .rich-editor {
            padding: 10px;
            border: 1px solid var(--line);
            border-radius: 0 0 6px 6px;
            min-height: 300px;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 8px;
            padding: 24px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            margin-bottom: 20px;
        }

        .modal-header h2 {
            font-size: 20px;
            font-weight: 600;
        }

        .modal-footer {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            justify-content: flex-end;
        }

        .close-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--muted);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 16px;
        }

        .stat-label {
            font-size: 13px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
        }

        .stat-change {
            font-size: 12px;
            margin-top: 8px;
        }

        .stat-change.positive {
            color: var(--success);
        }

        .stat-change.negative {
            color: var(--danger);
        }

        .chart {
            background: white;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .chart-title {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 16px;
        }

        .bar {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .bar-label {
            min-width: 100px;
            font-size: 13px;
        }

        .bar-container {
            flex: 1;
            height: 24px;
            background: var(--bg);
            border-radius: 4px;
            overflow: hidden;
        }

        .bar-fill {
            height: 100%;
            background: var(--primary);
        }

        .bar-value {
            min-width: 60px;
            text-align: right;
            font-weight: 600;
            font-size: 13px;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 14px;
        }

        .alert-success {
            background: #dcfce7;
            color: var(--success);
            border: 1px solid #bbf7d0;
        }

        .alert-info {
            background: #dbeafe;
            color: #0284c7;
            border: 1px solid #bfdbfe;
        }

        .alert-warning {
            background: #fef3c7;
            color: var(--warning);
            border: 1px solid #fcd34d;
        }

        .alert-danger {
            background: #fee2e2;
            color: var(--danger);
            border: 1px solid #fca5a5;
        }

        .filter-section {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .filter-group {
            display: flex;
            gap: 6px;
            align-items: center;
        }

        .filter-group label {
            font-weight: 500;
            font-size: 13px;
        }

        .filter-group input[type="checkbox"] {
            width: auto;
        }

        .preview-pane {
            background: var(--bg);
            border: 1px solid var(--line);
            border-radius: 6px;
            padding: 16px;
            min-height: 400px;
        }

        .code-block {
            background: #1f2937;
            color: #f3f4f6;
            padding: 12px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 12px;
            overflow-x: auto;
            margin-bottom: 16px;
        }

        .action-menu {
            display: flex;
            gap: 6px;
        }

        .action-menu .btn {
            margin: 0;
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .tabs {
                flex-wrap: wrap;
                gap: 12px;
            }
        }
    </style>
</head>
<body>

<div class="newsletter-container">

    <!-- Page Header -->
    <div class="page-header">
        <h1>Newsletter Dashboard</h1>
        <p>Create campaigns, manage subscribers, and track engagement metrics</p>
    </div>

    <!-- Tabs -->
    <div class="tabs">
        <a class="tab-link <?= $nwlTab === 'campaigns' ? 'active' : '' ?>" onclick="switchTab('campaigns')">Campaigns</a>
        <a class="tab-link <?= $nwlTab === 'subscribers' ? 'active' : '' ?>" onclick="switchTab('subscribers')">Subscribers</a>
        <a class="tab-link <?= $nwlTab === 'templates' ? 'active' : '' ?>" onclick="switchTab('templates')">Templates</a>
        <a class="tab-link <?= $nwlTab === 'analytics' ? 'active' : '' ?>" onclick="switchTab('analytics')">Analytics</a>
    </div>

    <!-- ═════════════════════════════════════════════════════════════════ -->
    <!-- TAB 1: CAMPAIGNS ─ Create, Edit, Send, Schedule, Analytics -->
    <!-- ═════════════════════════════════════════════════════════════════ -->
    <div id="campaigns-tab" class="tab-content <?= $nwlTab === 'campaigns' ? 'active' : '' ?>">

        <!-- Create New Campaign Panel -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Create New Campaign</div>
            </div>

            <?php if ($editCampaign): ?>
                <!-- Edit Mode -->
                <div class="alert alert-info">Editing draft: <?= h($editCampaign['title']) ?></div>

                <form method="post" class="campaign-form">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="update_campaign">
                    <input type="hidden" name="campaign_id" value="<?= $editCampaign['id'] ?>">

                    <div class="form-row">
                        <div class="form-group">
                            <label>Campaign Title *</label>
                            <input type="text" name="title" value="<?= h($editCampaign['title']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Sender Name</label>
                            <input type="text" name="sender_name" value="<?= h($editCampaign['sender_name']) ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Sender Email</label>
                            <input type="email" name="sender_email" value="<?= h($editCampaign['sender_email']) ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Email Content (Rich Text with MJML Support) *</label>
                        <div class="editor-toolbar">
                            <button type="button" class="editor-button" onclick="insertTag('<strong>', '</strong>')"><b>Bold</b></button>
                            <button type="button" class="editor-button" onclick="insertTag('<em>', '</em>')"><i>Italic</i></button>
                            <button type="button" class="editor-button" onclick="insertTag('<a href=\"#\">', '</a>')">Link</button>
                            <button type="button" class="editor-button" onclick="insertTag('<h2>', '</h2>')">Heading</button>
                            <button type="button" class="editor-button" onclick="insertMJMLTemplate()">MJML Template</button>
                            <button type="button" class="editor-button" onclick="togglePreview()">Preview</button>
                        </div>
                        <textarea name="content" id="editor" required><?= h($editCampaign['content']) ?></textarea>
                    </div>

                    <div class="form-group" id="preview-container" style="display:none;">
                        <label>Live Preview</label>
                        <div class="preview-pane" id="preview-content"></div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Update Draft</button>
                        <a href="index.php?page=admin&section=newsletter&tab=campaigns" class="btn btn-ghost">Cancel</a>
                    </div>
                </form>

            <?php else: ?>
                <!-- Create Mode -->
                <form method="post" class="campaign-form">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="create_campaign">

                    <div class="form-row">
                        <div class="form-group">
                            <label>Campaign Title *</label>
                            <input type="text" name="title" placeholder="e.g., Monthly Research Digest" required>
                        </div>
                        <div class="form-group">
                            <label>Sender Name</label>
                            <input type="text" name="sender_name" placeholder="e.g., FACT Hub Team">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Sender Email</label>
                            <input type="email" name="sender_email" placeholder="noreply@facthub.org">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Email Content (Rich Text with MJML Support) *</label>
                        <div class="editor-toolbar">
                            <button type="button" class="editor-button" onclick="insertTag('<strong>', '</strong>')"><b>Bold</b></button>
                            <button type="button" class="editor-button" onclick="insertTag('<em>', '</em>')"><i>Italic</i></button>
                            <button type="button" class="editor-button" onclick="insertTag('<a href=\"#\">', '</a>')">Link</button>
                            <button type="button" class="editor-button" onclick="insertTag('<h2>', '</h2>')">Heading</button>
                            <button type="button" class="editor-button" onclick="insertMJMLTemplate()">MJML Template</button>
                            <button type="button" class="editor-button" onclick="togglePreview()">Preview</button>
                        </div>
                        <textarea name="content" id="editor" placeholder="Start typing your email content or use templates..." required></textarea>
                    </div>

                    <div class="form-group" id="preview-container" style="display:none;">
                        <label>Live Preview</label>
                        <div class="preview-pane" id="preview-content"></div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Save as Draft</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <!-- Campaign List -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Campaigns</div>
            </div>

            <?php if (empty($campaigns)): ?>
                <p style="color: var(--muted); text-align: center; padding: 20px;">No campaigns yet. Create one above to get started.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Status</th>
                                <th>Sent</th>
                                <th>Open Rate</th>
                                <th>Click Rate</th>
                                <th>Recipients</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($campaigns as $camp): ?>
                            <tr>
                                <td><strong><?= h($camp['title']) ?></strong></td>
                                <td>
                                    <span class="status-badge status-<?= h($camp['status']) ?>">
                                        <?= ucfirst(h($camp['status'])) ?>
                                    </span>
                                </td>
                                <td><?= h($camp['sent_date'] ?? '-') ?></td>
                                <td><?= round($camp['open_rate'] ?? 0, 1) ?>%</td>
                                <td><?= round($camp['click_rate'] ?? 0, 1) ?>%</td>
                                <td><?= $camp['recipient_count'] ?? 0 ?></td>
                                <td>
                                    <div class="action-menu">
                                        <?php if ($camp['status'] === 'draft'): ?>
                                            <a href="?page=admin&section=newsletter&tab=campaigns&edit=<?= $camp['id'] ?>" class="btn btn-ghost btn-small">Edit</a>
                                            <button onclick="openTestModal(<?= $camp['id'] ?>)" class="btn btn-ghost btn-small">Test</button>
                                            <button onclick="openScheduleModal(<?= $camp['id'] ?>)" class="btn btn-ghost btn-small">Schedule</button>
                                        <?php elseif ($camp['status'] === 'scheduled'): ?>
                                            <a href="?page=admin&section=newsletter&tab=campaigns&edit=<?= $camp['id'] ?>" class="btn btn-ghost btn-small">View</a>
                                            <form method="post" style="display:inline;">
                                                <?= csrf_input() ?>
                                                <input type="hidden" name="action" value="send_campaign">
                                                <input type="hidden" name="campaign_id" value="<?= $camp['id'] ?>">
                                                <button type="submit" class="btn btn-success btn-small" onclick="return confirm('Send now?')">Send Now</button>
                                            </form>
                                        <?php elseif ($camp['status'] === 'sending' || $camp['status'] === 'sent'): ?>
                                            <a href="?page=admin&section=newsletter&tab=analytics&campaign=<?= $camp['id'] ?>" class="btn btn-ghost btn-small">Stats</a>
                                            <?php if ($camp['status'] === 'sending'): ?>
                                                <form method="post" style="display:inline;">
                                                    <?= csrf_input() ?>
                                                    <input type="hidden" name="action" value="pause_campaign">
                                                    <input type="hidden" name="campaign_id" value="<?= $camp['id'] ?>">
                                                    <button type="submit" class="btn btn-ghost btn-small">Pause</button>
                                                </form>
                                            <?php endif; ?>
                                        <?php elseif ($camp['status'] === 'paused'): ?>
                                            <a href="?page=admin&section=newsletter&tab=analytics&campaign=<?= $camp['id'] ?>" class="btn btn-ghost btn-small">Stats</a>
                                            <form method="post" style="display:inline;">
                                                <?= csrf_input() ?>
                                                <input type="hidden" name="action" value="resume_campaign">
                                                <input type="hidden" name="campaign_id" value="<?= $camp['id'] ?>">
                                                <button type="submit" class="btn btn-ghost btn-small">Resume</button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($camp['status'] === 'draft'): ?>
                                            <form method="post" style="display:inline;">
                                                <?= csrf_input() ?>
                                                <input type="hidden" name="action" value="delete_campaign">
                                                <input type="hidden" name="campaign_id" value="<?= $camp['id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-small" onclick="return confirm('Delete this draft?')">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- ═════════════════════════════════════════════════════════════════ -->
    <!-- TAB 2: SUBSCRIBERS ─ Manage, Filter, View Preferences -->
    <!-- ═════════════════════════════════════════════════════════════════ -->
    <div id="subscribers-tab" class="tab-content <?= $nwlTab === 'subscribers' ? 'active' : '' ?>">

        <!-- Filters & Search -->
        <div class="panel">
            <div class="filter-section">
                <div class="filter-group">
                    <label>Filter by Status:</label>
                    <input type="checkbox" id="filter-active" checked> Active
                    <input type="checkbox" id="filter-unsubscribed"> Unsubscribed
                </div>
            </div>
        </div>

        <!-- Subscribers Table -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Subscribers (<?= count($subscribers) ?>)</div>
            </div>

            <?php if (empty($subscribers)): ?>
                <p style="color: var(--muted); text-align: center; padding: 20px;">No subscribers yet.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Subscribed</th>
                                <th>Research Interests</th>
                                <th>Geography</th>
                                <th>Institution</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subscribers as $sub): ?>
                            <tr>
                                <td><?= h($sub['email']) ?></td>
                                <td>
                                    <span class="status-badge status-<?= h($sub['status']) ?>">
                                        <?= ucfirst(h($sub['status'])) ?>
                                    </span>
                                </td>
                                <td><?= date('M j, Y', strtotime($sub['subscribed_at'])) ?></td>
                                <td><?= h(substr($sub['research_interests'] ?? '', 0, 30)) ?><?= strlen($sub['research_interests'] ?? '') > 30 ? '...' : '' ?></td>
                                <td><?= h($sub['geography'] ?? '-') ?></td>
                                <td><?= h($sub['institution'] ?? '-') ?></td>
                                <td>
                                    <div class="action-menu">
                                        <button onclick="openSubscriberModal(<?= $sub['id'] ?>)" class="btn btn-ghost btn-small">View</button>
                                        <?php if ($sub['status'] === 'active'): ?>
                                            <form method="post" style="display:inline;">
                                                <?= csrf_input() ?>
                                                <input type="hidden" name="action" value="unsubscribe_subscriber">
                                                <input type="hidden" name="subscriber_id" value="<?= $sub['id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-small" onclick="return confirm('Unsubscribe this user?')">Unsub</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" style="display:inline;">
                                                <?= csrf_input() ?>
                                                <input type="hidden" name="action" value="resubscribe_subscriber">
                                                <input type="hidden" name="subscriber_id" value="<?= $sub['id'] ?>">
                                                <button type="submit" class="btn btn-success btn-small">Resub</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- ═════════════════════════════════════════════════════════════════ -->
    <!-- TAB 3: TEMPLATES ─ Manage MJML Templates -->
    <!-- ═════════════════════════════════════════════════════════════════ -->
    <div id="templates-tab" class="tab-content <?= $nwlTab === 'templates' ? 'active' : '' ?>">

        <!-- Create Template Panel -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Create New Template</div>
            </div>

            <form method="post">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="create_template">

                <div class="form-group">
                    <label>Template Name *</label>
                    <input type="text" name="template_name" placeholder="e.g., Monthly Newsletter" required>
                </div>

                <div class="form-group">
                    <label>MJML Template Content (with {{placeholder}} syntax)</label>
                    <textarea name="template_content" placeholder="<mjml>
  <mj-body>
    <mj-section>
      <mj-column>
        <mj-text>Hello {{first_name}}</mj-text>
      </mj-column>
    </mj-section>
  </mj-body>
</mjml>" style="font-family: monospace;"></textarea>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Create Template</button>
                </div>
            </form>
        </div>

        <!-- Templates List -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Reusable Templates</div>
            </div>

            <?php if (empty($templates)): ?>
                <p style="color: var(--muted); text-align: center; padding: 20px;">No templates yet.</p>
            <?php else: ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 16px;">
                    <?php foreach ($templates as $tpl): ?>
                    <div style="border: 1px solid var(--line); border-radius: 8px; padding: 16px;">
                        <h3 style="margin-bottom: 8px;"><?= h($tpl['name']) ?></h3>
                        <p style="font-size: 12px; color: var(--muted); margin-bottom: 12px;">Created <?= date('M j, Y', strtotime($tpl['created_at'])) ?></p>
                        <div class="code-block"><?= h(substr($tpl['content'], 0, 150)) ?>...</div>
                        <div class="action-menu">
                            <button onclick="copyToClipboard('<?= h(addslashes($tpl['content'])) ?>')" class="btn btn-ghost btn-small">Copy</button>
                            <form method="post" style="display:inline;">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="delete_template">
                                <input type="hidden" name="template_id" value="<?= $tpl['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-small" onclick="return confirm('Delete template?')">Delete</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- ═════════════════════════════════════════════════════════════════ -->
    <!-- TAB 4: ANALYTICS ─ Charts, Engagement Metrics, Performance -->
    <!-- ═════════════════════════════════════════════════════════════════ -->
    <div id="analytics-tab" class="tab-content <?= $nwlTab === 'analytics' ? 'active' : '' ?>">

        <!-- Key Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Campaigns</div>
                <div class="stat-value"><?= $analytics['total_campaigns'] ?? 0 ?></div>
                <div class="stat-change positive">↑ All time</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Subscribers</div>
                <div class="stat-value"><?= $analytics['total_subscribers'] ?? 0 ?></div>
                <div class="stat-change positive">↑ Active</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Avg Open Rate</div>
                <div class="stat-value"><?= round($analytics['avg_open_rate'] ?? 0, 1) ?>%</div>
                <div class="stat-change positive">↑ Industry avg: 25%</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Avg Click Rate</div>
                <div class="stat-value"><?= round($analytics['avg_click_rate'] ?? 0, 1) ?>%</div>
                <div class="stat-change positive">↑ Strong engagement</div>
            </div>
        </div>

        <!-- Campaign Performance -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Campaign Performance (Last 30 Days)</div>
            </div>

            <div class="chart">
                <div class="chart-title">Sent / Delivered / Opened / Clicked</div>
                <?php
                $campaignPerf = $analytics['campaign_performance'] ?? [
                    ['name' => 'Campaign A', 'sent' => 100, 'delivered' => 98, 'opened' => 32, 'clicked' => 8],
                    ['name' => 'Campaign B', 'sent' => 150, 'delivered' => 148, 'opened' => 45, 'clicked' => 12],
                ];
                $maxVal = max(array_column($campaignPerf, 'sent'));
                ?>
                <?php foreach ($campaignPerf as $perf): ?>
                    <div class="bar">
                        <div class="bar-label"><?= h($perf['name']) ?></div>
                        <div class="bar-container">
                            <div class="bar-fill" style="width: <?= ($perf['sent'] / $maxVal) * 100 ?>%"></div>
                        </div>
                        <div class="bar-value"><?= $perf['sent'] ?> sent</div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Engagement Trends -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Engagement Rates Trend</div>
            </div>
            <p style="color: var(--muted); padding: 20px; text-align: center;">Chart would display opens and clicks over time (requires charting library)</p>
        </div>

        <!-- Top Links -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Top Clicked Links</div>
            </div>

            <?php
            $topLinks = $analytics['top_links'] ?? [
                ['url' => 'https://facthub.org/research', 'clicks' => 145],
                ['url' => 'https://facthub.org/funding', 'clicks' => 89],
                ['url' => 'https://facthub.org/community', 'clicks' => 42],
            ];
            $maxClicks = max(array_column($topLinks, 'clicks'));
            ?>

            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>URL</th>
                            <th>Clicks</th>
                            <th>CTR %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topLinks as $link): ?>
                        <tr>
                            <td><code style="background: var(--bg); padding: 4px 8px; border-radius: 4px; font-size: 12px;"><?= h(substr($link['url'], 0, 50)) ?>...</code></td>
                            <td><strong><?= $link['clicks'] ?></strong></td>
                            <td><?= round(($link['clicks'] / max(1, count($subscribers))) * 100, 1) ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Subscriber Growth -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title">Subscriber Growth</div>
            </div>

            <div class="chart">
                <div class="chart-title">New Subscribers Per Month (Last 6 Months)</div>
                <?php
                $growthData = $analytics['subscriber_growth'] ?? [
                    ['month' => 'Jan', 'count' => 45],
                    ['month' => 'Feb', 'count' => 67],
                    ['month' => 'Mar', 'count' => 89],
                    ['month' => 'Apr', 'count' => 102],
                ];
                $maxGrowth = max(array_column($growthData, 'count'));
                ?>
                <?php foreach ($growthData as $data): ?>
                    <div class="bar">
                        <div class="bar-label"><?= h($data['month']) ?></div>
                        <div class="bar-container">
                            <div class="bar-fill" style="width: <?= ($data['count'] / $maxGrowth) * 100 ?>%"></div>
                        </div>
                        <div class="bar-value"><?= $data['count'] ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>

</div>

<!-- ═════════════════════════════════════════════════════════════════ -->
<!-- MODALS ─ Test Send, Schedule, Subscriber Details -->
<!-- ═════════════════════════════════════════════════════════════════ -->

<!-- Test Send Modal -->
<div id="test-modal" class="modal">
    <div class="modal-content">
        <button class="close-btn" onclick="closeModal('test-modal')">&times;</button>
        <div class="modal-header">
            <h2>Send Test Email</h2>
        </div>
        <form method="post" id="test-form">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="send_test">
            <input type="hidden" name="campaign_id" id="test-campaign-id">

            <div class="form-group">
                <label>Test Email Address *</label>
                <input type="email" name="test_email" placeholder="admin@example.com" required>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Send Test</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('test-modal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Schedule Modal -->
<div id="schedule-modal" class="modal">
    <div class="modal-content">
        <button class="close-btn" onclick="closeModal('schedule-modal')">&times;</button>
        <div class="modal-header">
            <h2>Schedule Campaign</h2>
        </div>
        <form method="post" id="schedule-form">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="schedule_campaign">
            <input type="hidden" name="campaign_id" id="schedule-campaign-id">

            <div class="form-row">
                <div class="form-group">
                    <label>Send Date *</label>
                    <input type="date" name="schedule_date" required>
                </div>
                <div class="form-group">
                    <label>Send Time *</label>
                    <input type="time" name="schedule_time" required>
                </div>
            </div>

            <p style="color: var(--muted); font-size: 13px; margin-bottom: 16px;">Campaign will be sent to all active subscribers at the scheduled time.</p>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Schedule</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('schedule-modal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Subscriber Modal -->
<div id="subscriber-modal" class="modal">
    <div class="modal-content">
        <button class="close-btn" onclick="closeModal('subscriber-modal')">&times;</button>
        <div class="modal-header">
            <h2>Subscriber Details</h2>
        </div>
        <div id="subscriber-content"></div>
    </div>
</div>

<!-- ═════════════════════════════════════════════════════════════════ -->
<!-- JAVASCRIPT ─ Tab Switching, Modals, Utilities -->
<!-- ═════════════════════════════════════════════════════════════════ -->

<script>
function switchTab(tabName) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
    document.querySelectorAll('.tab-link').forEach(link => link.classList.remove('active'));

    // Show selected tab
    document.getElementById(tabName + '-tab').classList.add('active');
    event.target.classList.add('active');

    // Update URL
    window.history.pushState({}, '', '?page=admin&section=newsletter&tab=' + tabName);
}

function openTestModal(campaignId) {
    document.getElementById('test-campaign-id').value = campaignId;
    document.getElementById('test-modal').classList.add('active');
}

function openScheduleModal(campaignId) {
    document.getElementById('schedule-campaign-id').value = campaignId;
    document.getElementById('schedule-modal').classList.add('active');
}

function openSubscriberModal(subscriberId) {
    // In production, this would fetch subscriber details via AJAX
    document.getElementById('subscriber-content').innerHTML = 'Subscriber #' + subscriberId;
    document.getElementById('subscriber-modal').classList.add('active');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

function insertTag(opening, closing) {
    const textarea = document.getElementById('editor');
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = textarea.value;
    const selectedText = text.substring(start, end);

    textarea.value = text.substring(0, start) + opening + selectedText + closing + text.substring(end);
    textarea.focus();
    textarea.setSelectionRange(start + opening.length, start + opening.length + selectedText.length);
}

function insertMJMLTemplate() {
    const mjmlTemplate = `<mjml>
  <mj-body>
    <mj-section>
      <mj-column>
        <mj-text font-size="20px"><strong>Hello {{first_name}}!</strong></mj-text>
        <mj-text>This is your personalized message based on your interests in {{research_interests}}.</mj-text>
        <mj-button href="https://example.com">Learn More</mj-button>
      </mj-column>
    </mj-section>
  </mj-body>
</mjml>`;

    const textarea = document.getElementById('editor');
    textarea.value += '\n\n' + mjmlTemplate;
    textarea.focus();
}

function togglePreview() {
    const preview = document.getElementById('preview-container');
    const editor = document.getElementById('editor');

    if (preview.style.display === 'none') {
        preview.style.display = 'block';
        document.getElementById('preview-content').innerHTML = '<pre>' + escapeHtml(editor.value) + '</pre>';
    } else {
        preview.style.display = 'none';
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        alert('Template copied to clipboard!');
    });
}

// Close modal when clicking outside
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('active');
        }
    });
});
</script>

</body>
</html>

<?php

/**
 * Helper Functions for Newsletter Management
 */

/**
 * Ensure newsletter tables exist
 */
function ensure_newsletter_tables() {
    global $conn;

    // This would typically be in a migration
    // Tables: newsletter_campaigns, newsletter_subscribers, newsletter_templates,
    // newsletter_sends, newsletter_opens, newsletter_clicks
}

/**
 * Get all campaigns
 */
function get_campaigns($conn) {
    $stmt = $conn->prepare("SELECT * FROM newsletter_campaigns ORDER BY created_at DESC LIMIT 100");
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?? [];
}

/**
 * Get single campaign
 */
function get_campaign($conn, $id) {
    $stmt = $conn->prepare("SELECT * FROM newsletter_campaigns WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

/**
 * Get all subscribers
 */
function get_subscribers($conn) {
    $stmt = $conn->prepare("SELECT * FROM newsletter_subscribers ORDER BY subscribed_at DESC LIMIT 1000");
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?? [];
}

/**
 * Get all templates
 */
function get_templates($conn) {
    $stmt = $conn->prepare("SELECT * FROM newsletter_templates ORDER BY created_at DESC");
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?? [];
}

/**
 * Get analytics data
 */
function get_analytics($conn) {
    return [
        'total_campaigns' => 0,
        'total_subscribers' => 0,
        'avg_open_rate' => 0,
        'avg_click_rate' => 0,
        'campaign_performance' => [],
        'top_links' => [],
        'subscriber_growth' => [],
    ];
}

/**
 * Send test email
 */
function send_test_email($campaign, $testEmail) {
    // Implementation would use mail service
    // For now, just log it
}

/**
 * Send newsletter campaign to all subscribers
 */
function send_newsletter_campaign($conn, $campaign) {
    // Implementation would queue async job
    // For now, just update status
    $stmt = $conn->prepare("UPDATE newsletter_campaigns SET status='sent', sent_at=NOW() WHERE id=?");
    $stmt->bind_param('i', $campaign['id']);
    $stmt->execute();
}

/**
 * HTML safe output
 */
function h($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

?>

<?php
/**
 * CampaignService
 * High-level service for managing newsletter campaigns, scheduling, sending, and analytics
 */
class CampaignService {
    private mysqli $conn;
    private string $logPrefix = '[CampaignService]';

    // Valid recipient statuses
    private array $validStatuses = ['pending', 'sent', 'delivered', 'bounced', 'failed', 'opened', 'clicked'];
    private array $validCampaignStatuses = ['draft', 'scheduled', 'sent', 'paused', 'cancelled'];

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    /**
     * Create a new campaign draft
     * Creates a new draft campaign with initial content and sender information
     *
     * @param string $title Campaign title
     * @param string $content_html Campaign content in HTML format
     * @param string $sender_name Name of the sender
     * @param string $sender_email Email address of the sender
     * @param int $created_by_user_id User ID of the creator
     * @return array|bool Array with 'campaign_id' on success, false on failure
     */
    public function createDraft(string $title, string $content_html, string $sender_name, string $sender_email, int $created_by_user_id): array|bool {
        try {
            $title = trim($title);
            $content_html = trim($content_html);
            $sender_name = trim($sender_name);
            $sender_email = trim($sender_email);

            // Validate inputs
            if (empty($title)) {
                error_log("{$this->logPrefix} Title is required");
                return false;
            }
            if (empty($content_html)) {
                error_log("{$this->logPrefix} Content is required");
                return false;
            }
            if (!filter_var($sender_email, FILTER_VALIDATE_EMAIL)) {
                error_log("{$this->logPrefix} Invalid sender email: {$sender_email}");
                return false;
            }
            if (empty($sender_name)) {
                error_log("{$this->logPrefix} Sender name is required");
                return false;
            }

            // Generate slug from title
            $slug = $this->generateSlug($title);

            // Ensure slug is unique
            $slug = $this->ensureUniqueSlug($slug);

            $now = date('Y-m-d H:i:s');
            $status = 'draft';

            $stmt = $this->conn->prepare(
                'INSERT INTO newsletter_campaigns
                 (title, slug, content_html, status, sender_name, sender_email, created_by_user_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('ssssssis',
                $title,
                $slug,
                $content_html,
                $status,
                $sender_name,
                $sender_email,
                $created_by_user_id,
                $now
            );

            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }

            $campaign_id = $this->conn->insert_id;
            $stmt->close();

            error_log("{$this->logPrefix} Draft campaign created with ID: {$campaign_id}");
            return ['campaign_id' => $campaign_id];
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error creating draft: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update a draft campaign
     * Updates specified fields of a draft campaign
     *
     * @param int $campaign_id Campaign ID
     * @param array $fields Fields to update: title, content_html, sender_name, sender_email
     * @return bool True on success, false on failure
     */
    public function updateDraft(int $campaign_id, array $fields): bool {
        try {
            if (empty($fields)) {
                error_log("{$this->logPrefix} No fields to update");
                return false;
            }

            // Verify campaign exists and is draft status
            $campaign = $this->getCampaignById($campaign_id);
            if (!$campaign) {
                error_log("{$this->logPrefix} Campaign not found: {$campaign_id}");
                return false;
            }
            if ($campaign['status'] !== 'draft') {
                error_log("{$this->logPrefix} Cannot update non-draft campaign: {$campaign_id}");
                return false;
            }

            // Build UPDATE query dynamically
            $setClauses = [];
            $params = [];
            $types = '';

            if (isset($fields['title'])) {
                $title = trim($fields['title']);
                if (empty($title)) {
                    error_log("{$this->logPrefix} Title cannot be empty");
                    return false;
                }
                $setClauses[] = 'title = ?';
                $params[] = $title;
                $types .= 's';

                // Regenerate slug if title changed
                $slug = $this->generateSlug($title);
                $slug = $this->ensureUniqueSlug($slug, $campaign_id);
                $setClauses[] = 'slug = ?';
                $params[] = $slug;
                $types .= 's';
            }

            if (isset($fields['content_html'])) {
                $content = trim($fields['content_html']);
                if (empty($content)) {
                    error_log("{$this->logPrefix} Content cannot be empty");
                    return false;
                }
                $setClauses[] = 'content_html = ?';
                $params[] = $content;
                $types .= 's';
            }

            if (isset($fields['sender_name'])) {
                $sender_name = trim($fields['sender_name']);
                if (empty($sender_name)) {
                    error_log("{$this->logPrefix} Sender name cannot be empty");
                    return false;
                }
                $setClauses[] = 'sender_name = ?';
                $params[] = $sender_name;
                $types .= 's';
            }

            if (isset($fields['sender_email'])) {
                $sender_email = trim($fields['sender_email']);
                if (!filter_var($sender_email, FILTER_VALIDATE_EMAIL)) {
                    error_log("{$this->logPrefix} Invalid sender email: {$sender_email}");
                    return false;
                }
                $setClauses[] = 'sender_email = ?';
                $params[] = $sender_email;
                $types .= 's';
            }

            if (empty($setClauses)) {
                error_log("{$this->logPrefix} No valid fields to update");
                return false;
            }

            $params[] = $campaign_id;
            $types .= 'i';

            $query = 'UPDATE newsletter_campaigns SET ' . implode(', ', $setClauses) . ' WHERE id = ?';
            $stmt = $this->conn->prepare($query);
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }

            $stmt->close();
            error_log("{$this->logPrefix} Draft campaign {$campaign_id} updated");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error updating draft: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Save the current state of a draft campaign
     * Explicitly marks a draft as saved (typically used after editing)
     *
     * @param int $campaign_id Campaign ID
     * @return bool True on success, false on failure
     */
    public function saveDraft(int $campaign_id): bool {
        try {
            $campaign = $this->getCampaignById($campaign_id);
            if (!$campaign) {
                error_log("{$this->logPrefix} Campaign not found: {$campaign_id}");
                return false;
            }

            if ($campaign['status'] !== 'draft') {
                error_log("{$this->logPrefix} Campaign is not in draft status: {$campaign_id}");
                return false;
            }

            // Draft is already saved in database, just log the action
            error_log("{$this->logPrefix} Draft campaign {$campaign_id} saved");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error saving draft: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate a personalized preview of the campaign
     * Returns HTML with tokens replaced for a specific subscriber (or with sample data)
     *
     * @param int $campaign_id Campaign ID
     * @param int|null $subscriber_id Optional subscriber ID for personalization
     * @return string|bool HTML preview string on success, false on failure
     */
    public function previewCampaign(int $campaign_id, ?int $subscriber_id = null): string|bool {
        try {
            $campaign = $this->getCampaignById($campaign_id);
            if (!$campaign) {
                error_log("{$this->logPrefix} Campaign not found: {$campaign_id}");
                return false;
            }

            $content = $campaign['content_html'];
            $replacements = [];

            // Get subscriber data for personalization if provided
            if ($subscriber_id !== null) {
                $subscriber = $this->getSubscriberData($subscriber_id);
                if ($subscriber) {
                    $replacements = [
                        '{{subscriber_name}}' => $subscriber['name'] ?? 'Subscriber',
                        '{{subscriber_email}}' => $subscriber['email'] ?? '',
                        '{{subscriber_first_name}}' => $subscriber['first_name'] ?? 'there',
                        '{{unsubscribe_url}}' => $this->generateUnsubscribeUrl($subscriber_id, $campaign_id),
                    ];
                }
            }

            // Set default replacements if not personalized
            if (empty($replacements)) {
                $replacements = [
                    '{{subscriber_name}}' => 'Subscriber Name',
                    '{{subscriber_email}}' => 'subscriber@example.com',
                    '{{subscriber_first_name}}' => 'there',
                    '{{unsubscribe_url}}' => '#unsubscribe',
                ];
            }

            // Replace tokens
            foreach ($replacements as $token => $value) {
                $content = str_replace($token, htmlspecialchars($value), $content);
            }

            // Wrap in basic email template structure
            $preview = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .email-container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .email-header { border-bottom: 1px solid #ddd; padding-bottom: 10px; margin-bottom: 20px; }
        .email-footer { border-top: 1px solid #ddd; padding-top: 10px; margin-top: 20px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <strong>From:</strong> ' . htmlspecialchars($campaign['sender_name']) . ' &lt;' . htmlspecialchars($campaign['sender_email']) . '&gt;
        </div>
        <div class="email-body">
            ' . $content . '
        </div>
        <div class="email-footer">
            <p>You are receiving this email because you are subscribed to our newsletter.</p>
        </div>
    </div>
</body>
</html>';

            return $preview;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error generating preview: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send a test email to a specified address
     * Sends the campaign content to a test email without creating recipient records
     *
     * @param int $campaign_id Campaign ID
     * @param string $email_address Email address to send test to
     * @return bool True on success, false on failure
     */
    public function sendTest(int $campaign_id, string $email_address): bool {
        try {
            $email_address = trim($email_address);
            if (!filter_var($email_address, FILTER_VALIDATE_EMAIL)) {
                error_log("{$this->logPrefix} Invalid test email address: {$email_address}");
                return false;
            }

            $campaign = $this->getCampaignById($campaign_id);
            if (!$campaign) {
                error_log("{$this->logPrefix} Campaign not found: {$campaign_id}");
                return false;
            }

            // Generate test preview
            $preview = $this->previewCampaign($campaign_id);
            if (!$preview) {
                error_log("{$this->logPrefix} Failed to generate preview for test email");
                return false;
            }

            // TODO: Integrate with email service (SwiftMailer, PHPMailer, AWS SES, etc.)
            // For now, we'll log the test send action
            error_log("{$this->logPrefix} Test email queued to {$email_address} for campaign {$campaign_id}");

            // Record test send in database
            $now = date('Y-m-d H:i:s');
            $stmt = $this->conn->prepare(
                'INSERT INTO newsletter_test_sends (campaign_id, test_email, sent_at)
                 VALUES (?, ?, ?)'
            );
            if ($stmt) {
                $stmt->bind_param('iss', $campaign_id, $email_address, $now);
                $stmt->execute();
                $stmt->close();
            }

            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error sending test email: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Schedule a campaign for future sending
     * Schedules campaign and optionally applies audience filters to determine recipients
     *
     * @param int $campaign_id Campaign ID
     * @param string $scheduled_at Datetime string in format 'Y-m-d H:i:s'
     * @param array $audience_filters Optional audience filters (frequency, categories, geography, etc.)
     * @return bool True on success, false on failure
     */
    public function scheduleCampaign(int $campaign_id, string $scheduled_at, array $audience_filters = []): bool {
        try {
            $campaign = $this->getCampaignById($campaign_id);
            if (!$campaign) {
                error_log("{$this->logPrefix} Campaign not found: {$campaign_id}");
                return false;
            }

            // Validate datetime format
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $scheduled_at);
            if (!$dt || $dt->format('Y-m-d H:i:s') !== $scheduled_at) {
                error_log("{$this->logPrefix} Invalid datetime format: {$scheduled_at}");
                return false;
            }

            // Check that scheduled time is in future
            if (strtotime($scheduled_at) <= time()) {
                error_log("{$this->logPrefix} Scheduled time must be in the future");
                return false;
            }

            // Update campaign status and scheduled time
            $status = 'scheduled';
            $stmt = $this->conn->prepare(
                'UPDATE newsletter_campaigns
                 SET status = ?, scheduled_at = ?
                 WHERE id = ?'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('ssi', $status, $scheduled_at, $campaign_id);
            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }
            $stmt->close();

            // Queue recipients based on audience filters
            $recipients_queued = $this->queueRecipientsForCampaign($campaign_id, $audience_filters);
            error_log("{$this->logPrefix} Campaign {$campaign_id} scheduled for {$scheduled_at} with {$recipients_queued} recipients queued");

            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error scheduling campaign: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send a campaign immediately
     * Immediately queues the campaign for sending to matching audience
     *
     * @param int $campaign_id Campaign ID
     * @param array $audience_filters Optional audience filters (frequency, categories, geography, etc.)
     * @return bool True on success, false on failure
     */
    public function sendNow(int $campaign_id, array $audience_filters = []): bool {
        try {
            $campaign = $this->getCampaignById($campaign_id);
            if (!$campaign) {
                error_log("{$this->logPrefix} Campaign not found: {$campaign_id}");
                return false;
            }

            // Check campaign can be sent
            if (!in_array($campaign['status'], ['draft', 'paused'], true)) {
                error_log("{$this->logPrefix} Campaign status {$campaign['status']} cannot be sent");
                return false;
            }

            // Update campaign status and sent_at
            $now = date('Y-m-d H:i:s');
            $status = 'sent';
            $stmt = $this->conn->prepare(
                'UPDATE newsletter_campaigns
                 SET status = ?, sent_at = ?, scheduled_at = NULL
                 WHERE id = ?'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('ssi', $status, $now, $campaign_id);
            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }
            $stmt->close();

            // Queue recipients
            $recipients_queued = $this->queueRecipientsForCampaign($campaign_id, $audience_filters);
            error_log("{$this->logPrefix} Campaign {$campaign_id} sent immediately with {$recipients_queued} recipients queued");

            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error sending campaign: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get campaign statistics and analytics
     * Returns detailed analytics for a campaign including sent, delivered, opened, clicked, unsubscribed counts
     *
     * @param int $campaign_id Campaign ID
     * @return array|bool Statistics array on success, false on failure
     */
    public function getCampaignStats(int $campaign_id): array|bool {
        try {
            $campaign = $this->getCampaignById($campaign_id);
            if (!$campaign) {
                error_log("{$this->logPrefix} Campaign not found: {$campaign_id}");
                return false;
            }

            // Get recipient counts by status
            $stmt = $this->conn->prepare(
                'SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status = "sent" THEN 1 ELSE 0 END) as sent_count,
                    SUM(CASE WHEN status = "delivered" THEN 1 ELSE 0 END) as delivered_count,
                    SUM(CASE WHEN status IN ("opened", "clicked") THEN 1 ELSE 0 END) as opened_count,
                    SUM(CASE WHEN status = "clicked" THEN 1 ELSE 0 END) as clicked_count,
                    SUM(CASE WHEN status IN ("bounced", "failed") THEN 1 ELSE 0 END) as bounced_count
                 FROM newsletter_recipients
                 WHERE campaign_id = ?'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('i', $campaign_id);
            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }

            $result = $stmt->get_result();
            $stats = $result->fetch_assoc();
            $stmt->close();

            // Count unsubscribe events
            $unsubscribed = $this->countEventsByType($campaign_id, 'unsubscribe');

            return [
                'campaign_id' => $campaign_id,
                'title' => $campaign['title'],
                'status' => $campaign['status'],
                'sent_count' => (int)($stats['sent_count'] ?? 0),
                'delivered_count' => (int)($stats['delivered_count'] ?? 0),
                'opened_count' => (int)($stats['opened_count'] ?? 0),
                'clicked_count' => (int)($stats['clicked_count'] ?? 0),
                'bounced_count' => (int)($stats['bounced_count'] ?? 0),
                'unsubscribed_count' => $unsubscribed,
                'total_recipients' => (int)($stats['total'] ?? 0),
                'open_rate' => $this->calculateRate($stats['opened_count'] ?? 0, $stats['delivered_count'] ?? 0),
                'click_rate' => $this->calculateRate($stats['clicked_count'] ?? 0, $stats['delivered_count'] ?? 0),
                'bounce_rate' => $this->calculateRate($stats['bounced_count'] ?? 0, $stats['total'] ?? 0),
                'unsubscribe_rate' => $this->calculateRate($unsubscribed, $stats['delivered_count'] ?? 0),
                'sent_at' => $campaign['sent_at'],
                'scheduled_at' => $campaign['scheduled_at'],
            ];
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error getting campaign stats: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Pause a campaign that is currently scheduled or in progress
     * Stops future sends without deleting queued recipients
     *
     * @param int $campaign_id Campaign ID
     * @return bool True on success, false on failure
     */
    public function pauseCampaign(int $campaign_id): bool {
        try {
            $campaign = $this->getCampaignById($campaign_id);
            if (!$campaign) {
                error_log("{$this->logPrefix} Campaign not found: {$campaign_id}");
                return false;
            }

            // Check campaign can be paused
            if (!in_array($campaign['status'], ['scheduled', 'sent'], true)) {
                error_log("{$this->logPrefix} Campaign status {$campaign['status']} cannot be paused");
                return false;
            }

            $status = 'paused';
            $stmt = $this->conn->prepare(
                'UPDATE newsletter_campaigns
                 SET status = ?
                 WHERE id = ?'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('si', $status, $campaign_id);
            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }

            $stmt->close();
            error_log("{$this->logPrefix} Campaign {$campaign_id} paused");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error pausing campaign: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Resume a paused campaign
     * Resumes sending to pending recipients from a paused campaign
     *
     * @param int $campaign_id Campaign ID
     * @return bool True on success, false on failure
     */
    public function resumeCampaign(int $campaign_id): bool {
        try {
            $campaign = $this->getCampaignById($campaign_id);
            if (!$campaign) {
                error_log("{$this->logPrefix} Campaign not found: {$campaign_id}");
                return false;
            }

            // Check campaign is paused
            if ($campaign['status'] !== 'paused') {
                error_log("{$this->logPrefix} Campaign is not paused: {$campaign_id}");
                return false;
            }

            // Determine previous status (scheduled or sent)
            $newStatus = $campaign['scheduled_at'] ? 'scheduled' : 'sent';

            $stmt = $this->conn->prepare(
                'UPDATE newsletter_campaigns
                 SET status = ?
                 WHERE id = ?'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('si', $newStatus, $campaign_id);
            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }

            $stmt->close();
            error_log("{$this->logPrefix} Campaign {$campaign_id} resumed as {$newStatus}");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error resuming campaign: " . $e->getMessage());
            return false;
        }
    }

    // ==================== PRIVATE HELPER METHODS ====================

    /**
     * Get campaign by ID
     * @param int $campaign_id
     * @return array|null Campaign data or null if not found
     */
    private function getCampaignById(int $campaign_id): ?array {
        try {
            $stmt = $this->conn->prepare(
                'SELECT id, title, slug, content_html, status, sender_name, sender_email,
                        scheduled_at, sent_at, created_by_user_id, recipients_count,
                        opened_count, clicked_count, bounced_count
                 FROM newsletter_campaigns
                 WHERE id = ?'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return null;
            }

            $stmt->bind_param('i', $campaign_id);
            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return null;
            }

            $result = $stmt->get_result();
            $campaign = $result->fetch_assoc();
            $stmt->close();

            return $campaign;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error getting campaign: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate URL-safe slug from text
     * @param string $text
     * @return string URL-safe slug
     */
    private function generateSlug(string $text): string {
        $slug = strtolower($text);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug;
    }

    /**
     * Ensure slug is unique, appending number if needed
     * @param string $slug
     * @param int|null $excludeId Campaign ID to exclude from uniqueness check
     * @return string Unique slug
     */
    private function ensureUniqueSlug(string $slug, ?int $excludeId = null): string {
        try {
            $originalSlug = $slug;
            $counter = 1;

            while (!$this->isUniqueSlug($slug, $excludeId)) {
                $slug = $originalSlug . '-' . $counter;
                $counter++;
            }

            return $slug;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error ensuring unique slug: " . $e->getMessage());
            return $slug;
        }
    }

    /**
     * Check if slug is unique
     * @param string $slug
     * @param int|null $excludeId Campaign ID to exclude
     * @return bool True if unique, false otherwise
     */
    private function isUniqueSlug(string $slug, ?int $excludeId = null): bool {
        try {
            if ($excludeId !== null) {
                $stmt = $this->conn->prepare(
                    'SELECT id FROM newsletter_campaigns WHERE slug = ? AND id != ? LIMIT 1'
                );
                $stmt->bind_param('si', $slug, $excludeId);
            } else {
                $stmt = $this->conn->prepare(
                    'SELECT id FROM newsletter_campaigns WHERE slug = ? LIMIT 1'
                );
                $stmt->bind_param('s', $slug);
            }

            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }

            $result = $stmt->get_result();
            $exists = $result->num_rows > 0;
            $stmt->close();

            return !$exists;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error checking slug uniqueness: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get subscriber data for personalization
     * @param int $subscriber_id
     * @return array|null Subscriber data or null if not found
     */
    private function getSubscriberData(int $subscriber_id): ?array {
        try {
            // Query newsletter_subscribers table
            $stmt = $this->conn->prepare(
                'SELECT id, email FROM newsletter_subscribers WHERE id = ?'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return null;
            }

            $stmt->bind_param('i', $subscriber_id);
            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return null;
            }

            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();

            if (!$row) {
                return null;
            }

            // Extract name from email if available
            $name = explode('@', $row['email'])[0];
            $firstNameParts = explode('.', $name);
            $firstName = ucfirst($firstNameParts[0] ?? 'there');

            return [
                'id' => $row['id'],
                'email' => $row['email'],
                'name' => $name,
                'first_name' => $firstName,
            ];
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error getting subscriber data: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate unsubscribe URL for subscriber
     * @param int $subscriber_id
     * @param int $campaign_id
     * @return string Unsubscribe URL
     */
    private function generateUnsubscribeUrl(int $subscriber_id, int $campaign_id): string {
        $baseUrl = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
        $baseUrl .= $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $baseUrl . '/newsletter/unsubscribe?subscriber=' . $subscriber_id . '&campaign=' . $campaign_id;
    }

    /**
     * Queue recipients for a campaign based on filters
     * @param int $campaign_id
     * @param array $filters Audience filters
     * @return int Number of recipients queued
     */
    private function queueRecipientsForCampaign(int $campaign_id, array $filters = []): int {
        try {
            // Get active subscribers matching filters
            $stmt = $this->conn->prepare(
                'SELECT id FROM newsletter_subscribers WHERE status = "active"'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return 0;
            }

            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return 0;
            }

            $result = $stmt->get_result();
            $subscribers = [];
            while ($row = $result->fetch_assoc()) {
                $subscribers[] = $row['id'];
            }
            $stmt->close();

            // Apply filters (frequency, categories, geography, interests, research_roles)
            $filteredSubscribers = $this->applyAudienceFilters($subscribers, $filters);

            // Create recipient records for campaign
            $queued = 0;
            $now = date('Y-m-d H:i:s');
            $status = 'pending';

            foreach ($filteredSubscribers as $subscriber_id) {
                // Skip if recipient already exists
                if ($this->recipientExists($campaign_id, $subscriber_id)) {
                    continue;
                }

                $insertStmt = $this->conn->prepare(
                    'INSERT INTO newsletter_recipients (campaign_id, subscriber_id, status, created_at)
                     VALUES (?, ?, ?, ?)'
                );
                if (!$insertStmt) {
                    continue;
                }

                $insertStmt->bind_param('iiss', $campaign_id, $subscriber_id, $status, $now);
                if ($insertStmt->execute()) {
                    $queued++;
                }
                $insertStmt->close();
            }

            // Update campaign recipient count
            if ($queued > 0) {
                $this->updateCampaignRecipientCount($campaign_id);
            }

            return $queued;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error queueing recipients: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Apply audience filters to subscriber list
     * @param array $subscriber_ids List of subscriber IDs
     * @param array $filters Audience filters
     * @return array Filtered subscriber IDs
     */
    private function applyAudienceFilters(array $subscriber_ids, array $filters): array {
        try {
            if (empty($filters)) {
                return $subscriber_ids;
            }

            $filtered = [];

            foreach ($subscriber_ids as $subscriber_id) {
                // Get subscriber preferences
                $prefs = $this->getSubscriberPreferences($subscriber_id);
                if (!$prefs) {
                    // Include subscribers without preferences
                    $filtered[] = $subscriber_id;
                    continue;
                }

                $include = true;

                // Check frequency filter
                if (!empty($filters['frequency'])) {
                    $freqs = (array)$filters['frequency'];
                    if (!in_array($prefs['frequency'], $freqs, true)) {
                        $include = false;
                    }
                }

                // Check categories filter
                if ($include && !empty($filters['categories'])) {
                    $categories = (array)$filters['categories'];
                    $prefCategories = $prefs['categories'] ?? [];
                    $intersect = array_intersect($categories, $prefCategories);
                    if (empty($intersect)) {
                        $include = false;
                    }
                }

                // Check geography filter
                if ($include && !empty($filters['geography'])) {
                    $geographies = (array)$filters['geography'];
                    $prefGeographies = $prefs['geography'] ?? [];
                    $intersect = array_intersect($geographies, $prefGeographies);
                    if (empty($intersect)) {
                        $include = false;
                    }
                }

                // Check interests filter
                if ($include && !empty($filters['interests'])) {
                    $interests = (array)$filters['interests'];
                    $prefInterests = $prefs['interests'] ?? [];
                    $intersect = array_intersect($interests, $prefInterests);
                    if (empty($intersect)) {
                        $include = false;
                    }
                }

                // Check research roles filter
                if ($include && !empty($filters['research_roles'])) {
                    $roles = (array)$filters['research_roles'];
                    $prefRoles = $prefs['research_roles'] ?? [];
                    $intersect = array_intersect($roles, $prefRoles);
                    if (empty($intersect)) {
                        $include = false;
                    }
                }

                if ($include) {
                    $filtered[] = $subscriber_id;
                }
            }

            return $filtered;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error applying filters: " . $e->getMessage());
            return $subscriber_ids;
        }
    }

    /**
     * Get subscriber preferences
     * @param int $subscriber_id
     * @return array|null Preferences data or null
     */
    private function getSubscriberPreferences(int $subscriber_id): ?array {
        try {
            $stmt = $this->conn->prepare(
                'SELECT frequency, categories, geography, interests, research_roles
                 FROM newsletter_preferences
                 WHERE subscriber_id = ?'
            );
            if (!$stmt) {
                return null;
            }

            $stmt->bind_param('i', $subscriber_id);
            if (!$stmt->execute()) {
                $stmt->close();
                return null;
            }

            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();

            if (!$row) {
                return null;
            }

            // Decode JSON fields
            $row['categories'] = json_decode($row['categories'] ?? '[]', true) ?? [];
            $row['geography'] = json_decode($row['geography'] ?? '[]', true) ?? [];
            $row['interests'] = json_decode($row['interests'] ?? '[]', true) ?? [];
            $row['research_roles'] = json_decode($row['research_roles'] ?? '[]', true) ?? [];

            return $row;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error getting subscriber preferences: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if recipient exists for campaign and subscriber
     * @param int $campaign_id
     * @param int $subscriber_id
     * @return bool True if exists
     */
    private function recipientExists(int $campaign_id, int $subscriber_id): bool {
        try {
            $stmt = $this->conn->prepare(
                'SELECT id FROM newsletter_recipients WHERE campaign_id = ? AND subscriber_id = ? LIMIT 1'
            );
            if (!$stmt) {
                return false;
            }

            $stmt->bind_param('ii', $campaign_id, $subscriber_id);
            if (!$stmt->execute()) {
                $stmt->close();
                return false;
            }

            $result = $stmt->get_result();
            $exists = $result->num_rows > 0;
            $stmt->close();

            return $exists;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error checking recipient existence: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update campaign recipient count
     * @param int $campaign_id
     * @return void
     */
    private function updateCampaignRecipientCount(int $campaign_id): void {
        try {
            $stmt = $this->conn->prepare(
                'UPDATE newsletter_campaigns
                 SET recipients_count = (SELECT COUNT(*) FROM newsletter_recipients WHERE campaign_id = ?)
                 WHERE id = ?'
            );
            if ($stmt) {
                $stmt->bind_param('ii', $campaign_id, $campaign_id);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error updating recipient count: " . $e->getMessage());
        }
    }

    /**
     * Count events by type for a campaign
     * @param int $campaign_id
     * @param string $event_type
     * @return int Event count
     */
    private function countEventsByType(int $campaign_id, string $event_type): int {
        try {
            $stmt = $this->conn->prepare(
                'SELECT COUNT(*) as count FROM newsletter_events ne
                 JOIN newsletter_recipients nr ON ne.recipient_id = nr.id
                 WHERE nr.campaign_id = ? AND ne.event_type = ?'
            );
            if (!$stmt) {
                return 0;
            }

            $stmt->bind_param('is', $campaign_id, $event_type);
            if (!$stmt->execute()) {
                $stmt->close();
                return 0;
            }

            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();

            return (int)($row['count'] ?? 0);
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error counting events: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Calculate engagement rate
     * @param int $numerator
     * @param int $denominator
     * @return float Rate as percentage, 0-100
     */
    private function calculateRate(int $numerator, int $denominator): float {
        if ($denominator === 0) {
            return 0.0;
        }
        return round(($numerator / $denominator) * 100, 2);
    }
}

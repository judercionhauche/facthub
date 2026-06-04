<?php
/**
 * NewsletterCampaign Model
 * Represents a newsletter campaign with content, scheduling, and analytics
 */
class NewsletterCampaign {
    private mysqli $conn;
    private string $logPrefix = '[NewsletterCampaign]';

    // Properties
    public ?int $id = null;
    public string $title = '';
    public string $slug = '';
    public string $content_html = '';
    public string $status = 'draft';  // draft, scheduled, sent, paused, cancelled
    public string $sender_name = '';
    public string $sender_email = '';
    public ?string $scheduled_at = null;
    public ?string $sent_at = null;
    public array $analytics = [];  // recipients_count, opened_count, clicked_count, bounced_count

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    /**
     * Load campaign by ID
     * @param int $id
     * @return bool True if campaign found, false otherwise
     */
    public function loadById(int $id): bool {
        try {
            $stmt = $this->conn->prepare(
                'SELECT id, title, slug, content_html, status, sender_name, sender_email,
                        scheduled_at, sent_at, recipients_count, opened_count, clicked_count, bounced_count
                 FROM newsletter_campaigns
                 WHERE id = ?'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }

            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();

            if (!$row) {
                return false;
            }

            return $this->loadFromRow($row);
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error loading campaign: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Load campaign by slug
     * @param string $slug
     * @return bool True if campaign found, false otherwise
     */
    public function loadBySlug(string $slug): bool {
        try {
            $slug = trim($slug);
            $stmt = $this->conn->prepare(
                'SELECT id, title, slug, content_html, status, sender_name, sender_email,
                        scheduled_at, sent_at, recipients_count, opened_count, clicked_count, bounced_count
                 FROM newsletter_campaigns
                 WHERE slug = ?'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('s', $slug);
            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }

            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();

            if (!$row) {
                return false;
            }

            return $this->loadFromRow($row);
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error loading campaign by slug: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Load campaign from database row
     * @param array $row Database row
     * @return bool True on success
     */
    private function loadFromRow(array $row): bool {
        $this->id = (int)$row['id'];
        $this->title = $row['title'];
        $this->slug = $row['slug'];
        $this->content_html = $row['content_html'];
        $this->status = $row['status'];
        $this->sender_name = $row['sender_name'];
        $this->sender_email = $row['sender_email'];
        $this->scheduled_at = $row['scheduled_at'];
        $this->sent_at = $row['sent_at'];
        $this->analytics = [
            'recipients_count' => (int)($row['recipients_count'] ?? 0),
            'opened_count' => (int)($row['opened_count'] ?? 0),
            'clicked_count' => (int)($row['clicked_count'] ?? 0),
            'bounced_count' => (int)($row['bounced_count'] ?? 0),
        ];
        return true;
    }

    /**
     * Generate URL-safe slug from title
     * @param string $title
     * @return string URL-safe slug
     */
    private function generateSlug(string $title): string {
        $slug = strtolower($title);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug;
    }

    /**
     * Check if slug is unique
     * @param string $slug
     * @param int|null $excludeId ID to exclude from check (for updates)
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
     * Validate campaign data
     * @return array Empty array if valid, array of error messages if invalid
     */
    private function validate(): array {
        $errors = [];

        if (empty(trim($this->title))) {
            $errors[] = 'Title is required';
        }

        if (empty($this->slug)) {
            $this->slug = $this->generateSlug($this->title);
        }

        if (empty($this->content_html)) {
            $errors[] = 'Content is required';
        }

        if (!filter_var($this->sender_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid sender email';
        }

        if (empty($this->sender_name)) {
            $errors[] = 'Sender name is required';
        }

        if (!in_array($this->status, ['draft', 'scheduled', 'sent', 'paused', 'cancelled'], true)) {
            $errors[] = 'Invalid status';
        }

        return $errors;
    }

    /**
     * Save campaign (insert or update)
     * @return bool True on success, false on failure
     */
    public function save(): bool {
        try {
            // Validate
            $errors = $this->validate();
            if (!empty($errors)) {
                error_log("{$this->logPrefix} Validation errors: " . implode(', ', $errors));
                return false;
            }

            // Check slug uniqueness
            if (!$this->isUniqueSlug($this->slug, $this->id)) {
                error_log("{$this->logPrefix} Slug already exists: {$this->slug}");
                return false;
            }

            if ($this->id === null) {
                return $this->insert();
            } else {
                return $this->update();
            }
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error saving campaign: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Insert new campaign
     * @return bool True on success, false on failure
     */
    private function insert(): bool {
        try {
            $now = date('Y-m-d H:i:s');

            $stmt = $this->conn->prepare(
                'INSERT INTO newsletter_campaigns
                 (title, slug, content_html, status, sender_name, sender_email, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('sssssss',
                $this->title,
                $this->slug,
                $this->content_html,
                $this->status,
                $this->sender_name,
                $this->sender_email,
                $now
            );

            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }

            $this->id = $this->conn->insert_id;
            $stmt->close();

            error_log("{$this->logPrefix} Campaign inserted with ID: {$this->id}");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error inserting campaign: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update existing campaign
     * @return bool True on success, false on failure
     */
    private function update(): bool {
        try {
            if ($this->id === null) {
                error_log("{$this->logPrefix} Cannot update campaign without ID");
                return false;
            }

            $stmt = $this->conn->prepare(
                'UPDATE newsletter_campaigns
                 SET title = ?, slug = ?, content_html = ?, status = ?,
                     sender_name = ?, sender_email = ?
                 WHERE id = ?'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('ssssssi',
                $this->title,
                $this->slug,
                $this->content_html,
                $this->status,
                $this->sender_name,
                $this->sender_email,
                $this->id
            );

            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }

            $stmt->close();
            error_log("{$this->logPrefix} Campaign updated with ID: {$this->id}");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error updating campaign: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Publish campaign (change status from draft to sent)
     * @return bool True on success, false on failure
     */
    public function publish(): bool {
        try {
            if ($this->id === null) {
                error_log("{$this->logPrefix} Cannot publish campaign without ID");
                return false;
            }

            if ($this->status !== 'draft' && $this->status !== 'paused') {
                error_log("{$this->logPrefix} Campaign status must be draft or paused to publish");
                return false;
            }

            $now = date('Y-m-d H:i:s');
            $status = 'sent';

            $stmt = $this->conn->prepare(
                'UPDATE newsletter_campaigns
                 SET status = ?, sent_at = ?
                 WHERE id = ?'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('ssi', $status, $now, $this->id);
            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }

            $this->status = $status;
            $this->sent_at = $now;
            $stmt->close();

            error_log("{$this->logPrefix} Campaign {$this->id} published");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error publishing campaign: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Schedule campaign for future sending
     * @param string $scheduledAt Datetime string (Y-m-d H:i:s format)
     * @return bool True on success, false on failure
     */
    public function schedule(string $scheduledAt): bool {
        try {
            if ($this->id === null) {
                error_log("{$this->logPrefix} Cannot schedule campaign without ID");
                return false;
            }

            // Validate datetime format
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $scheduledAt);
            if (!$dt || $dt->format('Y-m-d H:i:s') !== $scheduledAt) {
                error_log("{$this->logPrefix} Invalid datetime format: {$scheduledAt}");
                return false;
            }

            // Check that scheduled time is in future
            if (strtotime($scheduledAt) <= time()) {
                error_log("{$this->logPrefix} Scheduled time must be in the future");
                return false;
            }

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

            $stmt->bind_param('ssi', $status, $scheduledAt, $this->id);
            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }

            $this->status = $status;
            $this->scheduled_at = $scheduledAt;
            $stmt->close();

            error_log("{$this->logPrefix} Campaign {$this->id} scheduled for {$scheduledAt}");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error scheduling campaign: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get campaign statistics
     * @return array Campaign statistics
     */
    public function getStats(): array {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status,
            'recipients_count' => $this->analytics['recipients_count'] ?? 0,
            'opened_count' => $this->analytics['opened_count'] ?? 0,
            'clicked_count' => $this->analytics['clicked_count'] ?? 0,
            'bounced_count' => $this->analytics['bounced_count'] ?? 0,
            'open_rate' => $this->calculateOpenRate(),
            'click_rate' => $this->calculateClickRate(),
            'bounce_rate' => $this->calculateBounceRate(),
            'sent_at' => $this->sent_at,
            'scheduled_at' => $this->scheduled_at,
        ];
    }

    /**
     * Calculate open rate percentage
     * @return float Open rate as percentage
     */
    private function calculateOpenRate(): float {
        $recipients = $this->analytics['recipients_count'] ?? 0;
        if ($recipients === 0) return 0.0;
        return round(($this->analytics['opened_count'] / $recipients) * 100, 2);
    }

    /**
     * Calculate click rate percentage
     * @return float Click rate as percentage
     */
    private function calculateClickRate(): float {
        $recipients = $this->analytics['recipients_count'] ?? 0;
        if ($recipients === 0) return 0.0;
        return round(($this->analytics['clicked_count'] / $recipients) * 100, 2);
    }

    /**
     * Calculate bounce rate percentage
     * @return float Bounce rate as percentage
     */
    private function calculateBounceRate(): float {
        $recipients = $this->analytics['recipients_count'] ?? 0;
        if ($recipients === 0) return 0.0;
        return round(($this->analytics['bounced_count'] / $recipients) * 100, 2);
    }

    /**
     * Update analytics counts
     * @param array $updates Analytics updates (opened_count, clicked_count, bounced_count, etc)
     * @return bool True on success
     */
    public function updateAnalytics(array $updates): bool {
        try {
            if ($this->id === null) {
                error_log("{$this->logPrefix} Cannot update analytics without ID");
                return false;
            }

            // Prepare safe UPDATE statement
            $setClauses = [];
            $params = [];
            $types = '';

            foreach ($updates as $field => $value) {
                if (in_array($field, ['recipients_count', 'opened_count', 'clicked_count', 'bounced_count'], true)) {
                    $setClauses[] = "{$field} = ?";
                    $params[] = (int)$value;
                    $types .= 'i';
                }
            }

            if (empty($setClauses)) {
                return true;
            }

            $params[] = $this->id;
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

            // Update local analytics
            foreach ($updates as $field => $value) {
                if (in_array($field, ['recipients_count', 'opened_count', 'clicked_count', 'bounced_count'], true)) {
                    $this->analytics[$field] = (int)$value;
                }
            }

            $stmt->close();
            error_log("{$this->logPrefix} Campaign {$this->id} analytics updated");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error updating analytics: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete campaign
     * @return bool True on success, false on failure
     */
    public function delete(): bool {
        try {
            if ($this->id === null) {
                error_log("{$this->logPrefix} Cannot delete campaign without ID");
                return false;
            }

            // Delete associated recipients and events
            $recipientStmt = $this->conn->prepare(
                'SELECT id FROM newsletter_recipients WHERE campaign_id = ?'
            );
            if ($recipientStmt) {
                $recipientStmt->bind_param('i', $this->id);
                $recipientStmt->execute();
                $result = $recipientStmt->get_result();

                while ($row = $result->fetch_assoc()) {
                    $eventStmt = $this->conn->prepare(
                        'DELETE FROM newsletter_events WHERE recipient_id = ?'
                    );
                    if ($eventStmt) {
                        $eventStmt->bind_param('i', $row['id']);
                        $eventStmt->execute();
                        $eventStmt->close();
                    }
                }
                $recipientStmt->close();
            }

            $deleteRecipients = $this->conn->prepare(
                'DELETE FROM newsletter_recipients WHERE campaign_id = ?'
            );
            if ($deleteRecipients) {
                $deleteRecipients->bind_param('i', $this->id);
                $deleteRecipients->execute();
                $deleteRecipients->close();
            }

            // Delete campaign
            $stmt = $this->conn->prepare(
                'DELETE FROM newsletter_campaigns WHERE id = ?'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('i', $this->id);
            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }

            $stmt->close();
            error_log("{$this->logPrefix} Campaign {$this->id} deleted");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error deleting campaign: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all campaigns with optional filtering
     * @param string $status Filter by status (optional)
     * @param int $limit Maximum results
     * @param int $offset Pagination offset
     * @return array Array of campaigns
     */
    public static function getAll(mysqli $conn, ?string $status = null, int $limit = 50, int $offset = 0): array {
        try {
            if ($status !== null && !in_array($status, ['draft', 'scheduled', 'sent', 'paused', 'cancelled'], true)) {
                $status = null;
            }

            if ($status !== null) {
                $stmt = $conn->prepare(
                    'SELECT id, title, slug, status, sender_name, scheduled_at, sent_at,
                            recipients_count, opened_count, clicked_count, bounced_count
                     FROM newsletter_campaigns
                     WHERE status = ?
                     ORDER BY created_at DESC
                     LIMIT ? OFFSET ?'
                );
                $stmt->bind_param('sii', $status, $limit, $offset);
            } else {
                $stmt = $conn->prepare(
                    'SELECT id, title, slug, status, sender_name, scheduled_at, sent_at,
                            recipients_count, opened_count, clicked_count, bounced_count
                     FROM newsletter_campaigns
                     ORDER BY created_at DESC
                     LIMIT ? OFFSET ?'
                );
                $stmt->bind_param('ii', $limit, $offset);
            }

            if (!$stmt->execute()) {
                error_log("[NewsletterCampaign] Execute failed: " . $stmt->error);
                $stmt->close();
                return [];
            }

            $result = $stmt->get_result();
            $campaigns = [];
            while ($row = $result->fetch_assoc()) {
                $campaigns[] = $row;
            }
            $stmt->close();

            return $campaigns;
        } catch (Exception $e) {
            error_log("[NewsletterCampaign] Error getting campaigns: " . $e->getMessage());
            return [];
        }
    }
}

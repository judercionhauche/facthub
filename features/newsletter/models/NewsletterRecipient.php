<?php
/**
 * NewsletterRecipient Model
 * Represents a recipient of a newsletter campaign with delivery status tracking
 */
class NewsletterRecipient {
    private mysqli $conn;
    private string $logPrefix = '[NewsletterRecipient]';

    // Properties
    public ?int $id = null;
    public ?int $campaign_id = null;
    public ?int $subscriber_id = null;
    public string $status = 'pending';  // pending, sent, delivered, bounced, failed, opened, clicked
    public ?string $sent_at = null;
    public ?string $delivered_at = null;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    /**
     * Load recipient by ID
     * @param int $id
     * @return bool True if recipient found, false otherwise
     */
    public function loadById(int $id): bool {
        try {
            $stmt = $this->conn->prepare(
                'SELECT id, campaign_id, subscriber_id, status, sent_at, delivered_at
                 FROM newsletter_recipients
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
            error_log("{$this->logPrefix} Error loading recipient: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Load recipient from database row
     * @param array $row Database row
     * @return bool True on success
     */
    private function loadFromRow(array $row): bool {
        $this->id = (int)$row['id'];
        $this->campaign_id = (int)$row['campaign_id'];
        $this->subscriber_id = (int)$row['subscriber_id'];
        $this->status = $row['status'];
        $this->sent_at = $row['sent_at'];
        $this->delivered_at = $row['delivered_at'];
        return true;
    }

    /**
     * Validate recipient data
     * @return array Empty array if valid, array of error messages if invalid
     */
    private function validate(): array {
        $errors = [];

        if ($this->campaign_id === null) {
            $errors[] = 'Campaign ID is required';
        }

        if ($this->subscriber_id === null) {
            $errors[] = 'Subscriber ID is required';
        }

        if (!in_array($this->status, ['pending', 'sent', 'delivered', 'bounced', 'failed', 'opened', 'clicked'], true)) {
            $errors[] = 'Invalid status';
        }

        return $errors;
    }

    /**
     * Save recipient (insert or update)
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

            if ($this->id === null) {
                return $this->insert();
            } else {
                return $this->update();
            }
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error saving recipient: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Insert new recipient
     * @return bool True on success, false on failure
     */
    private function insert(): bool {
        try {
            $now = date('Y-m-d H:i:s');

            $stmt = $this->conn->prepare(
                'INSERT INTO newsletter_recipients
                 (campaign_id, subscriber_id, status, created_at)
                 VALUES (?, ?, ?, ?)'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('iiss',
                $this->campaign_id,
                $this->subscriber_id,
                $this->status,
                $now
            );

            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }

            $this->id = $this->conn->insert_id;
            $stmt->close();

            error_log("{$this->logPrefix} Recipient inserted with ID: {$this->id}");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error inserting recipient: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update existing recipient
     * @return bool True on success, false on failure
     */
    private function update(): bool {
        try {
            if ($this->id === null) {
                error_log("{$this->logPrefix} Cannot update recipient without ID");
                return false;
            }

            $stmt = $this->conn->prepare(
                'UPDATE newsletter_recipients
                 SET campaign_id = ?, subscriber_id = ?, status = ?
                 WHERE id = ?'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('iisi',
                $this->campaign_id,
                $this->subscriber_id,
                $this->status,
                $this->id
            );

            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }

            $stmt->close();
            error_log("{$this->logPrefix} Recipient updated with ID: {$this->id}");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error updating recipient: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update recipient status
     * @param string $newStatus New status value
     * @return bool True on success, false on failure
     */
    public function updateStatus(string $newStatus): bool {
        try {
            if ($this->id === null) {
                error_log("{$this->logPrefix} Cannot update status without recipient ID");
                return false;
            }

            $validStatuses = ['pending', 'sent', 'delivered', 'bounced', 'failed', 'opened', 'clicked'];
            if (!in_array($newStatus, $validStatuses, true)) {
                error_log("{$this->logPrefix} Invalid status: {$newStatus}");
                return false;
            }

            // Determine which timestamp to update based on status
            $now = date('Y-m-d H:i:s');
            $sentAt = null;
            $deliveredAt = null;

            // Handle different status transitions
            if ($newStatus === 'sent' && $this->sent_at === null) {
                $sentAt = $now;
            }
            if (in_array($newStatus, ['delivered', 'opened', 'clicked'], true) && $this->delivered_at === null) {
                $deliveredAt = $now;
            }

            // Update status and relevant timestamps
            $stmt = $this->conn->prepare(
                'UPDATE newsletter_recipients
                 SET status = ?, sent_at = COALESCE(sent_at, ?), delivered_at = COALESCE(delivered_at, ?)
                 WHERE id = ?'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('sssi', $newStatus, $sentAt, $deliveredAt, $this->id);
            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }

            $this->status = $newStatus;
            if ($sentAt !== null) {
                $this->sent_at = $sentAt;
            }
            if ($deliveredAt !== null) {
                $this->delivered_at = $deliveredAt;
            }

            $stmt->close();

            error_log("{$this->logPrefix} Recipient {$this->id} status updated to: {$newStatus}");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error updating status: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mark recipient as sent
     * @return bool True on success
     */
    public function markAsSent(): bool {
        return $this->updateStatus('sent');
    }

    /**
     * Mark recipient as delivered
     * @return bool True on success
     */
    public function markAsDelivered(): bool {
        return $this->updateStatus('delivered');
    }

    /**
     * Mark recipient as opened
     * @return bool True on success
     */
    public function markAsOpened(): bool {
        return $this->updateStatus('opened');
    }

    /**
     * Mark recipient as clicked
     * @return bool True on success
     */
    public function markAsClicked(): bool {
        return $this->updateStatus('clicked');
    }

    /**
     * Mark recipient as bounced
     * @return bool True on success
     */
    public function markAsBounced(): bool {
        return $this->updateStatus('bounced');
    }

    /**
     * Mark recipient as failed
     * @return bool True on success
     */
    public function markAsFailed(): bool {
        return $this->updateStatus('failed');
    }

    /**
     * Delete recipient
     * @return bool True on success, false on failure
     */
    public function delete(): bool {
        try {
            if ($this->id === null) {
                error_log("{$this->logPrefix} Cannot delete recipient without ID");
                return false;
            }

            // Delete associated events
            $eventStmt = $this->conn->prepare(
                'DELETE FROM newsletter_events WHERE recipient_id = ?'
            );
            if ($eventStmt) {
                $eventStmt->bind_param('i', $this->id);
                $eventStmt->execute();
                $eventStmt->close();
            }

            // Delete recipient
            $stmt = $this->conn->prepare(
                'DELETE FROM newsletter_recipients WHERE id = ?'
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
            error_log("{$this->logPrefix} Recipient {$this->id} deleted");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error deleting recipient: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get recipients for a campaign with optional status filter
     * @param int $campaignId Campaign ID
     * @param string|null $status Filter by status (optional)
     * @param int $limit Maximum results
     * @param int $offset Pagination offset
     * @return array Array of recipients
     */
    public static function getByCampaign(mysqli $conn, int $campaignId, ?string $status = null, int $limit = 100, int $offset = 0): array {
        try {
            if ($status !== null && !in_array($status, ['pending', 'sent', 'delivered', 'bounced', 'failed', 'opened', 'clicked'], true)) {
                $status = null;
            }

            if ($status !== null) {
                $stmt = $conn->prepare(
                    'SELECT id, campaign_id, subscriber_id, status, sent_at, delivered_at
                     FROM newsletter_recipients
                     WHERE campaign_id = ? AND status = ?
                     ORDER BY created_at DESC
                     LIMIT ? OFFSET ?'
                );
                $stmt->bind_param('isii', $campaignId, $status, $limit, $offset);
            } else {
                $stmt = $conn->prepare(
                    'SELECT id, campaign_id, subscriber_id, status, sent_at, delivered_at
                     FROM newsletter_recipients
                     WHERE campaign_id = ?
                     ORDER BY created_at DESC
                     LIMIT ? OFFSET ?'
                );
                $stmt->bind_param('iii', $campaignId, $limit, $offset);
            }

            if (!$stmt->execute()) {
                error_log("[NewsletterRecipient] Execute failed: " . $stmt->error);
                $stmt->close();
                return [];
            }

            $result = $stmt->get_result();
            $recipients = [];
            while ($row = $result->fetch_assoc()) {
                $recipients[] = $row;
            }
            $stmt->close();

            return $recipients;
        } catch (Exception $e) {
            error_log("[NewsletterRecipient] Error getting campaign recipients: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Count recipients for a campaign with optional status filter
     * @param int $campaignId Campaign ID
     * @param string|null $status Filter by status (optional)
     * @return int Count of recipients
     */
    public static function countByCampaign(mysqli $conn, int $campaignId, ?string $status = null): int {
        try {
            if ($status !== null && !in_array($status, ['pending', 'sent', 'delivered', 'bounced', 'failed', 'opened', 'clicked'], true)) {
                $status = null;
            }

            if ($status !== null) {
                $stmt = $conn->prepare(
                    'SELECT COUNT(*) as count FROM newsletter_recipients
                     WHERE campaign_id = ? AND status = ?'
                );
                $stmt->bind_param('is', $campaignId, $status);
            } else {
                $stmt = $conn->prepare(
                    'SELECT COUNT(*) as count FROM newsletter_recipients
                     WHERE campaign_id = ?'
                );
                $stmt->bind_param('i', $campaignId);
            }

            if (!$stmt->execute()) {
                error_log("[NewsletterRecipient] Execute failed: " . $stmt->error);
                $stmt->close();
                return 0;
            }

            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();

            return (int)($row['count'] ?? 0);
        } catch (Exception $e) {
            error_log("[NewsletterRecipient] Error counting recipients: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get pending recipients ready to send
     * @param int $limit Maximum results
     * @return array Array of pending recipients
     */
    public static function getPending(mysqli $conn, int $limit = 100): array {
        try {
            $stmt = $conn->prepare(
                'SELECT id, campaign_id, subscriber_id, status, sent_at, delivered_at
                 FROM newsletter_recipients
                 WHERE status = "pending"
                 ORDER BY created_at ASC
                 LIMIT ?'
            );
            if (!$stmt) {
                error_log("[NewsletterRecipient] Prepare failed: " . $conn->error);
                return [];
            }

            $stmt->bind_param('i', $limit);
            if (!$stmt->execute()) {
                error_log("[NewsletterRecipient] Execute failed: " . $stmt->error);
                $stmt->close();
                return [];
            }

            $result = $stmt->get_result();
            $recipients = [];
            while ($row = $result->fetch_assoc()) {
                $recipients[] = $row;
            }
            $stmt->close();

            return $recipients;
        } catch (Exception $e) {
            error_log("[NewsletterRecipient] Error getting pending recipients: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if recipient exists for campaign and subscriber
     * @param int $campaignId
     * @param int $subscriberId
     * @return bool True if recipient exists
     */
    public static function exists(mysqli $conn, int $campaignId, int $subscriberId): bool {
        try {
            $stmt = $conn->prepare(
                'SELECT id FROM newsletter_recipients
                 WHERE campaign_id = ? AND subscriber_id = ?
                 LIMIT 1'
            );
            if (!$stmt) {
                error_log("[NewsletterRecipient] Prepare failed: " . $conn->error);
                return false;
            }

            $stmt->bind_param('ii', $campaignId, $subscriberId);
            if (!$stmt->execute()) {
                error_log("[NewsletterRecipient] Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }

            $result = $stmt->get_result();
            $exists = $result->num_rows > 0;
            $stmt->close();

            return $exists;
        } catch (Exception $e) {
            error_log("[NewsletterRecipient] Error checking recipient existence: " . $e->getMessage());
            return false;
        }
    }
}

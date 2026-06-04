<?php
/**
 * NewsletterSubscriber Model
 * Represents a newsletter subscriber with their subscription status and preferences
 */
class NewsletterSubscriber {
    private mysqli $conn;
    private string $logPrefix = '[NewsletterSubscriber]';

    // Properties
    public ?int $id = null;
    public ?int $user_id = null;
    public string $email = '';
    public string $status = 'active';  // active, inactive, unsubscribed, bounced
    public ?string $subscribed_at = null;
    public ?string $unsubscribed_at = null;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    /**
     * Load subscriber by ID
     * @param int $id
     * @return bool True if subscriber found, false otherwise
     */
    public function loadById(int $id): bool {
        try {
            $stmt = $this->conn->prepare(
                'SELECT id, user_id, email, status, subscribed_at, unsubscribed_at
                 FROM newsletter_subscribers
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

            $this->id = (int)$row['id'];
            $this->user_id = $row['user_id'] ? (int)$row['user_id'] : null;
            $this->email = $row['email'];
            $this->status = $row['status'];
            $this->subscribed_at = $row['subscribed_at'];
            $this->unsubscribed_at = $row['unsubscribed_at'];

            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error loading subscriber: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Load subscriber by email
     * @param string $email
     * @return bool True if subscriber found, false otherwise
     */
    public function loadByEmail(string $email): bool {
        try {
            $email = trim($email);
            $stmt = $this->conn->prepare(
                'SELECT id, user_id, email, status, subscribed_at, unsubscribed_at
                 FROM newsletter_subscribers
                 WHERE email = ?'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('s', $email);
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

            $this->id = (int)$row['id'];
            $this->user_id = $row['user_id'] ? (int)$row['user_id'] : null;
            $this->email = $row['email'];
            $this->status = $row['status'];
            $this->subscribed_at = $row['subscribed_at'];
            $this->unsubscribed_at = $row['unsubscribed_at'];

            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error loading subscriber by email: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Load subscriber by user ID
     * @param int $user_id
     * @return bool True if subscriber found, false otherwise
     */
    public function loadByUserId(int $user_id): bool {
        try {
            $stmt = $this->conn->prepare(
                'SELECT id, user_id, email, status, subscribed_at, unsubscribed_at
                 FROM newsletter_subscribers
                 WHERE user_id = ?'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('i', $user_id);
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

            $this->id = (int)$row['id'];
            $this->user_id = (int)$row['user_id'];
            $this->email = $row['email'];
            $this->status = $row['status'];
            $this->subscribed_at = $row['subscribed_at'];
            $this->unsubscribed_at = $row['unsubscribed_at'];

            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error loading subscriber by user_id: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Save subscriber (insert or update)
     * @return bool True on success, false on failure
     */
    public function save(): bool {
        try {
            if ($this->id === null) {
                return $this->insert();
            } else {
                return $this->update();
            }
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error saving subscriber: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Insert new subscriber
     * @return bool True on success, false on failure
     */
    private function insert(): bool {
        try {
            $now = date('Y-m-d H:i:s');
            $status = $this->status;
            $email = trim($this->email);

            // Validate email
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                error_log("{$this->logPrefix} Invalid email: {$email}");
                return false;
            }

            $stmt = $this->conn->prepare(
                'INSERT INTO newsletter_subscribers (user_id, email, status, subscribed_at)
                 VALUES (?, ?, ?, ?)'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('isss', $this->user_id, $email, $status, $now);
            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }

            $this->id = $this->conn->insert_id;
            $this->subscribed_at = $now;
            $stmt->close();

            error_log("{$this->logPrefix} Subscriber inserted with ID: {$this->id}");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error inserting subscriber: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update existing subscriber
     * @return bool True on success, false on failure
     */
    private function update(): bool {
        try {
            if ($this->id === null) {
                error_log("{$this->logPrefix} Cannot update subscriber without ID");
                return false;
            }

            $status = $this->status;
            $email = trim($this->email);

            // Validate email
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                error_log("{$this->logPrefix} Invalid email: {$email}");
                return false;
            }

            $stmt = $this->conn->prepare(
                'UPDATE newsletter_subscribers
                 SET email = ?, status = ?
                 WHERE id = ?'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('ssi', $email, $status, $this->id);
            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }

            $stmt->close();
            error_log("{$this->logPrefix} Subscriber updated with ID: {$this->id}");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error updating subscriber: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get subscriber preferences
     * @return array|null Array of preferences or null on error
     */
    public function getPreferences(): ?array {
        try {
            if ($this->id === null) {
                error_log("{$this->logPrefix} Cannot get preferences without subscriber ID");
                return null;
            }

            $stmt = $this->conn->prepare(
                'SELECT id, subscriber_id, frequency, categories, geography, interests, research_roles
                 FROM newsletter_preferences
                 WHERE subscriber_id = ?'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return null;
            }

            $stmt->bind_param('i', $this->id);
            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return null;
            }

            $result = $stmt->get_result();
            $preferences = $result->fetch_assoc();
            $stmt->close();

            if (!$preferences) {
                return null;
            }

            // Parse JSON fields
            $preferences['categories'] = json_decode($preferences['categories'] ?? '[]', true);
            $preferences['geography'] = json_decode($preferences['geography'] ?? '[]', true);
            $preferences['interests'] = json_decode($preferences['interests'] ?? '[]', true);
            $preferences['research_roles'] = json_decode($preferences['research_roles'] ?? '[]', true);

            return $preferences;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error getting preferences: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Update subscriber status
     * @param string $newStatus New status value (active, inactive, unsubscribed, bounced)
     * @return bool True on success, false on failure
     */
    public function updateStatus(string $newStatus): bool {
        try {
            if ($this->id === null) {
                error_log("{$this->logPrefix} Cannot update status without subscriber ID");
                return false;
            }

            $validStatuses = ['active', 'inactive', 'unsubscribed', 'bounced'];
            if (!in_array($newStatus, $validStatuses, true)) {
                error_log("{$this->logPrefix} Invalid status: {$newStatus}");
                return false;
            }

            $unsubscribedAt = null;
            if ($newStatus === 'unsubscribed') {
                $unsubscribedAt = date('Y-m-d H:i:s');
            }

            $stmt = $this->conn->prepare(
                'UPDATE newsletter_subscribers
                 SET status = ?, unsubscribed_at = ?
                 WHERE id = ?'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('ssi', $newStatus, $unsubscribedAt, $this->id);
            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }

            $this->status = $newStatus;
            if ($unsubscribedAt) {
                $this->unsubscribed_at = $unsubscribedAt;
            }
            $stmt->close();

            error_log("{$this->logPrefix} Subscriber {$this->id} status updated to: {$newStatus}");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error updating status: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if subscriber is active
     * @return bool True if active, false otherwise
     */
    public function isActive(): bool {
        return $this->status === 'active' && $this->id !== null;
    }

    /**
     * Check if subscriber is unsubscribed
     * @return bool True if unsubscribed, false otherwise
     */
    public function isUnsubscribed(): bool {
        return $this->status === 'unsubscribed';
    }

    /**
     * Check if subscriber is inactive
     * @return bool True if inactive, false otherwise
     */
    public function isInactive(): bool {
        return $this->status === 'inactive';
    }

    /**
     * Delete subscriber
     * @return bool True on success, false on failure
     */
    public function delete(): bool {
        try {
            if ($this->id === null) {
                error_log("{$this->logPrefix} Cannot delete subscriber without ID");
                return false;
            }

            // Delete associated preferences
            $prefStmt = $this->conn->prepare(
                'DELETE FROM newsletter_preferences WHERE subscriber_id = ?'
            );
            if ($prefStmt) {
                $prefStmt->bind_param('i', $this->id);
                $prefStmt->execute();
                $prefStmt->close();
            }

            // Delete associated recipients
            $recipientStmt = $this->conn->prepare(
                'DELETE FROM newsletter_recipients
                 WHERE subscriber_id = ?'
            );
            if ($recipientStmt) {
                $recipientStmt->bind_param('i', $this->id);
                $recipientStmt->execute();
                $recipientStmt->close();
            }

            // Delete subscriber
            $stmt = $this->conn->prepare(
                'DELETE FROM newsletter_subscribers WHERE id = ?'
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
            error_log("{$this->logPrefix} Subscriber {$this->id} deleted");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error deleting subscriber: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all active subscribers
     * @param int $limit Maximum number of subscribers to return
     * @param int $offset Offset for pagination
     * @return array Array of subscriber arrays
     */
    public static function getActive(mysqli $conn, int $limit = 100, int $offset = 0): array {
        try {
            $stmt = $conn->prepare(
                'SELECT id, user_id, email, status, subscribed_at, unsubscribed_at
                 FROM newsletter_subscribers
                 WHERE status = "active"
                 ORDER BY subscribed_at DESC
                 LIMIT ? OFFSET ?'
            );
            if (!$stmt) {
                error_log("[NewsletterSubscriber] Prepare failed: " . $conn->error);
                return [];
            }

            $stmt->bind_param('ii', $limit, $offset);
            if (!$stmt->execute()) {
                error_log("[NewsletterSubscriber] Execute failed: " . $stmt->error);
                $stmt->close();
                return [];
            }

            $result = $stmt->get_result();
            $subscribers = [];
            while ($row = $result->fetch_assoc()) {
                $subscribers[] = $row;
            }
            $stmt->close();

            return $subscribers;
        } catch (Exception $e) {
            error_log("[NewsletterSubscriber] Error getting active subscribers: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Count active subscribers
     * @return int Number of active subscribers
     */
    public static function countActive(mysqli $conn): int {
        try {
            $result = $conn->query(
                'SELECT COUNT(*) as count FROM newsletter_subscribers WHERE status = "active"'
            );
            if (!$result) {
                error_log("[NewsletterSubscriber] Query failed: " . $conn->error);
                return 0;
            }

            $row = $result->fetch_assoc();
            return (int)($row['count'] ?? 0);
        } catch (Exception $e) {
            error_log("[NewsletterSubscriber] Error counting active subscribers: " . $e->getMessage());
            return 0;
        }
    }
}

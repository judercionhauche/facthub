<?php
/**
 * NewsletterEvent Model
 * Tracks events for newsletter recipients (opens, clicks, bounces, etc)
 */
class NewsletterEvent {
    private mysqli $conn;
    private string $logPrefix = '[NewsletterEvent]';

    // Properties
    public ?int $id = null;
    public ?int $recipient_id = null;
    public string $event_type = 'open';  // open, click, bounce, spam_report, unsubscribe, delivery_failed
    public array $metadata = [];  // Additional event data (link_url, ip_address, user_agent, etc)
    public ?string $timestamp = null;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    /**
     * Load event by ID
     * @param int $id
     * @return bool True if event found, false otherwise
     */
    public function loadById(int $id): bool {
        try {
            $stmt = $this->conn->prepare(
                'SELECT id, recipient_id, event_type, metadata, created_at
                 FROM newsletter_events
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
            error_log("{$this->logPrefix} Error loading event: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Load event from database row
     * @param array $row Database row
     * @return bool True on success
     */
    private function loadFromRow(array $row): bool {
        $this->id = (int)$row['id'];
        $this->recipient_id = (int)$row['recipient_id'];
        $this->event_type = $row['event_type'];
        $this->metadata = json_decode($row['metadata'] ?? '{}', true) ?? [];
        $this->timestamp = $row['created_at'];
        return true;
    }

    /**
     * Validate event data
     * @return array Empty array if valid, array of error messages if invalid
     */
    private function validate(): array {
        $errors = [];

        if ($this->recipient_id === null) {
            $errors[] = 'Recipient ID is required';
        }

        $validEventTypes = ['open', 'click', 'bounce', 'spam_report', 'unsubscribe', 'delivery_failed'];
        if (!in_array($this->event_type, $validEventTypes, true)) {
            $errors[] = 'Invalid event type';
        }

        return $errors;
    }

    /**
     * Record a new event
     * @return bool True on success, false on failure
     */
    public function record(): bool {
        try {
            // Validate
            $errors = $this->validate();
            if (!empty($errors)) {
                error_log("{$this->logPrefix} Validation errors: " . implode(', ', $errors));
                return false;
            }

            // Use current timestamp if not set
            if ($this->timestamp === null) {
                $this->timestamp = date('Y-m-d H:i:s');
            }

            $metadata = json_encode($this->metadata);

            $stmt = $this->conn->prepare(
                'INSERT INTO newsletter_events (recipient_id, event_type, metadata, created_at)
                 VALUES (?, ?, ?, ?)'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('isss',
                $this->recipient_id,
                $this->event_type,
                $metadata,
                $this->timestamp
            );

            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }

            $this->id = $this->conn->insert_id;
            $stmt->close();

            error_log("{$this->logPrefix} Event recorded with ID: {$this->id} (type: {$this->event_type})");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error recording event: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Record an open event
     * @param int $recipientId
     * @param array $metadata Optional metadata (ip_address, user_agent, etc)
     * @return bool True on success
     */
    public static function recordOpen(mysqli $conn, int $recipientId, array $metadata = []): bool {
        try {
            $event = new self($conn);
            $event->recipient_id = $recipientId;
            $event->event_type = 'open';
            $event->metadata = $metadata;
            $event->timestamp = date('Y-m-d H:i:s');

            $success = $event->record();

            if ($success) {
                // Update recipient status to opened
                $stmt = $conn->prepare(
                    'UPDATE newsletter_recipients SET status = "opened"
                     WHERE id = ? AND status NOT IN ("opened", "clicked")'
                );
                if ($stmt) {
                    $stmt->bind_param('i', $recipientId);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            return $success;
        } catch (Exception $e) {
            error_log("[NewsletterEvent] Error recording open event: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Record a click event
     * @param int $recipientId
     * @param string $linkUrl URL that was clicked
     * @param array $metadata Optional metadata (ip_address, user_agent, etc)
     * @return bool True on success
     */
    public static function recordClick(mysqli $conn, int $recipientId, string $linkUrl, array $metadata = []): bool {
        try {
            $metadata['link_url'] = $linkUrl;

            $event = new self($conn);
            $event->recipient_id = $recipientId;
            $event->event_type = 'click';
            $event->metadata = $metadata;
            $event->timestamp = date('Y-m-d H:i:s');

            $success = $event->record();

            if ($success) {
                // Update recipient status to clicked
                $stmt = $conn->prepare(
                    'UPDATE newsletter_recipients SET status = "clicked" WHERE id = ?'
                );
                if ($stmt) {
                    $stmt->bind_param('i', $recipientId);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            return $success;
        } catch (Exception $e) {
            error_log("[NewsletterEvent] Error recording click event: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Record a bounce event
     * @param int $recipientId
     * @param string $bounceType 'hard' or 'soft'
     * @param string $bounceReason Reason for bounce
     * @param array $metadata Optional metadata
     * @return bool True on success
     */
    public static function recordBounce(mysqli $conn, int $recipientId, string $bounceType, string $bounceReason, array $metadata = []): bool {
        try {
            $metadata['bounce_type'] = $bounceType;
            $metadata['bounce_reason'] = $bounceReason;

            $event = new self($conn);
            $event->recipient_id = $recipientId;
            $event->event_type = 'bounce';
            $event->metadata = $metadata;
            $event->timestamp = date('Y-m-d H:i:s');

            $success = $event->record();

            if ($success) {
                // Update recipient status to bounced
                $stmt = $conn->prepare(
                    'UPDATE newsletter_recipients SET status = "bounced" WHERE id = ?'
                );
                if ($stmt) {
                    $stmt->bind_param('i', $recipientId);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            return $success;
        } catch (Exception $e) {
            error_log("[NewsletterEvent] Error recording bounce event: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Record a spam report event
     * @param int $recipientId
     * @param array $metadata Optional metadata
     * @return bool True on success
     */
    public static function recordSpamReport(mysqli $conn, int $recipientId, array $metadata = []): bool {
        try {
            $event = new self($conn);
            $event->recipient_id = $recipientId;
            $event->event_type = 'spam_report';
            $event->metadata = $metadata;
            $event->timestamp = date('Y-m-d H:i:s');

            return $event->record();
        } catch (Exception $e) {
            error_log("[NewsletterEvent] Error recording spam report event: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Record an unsubscribe event
     * @param int $recipientId
     * @param array $metadata Optional metadata
     * @return bool True on success
     */
    public static function recordUnsubscribe(mysqli $conn, int $recipientId, array $metadata = []): bool {
        try {
            $event = new self($conn);
            $event->recipient_id = $recipientId;
            $event->event_type = 'unsubscribe';
            $event->metadata = $metadata;
            $event->timestamp = date('Y-m-d H:i:s');

            return $event->record();
        } catch (Exception $e) {
            error_log("[NewsletterEvent] Error recording unsubscribe event: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Record a delivery failed event
     * @param int $recipientId
     * @param string $failureReason Reason for delivery failure
     * @param array $metadata Optional metadata
     * @return bool True on success
     */
    public static function recordDeliveryFailed(mysqli $conn, int $recipientId, string $failureReason, array $metadata = []): bool {
        try {
            $metadata['failure_reason'] = $failureReason;

            $event = new self($conn);
            $event->recipient_id = $recipientId;
            $event->event_type = 'delivery_failed';
            $event->metadata = $metadata;
            $event->timestamp = date('Y-m-d H:i:s');

            $success = $event->record();

            if ($success) {
                // Update recipient status to failed
                $stmt = $conn->prepare(
                    'UPDATE newsletter_recipients SET status = "failed" WHERE id = ?'
                );
                if ($stmt) {
                    $stmt->bind_param('i', $recipientId);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            return $success;
        } catch (Exception $e) {
            error_log("[NewsletterEvent] Error recording delivery failed event: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get events for a recipient
     * @param int $recipientId
     * @param string|null $eventType Filter by event type (optional)
     * @param int $limit Maximum results
     * @param int $offset Pagination offset
     * @return array Array of events
     */
    public static function getByRecipient(mysqli $conn, int $recipientId, ?string $eventType = null, int $limit = 100, int $offset = 0): array {
        try {
            $validEventTypes = ['open', 'click', 'bounce', 'spam_report', 'unsubscribe', 'delivery_failed'];
            if ($eventType !== null && !in_array($eventType, $validEventTypes, true)) {
                $eventType = null;
            }

            if ($eventType !== null) {
                $stmt = $conn->prepare(
                    'SELECT id, recipient_id, event_type, metadata, created_at
                     FROM newsletter_events
                     WHERE recipient_id = ? AND event_type = ?
                     ORDER BY created_at DESC
                     LIMIT ? OFFSET ?'
                );
                $stmt->bind_param('isii', $recipientId, $eventType, $limit, $offset);
            } else {
                $stmt = $conn->prepare(
                    'SELECT id, recipient_id, event_type, metadata, created_at
                     FROM newsletter_events
                     WHERE recipient_id = ?
                     ORDER BY created_at DESC
                     LIMIT ? OFFSET ?'
                );
                $stmt->bind_param('iii', $recipientId, $limit, $offset);
            }

            if (!$stmt->execute()) {
                error_log("[NewsletterEvent] Execute failed: " . $stmt->error);
                $stmt->close();
                return [];
            }

            $result = $stmt->get_result();
            $events = [];
            while ($row = $result->fetch_assoc()) {
                $row['metadata'] = json_decode($row['metadata'] ?? '{}', true) ?? [];
                $events[] = $row;
            }
            $stmt->close();

            return $events;
        } catch (Exception $e) {
            error_log("[NewsletterEvent] Error getting recipient events: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get events for a campaign
     * @param int $campaignId
     * @param string|null $eventType Filter by event type (optional)
     * @param int $limit Maximum results
     * @return array Array of events
     */
    public static function getByCampaign(mysqli $conn, int $campaignId, ?string $eventType = null, int $limit = 1000): array {
        try {
            $validEventTypes = ['open', 'click', 'bounce', 'spam_report', 'unsubscribe', 'delivery_failed'];
            if ($eventType !== null && !in_array($eventType, $validEventTypes, true)) {
                $eventType = null;
            }

            if ($eventType !== null) {
                $stmt = $conn->prepare(
                    'SELECT ne.id, ne.recipient_id, ne.event_type, ne.metadata, ne.created_at
                     FROM newsletter_events ne
                     JOIN newsletter_recipients nr ON ne.recipient_id = nr.id
                     WHERE nr.campaign_id = ? AND ne.event_type = ?
                     ORDER BY ne.created_at DESC
                     LIMIT ?'
                );
                $stmt->bind_param('isi', $campaignId, $eventType, $limit);
            } else {
                $stmt = $conn->prepare(
                    'SELECT ne.id, ne.recipient_id, ne.event_type, ne.metadata, ne.created_at
                     FROM newsletter_events ne
                     JOIN newsletter_recipients nr ON ne.recipient_id = nr.id
                     WHERE nr.campaign_id = ?
                     ORDER BY ne.created_at DESC
                     LIMIT ?'
                );
                $stmt->bind_param('ii', $campaignId, $limit);
            }

            if (!$stmt->execute()) {
                error_log("[NewsletterEvent] Execute failed: " . $stmt->error);
                $stmt->close();
                return [];
            }

            $result = $stmt->get_result();
            $events = [];
            while ($row = $result->fetch_assoc()) {
                $row['metadata'] = json_decode($row['metadata'] ?? '{}', true) ?? [];
                $events[] = $row;
            }
            $stmt->close();

            return $events;
        } catch (Exception $e) {
            error_log("[NewsletterEvent] Error getting campaign events: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Count events by type
     * @param int $recipientId
     * @param string $eventType
     * @return int Count of events
     */
    public static function countByType(mysqli $conn, int $recipientId, string $eventType): int {
        try {
            $stmt = $conn->prepare(
                'SELECT COUNT(*) as count FROM newsletter_events
                 WHERE recipient_id = ? AND event_type = ?'
            );
            if (!$stmt) {
                error_log("[NewsletterEvent] Prepare failed: " . $conn->error);
                return 0;
            }

            $stmt->bind_param('is', $recipientId, $eventType);
            if (!$stmt->execute()) {
                error_log("[NewsletterEvent] Execute failed: " . $stmt->error);
                $stmt->close();
                return 0;
            }

            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();

            return (int)($row['count'] ?? 0);
        } catch (Exception $e) {
            error_log("[NewsletterEvent] Error counting events: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Delete events older than specified days
     * @param int $daysOld Delete events older than this many days
     * @return int Number of deleted events
     */
    public static function deleteOldEvents(mysqli $conn, int $daysOld = 90): int {
        try {
            $date = date('Y-m-d H:i:s', strtotime("-{$daysOld} days"));
            $stmt = $conn->prepare(
                'DELETE FROM newsletter_events WHERE created_at < ?'
            );
            if (!$stmt) {
                error_log("[NewsletterEvent] Prepare failed: " . $conn->error);
                return 0;
            }

            $stmt->bind_param('s', $date);
            if (!$stmt->execute()) {
                error_log("[NewsletterEvent] Execute failed: " . $stmt->error);
                $stmt->close();
                return 0;
            }

            $deletedCount = $stmt->affected_rows;
            $stmt->close();

            error_log("[NewsletterEvent] Deleted {$deletedCount} events older than {$daysOld} days");
            return $deletedCount;
        } catch (Exception $e) {
            error_log("[NewsletterEvent] Error deleting old events: " . $e->getMessage());
            return 0;
        }
    }
}

<?php
/**
 * AnalyticsService
 * High-level service for newsletter analytics, event tracking, and reporting
 * Provides metrics on campaign performance, subscriber engagement, and event analysis
 */
class AnalyticsService {
    private mysqli $conn;
    private string $logPrefix = '[AnalyticsService]';

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    /**
     * Record a newsletter event (sent, delivered, opened, clicked, unsubscribed, bounced)
     * Inserts into newsletter_events table with optional metadata
     *
     * @param int $recipient_id Newsletter recipient ID
     * @param string $event_type Event type: sent, delivered, opened, clicked, unsubscribed, bounced
     * @param array $metadata Optional metadata (link_url, ip_address, user_agent, provider, etc)
     * @return bool True on success, false on failure
     */
    public function recordEvent(int $recipient_id, string $event_type, array $metadata = []): bool {
        try {
            $validEventTypes = ['sent', 'delivered', 'opened', 'clicked', 'unsubscribed', 'bounced'];
            if (!in_array($event_type, $validEventTypes, true)) {
                error_log("{$this->logPrefix} Invalid event type: {$event_type}");
                return false;
            }

            $now = date('Y-m-d H:i:s');
            $metadataJson = json_encode($metadata);

            $stmt = $this->conn->prepare(
                'INSERT INTO newsletter_events (recipient_id, event_type, metadata, created_at)
                 VALUES (?, ?, ?, ?)'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('isss', $recipient_id, $event_type, $metadataJson, $now);
            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }

            $stmt->close();
            error_log("{$this->logPrefix} Event '{$event_type}' recorded for recipient {$recipient_id}");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error recording event: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get aggregate event statistics for a campaign
     * Returns counts of sent, delivered, opened, clicked, and unsubscribed emails
     *
     * @param int $campaign_id Campaign ID
     * @return array|bool Array with event counts or false on failure
     */
    public function getEventStats(int $campaign_id): array|bool {
        try {
            // Use subquery to join events with recipients and count by event type
            $stmt = $this->conn->prepare(
                'SELECT
                    SUM(CASE WHEN ne.event_type = "sent" THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN ne.event_type = "delivered" THEN 1 ELSE 0 END) as delivered,
                    SUM(CASE WHEN ne.event_type = "opened" THEN 1 ELSE 0 END) as opened,
                    SUM(CASE WHEN ne.event_type = "clicked" THEN 1 ELSE 0 END) as clicked,
                    SUM(CASE WHEN ne.event_type = "unsubscribed" THEN 1 ELSE 0 END) as unsubscribed,
                    SUM(CASE WHEN ne.event_type = "bounced" THEN 1 ELSE 0 END) as bounced,
                    COUNT(DISTINCT nr.id) as total_recipients
                 FROM newsletter_recipients nr
                 LEFT JOIN newsletter_events ne ON nr.id = ne.recipient_id
                 WHERE nr.campaign_id = ?'
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

            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$result) {
                return false;
            }

            // Ensure all values are integers
            return [
                'sent' => (int)($result['sent'] ?? 0),
                'delivered' => (int)($result['delivered'] ?? 0),
                'opened' => (int)($result['opened'] ?? 0),
                'clicked' => (int)($result['clicked'] ?? 0),
                'unsubscribed' => (int)($result['unsubscribed'] ?? 0),
                'bounced' => (int)($result['bounced'] ?? 0),
                'total_recipients' => (int)($result['total_recipients'] ?? 0)
            ];
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error getting event stats: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Calculate open rate for a campaign
     * Returns percentage of emails opened out of total sent
     *
     * @param int $campaign_id Campaign ID
     * @return float|false Open rate as percentage (0-100) or false on failure
     */
    public function getOpenRate(int $campaign_id): float|false {
        try {
            $stats = $this->getEventStats($campaign_id);
            if ($stats === false || $stats['sent'] === 0) {
                return 0.0;
            }

            $openRate = ($stats['opened'] / $stats['sent']) * 100;
            return round($openRate, 2);
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error calculating open rate: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Calculate click rate for a campaign
     * Returns percentage of emails clicked out of total sent
     *
     * @param int $campaign_id Campaign ID
     * @return float|false Click rate as percentage (0-100) or false on failure
     */
    public function getClickRate(int $campaign_id): float|false {
        try {
            $stats = $this->getEventStats($campaign_id);
            if ($stats === false || $stats['sent'] === 0) {
                return 0.0;
            }

            $clickRate = ($stats['clicked'] / $stats['sent']) * 100;
            return round($clickRate, 2);
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error calculating click rate: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Calculate unsubscribe rate for a campaign
     * Returns percentage of unsubscribes out of total sent
     *
     * @param int $campaign_id Campaign ID
     * @return float|false Unsubscribe rate as percentage (0-100) or false on failure
     */
    public function getUnsubscribeRate(int $campaign_id): float|false {
        try {
            $stats = $this->getEventStats($campaign_id);
            if ($stats === false || $stats['sent'] === 0) {
                return 0.0;
            }

            $unsubscribeRate = ($stats['unsubscribed'] / $stats['sent']) * 100;
            return round($unsubscribeRate, 2);
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error calculating unsubscribe rate: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get the most clicked links in a campaign
     * Aggregates click events by URL and returns top results
     *
     * @param int $campaign_id Campaign ID
     * @param int $limit Maximum number of results (default 10)
     * @return array|bool Array of links with click counts or false on failure
     */
    public function getTopLinks(int $campaign_id, int $limit = 10): array|bool {
        try {
            if ($limit <= 0) {
                $limit = 10;
            }

            // Extract link_url from metadata JSON and count clicks per link
            $stmt = $this->conn->prepare(
                'SELECT
                    JSON_EXTRACT(ne.metadata, "$.link_url") as url,
                    COUNT(*) as click_count
                 FROM newsletter_recipients nr
                 JOIN newsletter_events ne ON nr.id = ne.recipient_id
                 WHERE nr.campaign_id = ?
                   AND ne.event_type = "clicked"
                   AND JSON_EXTRACT(ne.metadata, "$.link_url") IS NOT NULL
                 GROUP BY JSON_EXTRACT(ne.metadata, "$.link_url")
                 ORDER BY click_count DESC
                 LIMIT ?'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('ii', $campaign_id, $limit);
            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }

            $result = $stmt->get_result();
            $links = [];

            while ($row = $result->fetch_assoc()) {
                $url = $row['url'];
                // Remove JSON quotes if present
                if ($url && $url[0] === '"' && $url[strlen($url) - 1] === '"') {
                    $url = substr($url, 1, -1);
                }
                $links[] = [
                    'url' => $url,
                    'click_count' => (int)$row['click_count']
                ];
            }

            $stmt->close();
            return $links;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error getting top links: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get the complete journey/event history for a subscriber
     * Returns all events across all campaigns for a specific subscriber
     *
     * @param int $subscriber_id Subscriber ID
     * @return array|bool Array of events with campaign info or false on failure
     */
    public function getSubscriberJourney(int $subscriber_id): array|bool {
        try {
            // Fetch all events for subscriber across all campaigns
            $stmt = $this->conn->prepare(
                'SELECT
                    ne.id as event_id,
                    ne.event_type,
                    ne.metadata,
                    ne.created_at as event_date,
                    nr.campaign_id,
                    nc.title as campaign_title,
                    nc.created_at as campaign_date,
                    ns.email
                 FROM newsletter_events ne
                 JOIN newsletter_recipients nr ON ne.recipient_id = nr.id
                 JOIN newsletter_campaigns nc ON nr.campaign_id = nc.id
                 JOIN newsletter_subscribers ns ON nr.subscriber_id = ns.id
                 WHERE ns.id = ?
                 ORDER BY ne.created_at DESC'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('i', $subscriber_id);
            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }

            $result = $stmt->get_result();
            $journey = [];

            while ($row = $result->fetch_assoc()) {
                $journey[] = [
                    'event_id' => (int)$row['event_id'],
                    'event_type' => $row['event_type'],
                    'event_date' => $row['event_date'],
                    'campaign_id' => (int)$row['campaign_id'],
                    'campaign_title' => $row['campaign_title'],
                    'campaign_date' => $row['campaign_date'],
                    'email' => $row['email'],
                    'metadata' => json_decode($row['metadata'] ?? '{}', true) ?? []
                ];
            }

            $stmt->close();
            return $journey;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error getting subscriber journey: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get dashboard statistics for a date range
     * Returns aggregate metrics across all campaigns within the specified period
     *
     * @param string $start_date Start date (YYYY-MM-DD format)
     * @param string $end_date End date (YYYY-MM-DD format)
     * @return array|bool Array with aggregate stats or false on failure
     */
    public function getDashboardStats(string $start_date, string $end_date): array|bool {
        try {
            // Validate date format
            if (!$this->isValidDate($start_date) || !$this->isValidDate($end_date)) {
                error_log("{$this->logPrefix} Invalid date format provided");
                return false;
            }

            // Convert to datetime for comparison
            $start_dt = $start_date . ' 00:00:00';
            $end_dt = $end_date . ' 23:59:59';

            // Get campaign-level aggregate stats
            $stmt = $this->conn->prepare(
                'SELECT
                    COUNT(DISTINCT nc.id) as total_campaigns,
                    COUNT(DISTINCT nr.id) as total_recipients,
                    SUM(CASE WHEN ne.event_type = "sent" THEN 1 ELSE 0 END) as total_sent,
                    SUM(CASE WHEN ne.event_type = "delivered" THEN 1 ELSE 0 END) as total_delivered,
                    SUM(CASE WHEN ne.event_type = "opened" THEN 1 ELSE 0 END) as total_opened,
                    SUM(CASE WHEN ne.event_type = "clicked" THEN 1 ELSE 0 END) as total_clicked,
                    SUM(CASE WHEN ne.event_type = "unsubscribed" THEN 1 ELSE 0 END) as total_unsubscribed,
                    SUM(CASE WHEN ne.event_type = "bounced" THEN 1 ELSE 0 END) as total_bounced,
                    COUNT(DISTINCT ne.recipient_id) as engaged_recipients
                 FROM newsletter_campaigns nc
                 LEFT JOIN newsletter_recipients nr ON nc.id = nr.campaign_id
                 LEFT JOIN newsletter_events ne ON nr.id = ne.recipient_id
                 WHERE nc.created_at >= ? AND nc.created_at <= ?'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('ss', $start_dt, $end_dt);
            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }

            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$result) {
                return false;
            }

            // Calculate rates
            $total_sent = (int)($result['total_sent'] ?? 0);
            $stats = [
                'date_range' => [
                    'start' => $start_date,
                    'end' => $end_date
                ],
                'campaigns' => (int)($result['total_campaigns'] ?? 0),
                'recipients' => (int)($result['total_recipients'] ?? 0),
                'events' => [
                    'sent' => (int)($result['total_sent'] ?? 0),
                    'delivered' => (int)($result['total_delivered'] ?? 0),
                    'opened' => (int)($result['total_opened'] ?? 0),
                    'clicked' => (int)($result['total_clicked'] ?? 0),
                    'unsubscribed' => (int)($result['total_unsubscribed'] ?? 0),
                    'bounced' => (int)($result['total_bounced'] ?? 0)
                ],
                'engaged_recipients' => (int)($result['engaged_recipients'] ?? 0),
                'rates' => [
                    'open_rate' => $total_sent > 0 ? round(((int)($result['total_opened'] ?? 0) / $total_sent) * 100, 2) : 0,
                    'click_rate' => $total_sent > 0 ? round(((int)($result['total_clicked'] ?? 0) / $total_sent) * 100, 2) : 0,
                    'unsubscribe_rate' => $total_sent > 0 ? round(((int)($result['total_unsubscribed'] ?? 0) / $total_sent) * 100, 2) : 0,
                    'bounce_rate' => $total_sent > 0 ? round(((int)($result['total_bounced'] ?? 0) / $total_sent) * 100, 2) : 0
                ]
            ];

            return $stats;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error getting dashboard stats: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Export analytics data for a campaign
     * Supports CSV format with event data, engagement metrics, and recipient details
     *
     * @param int $campaign_id Campaign ID
     * @param string $format Export format: 'csv' (default)
     * @return string|false CSV data as string or false on failure
     */
    public function exportAnalytics(int $campaign_id, string $format = 'csv'): string|false {
        try {
            if ($format !== 'csv') {
                error_log("{$this->logPrefix} Unsupported export format: {$format}");
                return false;
            }

            // Fetch campaign details
            $campaignStmt = $this->conn->prepare(
                'SELECT id, title, content_html, created_at FROM newsletter_campaigns WHERE id = ?'
            );
            if (!$campaignStmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $campaignStmt->bind_param('i', $campaign_id);
            $campaignStmt->execute();
            $campaign = $campaignStmt->get_result()->fetch_assoc();
            $campaignStmt->close();

            if (!$campaign) {
                return false;
            }

            // Fetch recipient and event data
            $stmt = $this->conn->prepare(
                'SELECT
                    nr.id as recipient_id,
                    nr.subscriber_id,
                    ns.email,
                    nr.status as recipient_status,
                    nr.sent_at,
                    GROUP_CONCAT(DISTINCT ne.event_type ORDER BY ne.event_type) as events,
                    MAX(CASE WHEN ne.event_type = "opened" THEN ne.created_at END) as opened_at,
                    MAX(CASE WHEN ne.event_type = "clicked" THEN ne.created_at END) as clicked_at,
                    COUNT(CASE WHEN ne.event_type = "clicked" THEN 1 END) as click_count
                 FROM newsletter_recipients nr
                 JOIN newsletter_subscribers ns ON nr.subscriber_id = ns.id
                 LEFT JOIN newsletter_events ne ON nr.id = ne.recipient_id
                 WHERE nr.campaign_id = ?
                 GROUP BY nr.id, nr.subscriber_id, ns.email, nr.status, nr.sent_at
                 ORDER BY nr.created_at DESC'
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
            $rows = [];

            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }

            $stmt->close();

            // Build CSV
            $csv = "Campaign Analytics Export\n";
            $csv .= "Campaign ID: {$campaign['id']}\n";
            $csv .= "Campaign Title: {$campaign['title']}\n";
            $csv .= "Created: {$campaign['created_at']}\n";
            $csv .= "\n";

            // Get stats for summary
            $stats = $this->getEventStats($campaign_id);
            if ($stats !== false) {
                $csv .= "Summary Statistics\n";
                $csv .= "Total Recipients: {$stats['total_recipients']}\n";
                $csv .= "Sent: {$stats['sent']}\n";
                $csv .= "Delivered: {$stats['delivered']}\n";
                $csv .= "Opened: {$stats['opened']}\n";
                $csv .= "Clicked: {$stats['clicked']}\n";
                $csv .= "Unsubscribed: {$stats['unsubscribed']}\n";
                $csv .= "Bounced: {$stats['bounced']}\n";
                $openRate = $this->getOpenRate($campaign_id);
                $clickRate = $this->getClickRate($campaign_id);
                $unsubRate = $this->getUnsubscribeRate($campaign_id);
                $csv .= "Open Rate: " . ($openRate !== false ? $openRate . "%" : "N/A") . "\n";
                $csv .= "Click Rate: " . ($clickRate !== false ? $clickRate . "%" : "N/A") . "\n";
                $csv .= "Unsubscribe Rate: " . ($unsubRate !== false ? $unsubRate . "%" : "N/A") . "\n";
                $csv .= "\n";
            }

            // Detail rows
            $csv .= "Recipient Email,Status,Sent At,Events,Opened At,Clicked At,Click Count\n";

            foreach ($rows as $row) {
                $email = '"' . str_replace('"', '""', $row['email']) . '"';
                $status = $row['recipient_status'];
                $sentAt = $row['sent_at'] ?? '';
                $events = $row['events'] ?? '';
                $openedAt = $row['opened_at'] ?? '';
                $clickedAt = $row['clicked_at'] ?? '';
                $clickCount = $row['click_count'] ?? 0;

                $csv .= "{$email},{$status},{$sentAt},{$events},{$openedAt},{$clickedAt},{$clickCount}\n";
            }

            return $csv;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error exporting analytics: " . $e->getMessage());
            return false;
        }
    }

    // ==================== PRIVATE HELPER METHODS ====================

    /**
     * Validate date string format (YYYY-MM-DD)
     */
    private function isValidDate(string $date): bool {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}
?>

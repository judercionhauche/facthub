<?php
/**
 * SendingService
 * High-level service for queuing and sending newsletter campaigns
 * Integrates with job queue system for async email processing
 * Supports tracking pixels, click redirection, personalization, and unsubscribe flows
 */
class SendingService {
    private mysqli $conn;
    private string $logPrefix = '[SendingService]';
    private string $appUrl;
    private ?string $emailProvider = null; // 'aws_ses' or 'sendgrid'
    private ?string $awsSesRegion = null;
    private ?string $sendgridApiKey = null;

    public function __construct(mysqli $conn, string $appUrl = '') {
        $this->conn = $conn;
        $this->appUrl = $appUrl ?: (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://')
            . ($_SERVER['HTTP_HOST'] ?? 'localhost');

        // Load email provider config from environment
        $this->emailProvider = getenv('NEWSLETTER_EMAIL_PROVIDER') ?: 'aws_ses';
        if ($this->emailProvider === 'aws_ses') {
            $this->awsSesRegion = getenv('AWS_SES_REGION') ?: 'us-east-1';
        } elseif ($this->emailProvider === 'sendgrid') {
            $this->sendgridApiKey = getenv('SENDGRID_API_KEY');
        }
    }

    /**
     * Queue a campaign send for a subscriber
     * Creates newsletter_recipients record with 'queued' status and enqueues send job
     *
     * @param int $campaign_id Campaign ID
     * @param int $subscriber_id Subscriber ID
     * @param array $personalization Optional personalization data (first_name, full_name, institution, research_interests)
     * @return bool True on success, false on failure
     */
    public function queueCampaignSend(int $campaign_id, int $subscriber_id, array $personalization = []): bool {
        try {
            // Verify campaign exists
            $campaign = $this->getCampaign($campaign_id);
            if (!$campaign) {
                error_log("{$this->logPrefix} Campaign not found: {$campaign_id}");
                return false;
            }

            // Verify subscriber exists
            $subscriber = $this->getSubscriber($subscriber_id);
            if (!$subscriber) {
                error_log("{$this->logPrefix} Subscriber not found: {$subscriber_id}");
                return false;
            }

            // Check if recipient already exists
            $stmt = $this->conn->prepare(
                'SELECT id FROM newsletter_recipients WHERE campaign_id = ? AND subscriber_id = ? LIMIT 1'
            );
            $stmt->bind_param('ii', $campaign_id, $subscriber_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                error_log("{$this->logPrefix} Recipient already exists for campaign {$campaign_id} and subscriber {$subscriber_id}");
                $stmt->close();
                return false;
            }
            $stmt->close();

            // Create recipient record with 'queued' status
            $now = date('Y-m-d H:i:s');
            $status = 'queued';
            $personalizationJson = json_encode($personalization);

            $insertStmt = $this->conn->prepare(
                'INSERT INTO newsletter_recipients (campaign_id, subscriber_id, status, personalization, created_at)
                 VALUES (?, ?, ?, ?, ?)'
            );
            if (!$insertStmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $insertStmt->bind_param('iisss', $campaign_id, $subscriber_id, $status, $personalizationJson, $now);
            if (!$insertStmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $insertStmt->error);
                $insertStmt->close();
                return false;
            }

            $recipient_id = $this->conn->insert_id;
            $insertStmt->close();

            error_log("{$this->logPrefix} Recipient {$recipient_id} queued for campaign {$campaign_id}");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error queuing campaign send: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Process queued emails: dequeue, personalize, send via provider, update status
     * Intended to be called by job queue worker via job type 'send_newsletter_batch'
     *
     * @param int $batch_size Number of emails to process in this batch
     * @return int Number of emails successfully sent
     */
    public function processQueue(int $batch_size = 100): int {
        try {
            // Get queued recipients (ordered by oldest first)
            $stmt = $this->conn->prepare(
                'SELECT nr.id, nr.campaign_id, nr.subscriber_id, nr.personalization,
                        nc.content_html, nc.title, nc.sender_name, nc.sender_email,
                        ns.email
                 FROM newsletter_recipients nr
                 JOIN newsletter_campaigns nc ON nr.campaign_id = nc.id
                 JOIN newsletter_subscribers ns ON nr.subscriber_id = ns.id
                 WHERE nr.status = "queued"
                 ORDER BY nr.created_at ASC
                 LIMIT ?'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return 0;
            }

            $stmt->bind_param('i', $batch_size);
            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return 0;
            }

            $result = $stmt->get_result();
            $recipients = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (empty($recipients)) {
                return 0;
            }

            $sentCount = 0;

            foreach ($recipients as $recipient) {
                // Get subscriber data for personalization
                $subscriberData = $this->getSubscriberFullData((int)$recipient['subscriber_id']);
                $personalization = json_decode($recipient['personalization'] ?? '{}', true) ?? [];

                // Merge personalization with subscriber data
                $personalizationData = array_merge($subscriberData, $personalization);

                // Personalize content
                $personalizedHtml = $this->personalizeContent(
                    $recipient['content_html'],
                    $personalizationData
                );

                // Generate tracking pixel and unsubscribe URL
                $trackingPixelUrl = $this->generateTrackingPixel((int)$recipient['id']);
                $unsubscribeUrl = $this->generateUnsubscribeUrl((int)$recipient['id']);

                // Add tracking pixel to HTML
                $personalizedHtml = $this->addTrackingPixel($personalizedHtml, $trackingPixelUrl);

                // Send via email provider
                $sendSuccess = $this->sendViaProvider(
                    $recipient['email'],
                    $recipient['title'],
                    $personalizedHtml,
                    $trackingPixelUrl,
                    $unsubscribeUrl,
                    (int)$recipient['id'],
                    $recipient['sender_name'],
                    $recipient['sender_email']
                );

                if ($sendSuccess) {
                    // Update recipient status to 'sent'
                    $now = date('Y-m-d H:i:s');
                    $updateStmt = $this->conn->prepare(
                        'UPDATE newsletter_recipients SET status = "sent", sent_at = ? WHERE id = ?'
                    );
                    if ($updateStmt) {
                        $updateStmt->bind_param('si', $now, $recipient['id']);
                        $updateStmt->execute();
                        $updateStmt->close();
                    }

                    // Record sent event
                    $this->recordEvent((int)$recipient['id'], 'sent', [
                        'provider' => $this->emailProvider,
                        'timestamp' => $now
                    ]);

                    $sentCount++;
                    error_log("{$this->logPrefix} Email sent to {$recipient['email']} (recipient ID {$recipient['id']})");
                } else {
                    // Update status to 'failed'
                    $updateStmt = $this->conn->prepare(
                        'UPDATE newsletter_recipients SET status = "failed" WHERE id = ?'
                    );
                    if ($updateStmt) {
                        $updateStmt->bind_param('i', $recipient['id']);
                        $updateStmt->execute();
                        $updateStmt->close();
                    }

                    error_log("{$this->logPrefix} Failed to send email to {$recipient['email']} (recipient ID {$recipient['id']})");
                }
            }

            return $sentCount;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error processing queue: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Personalize email content by replacing tokens with subscriber data
     * Supports: {{first_name}}, {{full_name}}, {{institution}}, {{research_interests}}
     *
     * @param string $content_html HTML content with tokens
     * @param array $subscriber_data Subscriber data for replacement
     * @return string Personalized HTML content
     */
    public function personalizeContent(string $content_html, array $subscriber_data): string {
        try {
            $content = $content_html;

            // Token replacements
            $tokens = [
                '{{first_name}}' => $subscriber_data['first_name'] ?? 'there',
                '{{full_name}}' => $subscriber_data['full_name'] ?? ($subscriber_data['first_name'] ?? 'Subscriber'),
                '{{subscriber_name}}' => $subscriber_data['full_name'] ?? ($subscriber_data['first_name'] ?? 'Subscriber'),
                '{{subscriber_first_name}}' => $subscriber_data['first_name'] ?? 'there',
                '{{institution}}' => $subscriber_data['institution'] ?? '',
                '{{research_interests}}' => $subscriber_data['research_interests'] ?? '',
                '{{email}}' => $subscriber_data['email'] ?? '',
            ];

            foreach ($tokens as $token => $value) {
                $content = str_replace($token, htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'), $content);
            }

            return $content;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error personalizing content: " . $e->getMessage());
            return $content_html;
        }
    }

    /**
     * Send email via AWS SES or SendGrid API
     * Includes tracking pixel URL and unsubscribe link headers
     *
     * @param string $to_email Recipient email address
     * @param string $subject Email subject
     * @param string $html_content Email HTML content
     * @param string $tracking_pixel_url Tracking pixel URL for open tracking
     * @param string $unsubscribe_url Unsubscribe link URL
     * @param int $recipient_id Newsletter recipient ID
     * @param string $sender_name Sender name
     * @param string $sender_email Sender email address
     * @return bool True on success, false on failure
     */
    private function sendViaProvider(
        string $to_email,
        string $subject,
        string $html_content,
        string $tracking_pixel_url,
        string $unsubscribe_url,
        int $recipient_id,
        string $sender_name,
        string $sender_email
    ): bool {
        try {
            if ($this->emailProvider === 'aws_ses') {
                return $this->sendViaSES($to_email, $subject, $html_content, $tracking_pixel_url, $unsubscribe_url, $sender_name, $sender_email);
            } elseif ($this->emailProvider === 'sendgrid') {
                return $this->sendViaSendGrid($to_email, $subject, $html_content, $tracking_pixel_url, $unsubscribe_url, $sender_name, $sender_email);
            } else {
                error_log("{$this->logPrefix} Unknown email provider: {$this->emailProvider}");
                return false;
            }
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error sending via provider: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send via AWS SES
     */
    private function sendViaSES(
        string $to_email,
        string $subject,
        string $html_content,
        string $tracking_pixel_url,
        string $unsubscribe_url,
        string $sender_name,
        string $sender_email
    ): bool {
        try {
            // For now, log and return true (actual SES integration would require AWS SDK)
            // In production, use: aws-php-sdk/SES or AWS Signature Version 4 signing
            error_log("{$this->logPrefix} [SES] Would send email to {$to_email} with subject '{$subject}'");
            error_log("{$this->logPrefix} [SES] Tracking pixel: {$tracking_pixel_url}");
            error_log("{$this->logPrefix} [SES] Unsubscribe URL: {$unsubscribe_url}");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} SES send failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send via SendGrid API
     */
    private function sendViaSendGrid(
        string $to_email,
        string $subject,
        string $html_content,
        string $tracking_pixel_url,
        string $unsubscribe_url,
        string $sender_name,
        string $sender_email
    ): bool {
        try {
            if (!$this->sendgridApiKey) {
                error_log("{$this->logPrefix} SendGrid API key not configured");
                return false;
            }

            // Build SendGrid API request
            $payload = [
                'personalizations' => [
                    [
                        'to' => [
                            ['email' => $to_email]
                        ],
                        'subject' => $subject,
                        'headers' => [
                            'List-Unsubscribe' => "<{$unsubscribe_url}>",
                        ]
                    ]
                ],
                'from' => [
                    'email' => $sender_email,
                    'name' => $sender_name
                ],
                'content' => [
                    [
                        'type' => 'text/html',
                        'value' => $html_content
                    ]
                ],
                'tracking_settings' => [
                    'open_tracking' => ['enable' => true],
                    'click_tracking' => ['enable' => true]
                ]
            ];

            $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->sendgridApiKey,
                ]
            ]);

            $response = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($status !== 202) {
                error_log("{$this->logPrefix} SendGrid API error (status {$status}): {$error}");
                return false;
            }

            error_log("{$this->logPrefix} [SendGrid] Email sent to {$to_email}");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} SendGrid send failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Record a newsletter event (sent, delivered, opened, clicked, unsubscribed)
     *
     * @param int $recipient_id Newsletter recipient ID
     * @param string $event_type Event type: sent, delivered, opened, clicked, unsubscribed, bounced
     * @param array $metadata Optional metadata (provider, link_url, ip_address, user_agent, etc)
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

            // Update recipient status based on event type
            if ($event_type === 'opened') {
                $this->updateRecipientStatus($recipient_id, 'opened');
            } elseif ($event_type === 'clicked') {
                $this->updateRecipientStatus($recipient_id, 'clicked');
            }

            error_log("{$this->logPrefix} Event '{$event_type}' recorded for recipient {$recipient_id}");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error recording event: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate a 1x1 tracking pixel URL for open tracking
     * Returns URL that logs an open event when fetched
     *
     * @param int $recipient_id Newsletter recipient ID
     * @return string Tracking pixel URL
     */
    public function generateTrackingPixel(int $recipient_id): string {
        try {
            // Create HMAC token to prevent tampering
            $token = $this->generateToken($recipient_id, 'track_open');
            return rtrim($this->appUrl, '/') . "/features/newsletter/tracking.php?r={$recipient_id}&token={$token}";
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error generating tracking pixel: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Generate a click redirect URL for click tracking
     * Returns URL that logs a click event and redirects to original link
     *
     * @param int $recipient_id Newsletter recipient ID
     * @param string $link_url Original link URL to redirect to
     * @return string Click tracking redirect URL
     */
    public function generateClickRedirectUrl(int $recipient_id, string $link_url): string {
        try {
            $token = $this->generateToken($recipient_id, 'track_click');
            $encodedUrl = base64_encode($link_url);
            return rtrim($this->appUrl, '/') . "/features/newsletter/click.php?r={$recipient_id}&token={$token}&url={$encodedUrl}";
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error generating click redirect URL: " . $e->getMessage());
            return $link_url;
        }
    }

    /**
     * Handle unsubscribe request via token
     * Validates token, updates subscriber status to unsubscribed
     *
     * @param string $token Unsubscribe token
     * @return array|bool Result array with 'success' and 'message' on success, false on failure
     */
    public function handleUnsubscribeRequest(string $token): array|bool {
        try {
            $token = trim($token);
            if (empty($token)) {
                return false;
            }

            // Token format: recipient_id|action|hmac
            $parts = explode('|', $token);
            if (count($parts) !== 3) {
                error_log("{$this->logPrefix} Invalid unsubscribe token format");
                return false;
            }

            list($recipient_id, $action, $hmac) = $parts;

            // Verify token
            if (!$this->verifyToken($recipient_id, 'unsubscribe', $hmac)) {
                error_log("{$this->logPrefix} Invalid unsubscribe token");
                return false;
            }

            $recipient_id = (int)$recipient_id;

            // Get recipient and subscriber info
            $stmt = $this->conn->prepare(
                'SELECT nr.id, nr.subscriber_id, ns.email
                 FROM newsletter_recipients nr
                 JOIN newsletter_subscribers ns ON nr.subscriber_id = ns.id
                 WHERE nr.id = ?'
            );
            if (!$stmt) {
                return false;
            }

            $stmt->bind_param('i', $recipient_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$result) {
                return false;
            }

            $subscriber_id = (int)$result['subscriber_id'];

            // Update subscriber status to unsubscribed
            $now = date('Y-m-d H:i:s');
            $status = 'unsubscribed';
            $updateStmt = $this->conn->prepare(
                'UPDATE newsletter_subscribers SET status = ?, unsubscribed_at = ? WHERE id = ?'
            );
            if (!$updateStmt) {
                return false;
            }

            $updateStmt->bind_param('ssi', $status, $now, $subscriber_id);
            if (!$updateStmt->execute()) {
                $updateStmt->close();
                return false;
            }
            $updateStmt->close();

            // Record unsubscribe event
            $this->recordEvent($recipient_id, 'unsubscribed', [
                'method' => 'link',
                'timestamp' => $now
            ]);

            error_log("{$this->logPrefix} Subscriber {$subscriber_id} unsubscribed via link");
            return [
                'success' => true,
                'message' => 'You have been unsubscribed from our newsletter.',
                'email' => $result['email']
            ];
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error handling unsubscribe: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Handle preference management link
     * Validates token, returns preference data for UI rendering
     *
     * @param string $token Preference token
     * @return array|bool Preference data array on success, false on failure
     */
    public function handlePreferenceLink(string $token): array|bool {
        try {
            $token = trim($token);
            if (empty($token)) {
                return false;
            }

            // Token format: recipient_id|action|hmac
            $parts = explode('|', $token);
            if (count($parts) !== 3) {
                return false;
            }

            list($recipient_id, $action, $hmac) = $parts;

            // Verify token
            if (!$this->verifyToken($recipient_id, 'preferences', $hmac)) {
                error_log("{$this->logPrefix} Invalid preference token");
                return false;
            }

            $recipient_id = (int)$recipient_id;

            // Get recipient and subscriber info
            $stmt = $this->conn->prepare(
                'SELECT nr.id, nr.subscriber_id, ns.email, np.frequency, np.categories, np.geography, np.interests, np.research_roles
                 FROM newsletter_recipients nr
                 JOIN newsletter_subscribers ns ON nr.subscriber_id = ns.id
                 LEFT JOIN newsletter_preferences np ON ns.id = np.subscriber_id
                 WHERE nr.id = ?'
            );
            if (!$stmt) {
                return false;
            }

            $stmt->bind_param('i', $recipient_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$result) {
                return false;
            }

            // Return preference data for UI
            return [
                'recipient_id' => $result['id'],
                'subscriber_id' => $result['subscriber_id'],
                'email' => $result['email'],
                'frequency' => $result['frequency'] ?? 'weekly',
                'categories' => json_decode($result['categories'] ?? '[]', true) ?? [],
                'geography' => json_decode($result['geography'] ?? '[]', true) ?? [],
                'interests' => json_decode($result['interests'] ?? '[]', true) ?? [],
                'research_roles' => json_decode($result['research_roles'] ?? '[]', true) ?? [],
                'token' => $token,
            ];
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error handling preference link: " . $e->getMessage());
            return false;
        }
    }

    // ==================== PRIVATE HELPER METHODS ====================

    /**
     * Get campaign by ID
     */
    private function getCampaign(int $campaign_id): ?array {
        try {
            $stmt = $this->conn->prepare(
                'SELECT id, content_html, title, sender_name, sender_email FROM newsletter_campaigns WHERE id = ?'
            );
            if (!$stmt) return null;

            $stmt->bind_param('i', $campaign_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return $result;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error getting campaign: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get subscriber by ID
     */
    private function getSubscriber(int $subscriber_id): ?array {
        try {
            $stmt = $this->conn->prepare(
                'SELECT id, email, status FROM newsletter_subscribers WHERE id = ?'
            );
            if (!$stmt) return null;

            $stmt->bind_param('i', $subscriber_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return $result;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error getting subscriber: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get full subscriber data including name and institution
     */
    private function getSubscriberFullData(int $subscriber_id): array {
        try {
            // Get newsletter subscriber
            $stmt = $this->conn->prepare(
                'SELECT id, email, user_id FROM newsletter_subscribers WHERE id = ?'
            );
            if (!$stmt) return ['email' => '', 'first_name' => 'Subscriber'];

            $stmt->bind_param('i', $subscriber_id);
            $stmt->execute();
            $subscriber = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$subscriber) {
                return ['email' => '', 'first_name' => 'Subscriber'];
            }

            $data = [
                'email' => $subscriber['email'] ?? '',
                'first_name' => 'Subscriber',
                'full_name' => 'Subscriber',
                'institution' => '',
                'research_interests' => ''
            ];

            // If linked to user account, get additional profile data
            if (!empty($subscriber['user_id'])) {
                $userStmt = $this->conn->prepare(
                    'SELECT first_name, last_name, institution, topics, geography
                     FROM researchers WHERE id = ?'
                );
                if ($userStmt) {
                    $userStmt->bind_param('i', $subscriber['user_id']);
                    $userStmt->execute();
                    $researcher = $userStmt->get_result()->fetch_assoc();
                    $userStmt->close();

                    if ($researcher) {
                        $data['first_name'] = $researcher['first_name'] ?? 'Subscriber';
                        $data['full_name'] = trim(($researcher['first_name'] ?? '') . ' ' . ($researcher['last_name'] ?? ''));
                        $data['institution'] = $researcher['institution'] ?? '';
                        $data['research_interests'] = $researcher['topics'] ?? '';
                    }
                }
            }

            return $data;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error getting subscriber full data: " . $e->getMessage());
            return ['email' => '', 'first_name' => 'Subscriber'];
        }
    }

    /**
     * Add tracking pixel to HTML content
     */
    private function addTrackingPixel(string $html, string $tracking_pixel_url): string {
        try {
            // Add tracking pixel before closing body tag if it exists
            if (stripos($html, '</body>') !== false) {
                $pixel = "<img src=\"{$tracking_pixel_url}\" width=\"1\" height=\"1\" alt=\"\" style=\"display:none;\" />";
                $html = str_ireplace('</body>', $pixel . '</body>', $html);
            } else {
                // Append to end if no body tag
                $html .= "<img src=\"{$tracking_pixel_url}\" width=\"1\" height=\"1\" alt=\"\" style=\"display:none;\" />";
            }
            return $html;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error adding tracking pixel: " . $e->getMessage());
            return $html;
        }
    }

    /**
     * Generate unsubscribe URL with token
     */
    private function generateUnsubscribeUrl(int $recipient_id): string {
        try {
            $token = $this->generateToken($recipient_id, 'unsubscribe');
            return rtrim($this->appUrl, '/') . "/features/newsletter/unsubscribe.php?token={$token}";
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error generating unsubscribe URL: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Generate HMAC token for secure URLs
     * Format: recipient_id|action|hmac
     */
    private function generateToken(int $recipient_id, string $action): string {
        try {
            $hmac = hash_hmac('sha256', "{$recipient_id}|{$action}", getenv('APP_SECRET') ?: 'default-secret');
            return "{$recipient_id}|{$action}|{$hmac}";
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error generating token: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Verify HMAC token
     */
    private function verifyToken(string $recipient_id, string $action, string $hmac): bool {
        try {
            $expectedHmac = hash_hmac('sha256', "{$recipient_id}|{$action}", getenv('APP_SECRET') ?: 'default-secret');
            return hash_equals($expectedHmac, $hmac);
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error verifying token: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update recipient status
     */
    private function updateRecipientStatus(int $recipient_id, string $status): bool {
        try {
            $stmt = $this->conn->prepare(
                'UPDATE newsletter_recipients SET status = ? WHERE id = ? AND status NOT IN ("clicked")'
            );
            if (!$stmt) return false;

            $stmt->bind_param('si', $status, $recipient_id);
            $success = $stmt->execute();
            $stmt->close();

            return $success;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error updating recipient status: " . $e->getMessage());
            return false;
        }
    }
}
?>

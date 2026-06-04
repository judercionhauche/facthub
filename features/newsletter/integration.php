<?php
/**
 * Newsletter Integration Module
 *
 * Central integration point for newsletter functionality with core FACT Hub infrastructure.
 * Provides hooks for:
 * - User registration lifecycle (auto-subscribe with defaults)
 * - User profile updates (sync preferences with research interests)
 * - User deletion (soft-delete from newsletter)
 * - Background job processing (newsletter_send job handler)
 * - Feature flag control (NEWSLETTER_ENABLED env var)
 * - Audit logging (all admin actions)
 * - Rate limiting (test sends per admin)
 *
 * API Boundaries:
 * - Newsletter module is completely decoupled from core app
 * - All interactions through well-defined functions
 * - Database schema managed via apply_newsletter_schema()
 * - Configuration via environment variables
 *
 * Usage:
 *   In public/index.php after database init:
 *   require_once __DIR__ . '/../features/newsletter/integration.php';
 *   NewsletterIntegration::initialize($conn);
 *
 *   Then use hook functions to trigger operations:
 *   - NewsletterIntegration::onUserRegistered($conn, $userId, $email, $profile)
 *   - NewsletterIntegration::onUserProfileUpdated($conn, $userId, $profileData)
 *   - NewsletterIntegration::onUserDeleted($conn, $userId, $email)
 */

class NewsletterIntegration {

    // ════════════════════════════════════════════════════════════════
    // Configuration & Constants
    // ════════════════════════════════════════════════════════════════

    /**
     * Is newsletter enabled via feature flag?
     */
    public static function isEnabled(): bool {
        $enabled = getenv('NEWSLETTER_ENABLED');
        return !empty($enabled) && strtolower($enabled) !== 'false';
    }

    /**
     * Get email provider configuration
     */
    public static function getEmailProvider(): array {
        return [
            'provider' => getenv('NEWSLETTER_EMAIL_PROVIDER') ?: 'aws_ses',
            'aws_region' => getenv('AWS_REGION') ?: 'us-east-1',
            'aws_key' => getenv('AWS_ACCESS_KEY_ID') ?: '',
            'aws_secret' => getenv('AWS_SECRET_ACCESS_KEY') ?: '',
            'sendgrid_key' => getenv('SENDGRID_API_KEY') ?: '',
            'from_email' => getenv('NEWSLETTER_FROM_EMAIL') ?: 'noreply@facthub.org',
            'from_name' => getenv('NEWSLETTER_FROM_NAME') ?: 'FACT Hub'
        ];
    }

    /**
     * Get rate limit configuration (test sends per admin per day)
     */
    public static function getTestSendRateLimit(): int {
        return (int)(getenv('NEWSLETTER_TEST_SEND_LIMIT') ?: '10');
    }

    // ════════════════════════════════════════════════════════════════
    // Initialization & Setup
    // ════════════════════════════════════════════════════════════════

    /**
     * Initialize newsletter integration on app startup
     * - Ensure schema is applied
     * - Load required classes
     * - Verify configuration
     *
     * Called from public/index.php after database connection
     */
    public static function initialize(mysqli $conn): bool {
        try {
            // Skip if disabled
            if (!self::isEnabled()) {
                return false;
            }

            // Ensure schema exists (applies via apply_newsletter_schema in schema_updates.php)
            // Called from public/index.php already, but safe to call multiple times

            // Load newsletter models and services
            $basePath = __DIR__;
            $requiredFiles = [
                'models/NewsletterSubscriber.php',
                'models/NewsletterCampaign.php',
                'models/NewsletterPreference.php',
                'models/NewsletterRecipient.php',
                'models/NewsletterEvent.php',
                'services/SubscriptionService.php',
                'services/CampaignService.php',
                'services/SendingService.php',
                'services/AnalyticsService.php'
            ];

            foreach ($requiredFiles as $file) {
                $filePath = $basePath . '/' . $file;
                if (file_exists($filePath)) {
                    require_once $filePath;
                } else {
                    error_log("[NewsletterIntegration] Missing required file: $file");
                }
            }

            error_log('[NewsletterIntegration] Initialized successfully');
            return true;

        } catch (Throwable $e) {
            error_log('[NewsletterIntegration] Initialization failed: ' . $e->getMessage());
            return false;
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Hook: User Registration
    // ════════════════════════════════════════════════════════════════

    /**
     * Hook called when new user registers
     * - Auto-creates newsletter_subscriber record
     * - Sets default preferences from user profile
     * - Logs audit event
     *
     * Called from: public/index.php researchers registration flow
     *
     * @param mysqli $conn Database connection
     * @param int $userId ID of newly created user
     * @param string $email User email address
     * @param array $profile User profile data (optional):
     *   - role: 'researcher', 'funder', 'admin'
     *   - first_name, last_name
     *   - interests: array of research interests
     *   - geography: array of geographic focus
     *   - topics: array of research topics
     *   - institution: institution name
     *   - orcid: ORCID identifier
     */
    public static function onUserRegistered(
        mysqli $conn,
        int $userId,
        string $email,
        array $profile = []
    ): bool {
        try {
            // Skip if disabled
            if (!self::isEnabled()) {
                return true;
            }

            // Auto-subscribe new user with default preferences
            $subscriber = self::autoSubscribeUser($conn, $userId, $email, $profile);
            if (!$subscriber) {
                error_log("[NewsletterIntegration] Failed to auto-subscribe user $userId");
                return false;
            }

            // Log audit event
            self::logAuditEvent(
                $conn,
                $email,
                'newsletter_auto_subscribe',
                'User registered and auto-subscribed to newsletter',
                'system',
                [
                    'user_id' => $userId,
                    'subscriber_id' => $subscriber['id'],
                    'frequency' => $subscriber['frequency'] ?? 'weekly'
                ]
            );

            error_log("[NewsletterIntegration] Auto-subscribed user $userId to newsletter");
            return true;

        } catch (Throwable $e) {
            error_log('[NewsletterIntegration] onUserRegistered error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Auto-subscribe user with sensible defaults
     * Creates newsletter_subscribers + newsletter_preferences records
     *
     * @return array|null Array with id, email, status, frequency; null on failure
     */
    private static function autoSubscribeUser(
        mysqli $conn,
        int $userId,
        string $email,
        array $profile = []
    ): ?array {
        try {
            // Create subscriber with active status
            $now = date('Y-m-d H:i:s');
            $status = 'active';
            $defaultFrequency = 'weekly';

            $stmt = $conn->prepare(
                'INSERT INTO newsletter_subscribers (user_id, email, status, subscribed_at)
                 VALUES (?, ?, ?, ?)'
            );
            if (!$stmt) {
                error_log('[NewsletterIntegration] Prepare subscriber insert failed: ' . $conn->error);
                return null;
            }

            $stmt->bind_param('isss', $userId, $email, $status, $now);
            if (!$stmt->execute()) {
                error_log('[NewsletterIntegration] Execute subscriber insert failed: ' . $stmt->error);
                $stmt->close();
                return null;
            }

            $subscriberId = $conn->insert_id;
            $stmt->close();

            // Create preferences with defaults from profile
            $categoriesJson = !empty($profile['topics'])
                ? json_encode((array)$profile['topics'])
                : json_encode([]);
            $geographyJson = !empty($profile['geography'])
                ? json_encode((array)$profile['geography'])
                : json_encode([]);
            $interestsJson = !empty($profile['interests'])
                ? json_encode((array)$profile['interests'])
                : json_encode([]);
            $rolesJson = !empty($profile['role'])
                ? json_encode([$profile['role']])
                : json_encode([]);

            $prefStmt = $conn->prepare(
                'INSERT INTO newsletter_preferences
                 (subscriber_id, frequency, categories_json, geography_json, interests_json, research_roles_json)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            if (!$prefStmt) {
                error_log('[NewsletterIntegration] Prepare preferences insert failed: ' . $conn->error);
                return null;
            }

            $prefStmt->bind_param(
                'isssss',
                $subscriberId,
                $defaultFrequency,
                $categoriesJson,
                $geographyJson,
                $interestsJson,
                $rolesJson
            );
            if (!$prefStmt->execute()) {
                error_log('[NewsletterIntegration] Execute preferences insert failed: ' . $prefStmt->error);
                $prefStmt->close();
                return null;
            }

            $prefStmt->close();

            return [
                'id' => $subscriberId,
                'email' => $email,
                'status' => $status,
                'frequency' => $defaultFrequency
            ];

        } catch (Throwable $e) {
            error_log('[NewsletterIntegration] autoSubscribeUser error: ' . $e->getMessage());
            return null;
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Hook: User Profile Update
    // ════════════════════════════════════════════════════════════════

    /**
     * Hook called when user updates their profile
     * - Syncs research interests, geography, topics to newsletter preferences
     * - Creates/updates subscription if needed
     * - Logs audit event
     *
     * Called from: public/index.php profile update flow
     *
     * @param mysqli $conn Database connection
     * @param int $userId User ID
     * @param array $profileData Updated profile fields:
     *   - interests: array of research interests
     *   - geography: array of geographic focus
     *   - topics: array of research topics
     *   - institution: institution name
     *   - orcid: ORCID identifier
     *   - frequency: 'daily', 'weekly', 'monthly', 'never' (optional)
     */
    public static function onUserProfileUpdated(
        mysqli $conn,
        int $userId,
        array $profileData = []
    ): bool {
        try {
            // Skip if disabled
            if (!self::isEnabled()) {
                return true;
            }

            // Get or create subscriber
            $subscriber = self::getOrCreateSubscriber($conn, $userId);
            if (!$subscriber) {
                error_log("[NewsletterIntegration] Failed to get/create subscriber for user $userId");
                return false;
            }

            // Update preferences with new profile data
            if (!self::updatePreferencesFromProfile($conn, $subscriber['id'], $profileData)) {
                error_log("[NewsletterIntegration] Failed to update preferences for subscriber {$subscriber['id']}");
                return false;
            }

            // Log audit event
            self::logAuditEvent(
                $conn,
                $subscriber['email'],
                'newsletter_profile_sync',
                'Subscriber preferences synced with profile update',
                'system',
                [
                    'user_id' => $userId,
                    'subscriber_id' => $subscriber['id'],
                    'updated_fields' => array_keys($profileData)
                ]
            );

            error_log("[NewsletterIntegration] Updated newsletter preferences for user $userId");
            return true;

        } catch (Throwable $e) {
            error_log('[NewsletterIntegration] onUserProfileUpdated error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get existing subscriber or create if missing
     */
    private static function getOrCreateSubscriber(mysqli $conn, int $userId): ?array {
        try {
            // Check if subscriber exists
            $stmt = $conn->prepare(
                'SELECT id, email, status FROM newsletter_subscribers WHERE user_id = ? LIMIT 1'
            );
            if (!$stmt) return null;

            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($result) {
                return $result;
            }

            // Get user email to create subscriber
            $userStmt = $conn->prepare('SELECT id, email FROM users WHERE id = ? LIMIT 1');
            if (!$userStmt) return null;

            $userStmt->bind_param('i', $userId);
            $userStmt->execute();
            $user = $userStmt->get_result()->fetch_assoc();
            $userStmt->close();

            if (!$user) return null;

            // Create subscriber
            $now = date('Y-m-d H:i:s');
            $status = 'active';
            $createStmt = $conn->prepare(
                'INSERT INTO newsletter_subscribers (user_id, email, status, subscribed_at)
                 VALUES (?, ?, ?, ?)'
            );
            if (!$createStmt) return null;

            $createStmt->bind_param('isss', $userId, $user['email'], $status, $now);
            if (!$createStmt->execute()) {
                $createStmt->close();
                return null;
            }

            $subscriberId = $conn->insert_id;
            $createStmt->close();

            // Create default preferences
            $frequency = 'weekly';
            $emptyJson = json_encode([]);
            $prefStmt = $conn->prepare(
                'INSERT INTO newsletter_preferences
                 (subscriber_id, frequency, categories_json, geography_json, interests_json, research_roles_json)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            if ($prefStmt) {
                $prefStmt->bind_param('isssss', $subscriberId, $frequency, $emptyJson, $emptyJson, $emptyJson, $emptyJson);
                $prefStmt->execute();
                $prefStmt->close();
            }

            return [
                'id' => $subscriberId,
                'email' => $user['email'],
                'status' => $status
            ];

        } catch (Throwable $e) {
            error_log('[NewsletterIntegration] getOrCreateSubscriber error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Update subscriber preferences from profile data
     */
    private static function updatePreferencesFromProfile(
        mysqli $conn,
        int $subscriberId,
        array $profileData
    ): bool {
        try {
            $updates = [];
            $types = '';
            $values = [];

            // Map profile fields to preference fields
            if (isset($profileData['topics']) && !empty($profileData['topics'])) {
                $updates[] = 'categories_json = ?';
                $types .= 's';
                $values[] = json_encode((array)$profileData['topics']);
            }

            if (isset($profileData['geography']) && !empty($profileData['geography'])) {
                $updates[] = 'geography_json = ?';
                $types .= 's';
                $values[] = json_encode((array)$profileData['geography']);
            }

            if (isset($profileData['interests']) && !empty($profileData['interests'])) {
                $updates[] = 'interests_json = ?';
                $types .= 's';
                $values[] = json_encode((array)$profileData['interests']);
            }

            if (isset($profileData['frequency']) && in_array($profileData['frequency'], ['daily', 'weekly', 'monthly', 'never'])) {
                $updates[] = 'frequency = ?';
                $types .= 's';
                $values[] = $profileData['frequency'];
            }

            // Nothing to update
            if (empty($updates)) {
                return true;
            }

            // Add subscriber_id parameter
            $types .= 'i';
            $values[] = $subscriberId;

            // Build and execute update
            $sql = 'UPDATE newsletter_preferences SET ' . implode(', ', $updates) . ' WHERE subscriber_id = ?';
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                error_log('[NewsletterIntegration] Prepare preferences update failed: ' . $conn->error);
                return false;
            }

            $stmt->bind_param($types, ...$values);
            if (!$stmt->execute()) {
                error_log('[NewsletterIntegration] Execute preferences update failed: ' . $stmt->error);
                $stmt->close();
                return false;
            }

            $stmt->close();
            return true;

        } catch (Throwable $e) {
            error_log('[NewsletterIntegration] updatePreferencesFromProfile error: ' . $e->getMessage());
            return false;
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Hook: User Deletion
    // ════════════════════════════════════════════════════════════════

    /**
     * Hook called when user is deleted (soft-delete)
     * - Marks subscriber as deleted (soft-delete)
     * - Preserves history for audit/compliance
     * - Logs deletion event
     *
     * Called from: public/index.php user deletion flow (admin action)
     *
     * @param mysqli $conn Database connection
     * @param int $userId User ID being deleted
     * @param string $email User email
     * @param string $reason Optional reason for deletion
     */
    public static function onUserDeleted(
        mysqli $conn,
        int $userId,
        string $email,
        string $reason = ''
    ): bool {
        try {
            // Skip if disabled
            if (!self::isEnabled()) {
                return true;
            }

            // Find subscriber for this user
            $stmt = $conn->prepare(
                'SELECT id, status FROM newsletter_subscribers WHERE user_id = ? LIMIT 1'
            );
            if (!$stmt) {
                error_log('[NewsletterIntegration] Prepare subscriber lookup failed: ' . $conn->error);
                return false;
            }

            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $subscriber = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // No subscriber record - nothing to delete
            if (!$subscriber) {
                return true;
            }

            // Soft-delete: mark as unsubscribed, preserve history
            $now = date('Y-m-d H:i:s');
            $status = 'unsubscribed';
            $delStmt = $conn->prepare(
                'UPDATE newsletter_subscribers
                 SET status = ?, unsubscribed_at = ?
                 WHERE id = ?'
            );
            if (!$delStmt) {
                error_log('[NewsletterIntegration] Prepare subscriber soft-delete failed: ' . $conn->error);
                return false;
            }

            $delStmt->bind_param('ssi', $status, $now, $subscriber['id']);
            if (!$delStmt->execute()) {
                error_log('[NewsletterIntegration] Execute subscriber soft-delete failed: ' . $delStmt->error);
                $delStmt->close();
                return false;
            }

            $delStmt->close();

            // Log audit event with deletion reason
            self::logAuditEvent(
                $conn,
                $email,
                'newsletter_user_deleted',
                'Subscriber soft-deleted due to user deletion',
                'admin',
                [
                    'user_id' => $userId,
                    'subscriber_id' => $subscriber['id'],
                    'reason' => $reason ?: 'User account deleted'
                ]
            );

            error_log("[NewsletterIntegration] Soft-deleted subscriber {$subscriber['id']} for user $userId");
            return true;

        } catch (Throwable $e) {
            error_log('[NewsletterIntegration] onUserDeleted error: ' . $e->getMessage());
            return false;
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Background Job Integration
    // ════════════════════════════════════════════════════════════════

    /**
     * Process newsletter_send background job
     * Called from app/jobs/worker.php
     *
     * Job structure:
     * {
     *   "job_type": "newsletter_send",
     *   "job_data": {
     *     "campaign_id": 123,
     *     "batch_size": 50,
     *     "retry_delay": 300
     *   }
     * }
     *
     * @param mysqli $conn Database connection
     * @param int $jobId Job queue ID
     * @param array $jobData Decoded job_data JSON
     * @return bool Success/failure
     */
    public static function processNewsletterSendJob(
        mysqli $conn,
        int $jobId,
        array $jobData
    ): bool {
        try {
            if (!self::isEnabled()) {
                return true;
            }

            $campaignId = (int)($jobData['campaign_id'] ?? 0);
            $batchSize = (int)($jobData['batch_size'] ?? 50);

            if ($campaignId <= 0) {
                error_log("[NewsletterIntegration] Invalid campaign_id in job $jobId");
                return false;
            }

            // Load SendingService
            $basePath = __DIR__;
            if (!class_exists('SendingService')) {
                require_once $basePath . '/services/SendingService.php';
            }

            // Process send for this campaign
            $sendingService = new SendingService($conn);
            if ($sendingService->processCampaignSend($campaignId, $batchSize)) {
                error_log("[NewsletterIntegration] Successfully processed newsletter send for campaign $campaignId");
                return true;
            } else {
                error_log("[NewsletterIntegration] Failed to process newsletter send for campaign $campaignId");
                return false;
            }

        } catch (Throwable $e) {
            error_log('[NewsletterIntegration] processNewsletterSendJob error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Queue a newsletter send job (called by CampaignService)
     * Adds 'newsletter_send' job to job_queue for background processing
     *
     * @param mysqli $conn Database connection
     * @param int $campaignId Campaign ID to send
     * @param int $delaySeconds Optional delay before processing
     * @return int|null Job ID or null on failure
     */
    public static function queueNewsletterSend(
        mysqli $conn,
        int $campaignId,
        int $delaySeconds = 0
    ): ?int {
        try {
            $jobType = 'newsletter_send';
            $jobData = json_encode([
                'campaign_id' => $campaignId,
                'batch_size' => 50,
                'retry_delay' => 300
            ]);
            $now = date('Y-m-d H:i:s');
            $runAfter = $delaySeconds > 0
                ? date('Y-m-d H:i:s', time() + $delaySeconds)
                : $now;
            $status = 'pending';
            $maxAttempts = 5;

            $stmt = $conn->prepare(
                'INSERT INTO job_queue (job_type, job_data, status, run_after, max_attempts, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                error_log('[NewsletterIntegration] Prepare job_queue insert failed: ' . $conn->error);
                return null;
            }

            $stmt->bind_param('sssssi', $jobType, $jobData, $status, $runAfter, $maxAttempts, $now);
            if (!$stmt->execute()) {
                error_log('[NewsletterIntegration] Execute job_queue insert failed: ' . $stmt->error);
                $stmt->close();
                return null;
            }

            $jobId = $conn->insert_id;
            $stmt->close();

            error_log("[NewsletterIntegration] Queued newsletter send job $jobId for campaign $campaignId");
            return $jobId;

        } catch (Throwable $e) {
            error_log('[NewsletterIntegration] queueNewsletterSend error: ' . $e->getMessage());
            return null;
        }
    }

    // ════════════════════════════════════════════════════════════════
    // Admin Actions & Audit Logging
    // ════════════════════════════════════════════════════════════════

    /**
     * Log admin action to audit_logs table
     * Provides compliance trail for newsletter operations
     *
     * @param mysqli $conn Database connection
     * @param string $targetEmail Target user/subscriber email (or 'system')
     * @param string $action Action type (e.g., 'newsletter_create_campaign', 'newsletter_send', 'newsletter_pause')
     * @param string $description Human-readable description
     * @param string $actorType 'admin' or 'system'
     * @param array $metadata Optional metadata array (will be JSON encoded)
     */
    public static function logAuditEvent(
        mysqli $conn,
        string $targetEmail,
        string $action,
        string $description,
        string $actorType = 'admin',
        array $metadata = []
    ): bool {
        try {
            // Skip if audit_log table doesn't exist
            $check = @$conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_NAME='audit_log' AND TABLE_SCHEMA=DATABASE() LIMIT 1");
            if (!$check || $check->num_rows === 0) {
                return false;
            }

            $now = date('Y-m-d H:i:s');
            $actorEmail = $actorType === 'system' ? 'system' : '';
            $metadataJson = json_encode($metadata);

            $stmt = $conn->prepare(
                'INSERT INTO audit_log (action, actor_email, target_email, description, metadata, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                return false;
            }

            $stmt->bind_param('ssssss', $action, $actorEmail, $targetEmail, $description, $metadataJson, $now);
            if (!$stmt->execute()) {
                $stmt->close();
                return false;
            }

            $stmt->close();
            return true;

        } catch (Throwable $e) {
            error_log('[NewsletterIntegration] logAuditEvent error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check and enforce rate limit for test sends
     * Prevents admin abuse of test send feature
     *
     * @param mysqli $conn Database connection
     * @param string $adminEmail Email of admin sending test
     * @return bool True if under limit, false if rate limited
     */
    public static function checkTestSendRateLimit(
        mysqli $conn,
        string $adminEmail
    ): bool {
        try {
            $limit = self::getTestSendRateLimit();
            $oneDayAgo = date('Y-m-d H:i:s', time() - 86400);

            // Count test sends in last 24 hours
            $stmt = $conn->prepare(
                'SELECT COUNT(*) as count FROM audit_log
                 WHERE actor_email = ? AND action = ? AND created_at > ?'
            );
            if (!$stmt) {
                return true; // Allow if query fails
            }

            $action = 'newsletter_test_send';
            $stmt->bind_param('sss', $adminEmail, $action, $oneDayAgo);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $count = (int)($result['count'] ?? 0);
            $allowed = $count < $limit;

            if (!$allowed) {
                error_log("[NewsletterIntegration] Rate limit exceeded for admin $adminEmail (count: $count, limit: $limit)");
            }

            return $allowed;

        } catch (Throwable $e) {
            error_log('[NewsletterIntegration] checkTestSendRateLimit error: ' . $e->getMessage());
            return true; // Allow on error
        }
    }

    /**
     * Record test send for rate limiting
     */
    public static function recordTestSend(
        mysqli $conn,
        string $adminEmail,
        int $campaignId,
        array $testEmails
    ): bool {
        return self::logAuditEvent(
            $conn,
            implode(',', $testEmails),
            'newsletter_test_send',
            'Test email sent to ' . count($testEmails) . ' recipient(s)',
            'admin',
            [
                'admin_email' => $adminEmail,
                'campaign_id' => $campaignId,
                'test_emails' => $testEmails
            ]
        );
    }

    /**
     * Record campaign action (create, send, pause)
     */
    public static function recordCampaignAction(
        mysqli $conn,
        string $adminEmail,
        int $campaignId,
        string $actionType,
        string $description,
        array $details = []
    ): bool {
        $action = 'newsletter_' . $actionType;
        return self::logAuditEvent(
            $conn,
            $adminEmail,
            $action,
            $description,
            'admin',
            array_merge(['campaign_id' => $campaignId], $details)
        );
    }

    // ════════════════════════════════════════════════════════════════
    // Utility Functions
    // ════════════════════════════════════════════════════════════════

    /**
     * Get newsletter subscriber count
     */
    public static function getSubscriberCount(mysqli $conn): int {
        try {
            $result = @$conn->query(
                "SELECT COUNT(*) as count FROM newsletter_subscribers WHERE status IN ('active', 'inactive')"
            );
            if (!$result) return 0;

            $row = $result->fetch_assoc();
            return (int)($row['count'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Get active subscriber count (subscription active)
     */
    public static function getActiveSubscriberCount(mysqli $conn): int {
        try {
            $result = @$conn->query(
                "SELECT COUNT(*) as count FROM newsletter_subscribers WHERE status = 'active'"
            );
            if (!$result) return 0;

            $row = $result->fetch_assoc();
            return (int)($row['count'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Get pending campaign count
     */
    public static function getPendingCampaignCount(mysqli $conn): int {
        try {
            $result = @$conn->query(
                "SELECT COUNT(*) as count FROM newsletter_campaigns WHERE status IN ('draft', 'scheduled')"
            );
            if (!$result) return 0;

            $row = $result->fetch_assoc();
            return (int)($row['count'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Check if user is newsletter subscriber
     */
    public static function isSubscriber(mysqli $conn, int $userId): bool {
        try {
            $stmt = $conn->prepare(
                "SELECT id FROM newsletter_subscribers WHERE user_id = ? AND status = 'active' LIMIT 1"
            );
            if (!$stmt) return false;

            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result()->num_rows > 0;
            $stmt->close();

            return $result;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Get subscriber by user ID
     */
    public static function getSubscriber(mysqli $conn, int $userId): ?array {
        try {
            $stmt = $conn->prepare(
                "SELECT id, email, status FROM newsletter_subscribers WHERE user_id = ? LIMIT 1"
            );
            if (!$stmt) return null;

            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return $result;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Get subscriber preferences
     */
    public static function getSubscriberPreferences(mysqli $conn, int $subscriberId): ?array {
        try {
            $stmt = $conn->prepare(
                "SELECT * FROM newsletter_preferences WHERE subscriber_id = ? LIMIT 1"
            );
            if (!$stmt) return null;

            $stmt->bind_param('i', $subscriberId);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // Decode JSON fields
            if ($result) {
                $result['categories'] = json_decode($result['categories_json'] ?? '[]', true) ?: [];
                $result['geography'] = json_decode($result['geography_json'] ?? '[]', true) ?: [];
                $result['interests'] = json_decode($result['interests_json'] ?? '[]', true) ?: [];
                $result['research_roles'] = json_decode($result['research_roles_json'] ?? '[]', true) ?: [];
            }

            return $result;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Clear feature flag check - useful for testing
     */
    public static function forceClearCache(): void {
        // No cache in this implementation - env vars are read fresh each time
    }
}

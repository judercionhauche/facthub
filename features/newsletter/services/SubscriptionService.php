<?php
/**
 * SubscriptionService
 * High-level service for managing newsletter subscriptions, preferences, and audience segmentation
 */
class SubscriptionService {
    private mysqli $conn;
    private string $logPrefix = '[SubscriptionService]';

    // Valid frequency options
    private array $validFrequencies = ['daily', 'weekly', 'monthly', 'none'];

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    /**
     * Subscribe a user to the newsletter
     * Creates subscriber record and sets initial preferences
     *
     * @param int $user_id User ID
     * @param string $email Email address
     * @param array $preferences Optional preference array with keys:
     *                           - frequency: 'daily', 'weekly', 'monthly' (default: 'weekly')
     *                           - categories: array of category names
     *                           - geography: array of geographic regions
     *                           - interests: array of interest tags
     *                           - research_roles: array of research roles
     * @return array|bool Array with 'subscriber_id' on success, false on failure
     */
    public function subscribe(int $user_id, string $email, array $preferences = []): array|bool {
        try {
            $email = trim($email);

            // Validate email
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                error_log("{$this->logPrefix} Invalid email provided: {$email}");
                return false;
            }

            // Check if already subscribed
            $existing = $this->getSubscriber($user_id);
            if ($existing && $existing['status'] === 'active') {
                error_log("{$this->logPrefix} User {$user_id} is already an active subscriber");
                return ['subscriber_id' => $existing['id'], 'already_subscribed' => true];
            }

            // Prepare subscription data
            $now = date('Y-m-d H:i:s');
            $status = 'active';

            // If resubscribing, use existing subscriber ID
            if ($existing) {
                $stmt = $this->conn->prepare(
                    'UPDATE newsletter_subscribers
                     SET status = ?, unsubscribed_at = NULL
                     WHERE id = ?'
                );
                if (!$stmt) {
                    error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                    return false;
                }

                $subscriber_id = $existing['id'];
                $stmt->bind_param('si', $status, $subscriber_id);
                if (!$stmt->execute()) {
                    error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                    $stmt->close();
                    return false;
                }
                $stmt->close();
            } else {
                // Create new subscriber
                $stmt = $this->conn->prepare(
                    'INSERT INTO newsletter_subscribers (user_id, email, status, subscribed_at)
                     VALUES (?, ?, ?, ?)'
                );
                if (!$stmt) {
                    error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                    return false;
                }

                $stmt->bind_param('isss', $user_id, $email, $status, $now);
                if (!$stmt->execute()) {
                    error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                    $stmt->close();
                    return false;
                }

                $subscriber_id = $this->conn->insert_id;
                $stmt->close();
            }

            // Set preferences
            if (!empty($preferences)) {
                if (!$this->updatePreferences($subscriber_id, $preferences)) {
                    error_log("{$this->logPrefix} Warning: Preferences failed for subscriber {$subscriber_id}");
                    // Don't fail subscription if preferences fail - subscriber is created
                }
            } else {
                // Create default preferences
                $this->createDefaultPreferences($subscriber_id);
            }

            error_log("{$this->logPrefix} User {$user_id} subscribed with ID: {$subscriber_id}");
            return ['subscriber_id' => $subscriber_id];
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error subscribing user: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Unsubscribe a subscriber from the newsletter
     * Marks subscriber as unsubscribed and optionally records reason
     *
     * @param int $subscriber_id Subscriber ID
     * @param string|null $reason Optional unsubscribe reason
     * @return bool True on success, false on failure
     */
    public function unsubscribe(int $subscriber_id, ?string $reason = null): bool {
        try {
            $status = 'unsubscribed';
            $unsubscribed_at = date('Y-m-d H:i:s');

            $stmt = $this->conn->prepare(
                'UPDATE newsletter_subscribers
                 SET status = ?, unsubscribed_at = ?
                 WHERE id = ?'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('ssi', $status, $unsubscribed_at, $subscriber_id);
            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }
            $stmt->close();

            // Log unsubscribe reason if provided
            if ($reason !== null && !empty(trim($reason))) {
                $this->logUnsubscribeReason($subscriber_id, trim($reason));
            }

            error_log("{$this->logPrefix} Subscriber {$subscriber_id} unsubscribed");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error unsubscribing: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update subscriber preferences
     * Updates frequency, categories, geography, interests, and research roles
     *
     * @param int $subscriber_id Subscriber ID
     * @param array $preferences Preference array with keys:
     *                           - frequency: 'daily', 'weekly', 'monthly', 'none'
     *                           - categories: array
     *                           - geography: array
     *                           - interests: array
     *                           - research_roles: array
     * @return bool True on success, false on failure
     */
    public function updatePreferences(int $subscriber_id, array $preferences): bool {
        try {
            // Validate preferences
            if (!$this->validatePreferences($preferences)) {
                error_log("{$this->logPrefix} Invalid preferences provided");
                return false;
            }

            // Prepare preference data
            $frequency = $preferences['frequency'] ?? 'weekly';
            $categories = json_encode($preferences['categories'] ?? []);
            $geography = json_encode($preferences['geography'] ?? []);
            $interests = json_encode($preferences['interests'] ?? []);
            $research_roles = json_encode($preferences['research_roles'] ?? []);

            // Check if preferences exist
            $existingPrefs = $this->getPreferences($subscriber_id);

            if ($existingPrefs) {
                // Update existing preferences
                $stmt = $this->conn->prepare(
                    'UPDATE newsletter_preferences
                     SET frequency = ?, categories = ?, geography = ?, interests = ?, research_roles = ?
                     WHERE subscriber_id = ?'
                );
                if (!$stmt) {
                    error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                    return false;
                }

                $stmt->bind_param('sssssi',
                    $frequency,
                    $categories,
                    $geography,
                    $interests,
                    $research_roles,
                    $subscriber_id
                );
            } else {
                // Insert new preferences
                $stmt = $this->conn->prepare(
                    'INSERT INTO newsletter_preferences (subscriber_id, frequency, categories, geography, interests, research_roles)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                if (!$stmt) {
                    error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                    return false;
                }

                $stmt->bind_param('isssss',
                    $subscriber_id,
                    $frequency,
                    $categories,
                    $geography,
                    $interests,
                    $research_roles
                );
            }

            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }
            $stmt->close();

            error_log("{$this->logPrefix} Preferences updated for subscriber {$subscriber_id}");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error updating preferences: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get subscriber record with full details
     *
     * @param int $user_id User ID
     * @return array|null Subscriber record with 'id', 'user_id', 'email', 'status', 'subscribed_at', 'unsubscribed_at', or null
     */
    public function getSubscriber(int $user_id): ?array {
        try {
            $stmt = $this->conn->prepare(
                'SELECT id, user_id, email, status, subscribed_at, unsubscribed_at
                 FROM newsletter_subscribers
                 WHERE user_id = ?'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return null;
            }

            $stmt->bind_param('i', $user_id);
            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return null;
            }

            $result = $stmt->get_result();
            $subscriber = $result->fetch_assoc();
            $stmt->close();

            return $subscriber;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error getting subscriber: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if user is an active subscriber
     *
     * @param int $user_id User ID
     * @return bool True if active subscriber, false otherwise
     */
    public function isSubscribed(int $user_id): bool {
        try {
            $subscriber = $this->getSubscriber($user_id);
            return $subscriber !== null && $subscriber['status'] === 'active';
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error checking subscription: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get active subscribers matching audience segmentation filters
     * Allows filtering by frequency, categories, geography, and more
     *
     * @param array $filters Optional filters array:
     *                        - frequency: 'daily', 'weekly', 'monthly' (or array of frequencies)
     *                        - categories: array of category names (match any)
     *                        - geography: array of regions (match any)
     *                        - interests: array of interests (match any)
     *                        - research_roles: array of roles (match any)
     *                        - limit: max results (default: 1000)
     *                        - offset: pagination offset (default: 0)
     * @return array Array of subscriber arrays with preferences, or empty array on error
     */
    public function getActiveSubscribers(array $filters = []): array {
        try {
            // Extract pagination
            $limit = (int)($filters['limit'] ?? 1000);
            $offset = (int)($filters['offset'] ?? 0);

            // Base query
            $query = 'SELECT s.id, s.user_id, s.email, s.status, s.subscribed_at, s.unsubscribed_at,
                             p.frequency, p.categories, p.geography, p.interests, p.research_roles
                      FROM newsletter_subscribers s
                      LEFT JOIN newsletter_preferences p ON s.id = p.subscriber_id
                      WHERE s.status = "active"';

            $params = [];
            $types = '';

            // Apply frequency filter
            if (!empty($filters['frequency'])) {
                $freqs = (array)$filters['frequency'];
                $placeholders = array_fill(0, count($freqs), '?');
                $query .= ' AND p.frequency IN (' . implode(',', $placeholders) . ')';
                $types .= str_repeat('s', count($freqs));
                $params = array_merge($params, $freqs);
            }

            // Apply category filter (JSON search)
            if (!empty($filters['categories']) && is_array($filters['categories'])) {
                foreach ($filters['categories'] as $category) {
                    $query .= ' AND (p.categories LIKE ? OR p.categories IS NULL)';
                    $types .= 's';
                    $params[] = '%' . $category . '%';
                }
            }

            // Apply geography filter (JSON search)
            if (!empty($filters['geography']) && is_array($filters['geography'])) {
                foreach ($filters['geography'] as $region) {
                    $query .= ' AND (p.geography LIKE ? OR p.geography IS NULL)';
                    $types .= 's';
                    $params[] = '%' . $region . '%';
                }
            }

            // Apply interests filter (JSON search)
            if (!empty($filters['interests']) && is_array($filters['interests'])) {
                foreach ($filters['interests'] as $interest) {
                    $query .= ' AND (p.interests LIKE ? OR p.interests IS NULL)';
                    $types .= 's';
                    $params[] = '%' . $interest . '%';
                }
            }

            // Apply research roles filter (JSON search)
            if (!empty($filters['research_roles']) && is_array($filters['research_roles'])) {
                foreach ($filters['research_roles'] as $role) {
                    $query .= ' AND (p.research_roles LIKE ? OR p.research_roles IS NULL)';
                    $types .= 's';
                    $params[] = '%' . $role . '%';
                }
            }

            // Add ordering and pagination
            $query .= ' ORDER BY s.subscribed_at DESC LIMIT ? OFFSET ?';
            $types .= 'ii';
            $params[] = $limit;
            $params[] = $offset;

            // Prepare and execute
            $stmt = $this->conn->prepare($query);
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return [];
            }

            // Bind parameters dynamically if any
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }

            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return [];
            }

            $result = $stmt->get_result();
            $subscribers = [];

            while ($row = $result->fetch_assoc()) {
                // Parse JSON preference fields
                if ($row['categories']) {
                    $row['categories'] = json_decode($row['categories'], true) ?? [];
                }
                if ($row['geography']) {
                    $row['geography'] = json_decode($row['geography'], true) ?? [];
                }
                if ($row['interests']) {
                    $row['interests'] = json_decode($row['interests'], true) ?? [];
                }
                if ($row['research_roles']) {
                    $row['research_roles'] = json_decode($row['research_roles'], true) ?? [];
                }
                $subscribers[] = $row;
            }
            $stmt->close();

            return $subscribers;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error getting active subscribers: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Validate preference values
     * Checks frequency is valid, and arrays are properly formatted
     *
     * @param array $prefs Preferences array to validate
     * @return bool True if valid, false otherwise
     */
    public function validatePreferences(array $prefs): bool {
        try {
            // Check frequency if provided
            if (isset($prefs['frequency'])) {
                if (!in_array($prefs['frequency'], $this->validFrequencies, true)) {
                    error_log("{$this->logPrefix} Invalid frequency: {$prefs['frequency']}");
                    return false;
                }
            }

            // Validate array fields
            $arrayFields = ['categories', 'geography', 'interests', 'research_roles'];
            foreach ($arrayFields as $field) {
                if (isset($prefs[$field]) && !is_array($prefs[$field])) {
                    error_log("{$this->logPrefix} {$field} must be an array");
                    return false;
                }

                // Check array items are strings
                if (isset($prefs[$field]) && is_array($prefs[$field])) {
                    foreach ($prefs[$field] as $item) {
                        if (!is_string($item) && !is_int($item)) {
                            error_log("{$this->logPrefix} {$field} items must be strings");
                            return false;
                        }
                    }
                }
            }

            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error validating preferences: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get subscriber preferences
     *
     * @param int $subscriber_id Subscriber ID
     * @return array|null Preferences array or null if not found
     */
    private function getPreferences(int $subscriber_id): ?array {
        try {
            $stmt = $this->conn->prepare(
                'SELECT id, subscriber_id, frequency, categories, geography, interests, research_roles
                 FROM newsletter_preferences
                 WHERE subscriber_id = ?'
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
            $prefs = $result->fetch_assoc();
            $stmt->close();

            if (!$prefs) {
                return null;
            }

            // Decode JSON fields
            $prefs['categories'] = json_decode($prefs['categories'] ?? '[]', true) ?? [];
            $prefs['geography'] = json_decode($prefs['geography'] ?? '[]', true) ?? [];
            $prefs['interests'] = json_decode($prefs['interests'] ?? '[]', true) ?? [];
            $prefs['research_roles'] = json_decode($prefs['research_roles'] ?? '[]', true) ?? [];

            return $prefs;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error getting preferences: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create default preferences for new subscriber
     *
     * @param int $subscriber_id Subscriber ID
     * @return bool True on success, false on failure
     */
    private function createDefaultPreferences(int $subscriber_id): bool {
        try {
            $frequency = 'weekly';
            $categories = json_encode([]);
            $geography = json_encode([]);
            $interests = json_encode([]);
            $research_roles = json_encode([]);

            $stmt = $this->conn->prepare(
                'INSERT INTO newsletter_preferences (subscriber_id, frequency, categories, geography, interests, research_roles)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('isssss',
                $subscriber_id,
                $frequency,
                $categories,
                $geography,
                $interests,
                $research_roles
            );

            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }
            $stmt->close();

            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error creating default preferences: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Log unsubscribe reason for analytics
     *
     * @param int $subscriber_id Subscriber ID
     * @param string $reason Unsubscribe reason
     * @return bool True on success, false on failure
     */
    private function logUnsubscribeReason(int $subscriber_id, string $reason): bool {
        try {
            $logged_at = date('Y-m-d H:i:s');

            // Check if unsubscribe_reasons table exists
            $result = $this->conn->query(
                'SHOW TABLES LIKE "newsletter_unsubscribe_reasons"'
            );

            if ($result && $result->num_rows > 0) {
                $stmt = $this->conn->prepare(
                    'INSERT INTO newsletter_unsubscribe_reasons (subscriber_id, reason, logged_at)
                     VALUES (?, ?, ?)'
                );
                if (!$stmt) {
                    error_log("{$this->logPrefix} Prepare failed for unsubscribe reason: " . $this->conn->error);
                    return false;
                }

                $stmt->bind_param('iss', $subscriber_id, $reason, $logged_at);
                if (!$stmt->execute()) {
                    error_log("{$this->logPrefix} Execute failed for unsubscribe reason: " . $stmt->error);
                    $stmt->close();
                    return false;
                }
                $stmt->close();
            }

            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error logging unsubscribe reason: " . $e->getMessage());
            return false;
        }
    }
}

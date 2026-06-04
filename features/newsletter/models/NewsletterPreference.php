<?php
/**
 * NewsletterPreference Model
 * Manages subscriber preferences for newsletter frequency, categories, geography, and interests
 */
class NewsletterPreference {
    private mysqli $conn;
    private string $logPrefix = '[NewsletterPreference]';

    // Properties
    public ?int $id = null;
    public ?int $subscriber_id = null;
    public string $frequency = 'weekly';  // daily, weekly, monthly, none
    public array $categories = [];
    public array $geography = [];
    public array $interests = [];
    public array $research_roles = [];

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    /**
     * Load preference by ID
     * @param int $id
     * @return bool True if preference found, false otherwise
     */
    public function loadById(int $id): bool {
        try {
            $stmt = $this->conn->prepare(
                'SELECT id, subscriber_id, frequency, categories, geography, interests, research_roles
                 FROM newsletter_preferences
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
            error_log("{$this->logPrefix} Error loading preference: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Load preference by subscriber ID
     * @param int $subscriber_id
     * @return bool True if preference found, false otherwise
     */
    public function loadBySubscriberId(int $subscriber_id): bool {
        try {
            $stmt = $this->conn->prepare(
                'SELECT id, subscriber_id, frequency, categories, geography, interests, research_roles
                 FROM newsletter_preferences
                 WHERE subscriber_id = ?'
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
            $row = $result->fetch_assoc();
            $stmt->close();

            if (!$row) {
                return false;
            }

            return $this->loadFromRow($row);
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error loading preference by subscriber_id: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Load preference from database row
     * @param array $row Database row
     * @return bool True on success
     */
    private function loadFromRow(array $row): bool {
        $this->id = (int)$row['id'];
        $this->subscriber_id = (int)$row['subscriber_id'];
        $this->frequency = $row['frequency'];
        $this->categories = json_decode($row['categories'] ?? '[]', true) ?? [];
        $this->geography = json_decode($row['geography'] ?? '[]', true) ?? [];
        $this->interests = json_decode($row['interests'] ?? '[]', true) ?? [];
        $this->research_roles = json_decode($row['research_roles'] ?? '[]', true) ?? [];
        return true;
    }

    /**
     * Validate frequency value
     * @param string $frequency
     * @return bool True if valid, false otherwise
     */
    private function isValidFrequency(string $frequency): bool {
        return in_array($frequency, ['daily', 'weekly', 'monthly', 'none'], true);
    }

    /**
     * Save preference (insert or update)
     * @return bool True on success, false on failure
     */
    public function save(): bool {
        try {
            // Validate
            if ($this->subscriber_id === null) {
                error_log("{$this->logPrefix} Cannot save preference without subscriber_id");
                return false;
            }

            if (!$this->isValidFrequency($this->frequency)) {
                error_log("{$this->logPrefix} Invalid frequency: {$this->frequency}");
                return false;
            }

            if ($this->id === null) {
                return $this->insert();
            } else {
                return $this->update();
            }
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error saving preference: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Insert new preference
     * @return bool True on success, false on failure
     */
    private function insert(): bool {
        try {
            $categories = json_encode($this->categories);
            $geography = json_encode($this->geography);
            $interests = json_encode($this->interests);
            $research_roles = json_encode($this->research_roles);

            $stmt = $this->conn->prepare(
                'INSERT INTO newsletter_preferences (subscriber_id, frequency, categories, geography, interests, research_roles)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('isssss',
                $this->subscriber_id,
                $this->frequency,
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

            $this->id = $this->conn->insert_id;
            $stmt->close();

            error_log("{$this->logPrefix} Preference inserted with ID: {$this->id}");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error inserting preference: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update existing preference
     * @return bool True on success, false on failure
     */
    private function update(): bool {
        try {
            if ($this->id === null) {
                error_log("{$this->logPrefix} Cannot update preference without ID");
                return false;
            }

            $categories = json_encode($this->categories);
            $geography = json_encode($this->geography);
            $interests = json_encode($this->interests);
            $research_roles = json_encode($this->research_roles);

            $stmt = $this->conn->prepare(
                'UPDATE newsletter_preferences
                 SET frequency = ?, categories = ?, geography = ?, interests = ?, research_roles = ?
                 WHERE id = ?'
            );
            if (!$stmt) {
                error_log("{$this->logPrefix} Prepare failed: " . $this->conn->error);
                return false;
            }

            $stmt->bind_param('sssssi',
                $this->frequency,
                $categories,
                $geography,
                $interests,
                $research_roles,
                $this->id
            );

            if (!$stmt->execute()) {
                error_log("{$this->logPrefix} Execute failed: " . $stmt->error);
                $stmt->close();
                return false;
            }

            $stmt->close();
            error_log("{$this->logPrefix} Preference updated with ID: {$this->id}");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error updating preference: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete preference
     * @return bool True on success, false on failure
     */
    public function delete(): bool {
        try {
            if ($this->id === null) {
                error_log("{$this->logPrefix} Cannot delete preference without ID");
                return false;
            }

            $stmt = $this->conn->prepare(
                'DELETE FROM newsletter_preferences WHERE id = ?'
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
            error_log("{$this->logPrefix} Preference deleted with ID: {$this->id}");
            return true;
        } catch (Exception $e) {
            error_log("{$this->logPrefix} Error deleting preference: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get preference
     * @param int $id
     * @return array|null Preference data or null if not found
     */
    public static function get(mysqli $conn, int $id): ?array {
        try {
            $stmt = $conn->prepare(
                'SELECT id, subscriber_id, frequency, categories, geography, interests, research_roles
                 FROM newsletter_preferences
                 WHERE id = ?'
            );
            if (!$stmt) {
                error_log("[NewsletterPreference] Prepare failed: " . $conn->error);
                return null;
            }

            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) {
                error_log("[NewsletterPreference] Execute failed: " . $stmt->error);
                $stmt->close();
                return null;
            }

            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();

            if (!$row) {
                return null;
            }

            // Parse JSON fields
            $row['categories'] = json_decode($row['categories'] ?? '[]', true) ?? [];
            $row['geography'] = json_decode($row['geography'] ?? '[]', true) ?? [];
            $row['interests'] = json_decode($row['interests'] ?? '[]', true) ?? [];
            $row['research_roles'] = json_decode($row['research_roles'] ?? '[]', true) ?? [];

            return $row;
        } catch (Exception $e) {
            error_log("[NewsletterPreference] Error getting preference: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Add category to preferences
     * @param string $category
     * @return bool True on success
     */
    public function addCategory(string $category): bool {
        $category = trim($category);
        if ($category === '' || in_array($category, $this->categories, true)) {
            return true;
        }
        $this->categories[] = $category;
        return true;
    }

    /**
     * Remove category from preferences
     * @param string $category
     * @return bool True on success
     */
    public function removeCategory(string $category): bool {
        $category = trim($category);
        $this->categories = array_filter($this->categories, function($c) use ($category) {
            return $c !== $category;
        });
        return true;
    }

    /**
     * Add geography to preferences
     * @param string $geography
     * @return bool True on success
     */
    public function addGeography(string $geography): bool {
        $geography = trim($geography);
        if ($geography === '' || in_array($geography, $this->geography, true)) {
            return true;
        }
        $this->geography[] = $geography;
        return true;
    }

    /**
     * Remove geography from preferences
     * @param string $geography
     * @return bool True on success
     */
    public function removeGeography(string $geography): bool {
        $geography = trim($geography);
        $this->geography = array_filter($this->geography, function($g) use ($geography) {
            return $g !== $geography;
        });
        return true;
    }

    /**
     * Add interest to preferences
     * @param string $interest
     * @return bool True on success
     */
    public function addInterest(string $interest): bool {
        $interest = trim($interest);
        if ($interest === '' || in_array($interest, $this->interests, true)) {
            return true;
        }
        $this->interests[] = $interest;
        return true;
    }

    /**
     * Remove interest from preferences
     * @param string $interest
     * @return bool True on success
     */
    public function removeInterest(string $interest): bool {
        $interest = trim($interest);
        $this->interests = array_filter($this->interests, function($i) use ($interest) {
            return $i !== $interest;
        });
        return true;
    }

    /**
     * Add research role to preferences
     * @param string $role
     * @return bool True on success
     */
    public function addResearchRole(string $role): bool {
        $role = trim($role);
        if ($role === '' || in_array($role, $this->research_roles, true)) {
            return true;
        }
        $this->research_roles[] = $role;
        return true;
    }

    /**
     * Remove research role from preferences
     * @param string $role
     * @return bool True on success
     */
    public function removeResearchRole(string $role): bool {
        $role = trim($role);
        $this->research_roles = array_filter($this->research_roles, function($r) use ($role) {
            return $r !== $role;
        });
        return true;
    }

    /**
     * Check if subscriber wants daily newsletters
     * @return bool True if daily, false otherwise
     */
    public function isDaily(): bool {
        return $this->frequency === 'daily';
    }

    /**
     * Check if subscriber wants weekly newsletters
     * @return bool True if weekly, false otherwise
     */
    public function isWeekly(): bool {
        return $this->frequency === 'weekly';
    }

    /**
     * Check if subscriber wants monthly newsletters
     * @return bool True if monthly, false otherwise
     */
    public function isMonthly(): bool {
        return $this->frequency === 'monthly';
    }

    /**
     * Check if subscriber has opted out
     * @return bool True if opted out (none), false otherwise
     */
    public function isOptedOut(): bool {
        return $this->frequency === 'none';
    }
}

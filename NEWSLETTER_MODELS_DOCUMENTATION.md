# Newsletter Data Models Documentation

## Overview

The newsletter system comprises 5 interconnected PHP data models that manage newsletter subscriptions, preferences, campaigns, delivery tracking, and event recording. All models use MySQLi with prepared statements for security and proper error handling.

## Models

### 1. NewsletterSubscriber

**File:** `features/newsletter/models/NewsletterSubscriber.php`

Manages individual newsletter subscribers with their subscription lifecycle.

**Properties:**
- `id` (int): Unique subscriber identifier
- `user_id` (int|null): Associated user account ID
- `email` (string): Subscriber email address
- `status` (string): One of `active`, `inactive`, `unsubscribed`, `bounced`
- `subscribed_at` (datetime): When subscriber joined
- `unsubscribed_at` (datetime|null): When subscriber left (if applicable)

**Key Methods:**

```php
// Loading subscribers
loadById(int $id): bool              // Load by subscriber ID
loadByEmail(string $email): bool     // Load by email address
loadByUserId(int $user_id): bool     // Load by associated user ID

// Lifecycle management
save(): bool                          // Insert or update
updateStatus(string $status): bool    // Change subscription status
getPreferences(): array|null          // Retrieve preferences for this subscriber
delete(): bool                        // Delete subscriber (cascades to preferences/recipients)

// Status checks
isActive(): bool                      // Check if actively subscribed
isUnsubscribed(): bool                // Check if unsubscribed
isInactive(): bool                    // Check if inactive

// Static utility methods
getActive(mysqli $conn, int $limit = 100, int $offset = 0): array
countActive(mysqli $conn): int
```

**Usage Example:**
```php
$subscriber = new NewsletterSubscriber($conn);
if ($subscriber->loadByEmail('user@example.com')) {
    $subscriber->updateStatus('active');
    $subscriber->save();
} else {
    $subscriber->email = 'user@example.com';
    $subscriber->status = 'active';
    $subscriber->save();
}
```

---

### 2. NewsletterPreference

**File:** `features/newsletter/models/NewsletterPreference.php`

Manages subscriber preferences for newsletter content and frequency.

**Properties:**
- `id` (int): Unique preference record ID
- `subscriber_id` (int): Associated subscriber
- `frequency` (string): One of `daily`, `weekly`, `monthly`, `none`
- `categories` (array): JSON array of interested categories
- `geography` (array): JSON array of geographic regions
- `interests` (array): JSON array of research interests
- `research_roles` (array): JSON array of research roles

**Key Methods:**

```php
// Loading preferences
loadById(int $id): bool
loadBySubscriberId(int $subscriber_id): bool

// Preference management
save(): bool                          // Insert or update
delete(): bool                        // Delete preferences
get(mysqli $conn, int $id): array|null  // Static method to get by ID

// Category management
addCategory(string $category): bool
removeCategory(string $category): bool

// Geography management
addGeography(string $geography): bool
removeGeography(string $geography): bool

// Interest management
addInterest(string $interest): bool
removeInterest(string $interest): bool

// Research role management
addResearchRole(string $role): bool
removeResearchRole(string $role): bool

// Frequency checks
isDaily(): bool
isWeekly(): bool
isMonthly(): bool
isOptedOut(): bool
```

**Usage Example:**
```php
$pref = new NewsletterPreference($conn);
$pref->subscriber_id = 5;
$pref->frequency = 'weekly';
$pref->addCategory('food_security');
$pref->addGeography('Africa');
$pref->addInterest('sustainable agriculture');
$pref->save();
```

---

### 3. NewsletterCampaign

**File:** `features/newsletter/models/NewsletterCampaign.php`

Manages newsletter campaigns with scheduling, publishing, and analytics.

**Properties:**
- `id` (int): Unique campaign identifier
- `title` (string): Campaign title
- `slug` (string): URL-safe slug (auto-generated from title)
- `content_html` (string): HTML email content
- `status` (string): One of `draft`, `scheduled`, `sent`, `paused`, `cancelled`
- `sender_name` (string): Display name for sender
- `sender_email` (string): Email address of sender
- `scheduled_at` (datetime|null): Scheduled send time
- `sent_at` (datetime|null): When campaign was sent
- `analytics` (array): Statistics object with counts

**Key Methods:**

```php
// Loading campaigns
loadById(int $id): bool
loadBySlug(string $slug): bool

// Campaign management
save(): bool                          // Insert or update (validates data)
delete(): bool                        // Delete campaign (cascades to recipients/events)

// Campaign lifecycle
publish(): bool                       // Send campaign (draft/paused -> sent)
schedule(string $scheduledAt): bool   // Schedule for future (validates datetime)

// Analytics
getStats(): array                     // Get comprehensive statistics object
updateAnalytics(array $updates): bool // Update counts (recipients, opens, clicks, bounces)

// Calculate rates
calculateOpenRate(): float
calculateClickRate(): float
calculateBounceRate(): float

// Static utility methods
getAll(mysqli $conn, string|null $status = null, int $limit = 50, int $offset = 0): array
```

**Analytics Object Structure:**
```php
[
    'id' => int,
    'title' => string,
    'status' => string,
    'recipients_count' => int,
    'opened_count' => int,
    'clicked_count' => int,
    'bounced_count' => int,
    'open_rate' => float,      // percentage
    'click_rate' => float,     // percentage
    'bounce_rate' => float,    // percentage
    'sent_at' => datetime|null,
    'scheduled_at' => datetime|null
]
```

**Usage Example:**
```php
$campaign = new NewsletterCampaign($conn);
$campaign->title = 'Monthly Newsletter - June 2026';
$campaign->content_html = '<h1>June Updates</h1>...';
$campaign->sender_name = 'FACT Alliance';
$campaign->sender_email = 'newsletter@factalliance.org';
$campaign->save();

// Schedule for later
$campaign->schedule('2026-06-15 09:00:00');

// Publish immediately
$campaign->publish();

// Update analytics
$campaign->updateAnalytics([
    'recipients_count' => 150,
    'opened_count' => 45,
    'clicked_count' => 12
]);

// Get stats
$stats = $campaign->getStats();
echo "Open rate: " . $stats['open_rate'] . "%";
```

---

### 4. NewsletterRecipient

**File:** `features/newsletter/models/NewsletterRecipient.php`

Tracks delivery status of individual newsletter recipients.

**Properties:**
- `id` (int): Unique recipient record ID
- `campaign_id` (int): Associated campaign
- `subscriber_id` (int): Associated subscriber
- `status` (string): One of `pending`, `sent`, `delivered`, `bounced`, `failed`, `opened`, `clicked`
- `sent_at` (datetime|null): When email was sent
- `delivered_at` (datetime|null): When email was delivered

**Key Methods:**

```php
// Loading recipients
loadById(int $id): bool

// Recipient management
save(): bool                          // Insert or update
delete(): bool                        // Delete recipient (cascades to events)

// Status updates
updateStatus(string $status): bool    // Update status with appropriate timestamps
markAsSent(): bool
markAsDelivered(): bool
markAsOpened(): bool
markAsClicked(): bool
markAsBounced(): bool
markAsFailed(): bool

// Static utility methods
getByCampaign(mysqli $conn, int $campaignId, string|null $status = null, 
              int $limit = 100, int $offset = 0): array
countByCampaign(mysqli $conn, int $campaignId, string|null $status = null): int
getPending(mysqli $conn, int $limit = 100): array  // Get pending recipients ready to send
exists(mysqli $conn, int $campaignId, int $subscriberId): bool
```

**Usage Example:**
```php
$recipient = new NewsletterRecipient($conn);
$recipient->campaign_id = 3;
$recipient->subscriber_id = 15;
$recipient->status = 'pending';
$recipient->save();

// Update status as email is sent
$recipient->markAsSent();

// Update status when opened
$recipient->markAsOpened();

// Get all recipients for a campaign
$recipients = NewsletterRecipient::getByCampaign($conn, 3, 'delivered', 50, 0);

// Get pending recipients ready to send
$pending = NewsletterRecipient::getPending($conn, 100);
```

---

### 5. NewsletterEvent

**File:** `features/newsletter/models/NewsletterEvent.php`

Records granular user interaction events with newsletters.

**Properties:**
- `id` (int): Unique event record ID
- `recipient_id` (int): Associated recipient
- `event_type` (string): One of `open`, `click`, `bounce`, `spam_report`, `unsubscribe`, `delivery_failed`
- `metadata` (array): JSON object with event-specific data
- `timestamp` (datetime): When event occurred

**Key Methods:**

```php
// Loading events
loadById(int $id): bool

// Recording events (instance method)
record(): bool                        // Record a new event

// Static convenience methods for recording specific events
recordOpen(mysqli $conn, int $recipientId, array $metadata = []): bool
recordClick(mysqli $conn, int $recipientId, string $linkUrl, array $metadata = []): bool
recordBounce(mysqli $conn, int $recipientId, string $bounceType, 
             string $bounceReason, array $metadata = []): bool
recordSpamReport(mysqli $conn, int $recipientId, array $metadata = []): bool
recordUnsubscribe(mysqli $conn, int $recipientId, array $metadata = []): bool
recordDeliveryFailed(mysqli $conn, int $recipientId, string $failureReason, 
                     array $metadata = []): bool

// Querying events
getByRecipient(mysqli $conn, int $recipientId, string|null $eventType = null,
               int $limit = 100, int $offset = 0): array
getByCampaign(mysqli $conn, int $campaignId, string|null $eventType = null,
              int $limit = 1000): array
countByType(mysqli $conn, int $recipientId, string $eventType): int

// Cleanup
deleteOldEvents(mysqli $conn, int $daysOld = 90): int
```

**Metadata Structures by Event Type:**

```php
// Open event
['ip_address' => '', 'user_agent' => '']

// Click event
['link_url' => 'https://...', 'ip_address' => '', 'user_agent' => '']

// Bounce event
['bounce_type' => 'hard|soft', 'bounce_reason' => 'user_unknown', ...]

// Delivery failed event
['failure_reason' => 'Invalid email address', ...]
```

**Usage Example:**
```php
// Record an open event
NewsletterEvent::recordOpen($conn, 42, [
    'ip_address' => '192.168.1.1',
    'user_agent' => 'Mozilla/5.0...'
]);

// Record a click event
NewsletterEvent::recordClick($conn, 42, 'https://factalliance.org/funding', [
    'ip_address' => '192.168.1.1'
]);

// Record a bounce
NewsletterEvent::recordBounce($conn, 42, 'hard', 'user_unknown');

// Get all events for a recipient
$events = NewsletterEvent::getByRecipient($conn, 42, null, 100, 0);

// Get only click events for a campaign
$clicks = NewsletterEvent::getByCampaign($conn, 3, 'click');

// Clean up old events
NewsletterEvent::deleteOldEvents($conn, 90);  // Delete events older than 90 days
```

---

## Database Schema Requirements

The models expect these tables to exist:

```sql
-- Subscribers table
CREATE TABLE newsletter_subscribers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    email VARCHAR(255) NOT NULL UNIQUE,
    status ENUM('active', 'inactive', 'unsubscribed', 'bounced') DEFAULT 'active',
    subscribed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    unsubscribed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Preferences table
CREATE TABLE newsletter_preferences (
    id INT PRIMARY KEY AUTO_INCREMENT,
    subscriber_id INT NOT NULL UNIQUE,
    frequency ENUM('daily', 'weekly', 'monthly', 'none') DEFAULT 'weekly',
    categories JSON,
    geography JSON,
    interests JSON,
    research_roles JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (subscriber_id) REFERENCES newsletter_subscribers(id) ON DELETE CASCADE
);

-- Campaigns table
CREATE TABLE newsletter_campaigns (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    content_html LONGTEXT NOT NULL,
    status ENUM('draft', 'scheduled', 'sent', 'paused', 'cancelled') DEFAULT 'draft',
    sender_name VARCHAR(255) NOT NULL,
    sender_email VARCHAR(255) NOT NULL,
    scheduled_at TIMESTAMP NULL,
    sent_at TIMESTAMP NULL,
    recipients_count INT DEFAULT 0,
    opened_count INT DEFAULT 0,
    clicked_count INT DEFAULT 0,
    bounced_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Recipients table
CREATE TABLE newsletter_recipients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    campaign_id INT NOT NULL,
    subscriber_id INT NOT NULL,
    status ENUM('pending', 'sent', 'delivered', 'bounced', 'failed', 'opened', 'clicked') DEFAULT 'pending',
    sent_at TIMESTAMP NULL,
    delivered_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES newsletter_campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (subscriber_id) REFERENCES newsletter_subscribers(id) ON DELETE CASCADE,
    UNIQUE KEY unique_campaign_subscriber (campaign_id, subscriber_id)
);

-- Events table
CREATE TABLE newsletter_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    recipient_id INT NOT NULL,
    event_type ENUM('open', 'click', 'bounce', 'spam_report', 'unsubscribe', 'delivery_failed') NOT NULL,
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (recipient_id) REFERENCES newsletter_recipients(id) ON DELETE CASCADE,
    INDEX idx_recipient (recipient_id),
    INDEX idx_event_type (event_type),
    INDEX idx_created (created_at)
);
```

---

## Common Workflows

### Create a New Subscriber
```php
$subscriber = new NewsletterSubscriber($conn);
$subscriber->user_id = 123;
$subscriber->email = 'researcher@university.edu';
$subscriber->status = 'active';
if ($subscriber->save()) {
    // Create default preferences
    $pref = new NewsletterPreference($conn);
    $pref->subscriber_id = $subscriber->id;
    $pref->frequency = 'weekly';
    $pref->save();
}
```

### Send a Newsletter Campaign
```php
// Create campaign
$campaign = new NewsletterCampaign($conn);
$campaign->title = 'May Newsletter';
$campaign->content_html = '<h1>May Updates</h1>...';
$campaign->sender_name = 'FACT Alliance';
$campaign->sender_email = 'newsletter@factalliance.org';
$campaign->save();

// Get active subscribers
$subscribers = NewsletterSubscriber::getActive($conn, 1000, 0);

// Create recipients for each subscriber
foreach ($subscribers as $sub) {
    // Check preferences match
    $pref = (new NewsletterPreference($conn))->loadBySubscriberId($sub['id']);
    if ($pref && !$pref->isOptedOut()) {
        $recipient = new NewsletterRecipient($conn);
        $recipient->campaign_id = $campaign->id;
        $recipient->subscriber_id = $sub['id'];
        $recipient->status = 'pending';
        $recipient->save();
    }
}

// Queue email sending (external job processor)
// When emails are sent, update status:
$recipient->markAsSent();
$recipient->save();
```

### Track Email Events
```php
// Track open (triggered by pixel in HTML email)
NewsletterEvent::recordOpen($conn, $recipientId, [
    'ip_address' => $_SERVER['REMOTE_ADDR'],
    'user_agent' => $_SERVER['HTTP_USER_AGENT']
]);

// Track click (with redirect tracking)
NewsletterEvent::recordClick($conn, $recipientId, 
    'https://factalliance.org/funding-call-123', [
    'ip_address' => $_SERVER['REMOTE_ADDR']
]);

// Track bounce (from webhook)
NewsletterEvent::recordBounce($conn, $recipientId, 'hard', 'user_unknown');
```

### Get Campaign Performance
```php
$campaign = new NewsletterCampaign($conn);
$campaign->loadById(5);

$stats = $campaign->getStats();
echo "Sent: " . $stats['recipients_count'] . "\n";
echo "Opened: " . $stats['opened_count'] . " (" . $stats['open_rate'] . "%)\n";
echo "Clicked: " . $stats['clicked_count'] . " (" . $stats['click_rate'] . "%)\n";
echo "Bounced: " . $stats['bounced_count'] . " (" . $stats['bounce_rate'] . "%)\n";
```

---

## Error Handling & Logging

All models use error_log() for debugging:
- Each model logs operations to the PHP error log with a prefix like `[NewsletterSubscriber]`
- All database errors are logged before returning false
- Validation errors are logged with specific failure reasons

Monitor logs during development:
```bash
tail -f /Applications/XAMPP/logs/php_error_log
```

---

## Notes

1. **Email Validation**: NewsletterSubscriber validates email format using PHP's FILTER_VALIDATE_EMAIL
2. **Slug Generation**: NewsletterCampaign auto-generates URL-safe slugs from titles, ensuring uniqueness
3. **Prepared Statements**: All database queries use prepared statements for SQL injection prevention
4. **Cascading Deletes**: Deleting a subscriber cascades to preferences and recipients; deleting a campaign cascades to recipients and events
5. **JSON Storage**: Preferences uses JSON arrays for flexibility in storing multiple values
6. **Automatic Timestamps**: All timestamps are managed by MySQL (CURRENT_TIMESTAMP)
7. **Status Transitions**: Status transitions are validated (e.g., can only publish draft or paused campaigns)

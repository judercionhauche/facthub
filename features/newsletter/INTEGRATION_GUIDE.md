# Newsletter Integration Guide

## Overview

The `features/newsletter/integration.php` module provides clean integration between the newsletter subsystem and the core FACT Hub infrastructure. It manages:

1. **User Lifecycle Hooks** - Registration, profile updates, deletion
2. **Background Job Processing** - Newsletter sending via job queue
3. **Audit Logging** - Compliance trail for all admin actions
4. **Rate Limiting** - Abuse prevention for test sends
5. **Feature Flags** - Enable/disable via environment variables
6. **API Boundaries** - Clear separation of concerns

## Configuration

### Environment Variables

Add to `.env`:

```bash
# Enable/disable newsletter feature (default: false)
NEWSLETTER_ENABLED=true

# Email provider (aws_ses or sendgrid)
NEWSLETTER_EMAIL_PROVIDER=aws_ses
NEWSLETTER_FROM_EMAIL=noreply@facthub.org
NEWSLETTER_FROM_NAME=FACT Hub

# AWS SES configuration (if using AWS)
AWS_REGION=us-east-1
AWS_ACCESS_KEY_ID=your_key
AWS_SECRET_ACCESS_KEY=your_secret

# SendGrid configuration (if using SendGrid)
SENDGRID_API_KEY=your_key

# Rate limiting for test sends (per admin per day)
NEWSLETTER_TEST_SEND_LIMIT=10
```

## Integration Points

### 1. App Initialization (`public/index.php`)

Already integrated. After database connection:

```php
// Apply schema updates safely
try {
    apply_security_schema_updates($conn);
    apply_newsletter_schema($conn);
} catch (Throwable $e) {
    error_log('[Schema Updates Error] ' . $e->getMessage());
}

// Initialize newsletter integration
require_once __DIR__ . '/../features/newsletter/integration.php';
NewsletterIntegration::initialize($conn);
```

**Note**: `apply_newsletter_schema()` is already called from `public/index.php` at line 49.

### 2. User Registration Hook

**Location**: `app/views/researchers/index.php` (and `funders/index.php`)

After user creation in registration flow:

```php
// After INSERT INTO users and email verification
$userId = $conn->insert_id;

// Hook: Auto-subscribe new user to newsletter
require_once __DIR__ . '/../../features/newsletter/integration.php';
NewsletterIntegration::onUserRegistered(
    $conn,
    $userId,
    $email,
    [
        'role' => 'researcher',
        'first_name' => $first,
        'last_name' => $last,
        'interests' => $interests_array,  // from profile
        'geography' => $geography_array,  // from profile
        'topics' => $topics_array,        // from profile
        'institution' => $institution,
        'orcid' => $orcid
    ]
);
```

**Behavior**:
- Creates `newsletter_subscribers` record with status='active'
- Creates `newsletter_preferences` record with defaults from profile
- Logs audit event 'newsletter_auto_subscribe'
- Returns true on success (non-fatal if fails)

### 3. Profile Update Hook

**Location**: `app/views/profile/index.php` (when user updates profile)

After profile data is saved:

```php
// After UPDATE users/researchers with new profile data
require_once __DIR__ . '/../../features/newsletter/integration.php';
NewsletterIntegration::onUserProfileUpdated(
    $conn,
    $userId,
    [
        'interests' => $new_interests_array,
        'geography' => $new_geography_array,
        'topics' => $new_topics_array,
        'institution' => $new_institution,
        'orcid' => $new_orcid,
        'frequency' => 'weekly'  // optional - update email frequency
    ]
);
```

**Behavior**:
- Gets or creates subscriber if missing
- Syncs preferences from profile data
- Logs audit event 'newsletter_profile_sync'
- Returns true on success (non-fatal if fails)

### 4. User Deletion Hook

**Location**: `app/views/admin/index.php` (user deletion flow)

When admin soft-deletes user:

```php
// After soft-delete from users table
require_once __DIR__ . '/../../features/newsletter/integration.php';
NewsletterIntegration::onUserDeleted(
    $conn,
    $userId,
    $user_email,
    'Admin deletion - GDPR compliance'  // optional reason
);
```

**Behavior**:
- Marks subscriber as 'unsubscribed' (soft-delete)
- Preserves all history for compliance
- Logs audit event 'newsletter_user_deleted'
- Non-fatal if no subscriber record exists

### 5. Background Job Processing

**Location**: `app/jobs/worker.php`

The worker already has structure to handle jobs. Add newsletter handling:

```php
// In worker.php job type handler section, add:
case 'newsletter_send':
    require_once __DIR__ . '/../../features/newsletter/integration.php';
    $success = NewsletterIntegration::processNewsletterSendJob($conn, $jobId, $jobData);
    if ($success) {
        mark_job_completed($conn, $jobId);
    } else {
        mark_job_failed($conn, $jobId, 'Newsletter send failed');
    }
    break;
```

**Job Structure**:

```json
{
  "job_type": "newsletter_send",
  "job_data": {
    "campaign_id": 123,
    "batch_size": 50,
    "retry_delay": 300
  }
}
```

**Processing**:
- Loads `SendingService` from newsletter module
- Processes campaign send in batches
- Updates recipient statuses
- Retries on failure (up to max_attempts)

### 6. Campaign Service Integration

**Location**: `features/newsletter/services/CampaignService.php`

When campaign is scheduled to send:

```php
// In CampaignService::scheduleForSending() or ::startSending()
require_once __DIR__ . '/../integration.php';

// Queue background job for sending
$jobId = NewsletterIntegration::queueNewsletterSend(
    $conn,
    $campaignId,
    $delaySeconds  // optional delay
);

// Log audit event
NewsletterIntegration::recordCampaignAction(
    $conn,
    $adminEmail,
    $campaignId,
    'schedule',
    'Campaign scheduled for sending',
    ['scheduled_at' => $scheduledTime]
);
```

**Job Queue**:
- Creates `job_queue` entry with type='newsletter_send'
- Worker picks up and processes in background
- Can be delayed with `run_after` parameter
- Includes retry logic with max_attempts

## API Reference

### Public Methods

#### `initialize(mysqli $conn): bool`
Initialize newsletter integration on app startup. Safe to call multiple times.

#### `onUserRegistered(mysqli $conn, int $userId, string $email, array $profile = []): bool`
Hook called when user registers. Auto-subscribes with defaults from profile.

**Parameters**:
- `$userId`: New user ID
- `$email`: User email
- `$profile`: Optional profile data (role, interests, geography, topics, institution, orcid)

**Returns**: true on success or disabled

#### `onUserProfileUpdated(mysqli $conn, int $userId, array $profileData = []): bool`
Hook called when user updates profile. Syncs preferences.

**Parameters**:
- `$userId`: User ID
- `$profileData`: Updated fields (interests, geography, topics, frequency, etc.)

**Returns**: true on success or disabled

#### `onUserDeleted(mysqli $conn, int $userId, string $email, string $reason = ''): bool`
Hook called when user is soft-deleted. Unsubscribes subscriber.

**Parameters**:
- `$userId`: User ID being deleted
- `$email`: User email
- `$reason`: Optional deletion reason

**Returns**: true on success or disabled

#### `processNewsletterSendJob(mysqli $conn, int $jobId, array $jobData): bool`
Process newsletter_send background job. Called from worker.php.

**Parameters**:
- `$jobId`: Job queue ID
- `$jobData`: Decoded job_data JSON (campaign_id, batch_size, etc.)

**Returns**: true on success, false on failure

#### `queueNewsletterSend(mysqli $conn, int $campaignId, int $delaySeconds = 0): ?int`
Queue a newsletter send job for background processing.

**Parameters**:
- `$campaignId`: Campaign to send
- `$delaySeconds`: Optional delay before processing

**Returns**: Job ID or null on failure

#### `logAuditEvent(mysqli $conn, string $targetEmail, string $action, string $description, string $actorType = 'admin', array $metadata = []): bool`
Log action to audit_log table for compliance.

**Parameters**:
- `$targetEmail`: Target email or 'system'
- `$action`: Action type (e.g., 'newsletter_create_campaign')
- `$description`: Human-readable description
- `$actorType`: 'admin' or 'system'
- `$metadata`: Optional metadata array

**Returns**: true on success (false if table doesn't exist)

#### `checkTestSendRateLimit(mysqli $conn, string $adminEmail): bool`
Check if admin is within rate limit for test sends.

**Returns**: true if under limit, false if rate limited

#### `recordTestSend(mysqli $conn, string $adminEmail, int $campaignId, array $testEmails): bool`
Record test send for rate limiting and audit trail.

#### `recordCampaignAction(mysqli $conn, string $adminEmail, int $campaignId, string $actionType, string $description, array $details = []): bool`
Record campaign action (create, send, pause) to audit log.

#### `isEnabled(): bool`
Check if newsletter feature is enabled via NEWSLETTER_ENABLED env var.

#### `getEmailProvider(): array`
Get email provider configuration.

#### `getTestSendRateLimit(): int`
Get test send rate limit (per admin per day).

### Utility Methods

#### `getSubscriberCount(mysqli $conn): int`
Get total subscriber count.

#### `getActiveSubscriberCount(mysqli $conn): int`
Get active (subscribed) subscriber count.

#### `getPendingCampaignCount(mysqli $conn): int`
Get pending (draft/scheduled) campaign count.

#### `isSubscriber(mysqli $conn, int $userId): bool`
Check if user is an active newsletter subscriber.

#### `getSubscriber(mysqli $conn, int $userId): ?array`
Get subscriber record by user ID.

**Returns**: Array with id, email, status or null

#### `getSubscriberPreferences(mysqli $conn, int $subscriberId): ?array`
Get subscriber preferences with decoded JSON fields.

**Returns**: Array with id, frequency, categories, geography, interests, research_roles or null

## Error Handling

All integration methods follow defensive patterns:

1. **Graceful Degradation** - If newsletter fails, core app operation completes
2. **Logging** - All errors logged to error_log with [NewsletterIntegration] prefix
3. **Feature Flag** - All hooks check `isEnabled()` and return early if disabled
4. **Null Safety** - Methods return sensible defaults (null, false, 0) on error

Example:

```php
// Even if newsletter fails, user registration completes
$success = NewsletterIntegration::onUserRegistered($conn, $userId, $email, $profile);
if (!$success) {
    error_log("Warning: Newsletter subscription failed for user $userId");
    // Continue anyway - registration is complete
}
```

## Audit Logging

All significant actions are logged to `audit_log` table:

### Event Types

- `newsletter_auto_subscribe` - New user auto-subscribed
- `newsletter_profile_sync` - Preferences synced with profile update
- `newsletter_user_deleted` - Subscriber unsubscribed due to user deletion
- `newsletter_create_campaign` - Admin created campaign
- `newsletter_send` - Campaign sent
- `newsletter_pause` - Campaign paused
- `newsletter_test_send` - Test email sent
- `newsletter_test_send` - Admin sent test email (rate limited)

### Example Audit Query

```sql
-- Get all newsletter actions for a subscriber
SELECT * FROM audit_log
WHERE action LIKE 'newsletter_%'
AND target_email = 'user@example.com'
ORDER BY created_at DESC;

-- Get admin actions (campaigns created, sent, etc.)
SELECT * FROM audit_log
WHERE action LIKE 'newsletter_%'
AND actor_email = 'admin@example.com'
ORDER BY created_at DESC;
```

## Rate Limiting

Test send rate limit prevents admin abuse:

```php
// In admin sending test email
if (!NewsletterIntegration::checkTestSendRateLimit($conn, $adminEmail)) {
    set_flash('error', 'You have exceeded the test send limit (10 per day)');
    return;
}

// Process test send
// ...

// Record for rate limiting
NewsletterIntegration::recordTestSend($conn, $adminEmail, $campaignId, ['test@example.com']);
```

## Testing

### Test User Registration Hook

```php
// Simulate user registration
$testEmail = 'test' . time() . '@example.com';
$testPassword = password_hash('testpass123', PASSWORD_DEFAULT);
$testName = 'Test User';
$testRole = 'researcher';
$testStatus = 'unverified';

$stmt = $conn->prepare('INSERT INTO users (email, password, name, role, status) VALUES (?, ?, ?, ?, ?)');
$stmt->bind_param('sssss', $testEmail, $testPassword, $testName, $testRole, $testStatus);
$stmt->execute();
$userId = $conn->insert_id;
$stmt->close();

// Test hook
require_once 'features/newsletter/integration.php';
$result = NewsletterIntegration::onUserRegistered($conn, $userId, $testEmail, [
    'role' => 'researcher',
    'interests' => ['climate', 'energy']
]);

echo $result ? 'SUCCESS' : 'FAILED';

// Verify subscriber created
$checkStmt = $conn->prepare('SELECT id FROM newsletter_subscribers WHERE user_id = ?');
$checkStmt->bind_param('i', $userId);
$checkStmt->execute();
$subscriber = $checkStmt->get_result()->fetch_assoc();
echo $subscriber ? 'Subscriber created: ' . $subscriber['id'] : 'No subscriber found';
```

### Test Profile Update Hook

```php
// Update profile
$newInterests = ['sustainability', 'policy'];
$result = NewsletterIntegration::onUserProfileUpdated($conn, $userId, [
    'interests' => $newInterests
]);

// Verify preferences updated
$prefs = NewsletterIntegration::getSubscriberPreferences($conn, $subscriber['id']);
echo json_encode($prefs['interests']);  // Should contain new interests
```

### Test Deletion Hook

```php
// Delete user
$result = NewsletterIntegration::onUserDeleted($conn, $userId, $testEmail, 'Test deletion');

// Verify unsubscribed
$sub = NewsletterIntegration::getSubscriber($conn, $userId);
echo $sub['status'] === 'unsubscribed' ? 'SUCCESS' : 'FAILED';
```

## Troubleshooting

### Newsletter Not Auto-Subscribing

1. Check `NEWSLETTER_ENABLED` is set to true
2. Verify `newsletter_subscribers` table exists
3. Check error_log for `[NewsletterIntegration]` entries
4. Verify hook called with correct user ID and email

### Rate Limit Not Working

1. Verify `audit_log` table exists
2. Check `NEWSLETTER_TEST_SEND_LIMIT` env var
3. Ensure audit entries are being created

### Background Jobs Not Processing

1. Verify `job_queue` table has entries with type='newsletter_send'
2. Check worker.php is running
3. Verify SendingService class is loading
4. Check error_log for job processing errors

## Future Enhancements

- [ ] Webhook support for email provider events (bounces, complaints)
- [ ] Subscriber segmentation API
- [ ] A/B testing support
- [ ] Dynamic content personalization
- [ ] Advanced analytics dashboards
- [ ] GDPR compliance reports

## References

- [NewsletterSubscriber Model](models/NewsletterSubscriber.php)
- [SubscriptionService](services/SubscriptionService.php)
- [CampaignService](services/CampaignService.php)
- [SendingService](services/SendingService.php)
- [AnalyticsService](services/AnalyticsService.php)

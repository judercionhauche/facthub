# Newsletter Module Integration

**Status**: Ready for integration  
**Version**: 1.0  
**Updated**: June 4, 2026  
**Location**: `/features/newsletter/`

## Quick Start

### 1. Enable Newsletter Feature

Add to `.env`:
```bash
NEWSLETTER_ENABLED=true
NEWSLETTER_EMAIL_PROVIDER=aws_ses
NEWSLETTER_FROM_EMAIL=noreply@facthub.org
NEWSLETTER_FROM_NAME=FACT Hub
```

### 2. Verify Schema

The schema is auto-applied on app startup via `apply_newsletter_schema()` called from `public/index.php`.

To manually verify:
```sql
SHOW TABLES LIKE 'newsletter_%';
-- Should show 7 tables: subscribers, preferences, campaigns, recipients, events, clicks, unsubscribe_tokens
```

### 3. Add Integration Hooks

**User Registration** (app/views/researchers/index.php, after user creation):
```php
NewsletterIntegration::onUserRegistered($conn, $userId, $email, [
    'role' => 'researcher',
    'interests' => $interests,
    'geography' => $geography
]);
```

**Profile Update** (app/views/profile/index.php, after profile save):
```php
NewsletterIntegration::onUserProfileUpdated($conn, $userId, [
    'interests' => $new_interests,
    'geography' => $new_geography
]);
```

**User Deletion** (app/views/admin/index.php, on soft-delete):
```php
NewsletterIntegration::onUserDeleted($conn, $userId, $email);
```

**Background Jobs** (app/jobs/worker.php, in job handler):
```php
case 'newsletter_send':
    NewsletterIntegration::processNewsletterSendJob($conn, $jobId, $jobData);
    break;
```

## Key Files

| File | Purpose |
|------|---------|
| `integration.php` | Main facade - all core app interactions go here (1,000 LOC) |
| `ARCHITECTURE.md` | System design, component breakdown, data flow |
| `INTEGRATION_GUIDE.md` | Detailed setup guide for each integration point |
| `IMPLEMENTATION_CHECKLIST.md` | Step-by-step verification checklist |
| `models/` | NewsletterSubscriber, Campaign, Preference, Recipient, Event |
| `services/` | SubscriptionService, CampaignService, SendingService, AnalyticsService |

## Features

### What's Included

✓ **User Lifecycle Management**
- Auto-subscribe on registration with defaults from profile
- Sync preferences on profile updates
- Soft-delete on user deletion (preserve history)

✓ **Campaign Management**
- Admin interface to create/edit/send campaigns
- Schedule campaigns for future delivery
- Campaign status tracking (draft, scheduled, sending, sent)

✓ **Batch Processing**
- Background job queue for sending (newsletter_send job type)
- Batch processing (configurable batch size, default 50)
- Automatic retries with exponential backoff (max 5 attempts)

✓ **Analytics & Tracking**
- Event tracking (sent, delivered, opened, clicked, bounced)
- Click tracking with link aggregation
- Campaign performance metrics

✓ **Compliance & Audit**
- Audit trail for all admin actions
- User privacy via soft-delete
- One-click unsubscribe mechanism
- GDPR-ready (can export/delete subscriber data)

✓ **Security & Limits**
- Feature flag control (NEWSLETTER_ENABLED env var)
- Rate limiting on test sends (default 10/admin/day)
- Email provider credential isolation
- SQL injection prevention (all queries parameterized)

### What's Not Included (v1)

✗ Email provider webhook integration (phase 2)
✗ Advanced segmentation (phase 2)
✗ A/B testing (phase 3)
✗ Personalization (phase 3)
✗ Advanced analytics dashboard (phase 3)

## Architecture

```
NewsletterIntegration (Facade)
├── onUserRegistered()          → auto-subscribe with defaults
├── onUserProfileUpdated()      → sync preferences
├── onUserDeleted()             → soft-delete (preserve history)
├── processNewsletterSendJob()  → background job handler
├── queueNewsletterSend()       → queue job for sending
├── logAuditEvent()             → compliance trail
└── Utility Methods             → getSubscriber(), isSubscriber(), etc.
    │
    ├─ SubscriptionService      → subscribe, unsubscribe, manage preferences
    ├─ CampaignService          → create, schedule, manage campaigns
    ├─ SendingService           → batch send, handle failures
    ├─ AnalyticsService         → track events, engagement
    ├─ NewsletterModels         → database entities
    └─ job_queue + audit_log    → background processing + compliance
```

## Configuration

### Environment Variables

```bash
# Feature Control
NEWSLETTER_ENABLED=true|false                # Master on/off (default: false)

# Email Provider
NEWSLETTER_EMAIL_PROVIDER=aws_ses|sendgrid   # Email provider (default: aws_ses)
NEWSLETTER_FROM_EMAIL=noreply@facthub.org    # Sender email
NEWSLETTER_FROM_NAME=FACT Hub                # Sender name

# AWS SES (if NEWSLETTER_EMAIL_PROVIDER=aws_ses)
AWS_REGION=us-east-1
AWS_ACCESS_KEY_ID=<your_key>
AWS_SECRET_ACCESS_KEY=<your_secret>

# SendGrid (if NEWSLETTER_EMAIL_PROVIDER=sendgrid)
SENDGRID_API_KEY=<your_key>

# Rate Limiting
NEWSLETTER_TEST_SEND_LIMIT=10                # Test sends per admin per day
```

## Database Schema

### Tables Created (auto-applied on startup)

1. **newsletter_subscribers** - Who is subscribed
   - Columns: id, user_id, email, status, subscribed_at, unsubscribed_at
   - Status: active, inactive, unsubscribed
   - Foreign keys: user_id → users.id

2. **newsletter_preferences** - What they want
   - Columns: id, subscriber_id, frequency, categories_json, geography_json, interests_json
   - Frequency: immediate, daily, weekly, never
   - JSON arrays for flexible filtering

3. **newsletter_campaigns** - What we're sending
   - Columns: id, title, slug, content_html, status, sender_*, analytics_json
   - Status: draft, scheduled, sending, sent, paused
   - Foreign keys: created_by_user_id → users.id

4. **newsletter_recipients** - Delivery status
   - Columns: id, campaign_id, subscriber_id, status, sent_at, delivered_at, bounce_reason
   - Status: queued, sending, sent, delivered, bounced, failed
   - Unique: (campaign_id, subscriber_id)

5. **newsletter_events** - Engagement tracking
   - Columns: id, recipient_id, event_type, metadata_json, timestamp
   - Event types: sent, delivered, opened, clicked, bounced, complained

6. **newsletter_clicks** - Aggregated click tracking
   - Columns: id, campaign_id, subscriber_id, link_url, click_count
   - Aggregated per (campaign, subscriber, link)

7. **newsletter_unsubscribe_tokens** - One-click unsubscribe
   - Columns: id, subscriber_id, token
   - Tokens: 64-char random, URL-safe

## API Reference

### Core Integration Methods

```php
// Initialize on app startup
NewsletterIntegration::initialize($conn): bool

// Lifecycle hooks
NewsletterIntegration::onUserRegistered($conn, $userId, $email, $profile): bool
NewsletterIntegration::onUserProfileUpdated($conn, $userId, $profileData): bool
NewsletterIntegration::onUserDeleted($conn, $userId, $email, $reason): bool

// Background jobs
NewsletterIntegration::processNewsletterSendJob($conn, $jobId, $jobData): bool
NewsletterIntegration::queueNewsletterSend($conn, $campaignId, $delaySeconds): ?int

// Audit & Rate Limiting
NewsletterIntegration::logAuditEvent($conn, $email, $action, $description, $actorType, $metadata): bool
NewsletterIntegration::checkTestSendRateLimit($conn, $adminEmail): bool
NewsletterIntegration::recordTestSend($conn, $adminEmail, $campaignId, $testEmails): bool

// Configuration
NewsletterIntegration::isEnabled(): bool
NewsletterIntegration::getEmailProvider(): array
NewsletterIntegration::getTestSendRateLimit(): int

// Utilities
NewsletterIntegration::getSubscriberCount($conn): int
NewsletterIntegration::getActiveSubscriberCount($conn): int
NewsletterIntegration::isSubscriber($conn, $userId): bool
NewsletterIntegration::getSubscriber($conn, $userId): ?array
NewsletterIntegration::getSubscriberPreferences($conn, $subscriberId): ?array
```

## Error Handling

All integration methods are defensive:

1. **Early Exit**: Return immediately if `NEWSLETTER_ENABLED=false`
2. **Exception Handling**: Catch all exceptions, log with `[NewsletterIntegration]` prefix
3. **Non-Fatal**: Return true/false but don't throw - caller continues
4. **Logging**: All errors logged to PHP error_log
5. **Graceful Degradation**: Core app works even if newsletter fails

```php
// Example: Registration continues even if newsletter fails
try {
    // ... create user ...
    NewsletterIntegration::onUserRegistered($conn, $userId, $email, $profile);
    // Subscription succeeded or was no-op (disabled)
} catch (Throwable $e) {
    // Should not happen due to error handling, but safe if it does
    error_log("Registration warning: " . $e->getMessage());
}
```

## Testing

### Quick Test

```php
// Test 1: Feature flag
echo getenv('NEWSLETTER_ENABLED') ? 'Enabled' : 'Disabled';

// Test 2: Schema exists
$result = $conn->query("SELECT 1 FROM newsletter_subscribers LIMIT 1");
echo $result !== false ? 'Schema OK' : 'Schema missing';

// Test 3: Integration function
$result = NewsletterIntegration::isEnabled();
echo $result ? 'Integration ready' : 'Integration disabled';

// Test 4: Register test user
$userId = /* ... create test user ... */;
$result = NewsletterIntegration::onUserRegistered($conn, $userId, 'test@example.com', []);
echo $result ? 'Hook succeeded' : 'Hook failed';

// Test 5: Verify subscriber created
$sub = NewsletterIntegration::getSubscriber($conn, $userId);
echo $sub ? 'Subscriber: ' . $sub['id'] : 'No subscriber';
```

### Full Integration Test

See [IMPLEMENTATION_CHECKLIST.md](IMPLEMENTATION_CHECKLIST.md) for comprehensive test plan.

## Monitoring

### Key Metrics

```sql
-- Subscriber growth
SELECT COUNT(*) as active_subscribers FROM newsletter_subscribers WHERE status = 'active';

-- Pending campaigns
SELECT COUNT(*) as pending_campaigns FROM newsletter_campaigns WHERE status IN ('draft', 'scheduled');

-- Jobs in queue
SELECT COUNT(*) as pending_jobs FROM job_queue WHERE status IN ('pending', 'processing');

-- Failed jobs
SELECT COUNT(*) as failed_jobs FROM job_queue WHERE status = 'failed';

-- Audit trail volume
SELECT COUNT(*) as total_actions FROM audit_log WHERE action LIKE 'newsletter_%' AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY);
```

### Alert Triggers

- [ ] Jobs failing repeatedly (job_queue.status='failed' count > 10)
- [ ] Subscriber unsubscribe spike (rate > normal baseline)
- [ ] Rate limit violations (audit_log newsletter_test_send > 10 per admin per day)
- [ ] Long processing time (job run time > 5 minutes)

## Troubleshooting

### Subscribers not created on registration

```
1. Check NEWSLETTER_ENABLED=true in .env
2. Verify newsletter_subscribers table exists: SHOW TABLES LIKE 'newsletter_%'
3. Check error_log for [NewsletterIntegration] errors
4. Verify hook called in registration code
5. Manually test: NewsletterIntegration::isEnabled() should return true
```

### Profile updates not syncing preferences

```
1. Verify hook called in profile update code
2. Check newsletter_preferences table exists
3. Verify subscriber exists: SELECT * FROM newsletter_subscribers WHERE user_id = X
4. Manually test update_preferences
5. Check error_log
```

### Background jobs not processing

```
1. Verify job_queue table exists
2. Check job entries: SELECT * FROM job_queue WHERE job_type='newsletter_send'
3. Verify worker.php is running
4. Check SendingService class loads: require_once features/newsletter/services/SendingService.php
5. Run worker manually and check output
6. Check error_log for job processing errors
```

### Rate limit not enforcing

```
1. Verify audit_log table exists
2. Check rate limit setting: NEWSLETTER_TEST_SEND_LIMIT in .env
3. Verify audit entries created: SELECT * FROM audit_log WHERE action='newsletter_test_send'
4. Manually check: NewsletterIntegration::checkTestSendRateLimit($conn, $email)
```

## Documentation

- **[ARCHITECTURE.md](ARCHITECTURE.md)** - System design, components, data flow
- **[INTEGRATION_GUIDE.md](INTEGRATION_GUIDE.md)** - Detailed how-to for each integration point
- **[IMPLEMENTATION_CHECKLIST.md](IMPLEMENTATION_CHECKLIST.md)** - Step-by-step verification
- **[integration.php](integration.php)** - Well-commented source code (1,000 LOC)

## Support

For questions about:
- **System Design**: See [ARCHITECTURE.md](ARCHITECTURE.md)
- **Implementation**: See [INTEGRATION_GUIDE.md](INTEGRATION_GUIDE.md)
- **Verification**: See [IMPLEMENTATION_CHECKLIST.md](IMPLEMENTATION_CHECKLIST.md)
- **Code Details**: See [integration.php](integration.php) comments
- **Data Model**: See `models/` directory
- **Services**: See `services/` directory

## Version History

- **v1.0** (June 4, 2026) - Initial integration module
  - User lifecycle hooks (register, profile, delete)
  - Background job processing
  - Audit logging
  - Rate limiting
  - Feature flag control

# Newsletter Integration Architecture

## System Overview

The newsletter module is a completely decoupled subsystem that integrates with FACT Hub core infrastructure through well-defined integration points (hooks). This architecture provides:

- **Isolation**: Newsletter failure doesn't break user registration or profile updates
- **Clarity**: All integration points are explicit and documented
- **Maintainability**: Newsletter code changes don't require core app changes
- **Testability**: Each integration point can be tested independently
- **Scalability**: Newsletter processing happens asynchronously via job queue

```
┌─────────────────────────────────────────────────────────────────┐
│                      FACT Hub Core App                           │
│  (public/index.php, app/views/*, app/core/*, app/jobs/worker.php)
└────────────────┬────────────────────────────────────────────────┘
                 │
                 │ Calls Integration Hooks
                 │
                 ▼
┌─────────────────────────────────────────────────────────────────┐
│           NewsletterIntegration (integration.php)               │
│  Facade that manages all integration points with core app       │
└────┬────────────────────────────────────────────────────────────┘
     │
     │ Uses
     │
     ├─ SubscriptionService (auto-subscribe, update preferences)
     ├─ CampaignService (manage campaigns, queue sending)
     ├─ SendingService (process batch sends, update status)
     ├─ AnalyticsService (track opens, clicks, bounces)
     ├─ NewsletterModels (database entities)
     └─ audit_log (compliance tracking)
     │
     └─ job_queue (background processing)
```

## Component Breakdown

### 1. Core App Integration Points

#### User Lifecycle Hooks

**Registration** (`app/views/researchers/index.php`)
```
User Registration Flow
  └─ INSERT INTO users
  └─ INSERT INTO email_verifications
  └─ CALL NewsletterIntegration::onUserRegistered()
       └─ Auto-create newsletter_subscribers
       └─ Auto-create newsletter_preferences (with defaults)
       └─ Log audit event
```

**Profile Update** (`app/views/profile/index.php`)
```
Profile Update Flow
  └─ UPDATE users / UPDATE researchers
  └─ CALL NewsletterIntegration::onUserProfileUpdated()
       └─ Get or create subscriber
       └─ Sync preferences from profile
       └─ Log audit event
```

**Deletion** (`app/views/admin/index.php`)
```
User Soft-Delete Flow
  └─ UPDATE users SET status='deleted'
  └─ CALL NewsletterIntegration::onUserDeleted()
       └─ UPDATE newsletter_subscribers SET status='unsubscribed'
       └─ Preserve history (unsubscribed_at, etc.)
       └─ Log audit event
```

#### Database Initialization

**App Startup** (`public/index.php`)
```
App Initialization
  └─ CALL apply_newsletter_schema($conn)
       └─ CREATE TABLE newsletter_subscribers
       └─ CREATE TABLE newsletter_preferences
       └─ CREATE TABLE newsletter_campaigns
       └─ ... (other newsletter tables)
       └─ All tables have proper indexes
  └─ CALL NewsletterIntegration::initialize($conn)
       └─ Load newsletter models/services
       └─ Verify configuration
```

### 2. Newsletter Module Architecture

```
features/newsletter/
├── integration.php                 # Main integration facade (1,000 LOC)
├── INTEGRATION_GUIDE.md           # How to integrate with core app
├── IMPLEMENTATION_CHECKLIST.md    # Step-by-step setup guide
├── ARCHITECTURE.md                # This file
│
├── models/
│   ├── NewsletterSubscriber.php   # Subscriber entity
│   ├── NewsletterPreference.php   # Preference entity
│   ├── NewsletterCampaign.php     # Campaign entity
│   ├── NewsletterRecipient.php    # Recipient entity (per campaign)
│   └── NewsletterEvent.php        # Event tracking entity
│
└── services/
    ├── SubscriptionService.php    # Subscribe, unsubscribe, manage prefs
    ├── CampaignService.php        # Create, schedule, manage campaigns
    ├── SendingService.php         # Process batch sends, handle bounces
    └── AnalyticsService.php       # Track opens, clicks, conversions
```

### 3. Background Job Processing

**Job Queue Integration** (`app/jobs/worker.php`)

```
Worker Main Loop
  └─ REPEAT FOREVER
       └─ Query job_queue WHERE status='pending' LIMIT 1
       └─ Check job type
            │
            ├─ job_type='newsletter_send'
            │   └─ CALL NewsletterIntegration::processNewsletterSendJob()
            │        └─ Load SendingService
            │        └─ Process campaign recipients in batches
            │        └─ Update recipient.status (sent, delivered, bounced, failed)
            │        └─ UPDATE job_queue SET status='completed'
            │
            └─ ... (other job types)
```

**Job Structure**

```json
{
  "id": 123,
  "job_type": "newsletter_send",
  "job_data": {
    "campaign_id": 42,
    "batch_size": 50,
    "retry_delay": 300
  },
  "status": "pending",
  "created_at": "2026-06-04 10:00:00",
  "run_after": "2026-06-04 10:30:00",
  "locked_at": null,
  "locked_by": null,
  "attempts": 0,
  "max_attempts": 5,
  "last_error": null
}
```

### 4. Database Schema

#### Core Tables (auto-created on startup)

**newsletter_subscribers**
- Tracks all people subscribed to newsletter
- Links to users via user_id FK
- Status: active, inactive, unsubscribed
- Soft-delete via status + unsubscribed_at

**newsletter_preferences**
- User's email frequency and interest filters
- Stores categories, geography, topics as JSON arrays
- One record per subscriber (UNIQUE KEY)

**newsletter_campaigns**
- Email campaigns created by admins
- Status: draft, scheduled, sending, sent, paused
- Stores HTML content and metadata

**newsletter_recipients**
- Tracks delivery status per campaign per subscriber
- Status: queued, sending, sent, delivered, bounced, failed
- Links campaign to subscribers with 1:N relationship

**newsletter_events**
- Tracks opens, clicks, bounces from email provider webhooks
- Enables analytics and engagement tracking
- Stores metadata (IP, user agent, link URL)

**newsletter_clicks**
- Aggregated click tracking (deduplicated by URL)
- Enables link-level analytics

**newsletter_unsubscribe_tokens**
- One-click unsubscribe tokens
- Prevents database lookups on unsubscribe links

#### Integration Points

**users table**
- Extended with deletion fields (soft-delete)
- Linked to newsletter_subscribers via user_id FK

**job_queue table**
- Extended with newsletter_send job type
- Enables async batch processing

**audit_log table**
- Logs all newsletter admin actions
- Logs auto-subscription on registration
- Logs profile syncs
- Logs deletion handling

## Feature Flags & Configuration

### Environment Variables

```bash
# Feature control
NEWSLETTER_ENABLED=true|false        # Master on/off switch

# Email provider
NEWSLETTER_EMAIL_PROVIDER=aws_ses|sendgrid
NEWSLETTER_FROM_EMAIL=noreply@facthub.org
NEWSLETTER_FROM_NAME=FACT Hub

# AWS SES (if using)
AWS_REGION=us-east-1
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...

# SendGrid (if using)
SENDGRID_API_KEY=...

# Rate limiting
NEWSLETTER_TEST_SEND_LIMIT=10        # Per admin per day
```

### Runtime Behavior

When `NEWSLETTER_ENABLED=false`:
- All hooks return true immediately (no-op)
- No subscriber records created
- No preferences updated
- No audit events logged
- Core app unaffected

When `NEWSLETTER_ENABLED=true`:
- All hooks fully functional
- Auto-subscription on registration
- Preferences auto-synced on profile updates
- Rate limiting enforced
- All actions audited

## Error Handling & Resilience

### Graceful Degradation

```
User Registration
  ├─ Success: User created ✓
  └─ Newsletter Hook Called
       ├─ Success: Subscribed ✓
       ├─ Disabled: Skipped (NEWSLETTER_ENABLED=false) ✓
       └─ Error: Logged, user registration still succeeds ✓
```

The core app never depends on newsletter success. All hooks:
1. Return early if `NEWSLETTER_ENABLED=false`
2. Catch all exceptions and log
3. Return true/false but don't throw
4. Are called after critical core operations complete

### Database Resilience

- All schema creation is idempotent (`CREATE TABLE IF NOT EXISTS`)
- Column additions check existence first (`SELECT ... FROM INFORMATION_SCHEMA`)
- Foreign keys are soft (NULL allowed on FK columns)
- Duplicate key handling with `INSERT ... ON DUPLICATE KEY UPDATE`

### Service Resilience

- SendingService batches in small chunks (default 50)
- Failed emails don't stop processing
- Exponential backoff via job retry mechanism
- Email provider errors captured and logged
- Bounces handled gracefully without crashing

## API Boundaries

### What Core App Can Call

✓ NewsletterIntegration::initialize()
✓ NewsletterIntegration::onUserRegistered()
✓ NewsletterIntegration::onUserProfileUpdated()
✓ NewsletterIntegration::onUserDeleted()
✓ NewsletterIntegration::processNewsletterSendJob()
✓ NewsletterIntegration::queueNewsletterSend()
✓ NewsletterIntegration::checkTestSendRateLimit()
✓ NewsletterIntegration::recordTestSend()
✓ NewsletterIntegration::getSubscriberCount()

### What Core App Should NOT Call

✗ SubscriptionService methods directly
✗ CampaignService methods directly
✗ SendingService methods directly
✗ Direct database queries on newsletter tables
✗ Private integration methods

**Reason**: Integration may evolve - all external calls should go through NewsletterIntegration facade.

## Audit Trail

All significant operations logged to `audit_log` table:

```sql
-- View all newsletter operations
SELECT * FROM audit_log 
WHERE action LIKE 'newsletter_%'
ORDER BY created_at DESC;

-- View per-user subscription history
SELECT * FROM audit_log 
WHERE action LIKE 'newsletter_%' 
AND target_email = 'user@example.com'
ORDER BY created_at DESC;

-- View admin campaign actions
SELECT * FROM audit_log 
WHERE action LIKE 'newsletter_%' 
AND actor_email = 'admin@example.com'
ORDER BY created_at DESC;
```

## Testing Strategy

### Unit Tests (for each integration point)

```php
// Test: User registration auto-subscribes
$userId = create_test_user();
$result = NewsletterIntegration::onUserRegistered($conn, $userId, 'test@example.com', []);
assert($result === true);
assert_subscriber_exists($conn, $userId);
assert_preferences_exist($conn, $userId);
assert_audit_logged($conn, 'newsletter_auto_subscribe');

// Test: Profile update syncs preferences
$new_prefs = ['interests' => ['climate', 'energy']];
$result = NewsletterIntegration::onUserProfileUpdated($conn, $userId, $new_prefs);
assert($result === true);
assert_preferences_synced($conn, $userId, $new_prefs);
```

### Integration Tests

```php
// Test: End-to-end user lifecycle
$email = 'lifecycle_test_' . time() . '@example.com';
$user_id = register_user($email);  // Calls registration hook
assert_subscriber_created($email);

update_user_profile($user_id, ['interests' => ['ai']]);  // Calls profile hook
assert_preferences_updated($email, ['interests' => ['ai']]);

delete_user($user_id);  // Calls deletion hook
assert_unsubscribed($email);
```

### Load Tests

```bash
# Register 1000 users
for i in {1..1000}; do
  curl -X POST /register -d "email=test$i@example.com&..."
done

# Check database
SELECT COUNT(*) FROM newsletter_subscribers;  -- Should be ~1000

# Check performance
SELECT AVG(TIMESTAMPDIFF(MILLISECOND, created_at, updated_at))
FROM newsletter_subscribers;  -- Should be <100ms per user
```

## Deployment Checklist

1. **Pre-deployment**
   - [ ] All env vars configured
   - [ ] Email provider credentials set
   - [ ] Database has sufficient disk space
   - [ ] Backup taken

2. **Deployment**
   - [ ] Code deployed
   - [ ] Schema migrations run
   - [ ] Integration points verified
   - [ ] Feature flag enabled

3. **Post-deployment**
   - [ ] Test user registration
   - [ ] Test profile update
   - [ ] Monitor error_log for integration errors
   - [ ] Check job_queue for pending jobs
   - [ ] Verify subscriber counts

4. **Rollback** (if needed)
   - [ ] Set NEWSLETTER_ENABLED=false
   - [ ] Kill any running worker processes
   - [ ] Restart app
   - [ ] Verify core functionality works

## Future Enhancements

### Phase 2: Advanced Segmentation
- Preference-based segmentation engine
- Smart send time optimization
- Engagement scoring
- Dormant subscriber re-engagement

### Phase 3: Webhooks & Real-time Events
- Email provider webhook integration
- Real-time bounce/complaint handling
- Subscriber engagement webhooks
- Integration with external systems

### Phase 4: Advanced Analytics
- Detailed campaign performance dashboard
- Cohort analysis
- Churn prediction
- Revenue attribution

### Phase 5: Personalization
- Dynamic content blocks
- A/B testing framework
- Product recommendation engine
- Behavioral trigger campaigns

## References

- [Integration Guide](INTEGRATION_GUIDE.md) - How to implement each hook
- [Implementation Checklist](IMPLEMENTATION_CHECKLIST.md) - Step-by-step setup
- [NewsletterSubscriber Model](models/NewsletterSubscriber.php)
- [SubscriptionService](services/SubscriptionService.php)
- [CampaignService](services/CampaignService.php)
- [SendingService](services/SendingService.php)
- [AnalyticsService](services/AnalyticsService.php)

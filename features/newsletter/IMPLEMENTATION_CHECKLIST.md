# Newsletter Integration Implementation Checklist

Complete this checklist to fully integrate the newsletter module with your FACT Hub instance.

## Phase 1: Configuration & Environment

- [ ] Add environment variables to `.env`:
  ```bash
  NEWSLETTER_ENABLED=true
  NEWSLETTER_EMAIL_PROVIDER=aws_ses
  NEWSLETTER_FROM_EMAIL=noreply@facthub.org
  NEWSLETTER_FROM_NAME=FACT Hub
  NEWSLETTER_TEST_SEND_LIMIT=10
  ```

- [ ] Configure email provider credentials:
  - [ ] AWS SES: `AWS_REGION`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`
  - [ ] OR SendGrid: `SENDGRID_API_KEY`

- [ ] Test environment variables are readable:
  ```php
  echo getenv('NEWSLETTER_ENABLED');  // Should print true
  ```

## Phase 2: Database Schema

- [ ] Verify schema was applied:
  ```sql
  SHOW TABLES LIKE 'newsletter_%';
  -- Should show: newsletter_campaigns, newsletter_clicks, newsletter_events, 
  --              newsletter_preferences, newsletter_recipients, newsletter_subscribers,
  --              newsletter_unsubscribe_tokens
  ```

- [ ] Check all tables have proper indexes:
  ```sql
  SHOW INDEXES FROM newsletter_subscribers;
  SHOW INDEXES FROM newsletter_campaigns;
  SHOW INDEXES FROM newsletter_recipients;
  ```

- [ ] Verify foreign keys:
  ```sql
  SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME 
  FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
  WHERE TABLE_NAME LIKE 'newsletter_%' AND REFERENCED_TABLE_NAME IS NOT NULL;
  ```

## Phase 3: Integration Points

### 3.1 App Initialization (public/index.php)

- [ ] Verify `apply_newsletter_schema($conn)` is called (line 49)
- [ ] Verify initialization is in place:
  ```php
  require_once __DIR__ . '/../features/newsletter/integration.php';
  NewsletterIntegration::initialize($conn);
  ```

### 3.2 User Registration (app/views/researchers/index.php)

- [ ] Add hook after user creation (after line with `$conn->insert_id`):
  ```php
  require_once __DIR__ . '/../../features/newsletter/integration.php';
  NewsletterIntegration::onUserRegistered(
      $conn,
      $userId,
      $email,
      [
          'role' => 'researcher',
          'interests' => $interests,
          'geography' => $geography,
          'topics' => $topics,
          'institution' => $institution,
          'orcid' => $orcid
      ]
  );
  ```

- [ ] Test registration creates subscriber:
  - [ ] Register new researcher
  - [ ] Verify `newsletter_subscribers` record created
  - [ ] Verify `newsletter_preferences` record created with defaults
  - [ ] Check audit_log for 'newsletter_auto_subscribe' event

- [ ] Repeat for funders/index.php user creation

### 3.3 User Profile Update (app/views/profile/index.php)

- [ ] Add hook after profile save:
  ```php
  NewsletterIntegration::onUserProfileUpdated(
      $conn,
      $userId,
      [
          'interests' => $newInterests,
          'geography' => $newGeography,
          'topics' => $newTopics,
          'frequency' => $frequency
      ]
  );
  ```

- [ ] Test profile update syncs preferences:
  - [ ] Update researcher profile
  - [ ] Verify `newsletter_preferences` updated
  - [ ] Check audit_log for 'newsletter_profile_sync' event

### 3.4 User Deletion (app/views/admin/index.php)

- [ ] Add hook in user deletion flow:
  ```php
  NewsletterIntegration::onUserDeleted(
      $conn,
      $userId,
      $email,
      'Admin deletion - GDPR compliance'
  );
  ```

- [ ] Test deletion unsubscribes:
  - [ ] Delete user via admin panel
  - [ ] Verify `newsletter_subscribers` status = 'unsubscribed'
  - [ ] Verify `unsubscribed_at` is set
  - [ ] Check audit_log for 'newsletter_user_deleted' event

## Phase 4: Background Job Processing

### 4.1 Job Queue Integration (app/jobs/worker.php)

- [ ] Verify job_queue table exists:
  ```sql
  DESCRIBE job_queue;
  ```

- [ ] Add newsletter_send handler in worker.php job processing section:
  ```php
  case 'newsletter_send':
      require_once __DIR__ . '/../../features/newsletter/integration.php';
      $success = NewsletterIntegration::processNewsletterSendJob($conn, $jobId, $jobData);
      // ... mark job complete/failed
      break;
  ```

- [ ] Test job processing:
  - [ ] Manually queue newsletter_send job
  - [ ] Run worker manually
  - [ ] Verify job marked as completed/processing
  - [ ] Check error_log for job processing logs

### 4.2 Campaign Service Integration (features/newsletter/services/CampaignService.php)

- [ ] Add job queueing in CampaignService:
  ```php
  // When scheduling campaign send
  $jobId = NewsletterIntegration::queueNewsletterSend($conn, $campaignId);
  
  // Log action
  NewsletterIntegration::recordCampaignAction(
      $conn,
      $adminEmail,
      $campaignId,
      'schedule',
      'Campaign scheduled for sending'
  );
  ```

- [ ] Test campaign sending:
  - [ ] Create campaign in admin
  - [ ] Schedule for sending
  - [ ] Verify job_queue entry created
  - [ ] Verify audit_log entry created
  - [ ] Run worker
  - [ ] Verify recipients marked as sent

## Phase 5: Admin Features

### 5.1 Campaign Management

- [ ] Verify admin can create campaigns
- [ ] Verify admin can schedule campaigns
- [ ] Verify admin can view campaign status
- [ ] Verify admin can pause campaigns

### 5.2 Test Sends

- [ ] Verify admin can send test email
- [ ] Verify rate limit is enforced (10 per day)
- [ ] Test exceeding rate limit:
  ```php
  // Run this 11 times
  NewsletterIntegration::recordTestSend($conn, $adminEmail, $campaignId, ['test@example.com']);
  ```

### 5.3 Audit Trail

- [ ] Verify audit_log entries created for:
  - [ ] `newsletter_auto_subscribe` on user registration
  - [ ] `newsletter_profile_sync` on profile update
  - [ ] `newsletter_user_deleted` on user deletion
  - [ ] `newsletter_create_campaign` on campaign creation
  - [ ] `newsletter_send` on campaign send
  - [ ] `newsletter_test_send` on test send

- [ ] Query audit logs:
  ```sql
  SELECT * FROM audit_log 
  WHERE action LIKE 'newsletter_%' 
  ORDER BY created_at DESC LIMIT 20;
  ```

## Phase 6: Testing & Validation

### 6.1 Feature Flag Testing

- [ ] With `NEWSLETTER_ENABLED=false`:
  - [ ] Register user - verify no subscriber created
  - [ ] Update profile - verify no preference update
  - [ ] Delete user - verify no audit event

- [ ] With `NEWSLETTER_ENABLED=true`:
  - [ ] Register user - verify subscriber created
  - [ ] Update profile - verify preferences updated
  - [ ] Delete user - verify unsubscribed

### 6.2 Database Integrity

- [ ] Verify foreign key relationships:
  ```sql
  -- Check no orphaned subscriber records
  SELECT s.id FROM newsletter_subscribers s
  WHERE s.user_id IS NOT NULL AND s.user_id NOT IN (SELECT id FROM users);
  -- Should return 0 rows
  ```

- [ ] Verify preference consistency:
  ```sql
  -- Check every subscriber has preferences
  SELECT s.id FROM newsletter_subscribers s
  WHERE s.id NOT IN (SELECT DISTINCT subscriber_id FROM newsletter_preferences);
  -- Should return 0 rows
  ```

### 6.3 Error Handling

- [ ] Simulate database connection failure:
  - [ ] Stop MySQL
  - [ ] Register user
  - [ ] Verify registration completes (newsletter fails gracefully)
  - [ ] Start MySQL, check error_log

- [ ] Simulate missing newsletter tables:
  - [ ] Temporarily disable schema creation
  - [ ] Register user
  - [ ] Verify registration completes
  - [ ] Check error_log for schema errors

### 6.4 Performance Testing

- [ ] Bulk user registration (100+ users):
  - [ ] Measure registration time
  - [ ] Verify subscribers created
  - [ ] Check database load

- [ ] Bulk profile updates (100+ users):
  - [ ] Measure update time
  - [ ] Verify preferences synced

## Phase 7: Configuration Audit

- [ ] Verify `.env` has NEWSLETTER_ENABLED=true
- [ ] Verify email provider is configured correctly
- [ ] Verify all required env vars are set
- [ ] Test email sending works:
  ```php
  // In a test script
  $provider = NewsletterIntegration::getEmailProvider();
  echo "Provider: " . $provider['provider'];
  echo "From: " . $provider['from_email'];
  ```

## Phase 8: Documentation & Runbooks

- [ ] Document your environment's configuration
- [ ] Create runbook for:
  - [ ] Starting/stopping worker
  - [ ] Manual job processing
  - [ ] Troubleshooting failed sends
  - [ ] Managing rate limits
  - [ ] Audit log queries

- [ ] Update team wiki with:
  - [ ] Newsletter feature overview
  - [ ] Admin UI walkthrough
  - [ ] How to create campaigns
  - [ ] How to view analytics

## Phase 9: Monitoring & Alerts

- [ ] Set up monitoring for:
  - [ ] Job queue backlog (SELECT COUNT(*) FROM job_queue WHERE status IN ('pending', 'processing'))
  - [ ] Failed jobs (SELECT COUNT(*) FROM job_queue WHERE status = 'failed')
  - [ ] Test send rate limit violations
  - [ ] Unsubscribe trends

- [ ] Set up alerts for:
  - [ ] Job processing failures
  - [ ] Email provider errors
  - [ ] Unusual rate limit violations

- [ ] Create dashboard queries:
  ```sql
  -- Daily subscriber growth
  SELECT DATE(created_at) as date, COUNT(*) as new_subs
  FROM newsletter_subscribers
  GROUP BY DATE(created_at)
  ORDER BY date DESC LIMIT 30;
  
  -- Campaign send status
  SELECT id, title, status, 
         (SELECT COUNT(*) FROM newsletter_recipients WHERE campaign_id = nc.id AND status = 'sent') as sent,
         (SELECT COUNT(*) FROM newsletter_recipients WHERE campaign_id = nc.id AND status = 'bounced') as bounced
  FROM newsletter_campaigns nc
  ORDER BY created_at DESC LIMIT 10;
  ```

## Phase 10: Go-Live Checklist

- [ ] All environment variables set and tested
- [ ] All integration points added
- [ ] Database schema verified
- [ ] User registration tested end-to-end
- [ ] Profile updates tested end-to-end
- [ ] User deletion tested end-to-end
- [ ] Background job processing tested
- [ ] Admin features tested
- [ ] Rate limiting tested
- [ ] Audit trail verified
- [ ] Error handling tested
- [ ] Performance acceptable
- [ ] Documentation complete
- [ ] Team trained
- [ ] Monitoring in place

## Rollback Plan

If issues arise:

1. **Disable Feature Flag**:
   ```bash
   # In .env
   NEWSLETTER_ENABLED=false
   ```
   - Users can still register/update profiles
   - No new subscribers created
   - No jobs queued

2. **Stop Background Jobs**:
   ```bash
   # Kill worker process
   killall php
   ```
   - No more emails sent
   - Pending jobs stay in queue

3. **Restore Data**:
   ```sql
   -- If subscriber data corrupted, truncate:
   TRUNCATE TABLE newsletter_preferences;
   TRUNCATE TABLE newsletter_subscribers;
   -- Data will be recreated on next user actions
   ```

4. **Reapply Schema** (if needed):
   ```bash
   # Restart app with NEWSLETTER_ENABLED=true
   # Schema will reapply automatically
   ```

## Sign-Off

- [ ] Development: _____________________ Date: _______
- [ ] QA: _____________________ Date: _______
- [ ] Operations: _____________________ Date: _______
- [ ] Product: _____________________ Date: _______

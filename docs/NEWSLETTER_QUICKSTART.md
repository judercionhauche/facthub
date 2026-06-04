# Newsletter Dashboard Quick Start Guide

## Installation & Setup

### 1. Database Migration

Run the migration to create all required tables:

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/fact_hub2
mysql -u root -p fact_hub2 < migrations/2026_06_04_create_newsletter_tables.sql
```

This creates:
- `newsletter_campaigns` - Email campaign storage
- `newsletter_subscribers` - Subscriber list
- `newsletter_templates` - Reusable MJML templates
- `newsletter_sends` - Individual send tracking
- `newsletter_opens` - Open tracking
- `newsletter_clicks` - Click tracking
- `newsletter_segments` - Audience segments

### 2. Access the Dashboard

**URL**: `http://localhost/facthub/public/?page=admin&section=newsletter`

Or add to admin navigation in the main admin index.php:

```php
// In app/views/admin/index.php, update the $adminSection line:
$adminSection = in_array($_GET['section'] ?? '', [
    'dashboard','users','researchers','funders','audit','api_usage','jobs','settings','embeddings','newsletter'
]) ? $_GET['section'] : 'dashboard';
```

And add tab in the admin navigation menu:
```html
<a href="?page=admin&section=newsletter">Newsletter</a>
```

### 3. Configure Mail Settings

Ensure `config/mail.php` has correct values:

```php
return [
    'app_url' => 'https://facthub.org',
    'from_email' => 'noreply@facthub.org',
    'from_name' => 'FACT Hub',
    'smtp_host' => 'mail.example.com',
    'smtp_port' => 587,
    'smtp_user' => 'your-email@example.com',
    'smtp_pass' => 'your-app-password',
];
```

## First Steps

### Step 1: Create Your First Campaign

1. Go to **Campaigns** tab
2. Fill in:
   - **Title**: "Welcome to FACT Hub Newsletter"
   - **Sender Name**: "FACT Hub Team"
   - **Sender Email**: "noreply@facthub.org"
   - **Content**: Use the MJML Template button to insert a template
3. Click **Save as Draft**
4. Edit and refine the content
5. **Test** with your email address
6. **Schedule** for tomorrow morning at 9 AM
7. **Monitor** in Analytics tab

### Step 2: Add Subscribers

Subscribers can be:
1. **Auto-registered** when users sign up (requires integration with user registration)
2. **Manually added** via admin interface (future feature)
3. **Imported** from CSV (future feature)

For now, manually insert test subscribers:

```sql
INSERT INTO newsletter_subscribers (email, research_interests, geography, institution, status) VALUES
('researcher1@university.edu', 'Climate Change, Sustainability', 'Africa', 'University of Cape Town', 'active'),
('researcher2@university.edu', 'Food Security, Agriculture', 'South Asia', 'University of Delhi', 'active'),
('funder@foundation.org', 'Climate Funding', 'Global', 'Green Climate Fund', 'active');
```

### Step 3: Create Email Templates

1. Go to **Templates** tab
2. Click form area
3. Fill in:
   - **Name**: "Monthly Research Digest"
   - **Content**: Paste MJML with {{placeholders}}
4. Click **Create Template**
5. In campaigns, click **MJML Template** button to insert

#### Sample MJML Template:

```html
<mjml>
  <mj-body>
    <mj-section>
      <mj-column>
        <mj-image width="300px" src="https://facthub.org/logo.png" alt="FACT Hub"></mj-image>
      </mj-column>
    </mj-section>
    
    <mj-section background-color="#f9fafb">
      <mj-column>
        <mj-text font-size="24px" font-weight="bold" color="#0066cc">
          {{campaign_title}}
        </mj-text>
      </mj-column>
    </mj-section>
    
    <mj-section>
      <mj-column>
        <mj-text font-size="16px">
          Hello {{first_name}},
        </mj-text>
        <mj-text>
          {{campaign_content}}
        </mj-text>
        <mj-button href="{{cta_url}}" background-color="#0066cc">
          {{cta_text}}
        </mj-button>
      </mj-column>
    </mj-section>
    
    <mj-section background-color="#f3f4f6">
      <mj-column>
        <mj-text font-size="12px" color="#6b7280" align="center">
          You're receiving this because you're subscribed to FACT Hub research updates.
          <br/><a href="{{unsubscribe_url}}">Unsubscribe</a>
        </mj-text>
      </mj-column>
    </mj-section>
  </mj-body>
</mjml>
```

### Step 4: Monitor Analytics

1. Go to **Analytics** tab
2. View key metrics:
   - Total campaigns
   - Total subscribers
   - Average open rate
   - Average click rate
3. Review **Campaign Performance** chart
4. Check **Top Clicked Links**
5. Monitor **Subscriber Growth** trends

## Common Tasks

### Send a Test Email

1. Open campaign
2. Click **Test** button
3. Enter test email address
4. Click **Send Test**
5. Check inbox for test email

### Schedule a Campaign

1. Open campaign in **Campaigns** tab
2. Click **Schedule** button
3. Select date and time
4. Click **Schedule**
5. Campaign status changes to "scheduled"
6. At scheduled time, email sends automatically (requires async job)

### Unsubscribe a User

1. Go to **Subscribers** tab
2. Find subscriber in list
3. Click **Unsub** button in Actions
4. Subscriber marked as "unsubscribed"

### Resubscribe a User

1. Go to **Subscribers** tab
2. Find unsubscribed subscriber
3. Click **Resub** button
4. Subscriber status changes to "active"

### View Campaign Stats

1. Go to **Campaigns** tab
2. Find campaign
3. Click **Stats** button
4. View opens, clicks, engagement metrics

## Personalization Placeholders

Use in campaign content and templates:

- `{{first_name}}` - Subscriber first name
- `{{last_name}}` - Subscriber last name
- `{{email}}` - Subscriber email
- `{{research_interests}}` - Their research interests
- `{{geography}}` - Their geographic focus
- `{{institution}}` - Their institution/organization
- `{{role}}` - Their role (researcher/funder)
- `{{funding_preference}}` - Their funding interest
- `{{campaign_title}}` - Campaign title
- `{{campaign_content}}` - Campaign content
- `{{cta_text}}` - Call-to-action button text
- `{{cta_url}}` - Call-to-action URL
- `{{unsubscribe_url}}` - Unsubscribe link
- `{{list_name}}` - Newsletter list name

## Audience Filtering

Currently supported filters:
- Research interests
- Geography (region/country)
- Institution
- User role (researcher/funder)
- Funding preferences
- Subscription status

Campaigns can target:
- All active subscribers
- Specific segments (future)
- Geographic regions (future)
- Interest-based groups (future)

## Best Practices

### Email Design

1. **Keep it responsive** - Use MJML components for mobile compatibility
2. **Simple layout** - Max 2-3 sections per email
3. **Clear CTA** - One primary action per email
4. **Unsubscribe link** - Required by law in most jurisdictions
5. **Preview text** - Add in campaign title

### Sending

1. **Test first** - Always send test before mass send
2. **Schedule wisely** - Send Tuesday-Thursday 9-11 AM
3. **Frequency** - Not more than 1-2 per week
4. **Avoid spam words** - "Free", "Act now!", "Limited time"
5. **Monitor metrics** - Track opens, clicks, unsubscribes

### Subscriber Management

1. **Get consent** - Require opt-in for subscriptions
2. **Provide value** - Relevant content to interests
3. **Keep updated** - Let users manage preferences
4. **Honor unsubscribe** - Process within 5 days
5. **Remove bounces** - Hard bounces should auto-remove

## Troubleshooting

### Emails not sending

**Check**:
1. Campaign status is "sending" or "sent" (not "draft")
2. Subscribers exist and are "active" status
3. SMTP configuration in `config/mail.php` is correct
4. Check error logs in `/tmp/`

**Solution**:
```bash
# Test SMTP connection
telnet mail.example.com 587

# Check database for records
mysql> SELECT COUNT(*) FROM newsletter_sends;

# Check for errors
tail -f /var/log/php-error.log
```

### Test emails not arriving

**Check**:
1. Test email address is valid
2. Check spam/junk folder
3. SMTP credentials are correct

**Solution**:
```php
// Add debug logging
error_log("Sending test to: " . $testEmail);
error_log("Campaign: " . json_encode($campaign));
```

### Analytics showing zero

**Check**:
1. Campaign status is "sent" (not "draft")
2. Enough time has passed for opens/clicks
3. Tracking pixels are embedded in emails

**Solution**:
- Opens track when user opens email (requires pixel)
- Clicks track when user clicks links (requires redirect)
- May take hours/days to see data

### Database errors

**Check**:
1. Migration ran successfully
2. Tables exist: `SHOW TABLES LIKE 'newsletter%'`
3. Correct database: `SELECT DATABASE()`

**Solution**:
```bash
# Re-run migration
mysql -u root -p fact_hub2 < migrations/2026_06_04_create_newsletter_tables.sql

# Verify tables
mysql -u root -p -e "USE fact_hub2; SHOW TABLES LIKE 'newsletter%';"
```

## Advanced Configuration

### Async Job Queue Setup

For production, configure async sending:

```php
// In send_newsletter_campaign()
$jobId = queue_job('send_newsletter', ['campaign_id' => $campaign['id']]);
return ['status' => 'queued', 'job_id' => $jobId];
```

### Bounce Handling

```php
// Listen for bounce webhooks from email service
if ($incomingWebhook['type'] === 'bounce') {
    mark_subscriber_bounced($conn, $incomingWebhook['email']);
}
```

### GDPR Compliance

1. **Consent tracking** - Record when user opted in
2. **Right to delete** - Delete subscriber data on request
3. **Data export** - Export subscriber data on request
4. **Retention policy** - Auto-delete old records after 1 year

## Integration Points

The newsletter system integrates with:

- **Users table** - Auto-subscribe on registration
- **Researchers table** - Pull research interests for segmentation
- **Funders table** - Pull funding focus for segmentation
- **Audit log** - Log all admin actions
- **Mail config** - Use for SMTP settings
- **Session system** - Admin-only access checks

## Next Steps

1. **Create first campaign** (see Quick Start above)
2. **Add test subscribers** via SQL
3. **Test sending** with test email
4. **Schedule campaign** for tomorrow
5. **Review analytics** after send
6. **Create audience segments** for targeting
7. **Build template library** for quick campaign creation
8. **Set up async job queue** for production
9. **Configure webhook handling** for bounce/complaint tracking
10. **Implement GDPR features** for compliance

## Support

- **Documentation**: See `docs/NEWSLETTER_DASHBOARD.md` and `docs/NEWSLETTER_API.md`
- **Database Schema**: See `migrations/2026_06_04_create_newsletter_tables.sql`
- **Code Reference**: See `app/views/admin/newsletter.php`
- **Audit Log**: Check `audit` table for action history

## FAQ

**Q: How do I auto-subscribe users when they sign up?**
A: Add this to user registration:
```php
$stmt = $conn->prepare("INSERT INTO newsletter_subscribers (email, user_id, status) VALUES (?, ?, 'active')");
$stmt->bind_param('si', $newUserEmail, $newUserId);
$stmt->execute();
```

**Q: Can I send to specific users only?**
A: Yes, use the segment system or modify send query to filter subscribers.

**Q: How do I track email opens?**
A: Include tracking pixel in template:
```html
<img src="https://facthub.org/track-open.php?c={{campaign_id}}&s={{subscriber_id}}" width="1" height="1">
```

**Q: Can I schedule emails to send at different times per timezone?**
A: Not yet - future enhancement. Currently uses server timezone.

**Q: How do I handle unsubscribe requests?**
A: The dashboard provides unsubscribe buttons. Include unsubscribe link in emails:
```html
<a href="https://facthub.org/unsubscribe.php?email={{email}}&token={{unsubscribe_token}}">
  Unsubscribe
</a>
```

# Newsletter System API Reference

## Overview

This document describes the backend functions and database API for the newsletter system. The dashboard (`app/views/admin/newsletter.php`) provides a UI wrapper around these functions.

## Helper Functions

### Campaign Functions

#### `get_campaigns($conn, $limit = 100)`
Retrieves all campaigns ordered by creation date.

**Parameters**:
- `$conn` - Database connection
- `$limit` - Maximum campaigns to return (default: 100)

**Returns**: Array of campaign arrays
```php
[
    ['id' => 1, 'title' => 'Campaign Title', 'status' => 'draft', 'open_rate' => 25.5, ...],
    ...
]
```

**Example**:
```php
$campaigns = get_campaigns($conn);
foreach ($campaigns as $campaign) {
    echo $campaign['title'];
}
```

---

#### `get_campaign($conn, $id)`
Retrieves a single campaign by ID.

**Parameters**:
- `$conn` - Database connection
- `$id` - Campaign ID (int)

**Returns**: Single campaign array or null
```php
[
    'id' => 1,
    'title' => 'Monthly Digest',
    'content' => '<mjml>...</mjml>',
    'sender_name' => 'FACT Hub',
    'sender_email' => 'noreply@facthub.org',
    'status' => 'draft',
    'sent_date' => '2026-06-04 10:00:00',
    'scheduled_at' => '2026-06-10 09:00:00',
    'sent_at' => null,
    'recipient_count' => 0,
    'open_rate' => 0,
    'click_rate' => 0,
    'created_by' => 'admin@facthub.org',
    'created_at' => '2026-06-04 15:20:00',
    'updated_at' => '2026-06-04 15:20:00'
]
```

**Example**:
```php
$campaign = get_campaign($conn, 5);
if ($campaign) {
    echo "Title: " . $campaign['title'];
}
```

---

### Subscriber Functions

#### `get_subscribers($conn, $status = null, $limit = 1000)`
Retrieves all subscribers, optionally filtered by status.

**Parameters**:
- `$conn` - Database connection
- `$status` - Optional status filter ('active', 'unsubscribed', 'bounced')
- `$limit` - Maximum subscribers to return (default: 1000)

**Returns**: Array of subscriber arrays
```php
[
    ['id' => 1, 'email' => 'user@example.com', 'status' => 'active', ...],
    ...
]
```

**Example**:
```php
$activeSubscribers = get_subscribers($conn, 'active');
echo count($activeSubscribers) . " active subscribers";
```

---

#### `get_subscriber($conn, $id)`
Retrieves a single subscriber by ID.

**Parameters**:
- `$conn` - Database connection
- `$id` - Subscriber ID (int)

**Returns**: Single subscriber array
```php
[
    'id' => 1,
    'email' => 'researcher@university.edu',
    'user_id' => 42,
    'status' => 'active',
    'research_interests' => 'Climate change, Sustainability',
    'geography' => 'Sub-Saharan Africa',
    'institution' => 'University of Cape Town',
    'role' => 'researcher',
    'funding_preference' => 'Climate action funds',
    'subscribed_at' => '2026-04-15 10:30:00',
    'unsubscribed_at' => null,
    'created_at' => '2026-04-15 10:30:00',
    'updated_at' => '2026-04-15 10:30:00'
]
```

---

#### `get_subscribers_by_segment($conn, $segmentId)`
Retrieves all subscribers matching a segment's filters.

**Parameters**:
- `$conn` - Database connection
- `$segmentId` - Segment ID (int)

**Returns**: Array of subscriber IDs
```php
[42, 85, 127, ...]
```

---

### Template Functions

#### `get_templates($conn, $limit = 50)`
Retrieves all email templates.

**Parameters**:
- `$conn` - Database connection
- `$limit` - Maximum templates to return

**Returns**: Array of template arrays
```php
[
    ['id' => 1, 'name' => 'Professional Newsletter', 'content' => '<mjml>...</mjml>', ...],
    ...
]
```

---

#### `get_template($conn, $id)`
Retrieves a single template by ID.

**Parameters**:
- `$conn` - Database connection
- `$id` - Template ID (int)

**Returns**: Template array
```php
[
    'id' => 1,
    'name' => 'Research Updates',
    'content' => '<mjml>...</mjml>',
    'created_by' => 'admin@facthub.org',
    'created_at' => '2026-06-04 15:20:00',
    'updated_at' => '2026-06-04 15:20:00'
]
```

---

### Analytics Functions

#### `get_analytics($conn, $campaignId = null, $days = 30)`
Retrieves analytics metrics.

**Parameters**:
- `$conn` - Database connection
- `$campaignId` - Optional campaign ID to get specific campaign analytics
- `$days` - Days to include in metrics (default: 30)

**Returns**: Analytics array
```php
[
    'total_campaigns' => 12,
    'total_subscribers' => 450,
    'avg_open_rate' => 28.5,
    'avg_click_rate' => 6.2,
    'campaign_performance' => [
        ['name' => 'Campaign A', 'sent' => 100, 'delivered' => 98, 'opened' => 32, 'clicked' => 8],
        ...
    ],
    'top_links' => [
        ['url' => 'https://facthub.org/research', 'clicks' => 145],
        ...
    ],
    'subscriber_growth' => [
        ['month' => 'Jan', 'count' => 45],
        ...
    ]
]
```

---

#### `get_campaign_opens($conn, $campaignId)`
Gets open count for a campaign.

**Parameters**:
- `$conn` - Database connection
- `$campaignId` - Campaign ID (int)

**Returns**: Integer count
```php
$opens = get_campaign_opens($conn, 5);
echo "Campaign opened $opens times";
```

---

#### `get_campaign_clicks($conn, $campaignId)`
Gets click count for a campaign.

**Parameters**:
- `$conn` - Database connection
- `$campaignId` - Campaign ID (int)

**Returns**: Integer count + array of top links
```php
['total' => 48, 'links' => [['url' => '...', 'count' => 12], ...]]
```

---

### Sending Functions

#### `send_test_email($campaign, $testEmail)`
Sends a test email for preview purposes.

**Parameters**:
- `$campaign` - Campaign array from get_campaign()
- `$testEmail` - Test recipient email address (string)

**Returns**: Boolean success

**Example**:
```php
$campaign = get_campaign($conn, 5);
if (send_test_email($campaign, 'tester@example.com')) {
    echo "Test email sent!";
}
```

---

#### `send_newsletter_campaign($conn, $campaign, $subscriberIds = null)`
Sends campaign to all or specified subscribers.

**Parameters**:
- `$conn` - Database connection
- `$campaign` - Campaign array
- `$subscriberIds` - Optional array of specific subscriber IDs to send to

**Returns**: Boolean success

**Important**: In production, this should queue an async job rather than sending synchronously.

**Example**:
```php
$campaign = get_campaign($conn, 5);
// Send to all subscribers
send_newsletter_campaign($conn, $campaign);

// Or send to specific segment
$segmentSubs = get_subscribers_by_segment($conn, 3);
send_newsletter_campaign($conn, $campaign, $segmentSubs);
```

---

#### `send_campaign_batch($conn, $campaignId, $batchSize = 100)`
Sends campaign in batches (for async job system).

**Parameters**:
- `$conn` - Database connection
- `$campaignId` - Campaign ID
- `$batchSize` - Number of emails per batch

**Returns**: Number of emails sent

**Example**:
```php
// In background job queue
while (true) {
    $sent = send_campaign_batch($conn, 5, 100);
    if ($sent === 0) break;
    sleep(5); // Delay between batches
}
```

---

### Personalization Functions

#### `render_campaign_content($content, $subscriberData)`
Renders campaign content with personalization placeholders.

**Parameters**:
- `$content` - Template content with {{placeholders}}
- `$subscriberData` - Subscriber array from get_subscriber()

**Returns**: Rendered content string

**Example**:
```php
$subscriber = get_subscriber($conn, 42);
$rendered = render_campaign_content(
    "Hello {{first_name}}, check out {{research_interests}}",
    $subscriber
);
```

---

#### `compile_mjml_to_html($mjmlContent)`
Compiles MJML template to responsive HTML email.

**Parameters**:
- `$mjmlContent` - MJML XML content

**Returns**: HTML string suitable for email

**Note**: Requires MJML compiler (separate service/library)

---

### Tracking Functions

#### `record_open($campaignId, $subscriberId, $userAgent = null, $ipAddress = null)`
Records that subscriber opened email.

**Parameters**:
- `$campaignId` - Campaign ID
- `$subscriberId` - Subscriber ID
- `$userAgent` - Optional user agent string
- `$ipAddress` - Optional IP address

**Returns**: Boolean success

**Example** (in tracking pixel endpoint):
```php
if (isset($_GET['c']) && isset($_GET['s'])) {
    record_open($_GET['c'], $_GET['s'], $_SERVER['HTTP_USER_AGENT'], $_SERVER['REMOTE_ADDR']);
}
```

---

#### `record_click($campaignId, $subscriberId, $url, $userAgent = null, $ipAddress = null)`
Records that subscriber clicked link.

**Parameters**:
- `$campaignId` - Campaign ID
- `$subscriberId` - Subscriber ID
- `$url` - URL that was clicked
- `$userAgent` - Optional user agent string
- `$ipAddress` - Optional IP address

**Returns**: Boolean success

---

### Segment Functions

#### `get_segments($conn)`
Retrieves all audience segments.

**Returns**: Array of segment arrays

#### `get_segment($conn, $id)`
Retrieves single segment by ID.

**Returns**: Segment array with filters (JSON)

#### `create_segment($conn, $name, $filters, $createdBy)`
Creates new audience segment.

**Parameters**:
- `$conn` - Database connection
- `$name` - Segment name
- `$filters` - JSON object with filter criteria
- `$createdBy` - Admin email

**Example**:
```php
$filters = [
    'role' => 'researcher',
    'geography' => 'Africa',
    'status' => 'active'
];
create_segment($conn, 'African Researchers', $filters, 'admin@facthub.org');
```

---

## Database Queries

### Direct SQL Examples

**Get open rate for campaign**:
```sql
SELECT 
    (COUNT(DISTINCT o.id) / COUNT(DISTINCT s.id)) * 100 as open_rate
FROM newsletter_sends s
LEFT JOIN newsletter_opens o ON s.campaign_id = o.campaign_id AND s.subscriber_id = o.subscriber_id
WHERE s.campaign_id = ?;
```

**Get top clicked links**:
```sql
SELECT url, COUNT(*) as clicks
FROM newsletter_clicks
WHERE campaign_id = ?
GROUP BY url
ORDER BY clicks DESC
LIMIT 10;
```

**Get subscriber count for segment**:
```sql
SELECT COUNT(*) as count
FROM newsletter_subscribers
WHERE JSON_CONTAINS(?, filters);
```

---

## Status Values

### Campaign Status
- `draft` - Not yet sent, can be edited
- `scheduled` - Scheduled for future send
- `sending` - Currently sending to subscribers
- `sent` - All sent (some may have bounced)
- `paused` - Paused mid-send

### Subscriber Status
- `active` - Actively receiving emails
- `unsubscribed` - User unsubscribed
- `bounced` - Email bounced (hard bounce)

### Send Status
- `queued` - Waiting to be sent
- `sending` - Currently being sent
- `sent` - Successfully sent
- `bounced` - Hard bounce (invalid address)
- `failed` - Temporary failure (retry)

---

## Error Handling

All functions return false or null on error. Check return values:

```php
if (send_test_email($campaign, $email)) {
    echo "Success";
} else {
    echo "Failed to send test email";
}
```

For debugging, check audit logs:
```sql
SELECT * FROM audit_log WHERE action LIKE '%campaign%' ORDER BY created_at DESC;
```

---

## Performance Tips

1. **Use pagination** for large subscriber lists
2. **Index frequently queried columns**: status, created_at, email
3. **Batch sending** for large campaigns (1000+ subscribers)
4. **Cache analytics** results for dashboard
5. **Archive old campaign data** after 1 year
6. **Use async jobs** for sending and tracking

---

## Integration with Existing System

### User Mapping
```php
$user = current_user();
// Can link to users table via user_id
// Subscriber email matches user email for auto-subscription
```

### Audit Logging
```php
audit($conn, 'send_campaign', [
    'detail' => 'Campaign: ' . $campaign['title'],
    'campaign_id' => $campaign['id'],
    'recipient_count' => count($subscriberIds)
]);
```

### CSRF Protection
```php
// In forms
<?= csrf_input() ?>

// In processing
if (!verify_csrf($_POST['csrf_token'])) {
    die('CSRF token invalid');
}
```

---

## Configuration

Mail configuration from `config/mail.php`:
```php
$mailCfg = include 'config/mail.php';
$appUrl = $mailCfg['app_url']; // For unsubscribe links
$senderEmail = $mailCfg['from_email']; // Default sender
```

---

## Testing

### Unit Test Example
```php
public function testCreateCampaign() {
    global $conn;
    
    $stmt = $conn->prepare("INSERT INTO newsletter_campaigns 
        (title, content, status, created_by) 
        VALUES (?, ?, 'draft', ?)");
    
    $stmt->bind_param('sss', 'Test Campaign', '<p>Test</p>', 'test@admin.org');
    $this->assertTrue($stmt->execute());
}
```

### Integration Test Example
```php
public function testSendCampaign() {
    global $conn;
    
    $campaign = get_campaign($conn, 1);
    $result = send_newsletter_campaign($conn, $campaign);
    
    $this->assertTrue($result);
    
    // Verify sends created
    $stmt = $conn->prepare("SELECT COUNT(*) FROM newsletter_sends WHERE campaign_id = ?");
    $stmt->bind_param('i', $campaign['id']);
    // Assert count > 0
}
```

---

## Version History

- **v1.0** (2026-06-04) - Initial dashboard with core features
  - Campaign CRUD
  - Subscriber management
  - Template library
  - Basic analytics

---

## Support & Debugging

**Common Issues**:

1. **Emails not sending**: Check async job queue, verify SMTP config
2. **Opens/clicks not tracked**: Ensure tracking pixels/redirects configured
3. **Slow analytics**: Add database indexes, cache results
4. **Large subscriber lists timeout**: Use batch processing, pagination

**Debug Mode**:
```php
// In newsletter.php, add logging
error_log("Campaign sent: " . json_encode($campaign));
```

Check logs in `/tmp/` or configured log path.

# Newsletter Preferences Integration Guide

This guide explains how to integrate the newsletter preferences system into your FACT Hub application.

## Quick Start

### 1. Add Newsletter Helpers to Profile Page

In `app/views/profile/index.php`, add to the imports:

```php
require_once __DIR__ . '/../../core/newsletter_helpers.php';
```

### 2. Add Newsletter Tab to Profile Navigation

In the profile sidebar navigation, add:

```php
<a href="?page=profile&tab=newsletter" class="profile-sidebar-item <?= $tab === 'newsletter' ? 'active' : '' ?>">Newsletter</a>
```

### 3. Add Newsletter Tab to Validation

Update the `$validTabs` array:

```php
$validTabs = ['overview', 'settings', 'links', 'preferences', 'newsletter'];
```

### 4. Include Newsletter Preferences in Profile Page

Add to the main content area (in the tab section):

```php
<?php elseif ($tab === 'newsletter'): ?>
<!-- Newsletter Preferences Tab -->
<?php include __DIR__ . '/newsletter_preferences.php'; ?>
```

## Integration Points

### Database Schema
The newsletter preferences system requires the `newsletter_subscribers` table from the migration:

```bash
# Run this migration to set up the database
mysql fact_hub2 < migrations/2026_06_04_create_newsletter_tables.sql
mysql fact_hub2 < migrations/2026_06_04_add_newsletter_frequency_columns.sql
```

### Configuration
Ensure your `config/mail.php` has the `notify_secret` configured:

```php
return [
    'notify_secret' => env('NEWSLETTER_SECRET_KEY', 'your-secret-key-here'),
    // ... other mail config
];
```

### Audit Logging
The system logs preference changes to the audit trail:

```
audit($conn, 'update_newsletter_preferences', ['email' => $user['email']])
audit($conn, 'newsletter_unsubscribe', ['email' => $email])
audit($conn, 'update_newsletter_preferences_public', ['email' => $email])
```

## Using in Email Templates

When sending newsletters, include preference links:

```php
<?php
require_once __DIR__ . '/app/core/newsletter_helpers.php';

$email = 'subscriber@example.com';
$mailCfg = require __DIR__ . '/config/mail.php';
$secret = $mailCfg['notify_secret'] ?? '';

// Generate tokens
$unsub_token = generate_newsletter_unsubscribe_token($email, $secret);
$prefs_token = generate_newsletter_prefs_token($email, $secret);

// Build URLs
$unsub_url = 'index.php?page=newsletter_unsubscribe&e=' . urlencode($email) . '&t=' . $unsub_token;
$prefs_url = 'index.php?page=newsletter_prefs&e=' . urlencode($email) . '&t=' . $prefs_token;
?>
```

Include in email footer:

```html
<p style="font-size: 12px; color: #999;">
  You received this email because you're subscribed to our newsletter.
  <a href="<?= $unsub_url ?>">Unsubscribe</a> |
  <a href="<?= $prefs_url ?>">Manage preferences</a>
</p>
```

## API Endpoints

### Create/Get Subscription
```php
$sub = get_or_create_subscription($conn, 'user@example.com', 'researcher');
```

### Update Status
```php
update_subscription_status($conn, 'user@example.com', 'active');
update_subscription_status($conn, 'user@example.com', 'unsubscribed');
```

### Update Frequency
```php
update_subscription_frequency($conn, 'user@example.com', 'weekly');
```

### Update Content Preferences
```php
update_content_preferences($conn, 'user@example.com', [
    'research_interests' => 'climate change, agriculture',
    'geography' => 'Sub-Saharan Africa',
    'institution' => 'universities, NGOs',
    'funding_preference' => 'early-stage research'
]);
```

### Get Subscribers for Sending
```php
$weekly_subs = get_subscribers_for_sending($conn, 'weekly');
$immediate_subs = get_subscribers_for_sending($conn, 'immediate');
```

### Get Statistics
```php
$stats = get_subscription_stats($conn);
// Returns:
// [
//   'total' => 1234,
//   'by_status' => ['active' => 1100, 'unsubscribed' => 134],
//   'by_frequency' => ['weekly' => 600, 'daily' => 300, 'immediate' => 200]
// ]
```

### Export for External Service
```php
$subscribers = export_subscribers($conn, ['frequency' => 'weekly']);
// Returns array of subscriber objects with email and preferences
```

## Page Routes

### Views Created

1. **Newsletter Preferences (Logged-in)**
   - File: `app/views/profile/newsletter_preferences.php`
   - URL: `?page=profile&tab=newsletter`
   - Requires: Authentication
   - Features: Full preference management with CSRF protection

2. **Public Unsubscribe**
   - File: `app/views/newsletter_unsubscribe/index.php`
   - URL: `?page=newsletter_unsubscribe&e=email@example.com&t=token`
   - Requires: Valid token
   - Features: One-click unsubscribe

3. **Public Preferences**
   - File: `app/views/newsletter_prefs/index.php`
   - URL: `?page=newsletter_prefs&e=email@example.com&t=token`
   - Requires: Valid token
   - Features: Full preference management without login

## Form Validation

All forms include:
- **CSRF protection**: `csrf_input()` and `verify_csrf()`
- **Input sanitization**: `trim()` and `h()` for output
- **Database safety**: Prepared statements with type binding
- **Enum validation**: Status and frequency checked against allowed values

Example form:
```php
<form method="post">
    <?= csrf_input() ?>
    <input type="hidden" name="update_preferences" value="1">
    
    <!-- Form fields... -->
    
    <button type="submit" class="save-btn">Save Preferences</button>
</form>
```

## Error Handling

The system handles:
- Invalid tokens (shows error message to user)
- Missing email address (redirects to login)
- Database errors (logs to error_log, shows generic message)
- CSRF token mismatch (shows security error)

Example error message handling:
```php
if ($error_message):
    echo '<div class="alert alert-error">' . h($error_message) . '</div>';
endif;
```

## Styling

The preference pages include comprehensive styling for:
- Subscription status indicators
- Toggle switches and radio buttons
- Form fields and inputs
- Alert messages (success/error)
- Responsive mobile layout

CSS classes available:
- `.newsletter-container` - Main container
- `.newsletter-section` - Content section
- `.subscription-status` - Status display
- `.alert` `.alert-success` `.alert-error` - Messages
- `.save-btn` - Submit button

## Testing

### Test Unsubscribe Link
```php
$email = 'test@example.com';
$secret = 'test_secret';
$token = generate_newsletter_unsubscribe_token($email, $secret);
// Link: ?page=newsletter_unsubscribe&e=test@example.com&t=$token
```

### Test Preference Link
```php
$email = 'test@example.com';
$secret = 'test_secret';
$token = generate_newsletter_prefs_token($email, $secret);
// Link: ?page=newsletter_prefs&e=test@example.com&t=$token
```

### Test Invalid Token
```php
// Use wrong email with token
// Link: ?page=newsletter_unsubscribe&e=wrong@example.com&t=$token
// Should show "Invalid Link" message
```

## Troubleshooting

### Issue: "Invalid Link" when clicking unsubscribe
**Solution**: 
- Check that `notify_secret` is configured in `config/mail.php`
- Verify email address matches (case-sensitive in URL but normalized in code)
- Ensure URL encoding is correct: `urlencode($email)`

### Issue: Preferences not saving
**Solution**:
- Check CSRF token is in form: `<?= csrf_input() ?>`
- Verify `verify_csrf()` function returns true
- Check database errors in error_log
- Ensure form has `method="post"` and `name="update_preferences"`

### Issue: Can't find newsletter_preferences.php
**Solution**:
- File location: `app/views/profile/newsletter_preferences.php`
- Include path should be: `include __DIR__ . '/newsletter_preferences.php';`
- Current working directory may affect relative paths

## Performance Considerations

### Subscriber Queries
```php
// Efficient: Uses index on status
$active = get_subscribers_for_sending($conn, 'weekly');

// Less efficient: Full table scan
$all = export_subscribers($conn);
```

### Indexes to Add
```sql
-- Already in migration, but verify:
ALTER TABLE newsletter_subscribers 
  ADD INDEX `status` (`status`),
  ADD INDEX `email` (`email`),
  ADD INDEX `frequency` (`frequency`);
```

### Batch Operations
For large lists (>10,000 subscribers):
```php
// Use pagination
$batch_size = 1000;
$offset = 0;
while (true) {
    // Fetch and process batch
    $sql = "SELECT * FROM newsletter_subscribers LIMIT ? OFFSET ?";
    // Process...
    $offset += $batch_size;
}
```

## Security Best Practices

1. **Token Expiration** (Future enhancement)
   - Add `token_expires_at` timestamp
   - Validate token age before accepting

2. **Rate Limiting**
   - Limit preference changes to 10/hour per email
   - Limit unsubscribe requests to 3/hour per email
   - Track in separate rate_limit table

3. **Email Validation**
   - Verify email format with filter_var()
   - Consider double opt-in for new subscriptions
   - Monitor for bounce rates

4. **Audit Trail**
   - All changes logged via audit() function
   - Track user agent and IP in audit logs
   - Review suspicious activity regularly

## Future Enhancements

See `docs/NEWSLETTER_PREFERENCES.md` for planned features:
- Email template customization
- Frequency scheduling with timezones
- Segment-based targeting
- Engagement tracking
- Double opt-in workflow
- List hygiene automation
- Analytics dashboard

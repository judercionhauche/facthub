# Newsletter Preferences System

## Overview

The Newsletter Preferences system allows users to manage their email subscriptions and content preferences with full GDPR and CAN-SPAM compliance. It provides three levels of preference management:

1. **Logged-in user preferences** - Full control from profile dashboard
2. **Token-based public preferences** - Non-logged-in users via email links
3. **One-click unsubscribe** - Quick opt-out from any newsletter email

## Features

### 1. Subscription Status Toggle
- **Subscribe/Unsubscribe**: Users can toggle their subscription status
- **Subscription dates**: Tracks when subscribed and unsubscribed
- **Status tracking**: Records active, unsubscribed, and bounced statuses

### 2. Email Frequency Options
- **Immediate**: Receive emails as soon as content is published
- **Daily**: Once per day in the morning
- **Weekly**: Every Monday morning (default)
- **Never**: Turn off email notifications

### 3. Content Preferences
Users can specify interests in:
- **Research interests**: Comma-separated topics (climate change, agriculture, etc.)
- **Geographic regions**: Target areas (Sub-Saharan Africa, South Asia, etc.)
- **Institution types**: Partner organization types (universities, NGOs, etc.)
- **Funding focus areas**: Funding opportunity types (early-stage, capacity building, etc.)

### 4. Email Address Management
- Display current email address
- Link to change email address in main account settings
- Validation of email format in database

### 5. Privacy & Compliance
- Data usage explanation
- Link to privacy policy
- GDPR and CAN-SPAM compliance notice
- Secure token-based access for non-logged-in preference management

### 6. Secure Token-Based Access
- **Unsubscribe token**: One-click unsubscribe without logging in
  - Format: `HMAC-SHA256(email|newsletter_unsubscribe, secret)`
  - Verification: `hash_equals()` prevents timing attacks
  
- **Preference token**: Manage preferences without logging in
  - Format: `HMAC-SHA256(email|newsletter_prefs, secret)`
  - Requires valid token to access/modify preferences

## File Structure

### Views (User Interface)
- **`app/views/profile/newsletter_preferences.php`**
  - Main preferences page for logged-in users
  - Integrated into profile/preferences tab
  - Full form with CSRF protection
  - Shows subscription status, frequency, content preferences, email
  - Displays token-based links for sharing/embedding in emails

- **`app/views/newsletter_unsubscribe/index.php`**
  - Public one-click unsubscribe page
  - Accessible via secure token link
  - No login required
  - Shows confirmation states: done, already unsubscribed, invalid

- **`app/views/newsletter_prefs/index.php`**
  - Public preference management page
  - Accessible via secure token link
  - No login required
  - Full form for updating preferences without logging in

### Helpers
- **`app/core/newsletter_helpers.php`**
  - `generate_newsletter_unsubscribe_token()`: Create unsubscribe tokens
  - `generate_newsletter_prefs_token()`: Create preference tokens
  - `verify_newsletter_token()`: Validate tokens
  - `get_or_create_subscription()`: Fetch/create subscription records
  - `update_subscription_status()`: Change subscription status
  - `update_subscription_frequency()`: Change email frequency
  - `update_content_preferences()`: Update content preferences
  - `get_subscribers_for_sending()`: Get subscribers for batch operations
  - `count_active_subscribers()`: Get active subscriber count
  - `get_subscription_stats()`: Get statistics breakdown
  - `cleanup_inactive_subscribers()`: Remove old inactive records
  - `export_subscribers()`: Export for newsletter services

### Database
- **`migrations/2026_06_04_create_newsletter_tables.sql`**
  - Creates `newsletter_subscribers` table with all preference columns
  - Additional tables for campaigns, sends, opens, clicks tracking
  - Includes sample data and segments

- **`migrations/2026_06_04_add_newsletter_frequency_columns.sql`**
  - Adds `frequency` column if not already present

## Database Schema

### newsletter_subscribers Table
```sql
id              INT PRIMARY KEY
email           VARCHAR(255) UNIQUE
user_id         INT (optional foreign key)
status          ENUM('active', 'unsubscribed', 'bounced')
frequency       ENUM('immediate', 'daily', 'weekly', 'never')
research_interests TEXT
geography       VARCHAR(255)
institution     VARCHAR(255)
role            ENUM('researcher', 'funder', 'both')
funding_preference VARCHAR(255)
subscribed_at   TIMESTAMP
unsubscribed_at DATETIME
created_at      TIMESTAMP
updated_at      TIMESTAMP
```

## Security Implementation

### CSRF Protection
All preference updates are protected with CSRF tokens:
```php
<?= csrf_input() ?>  // Include hidden field in forms
verify_csrf()        // Validate in POST handlers
```

### Token-Based Authentication
Tokens are HMAC-SHA256 hashed and verified using `hash_equals()`:
```php
// Generate token
$token = bin2hex(hash_hmac('sha256', $email . '|newsletter_prefs', $secret, true));

// Verify token (prevents timing attacks)
hash_equals($expected, $token)
```

### Input Validation
- Email addresses are normalized (lowercase, trimmed)
- Status/frequency values checked against allowed enums
- HTML entities escaped with `h()` function
- Prepared statements for all database queries

### Rate Limiting
Consider adding rate limiting for:
- Preference change requests (max 10 per hour per email)
- Unsubscribe requests (max 3 per hour per email)
- Token generation (max 5 per hour per email)

## Usage Examples

### For Logged-In Users
```php
// Access from profile
?page=profile&tab=preferences

// Then click "Newsletter Preferences" tab
```

### For Non-Logged-In Users
Include these links in every newsletter email:

```html
<!-- One-click unsubscribe -->
<a href="index.php?page=newsletter_unsubscribe&e=<?= urlencode($email) ?>&t=<?= $token ?>">
  Unsubscribe
</a>

<!-- Manage preferences -->
<a href="index.php?page=newsletter_prefs&e=<?= urlencode($email) ?>&t=<?= $token ?>">
  Manage preferences
</a>
```

### In Code
```php
// Get/create subscription
$sub = get_or_create_subscription($conn, 'user@example.com', 'researcher');

// Update status
update_subscription_status($conn, 'user@example.com', 'active');

// Update frequency
update_subscription_frequency($conn, 'user@example.com', 'weekly');

// Update content preferences
update_content_preferences($conn, 'user@example.com', [
    'research_interests' => 'climate change, agriculture',
    'geography' => 'Sub-Saharan Africa',
]);

// Get subscribers for sending
$weekly_subscribers = get_subscribers_for_sending($conn, 'weekly');

// Get stats
$stats = get_subscription_stats($conn);
```

## Integration Points

### Profile Page
The preferences are integrated into the existing profile system at:
- URL: `?page=profile&tab=preferences`
- File: `app/views/profile/index.php` (existing profile structure)

Add to profile navigation:
```php
<a href="?page=profile&tab=newsletter" class="profile-sidebar-item">
  Newsletter
</a>
```

Then include the preferences page content.

### Email Templates
When sending newsletters, include:
```php
$email = 'user@example.com';
$mailCfg = require __DIR__ . '/../../../config/mail.php';
$secret = $mailCfg['notify_secret'] ?? '';

$unsub_token = generate_newsletter_unsubscribe_token($email, $secret);
$unsub_url = "index.php?page=newsletter_unsubscribe&e=" . urlencode($email) . "&t=" . $unsub_token;

$prefs_token = generate_newsletter_prefs_token($email, $secret);
$prefs_url = "index.php?page=newsletter_prefs&e=" . urlencode($email) . "&t=" . $prefs_token;
```

### Admin Dashboard
Connect to admin panel for viewing:
- Subscriber counts by status
- Breakdown by frequency
- Recent subscription/unsubscription activity
- Export subscribers for external newsletter service

## Compliance

### GDPR
- Explicit opt-in for all communications
- Easy opt-out mechanism
- Data retention policies enforced
- Right to access/modify preferences
- Data portability via exports

### CAN-SPAM
- One-click unsubscribe mechanism
- Honor opt-out within 10 business days
- Include physical mailing address in emails
- Clear identification of content as promotional
- Accurate subject lines

### Privacy Policy Link
Update privacy policy to include:
- Types of data collected (email, interests, location)
- Purpose of collection (targeted communications)
- Data retention period (e.g., 2 years after unsubscribe)
- Third-party sharing (none unless explicit)
- User rights (access, modify, delete)

## Testing

### Unit Tests
Test token generation/verification:
```php
$email = 'test@example.com';
$secret = 'test_secret_key';

$token = generate_newsletter_unsubscribe_token($email, $secret);
assert(verify_newsletter_token($email, $token, $secret, 'unsubscribe'));

$bad_token = bin2hex(random_bytes(32));
assert(!verify_newsletter_token($email, $bad_token, $secret, 'unsubscribe'));
```

### Manual Testing
1. Create new subscription
   - Navigate to profile newsletter preferences
   - Should create record if none exists

2. Update preferences
   - Change subscription status
   - Change frequency
   - Add content preferences
   - Verify updates in database

3. Test unsubscribe link
   - Generate token
   - Click link without logging in
   - Verify status changed to 'unsubscribed'

4. Test preference link
   - Generate token
   - Click link without logging in
   - Update preferences
   - Verify changes persisted

5. Test with invalid tokens
   - Try token for different email
   - Try expired/wrong token format
   - Verify access denied

## Future Enhancements

1. **Email template customization**
   - User-defined content types to receive
   - Custom digest formatting

2. **Frequency scheduling**
   - Specific days/times for digest emails
   - Timezone awareness

3. **Segment-based targeting**
   - Create audience segments
   - Target campaigns to specific segments

4. **Engagement tracking**
   - Track opens and clicks
   - Adjust frequency based on engagement

5. **Double opt-in**
   - Confirmation email for new subscriptions
   - Higher deliverability rates

6. **List hygiene**
   - Automated bounce handling
   - Re-engagement campaigns

7. **Analytics dashboard**
   - Subscription trends
   - Engagement metrics
   - Demographic breakdowns

## Troubleshooting

### Invalid Token Errors
- Check that `notify_secret` is properly configured in `config/mail.php`
- Verify email address matches exactly (case-insensitive)
- Ensure token URL encoding/decoding is correct

### Preferences Not Saving
- Check CSRF token is present in form
- Verify `verify_csrf()` is being called
- Check for database errors in error log

### Tokens Not Generating
- Verify `hash_hmac()` function is available
- Check PHP `hash` extension is enabled
- Ensure random_bytes() works for CSRF generation

### Email Not Received
- Check subscription status is 'active'
- Verify frequency matches sending schedule
- Check email deliverability/spam filters

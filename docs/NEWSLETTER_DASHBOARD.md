# Admin Newsletter Dashboard

## Overview

The admin newsletter dashboard (`app/views/admin/newsletter.php`) provides comprehensive email campaign management, subscriber tracking, and analytics for the FACT Hub platform. It's a feature-rich tool for creating, scheduling, and monitoring email newsletters with full personalization support.

## Access

- **URL**: `/admin/?section=newsletter`
- **Required Role**: Admin (protected by `require_admin()`)
- **CSRF Protection**: All POST actions validated with `verify_csrf()`

## Main Sections

### 1. Campaigns Tab

**Create & Edit Campaigns**
- Form fields: Title, Content (rich text editor), Sender Name, Sender Email
- Save as draft for later refinement
- Edit existing draft campaigns
- Built-in rich text editor with formatting buttons (Bold, Italic, Link, Heading)
- MJML template support with `{{placeholder}}` syntax for personalization
- Live preview toggle to see rendered content

**Campaign Management**
- Campaign list table with columns:
  - Title
  - Status (draft, scheduled, sending, sent, paused)
  - Sent date
  - Open rate (%)
  - Click rate (%)
  - Recipient count
  - Action buttons (context-aware)

**Campaign Actions**
- **Draft campaigns**: Edit, Test, Schedule, Delete
- **Scheduled campaigns**: View, Send Now
- **Sending/Sent campaigns**: View Stats, Pause (if still sending)
- **Paused campaigns**: View Stats, Resume
- **All campaigns**: View detailed analytics

**Test Send**
- Modal form to send test email to specific address
- Useful for preview before mass send
- Validates email format

**Scheduling**
- Date/time picker modal
- Schedule future sends
- Campaign status updates to "scheduled"
- All active subscribers receive email at scheduled time

### 2. Subscribers Tab

**Subscriber Management**
- Full subscriber list with filters
- Table columns:
  - Email address
  - Status (active, unsubscribed, bounced)
  - Subscription date
  - Research interests
  - Geography
  - Institution

**Filtering & Search**
- Filter by status (active/unsubscribed)
- Expandable for additional filters (geography, interest, role)

**Subscriber Actions**
- **Active subscribers**: Unsubscribe button
- **Unsubscribed subscribers**: Resubscribe button
- View detailed subscriber preferences
- Edit subscription preferences

**Preferences Captured**
- Research interests (multiple)
- Geography (region/country)
- Institution/Organization
- User role (researcher, funder, both)
- Funding preferences

### 3. Templates Tab

**Template Management**
- Library of reusable MJML email templates
- Create new templates with MJML syntax
- Preview template content
- Copy template to clipboard
- Delete unused templates

**MJML Features**
- Professional email layout components
- Responsive design out-of-the-box
- Placeholder support: `{{placeholder_name}}`
- Includes sample professional and research update templates

**Sample Templates**
1. **Professional Newsletter** - General purpose with CTA button
2. **Research Updates Template** - Featured research, funding opportunities

**Template Usage**
- Insert template content into campaign editor via "MJML Template" button
- Customize placeholders for campaign
- Use as starting point for new campaigns

### 4. Analytics Tab

**Key Performance Metrics**
- Total campaigns (all-time count)
- Total active subscribers
- Average open rate (%)
- Average click rate (%)

**Campaign Performance Chart**
- Bar chart showing sent/delivered/opened/clicked
- Filterable by date range (Last 30 Days default)
- Visual comparison across campaigns

**Engagement Trends**
- Line chart tracking open and click rates over time
- Identifies peak engagement periods
- Helps optimize send times

**Top Clicked Links**
- Table of most clicked URLs in campaigns
- Click count for each link
- Click-through rate (CTR) percentage
- Identifies most valuable content

**Subscriber Growth**
- Bar chart showing new subscribers per month
- Last 6 months of growth data
- Identifies growth trends

## Database Schema

### Tables Created

#### `newsletter_campaigns`
```sql
- id (PK)
- title (varchar)
- content (longtext)
- sender_name, sender_email
- status (enum: draft, scheduled, sending, sent, paused)
- sent_date, scheduled_at, sent_at (datetime)
- recipient_count, open_rate, click_rate
- created_by (admin email)
- created_at, updated_at
```

#### `newsletter_subscribers`
```sql
- id (PK)
- email (unique)
- user_id (FK to users table)
- status (enum: active, unsubscribed, bounced)
- research_interests, geography, institution, role
- funding_preference
- subscribed_at, unsubscribed_at
- created_at, updated_at
```

#### `newsletter_templates`
```sql
- id (PK)
- name (varchar)
- content (longtext MJML)
- created_by (admin email)
- created_at, updated_at
```

#### `newsletter_sends`
Tracks individual email sends
```sql
- id (PK)
- campaign_id (FK)
- subscriber_id (FK)
- email, status (queued, sending, sent, bounced, failed)
- sent_at, error_message
- created_at
```

#### `newsletter_opens`
Email open tracking (pixel-based)
```sql
- id (PK)
- campaign_id (FK)
- subscriber_id (FK)
- opened_at, user_agent, ip_address
```

#### `newsletter_clicks`
Link click tracking
```sql
- id (PK)
- campaign_id (FK)
- subscriber_id (FK)
- url, clicked_at, user_agent, ip_address
```

#### `newsletter_segments`
Audience segmentation for targeting
```sql
- id (PK)
- name, description
- filters (JSON)
- subscriber_count
- created_by, created_at
```

## Features

### Email Personalization

MJML templates support placeholders:
- `{{first_name}}` - Subscriber first name
- `{{last_name}}` - Subscriber last name
- `{{research_interests}}` - Their interests
- `{{institution}}` - Their institution
- `{{campaign_title}}` - Campaign title
- `{{campaign_content}}` - Campaign content
- `{{cta_url}}` - Call-to-action URL
- `{{unsubscribe_url}}` - Unsubscribe link
- Custom placeholders can be added

### Audience Targeting

Filter subscribers by:
- Research interests (multi-select checkboxes)
- Geography (region/country)
- Institution/Organization
- User role (researcher/funder)
- Funding preferences
- Subscription status

### Security

- **Admin-only access**: `require_admin()` gate
- **CSRF protection**: All POST forms include `csrf_input()` and validated with `verify_csrf()`
- **HTML escaping**: Output sanitized with `h()` helper
- **Prepared statements**: All database queries use parameterized queries
- **Audit logging**: Campaign actions logged via `audit()` helper

### Rich Text Editing

**Toolbar buttons**:
- Bold (`<strong>`)
- Italic (`<em>`)
- Link (`<a>`)
- Heading (`<h2>`)
- MJML Template insertion
- Live Preview toggle

**Content types supported**:
- Plain HTML
- MJML with responsive email components
- Personalization placeholders
- Inline styles and CSS

## POST Actions

All POST actions require CSRF token and return audit log entries.

### Campaign Actions
- `create_campaign` - Create draft campaign
- `update_campaign` - Update draft campaign
- `send_test` - Send test email
- `schedule_campaign` - Schedule future send
- `send_campaign` - Send immediately to all subscribers
- `pause_campaign` - Pause sending
- `resume_campaign` - Resume paused send
- `delete_campaign` - Delete draft only

### Template Actions
- `create_template` - Create reusable MJML template
- `delete_template` - Delete template

### Subscriber Actions
- `unsubscribe_subscriber` - Unsubscribe user
- `resubscribe_subscriber` - Resubscribe user

## Integration Points

### Current Implementation
- Admin authentication via `require_admin()`
- User identification via `current_user()`
- Mail configuration from `config/mail.php`
- CSRF token generation/validation
- Audit logging system

### Async Job System (Future)
The dashboard is designed to work with an async job system for:
- Batch sending campaigns to all subscribers
- Processing opens/clicks in background
- Calculating analytics metrics
- Handling bounces and failures

### API Layer (Future)
REST API endpoints for:
- Campaign CRUD operations
- Subscriber management
- Template management
- Analytics data
- Webhook handling for bounces/opens

## Database Setup

Run the migration to create all newsletter tables:

```sql
-- From migrations/2026_06_04_create_newsletter_tables.sql
mysql -u root -p fact_hub2 < migrations/2026_06_04_create_newsletter_tables.sql
```

This creates:
- 7 tables (campaigns, subscribers, templates, sends, opens, clicks, segments)
- Foreign key relationships
- Indexes for performance
- Sample templates
- Sample audience segments

## Usage Examples

### Create and Send Campaign

1. **Navigate to Campaigns tab**
2. **Fill in campaign form**:
   - Title: "Monthly Research Digest - June 2026"
   - Sender: "FACT Hub Team <digest@facthub.org>"
   - Content: Paste or write email content
3. **Click "Save as Draft"**
4. **Edit if needed** by clicking campaign in list
5. **Test** with test email address before full send
6. **Schedule** for future date/time OR **Send Now**
7. **Monitor** engagement in Analytics tab

### Create Reusable Template

1. **Go to Templates tab**
2. **Fill template form**:
   - Name: "Monthly Newsletter"
   - Content: Paste MJML with placeholders
3. **Click "Create Template"**
4. **Use in campaigns**: Click "MJML Template" button in editor

### Analyze Campaign Performance

1. **Go to Analytics tab**
2. **View key metrics**:
   - Total campaigns and subscribers
   - Average engagement rates
3. **Check campaign performance** chart
4. **Review top links** clicked
5. **Track growth** of subscriber base

## Styling & Theming

The dashboard uses CSS custom properties for theming:

```css
--primary: #0066cc (blue)
--success: #1a6b5a (green)
--danger: #b54646 (red)
--warning: #b45309 (orange)
--line: #e5e7eb (border)
--bg: #f9fafb (light background)
--muted: #6b7280 (grey text)
--text: #111827 (black)
```

Responsive design breakpoint at 768px (mobile-first approach).

## Performance Considerations

- **Pagination**: Campaigns limited to 100, subscribers to 1000 (configurable)
- **Indexes**: Added on status, dates, email for fast queries
- **Async sending**: Should be delegated to background job queue
- **Analytics caching**: Consider caching analytics for large datasets

## Future Enhancements

1. **Advanced Analytics**
   - Chart.js or similar for interactive charts
   - Cohort analysis
   - A/B testing framework

2. **Automation**
   - Trigger-based sends (new user, new funding call)
   - Recurring campaigns (daily digest, weekly summary)
   - Behavioral triggers (open-based follow-ups)

3. **Deliverability**
   - SPF/DKIM/DMARC validation
   - Bounce handling
   - Complaint management
   - Suppression lists

4. **Collaboration**
   - Campaign scheduling/approval workflow
   - Multiple admin roles (viewer, editor, sender)
   - Comments/feedback on drafts

5. **Compliance**
   - GDPR consent management
   - Detailed unsubscribe reasons
   - Data retention policies
   - Archive of sent emails

## Support

For issues or questions:
- Check audit logs for action history
- Review database schema in migration file
- Test with sample data in templates/segments
- Use browser developer tools to debug JavaScript

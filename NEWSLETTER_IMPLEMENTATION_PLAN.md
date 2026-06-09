# Newsletter Subscription Feature - Implementation Plan

**Status:** Planning Phase  
**Date:** 2026-06-09  
**Scope:** Add newsletter preferences to FACT Alliance Hub  

---

## 1. Database Schema Changes

### 1.1 Add Field to `users` Table

```sql
ALTER TABLE users ADD COLUMN newsletter_subscribed TINYINT(1) DEFAULT 0 
COMMENT 'Newsletter subscription preference (1=subscribed, 0=not subscribed)' 
AFTER status;

ALTER TABLE users ADD COLUMN newsletter_subscribed_at TIMESTAMP NULL 
COMMENT 'Date when user subscribed to newsletter' 
AFTER newsletter_subscribed;
```

### 1.2 Migration Script

File: `app/core/schema_updates.php` - Add after existing updates:

```php
function apply_newsletter_schema_updates() {
    global $conn;
    
    // Check if newsletter_subscribed column exists
    $result = @$conn->query("SELECT 1 FROM information_schema.COLUMNS 
      WHERE TABLE_NAME='users' AND COLUMN_NAME='newsletter_subscribed' LIMIT 1");
    
    if (!$result || $result->num_rows === 0) {
        // Add newsletter_subscribed column
        @$conn->query("ALTER TABLE users ADD COLUMN newsletter_subscribed TINYINT(1) DEFAULT 0 
          COMMENT 'Newsletter subscription preference'");
        
        // Add newsletter_subscribed_at column
        @$conn->query("ALTER TABLE users ADD COLUMN newsletter_subscribed_at TIMESTAMP NULL 
          COMMENT 'Date when subscribed to newsletter'");
        
        error_log('[Schema] Added newsletter_subscribed columns to users table');
    }
}
```

---

## 2. API Endpoints

### 2.1 Update Newsletter Preference

**Endpoint:** `POST /api/user/newsletter-preference`

**Request:**
```json
{
  "newsletter_subscribed": true,
  "_csrf": "token"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Newsletter preference updated",
  "subscribed": true
}
```

### 2.2 Fetch Subscriber List (Admin)

**Endpoint:** `GET /api/admin/newsletter/subscribers`

**Response:**
```json
{
  "subscribers": [
    {
      "id": 50,
      "name": "Greg Sixt",
      "email": "sixt@mit.edu",
      "institution": "MIT",
      "topics": "agriculture, water",
      "subscribed_at": "2026-06-09 10:30:00"
    }
  ],
  "total": 42
}
```

### 2.3 Export Subscribers as Excel

**Endpoint:** `GET /api/admin/newsletter/export`

**Response:** Binary Excel file with columns:
- Full Name
- Email
- Institution
- Research Topics
- Date Subscribed
- Status

---

## 3. Frontend Components

### 3.1 Registration Form

Add to researcher registration form:
```html
<div class="form-group">
  <label>
    <input type="checkbox" name="newsletter_subscribed" value="1">
    Subscribe to FACT Alliance newsletter for updates on matching opportunities
  </label>
  <p class="help-text">Receive monthly digest of relevant funding calls and research collaborations</p>
</div>
```

### 3.2 Profile Update

Add to researcher profile page:
```html
<div class="preference-card">
  <h3>Newsletter Preferences</h3>
  <div class="toggle-group">
    <label class="toggle">
      <input type="checkbox" id="newsletter-toggle" name="newsletter_subscribed">
      <span>Subscribe to FACT Alliance Newsletter</span>
    </label>
  </div>
  <div id="newsletter-message" class="message" style="display:none;"></div>
</div>

<script>
document.getElementById('newsletter-toggle').addEventListener('change', function() {
  fetch('/api/user/newsletter-preference', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({
      newsletter_subscribed: this.checked,
      _csrf: document.querySelector('[name="_csrf"]').value
    })
  })
  .then(r => r.json())
  .then(data => {
    const msg = document.getElementById('newsletter-message');
    msg.className = data.success ? 'message success' : 'message error';
    msg.textContent = data.message;
    msg.style.display = 'block';
    setTimeout(() => msg.style.display = 'none', 3000);
  });
});
</script>
```

### 3.3 Admin Dashboard

Add to admin page:
```html
<div class="admin-section">
  <h2>Newsletter Management</h2>
  <div class="subscriber-stats">
    <div class="stat-card">
      <span class="label">Total Subscribers</span>
      <span class="value" id="subscriber-count">0</span>
    </div>
    <button id="export-subscribers" class="btn primary">
      📥 Download Subscribers (.xlsx)
    </button>
  </div>
  
  <table id="subscribers-table" class="table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Email</th>
        <th>Institution</th>
        <th>Research Area</th>
        <th>Subscribed</th>
      </tr>
    </thead>
    <tbody id="subscribers-tbody">
    </tbody>
  </table>
</div>

<script>
// Load subscribers
fetch('/api/admin/newsletter/subscribers')
  .then(r => r.json())
  .then(data => {
    document.getElementById('subscriber-count').textContent = data.total;
    const tbody = document.getElementById('subscribers-tbody');
    data.subscribers.forEach(sub => {
      tbody.innerHTML += `
        <tr>
          <td>${sub.name}</td>
          <td>${sub.email}</td>
          <td>${sub.institution || '-'}</td>
          <td>${sub.topics || '-'}</td>
          <td>${new Date(sub.subscribed_at).toLocaleDateString()}</td>
        </tr>
      `;
    });
  });

// Export function
document.getElementById('export-subscribers').addEventListener('click', function() {
  window.location.href = '/api/admin/newsletter/export';
});
</script>
```

---

## 4. Backend Implementation Files

### 4.1 API Endpoints File

**File:** `public/api/user-newsletter.php`

Handles:
- `POST` - Update newsletter preference
- Validates CSRF token
- Updates database
- Returns JSON response

### 4.2 Admin API

**File:** `public/api/admin-newsletter.php`

Handles:
- `GET /subscribers` - List all subscribers
- `GET /export` - Generate and download Excel

### 4.3 Excel Export Helper

**File:** `app/core/excel_export.php`

Uses PHP's ZipArchive and XML to generate XLSX without external dependencies.

---

## 5. Integration Points

### 5.1 Registration Flow (researchers/index.php)

When user registers:
1. Capture `newsletter_subscribed` from POST
2. Store in `users.newsletter_subscribed`
3. Store subscription timestamp

### 5.2 Profile Update (researchers/index.php)

When user updates profile:
1. Check if `newsletter_subscribed` changed
2. Update `users.newsletter_subscribed`
3. Update `users.newsletter_subscribed_at` if newly subscribed

### 5.3 Admin Dashboard (admin/index.php)

Add new section:
- View subscriber count
- Display subscriber list
- Export button

---

## 6. Security Considerations

✅ CSRF token required for all mutations  
✅ Admin-only endpoints protected with `is_admin()` check  
✅ Database queries use prepared statements  
✅ Email validation (already in place)  
✅ No data exposure (only returns user's own data or admin's aggregated data)  

---

## 7. Data Integrity

✅ Newsletter preference stored in `users` table (single source of truth)  
✅ No duplicate exports (dynamic generation from current DB state)  
✅ Timestamp tracking (knows when user subscribed)  
✅ Existing users default to `newsletter_subscribed = 0` (safe)  
✅ No breaking changes to existing tables  

---

## 8. Testing Checklist

### Registration Flow
- [ ] New researcher can select newsletter option
- [ ] Checkbox persists on form submit
- [ ] Database stores preference correctly
- [ ] Default is unchecked (opt-in)

### Profile Updates
- [ ] User can toggle newsletter preference
- [ ] Toggle saves immediately
- [ ] Success message appears
- [ ] Database updates correctly
- [ ] Can change from Yes → No
- [ ] Can change from No → Yes

### Admin Dashboard
- [ ] Subscriber count displays correctly
- [ ] Subscriber list shows all subscribed users
- [ ] Export button downloads Excel file
- [ ] Excel file contains correct columns
- [ ] Excel updates when user subscribes/unsubscribes

### Data Quality
- [ ] No duplicate emails in export
- [ ] Only subscribed users (newsletter_subscribed = 1) in export
- [ ] Timestamps accurate
- [ ] Database consistency maintained

---

## 9. Implementation Order

1. ✅ Schema migration (database)
2. ✅ API endpoints (backend)
3. ✅ Registration form update
4. ✅ Profile preference control
5. ✅ Admin dashboard section
6. ✅ Testing
7. ✅ Deployment

---

## 10. Rollback Plan

If issues occur:
1. Remove newsletter columns: `ALTER TABLE users DROP COLUMN newsletter_subscribed;`
2. Remove API endpoints
3. Remove form fields
4. Revert code to previous version

---

**Next Phase:** Code Implementation

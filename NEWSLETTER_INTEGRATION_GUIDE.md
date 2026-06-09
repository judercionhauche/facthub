# Newsletter Feature - Integration Guide

**Status:** Phase 1 (APIs) ✅ Complete | Phases 2-4 (Frontend) - Ready for Implementation

---

## What's Been Completed (Phase 1)

✅ **Database Schema** - Auto-created on first run via `apply_newsletter_schema()`
✅ **API Endpoints:**
  - `POST /api/newsletter-preference.php` - Update user subscription
  - `GET /api/admin-newsletter.php?action=list` - Fetch subscribers (JSON)
  - `GET /api/admin-newsletter.php?action=export` - Download Excel (XLSX)

✅ **Features:**
  - Dynamic Excel generation (never stale)
  - Admin-only protection
  - CSRF token validation
  - Audit logging
  - No external Excel library needed (native ZIP/XML)

---

## Phase 2: Registration Form Integration

### File: `app/views/researchers/index.php`

**Location:** Around line 500-550 (in the form section where you collect user details)

**Add this HTML after the email field:**

```html
<div class="form-group" style="margin-top: 16px;">
  <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
    <input type="checkbox" 
           name="newsletter_subscribed" 
           value="1"
           <?= ($editing && isset($editing['newsletter_subscribed']) && $editing['newsletter_subscribed']) ? 'checked' : '' ?>>
    <span style="font-weight: 500;">Subscribe to FACT Alliance newsletter</span>
  </label>
  <p style="font-size: 13px; color: #666; margin: 6px 0 0 28px;">
    Receive monthly updates on funding opportunities and research collaborations relevant to your interests.
  </p>
</div>
```

**In the POST handler (line 33-45, where you process form data):**

Add after validating other fields:

```php
// Handle newsletter subscription
$newsletter_subscribed = isset($_POST['newsletter_subscribed']) ? 1 : 0;

// If new registration, subscribe user
if ($id === 0 && $isNewRegistration) {
    // User subscribing during registration
    if ($newsletter_subscribed) {
        $nl_stmt = $conn->prepare("
            INSERT INTO newsletter_subscribers (user_id, email, status, subscribed_at)
            VALUES (?, ?, 'active', NOW())
            ON DUPLICATE KEY UPDATE status = 'active', updated_at = NOW()
        ");
        $nl_stmt->bind_param('is', $userId, $email);
        @$nl_stmt->execute();
    }
}
```

---

## Phase 3: Profile Update Integration

### File: `app/views/researchers/index.php`

**Location:** Around line 800-900 (in the view/edit profile section)

**Add this HTML block after the "Quiet Hours" section:**

```html
<!-- ── Newsletter Preference ─────────────────────────────────── -->
<div class="panel" style="margin-top: 24px;">
  <h3 style="margin-bottom: 16px;">Communication Preferences</h3>
  
  <div class="form-group">
    <label style="display: flex; align-items: center; gap: 12px; cursor: pointer;">
      <input type="checkbox" 
             id="newsletter-toggle"
             name="newsletter_subscribed"
             value="1"
             style="width: 18px; height: 18px; cursor: pointer;"
             <?= (isset($r['newsletter_subscribed']) && $r['newsletter_subscribed']) ? 'checked' : '' ?>>
      <div>
        <span style="font-weight: 600; display: block;">Subscribe to Newsletter</span>
        <span style="font-size: 13px; color: #666;">Receive monthly updates about funding opportunities and collaboration matches</span>
      </div>
    </label>
  </div>
  
  <div id="newsletter-feedback" class="message" style="display: none; margin-top: 12px; padding: 10px; border-radius: 4px;"></div>
</div>

<script>
// Handle newsletter toggle
document.getElementById('newsletter-toggle').addEventListener('change', async function() {
  const messageDiv = document.getElementById('newsletter-feedback');
  messageDiv.style.display = 'block';
  messageDiv.className = 'message loading';
  messageDiv.textContent = 'Updating preference...';
  
  try {
    const formData = new FormData();
    formData.append('newsletter_subscribed', this.checked ? '1' : '0');
    formData.append('_csrf', document.querySelector('[name="_csrf"]').value);
    
    const response = await fetch('/api/newsletter-preference.php', {
      method: 'POST',
      body: formData
    });
    
    const data = await response.json();
    
    if (data.success) {
      messageDiv.className = 'message success';
      messageDiv.textContent = '✓ ' + data.message;
      setTimeout(() => {
        messageDiv.style.display = 'none';
      }, 4000);
    } else {
      messageDiv.className = 'message error';
      messageDiv.textContent = '✗ ' + (data.error || 'Failed to update preference');
    }
  } catch (err) {
    messageDiv.className = 'message error';
    messageDiv.textContent = '✗ Error updating preference. Please try again.';
    console.error(err);
  }
});
</script>

<style>
.message {
  padding: 10px 12px;
  border-radius: 4px;
  font-size: 13px;
  animation: slideIn 0.3s ease;
}
.message.loading {
  background: #e3f2fd;
  color: #1565c0;
}
.message.success {
  background: #e8f5e9;
  color: #2e7d32;
  border: 1px solid #81c784;
}
.message.error {
  background: #ffebee;
  color: #c62828;
  border: 1px solid #ef9a9a;
}
@keyframes slideIn {
  from { opacity: 0; transform: translateY(-5px); }
  to { opacity: 1; transform: translateY(0); }
}
</style>
```

---

## Phase 4: Admin Dashboard Integration

### File: `app/views/admin/index.php`

**Location:** After other admin sections (around line 700-800, before closing PHP)

**Add this new section:**

```php
<!-- ═══════════════════════════════════════════════════════════
     NEWSLETTER MANAGEMENT SECTION
     ═══════════════════════════════════════════════════════════ -->

<?php if ($adminSection === 'newsletter'): ?>

<div class="panel">
  <div class="head-row">
    <h2>Newsletter Management</h2>
    <div>
      <button id="export-newsletter" class="primary-btn" style="gap: 8px;">
        📥 Download Subscribers (.xlsx)
      </button>
    </div>
  </div>

  <!-- Statistics -->
  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div class="stat-card">
      <div style="font-size: 11px; font-weight: 800; text-transform: uppercase; color: var(--muted); margin-bottom: 8px;">Total Subscribers</div>
      <div id="subscriber-total" style="font-size: 28px; font-weight: 700; color: var(--primary);">-</div>
    </div>
    <div class="stat-card">
      <div style="font-size: 11px; font-weight: 800; text-transform: uppercase; color: var(--muted); margin-bottom: 8px;">Last Updated</div>
      <div id="last-updated" style="font-size: 14px; color: var(--text-primary);">Now</div>
    </div>
  </div>

  <!-- Subscriber Table -->
  <table class="table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Email</th>
        <th>Institution</th>
        <th>Focus Area</th>
        <th>Subscribed</th>
      </tr>
    </thead>
    <tbody id="newsletter-tbody">
      <tr><td colspan="5" style="text-align: center; padding: 20px; color: var(--muted);">Loading...</td></tr>
    </tbody>
  </table>
</div>

<?php endif; ?>
```

**In the admin nav (find the section tabs, around line 736):**

Add this nav item:

```html
<a class="admin-tab <?= $adminSection==='newsletter' ? 'active' : '' ?>" 
   href="index.php?page=admin&section=newsletter">
  📧 Newsletter
</a>
```

**At the end of the admin page (before closing PHP tag):**

Add this JavaScript:

```html
<script>
// Load newsletter data if on newsletter section
function loadNewsletterData() {
  fetch('/api/admin-newsletter.php?action=list')
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        // Update count
        document.getElementById('subscriber-total').textContent = data.total;
        document.getElementById('last-updated').textContent = new Date().toLocaleString();
        
        // Populate table
        const tbody = document.getElementById('newsletter-tbody');
        if (data.subscribers.length === 0) {
          tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: var(--muted);">No subscribers yet</td></tr>';
          return;
        }
        
        tbody.innerHTML = data.subscribers.map(sub => `
          <tr>
            <td><strong>${sub.name}</strong></td>
            <td><a href="mailto:${sub.email}">${sub.email}</a></td>
            <td>${sub.institution}</td>
            <td>${sub.focus_area}</td>
            <td>${new Date(sub.subscribed_at).toLocaleDateString()}</td>
          </tr>
        `).join('');
      }
    })
    .catch(err => {
      console.error('Error loading subscribers:', err);
      document.getElementById('newsletter-tbody').innerHTML = 
        '<tr><td colspan="5" style="text-align: center; color: red;">Error loading data</td></tr>';
    });
}

// Export function
document.getElementById('export-newsletter')?.addEventListener('click', function() {
  this.disabled = true;
  this.textContent = '⏳ Exporting...';
  
  window.location.href = '/api/admin-newsletter.php?action=export';
  
  setTimeout(() => {
    this.disabled = false;
    this.textContent = '📥 Download Subscribers (.xlsx)';
  }, 2000);
});

// Load on page load
if (document.getElementById('export-newsletter')) {
  loadNewsletterData();
  // Refresh every 30 seconds
  setInterval(loadNewsletterData, 30000);
}
</script>
```

---

## Testing Checklist

### Registration Test
- [ ] Open registration form
- [ ] Newsletter checkbox appears
- [ ] Can check/uncheck it
- [ ] Submit registration
- [ ] Verify in phpmyadmin: newsletter_subscribers table has new row

### Profile Update Test
- [ ] Log in as researcher
- [ ] Navigate to profile
- [ ] Newsletter toggle appears
- [ ] Toggle it ON
- [ ] Success message appears
- [ ] Toggle it OFF
- [ ] Success message appears
- [ ] Check database: newsletter_subscribed status updated

### Admin Export Test
- [ ] Log in as admin
- [ ] Go to Admin > Newsletter
- [ ] Subscriber count displays correctly
- [ ] Click "Download Subscribers"
- [ ] Excel file downloads with correct filename
- [ ] Open Excel file:
  - [ ] Headers are correct
  - [ ] All subscribed users appear
  - [ ] No duplicates
  - [ ] Dates are readable

### Data Integrity Test
- [ ] Subscribe 5 users
- [ ] Download Excel → lists 5
- [ ] Unsubscribe 1 user
- [ ] Download Excel → lists 4 (not 5)
- [ ] Resubscribe that user
- [ ] Download Excel → lists 5 again

---

## API Usage Examples

### User Updates Preference (JavaScript)

```javascript
const response = await fetch('/api/newsletter-preference.php', {
  method: 'POST',
  body: new FormData(document.querySelector('form'))
});
const data = await response.json();
console.log(data.message);
```

### Admin Fetches Subscribers (curl)

```bash
curl -H "Cookie: [SESSION_COOKIE]" \
  "http://localhost/api/admin-newsletter.php?action=list" \
  | jq .subscribers
```

### Admin Exports Excel (curl)

```bash
curl -H "Cookie: [SESSION_COOKIE]" \
  "http://localhost/api/admin-newsletter.php?action=export" \
  > subscribers.xlsx
```

---

## File Modifications Summary

| File | Changes | Lines |
|------|---------|-------|
| `app/views/researchers/index.php` | Add registration checkbox + profile toggle | ~100 |
| `app/views/admin/index.php` | Add newsletter management section | ~80 |
| `public/api/newsletter-preference.php` | NEW - Update API | 62 |
| `public/api/admin-newsletter.php` | NEW - Admin API + Excel export | 273 |
| `app/core/schema_updates.php` | Already has `apply_newsletter_schema()` | 0 (done) |

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Newsletter section doesn't appear in admin | Verify `$adminSection` is set correctly in admin/index.php |
| Excel download fails | Check file permissions, ensure `/tmp` is writable |
| API returns 403 | Verify admin login, check CSRF token |
| Subscriber count wrong | Run `SELECT COUNT(*) FROM newsletter_subscribers WHERE status='active'` |
| Toggle doesn't work on profile | Check browser console for JS errors, verify CSRF input exists |

---

## Database Verification

To verify newsletter tables exist:

```sql
-- Check newsletter tables
SHOW TABLES LIKE 'newsletter%';

-- Count active subscribers
SELECT COUNT(*) as total FROM newsletter_subscribers WHERE status='active';

-- View recent subscribers
SELECT email, subscribed_at FROM newsletter_subscribers ORDER BY subscribed_at DESC LIMIT 10;
```

---

## Next Steps

1. **Read** `NEWSLETTER_IMPLEMENTATION_PLAN.md` for full context
2. **Update** `app/views/researchers/index.php` with Phases 2-3 code
3. **Update** `app/views/admin/index.php` with Phase 4 code
4. **Test** using the Testing Checklist above
5. **Commit** with message: "Newsletter feature - Phase 2-4 complete"
6. **Push** to GitHub
7. **Deploy** to AWS with updated database

---

**Questions?** All code is ready to integrate. Follow the file locations and code blocks above for each phase.

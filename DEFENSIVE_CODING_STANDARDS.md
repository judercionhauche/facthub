# Defensive Coding Standards for FACT Hub
**For MIT Institution Security**

## Golden Rule: Never Expose Protected Content

**If it's protected, it must be IMPOSSIBLE for unauthenticated users to see it.**

---

## Principle 1: Server-Side Auth (Not Client-Side)

❌ **WRONG - Client-Side Check Only**
```javascript
// DO NOT DO THIS
if (userRole === 'admin') {
    showAdminPanel();
}
```
User can open DevTools and set `userRole = 'admin'` → VULNERABLE

✅ **CORRECT - Server-Side Check**
```php
// ALWAYS DO THIS
<?php if (is_admin()): ?>
    <div class="admin-panel">
        <!-- Content only renders if admin -->
    </div>
<?php endif; ?>
```
HTML never renders if not admin → SECURE

---

## Principle 2: Never Rely on CSS to Hide Protected Content

❌ **WRONG**
```php
<?php /* All users see this */ ?>
<div id="admin-panel" style="display: none;">
    Secret admin content
</div>
```
User can modify CSS to show it → VULNERABLE

✅ **CORRECT**
```php
<?php if (is_admin()): ?>
    <div class="admin-panel">
        Secret admin content
    </div>
<?php endif; ?>
```
HTML doesn't exist unless admin → SECURE

**CSS Rule:** Use CSS only as a BACKUP layer, never as primary protection.

---

## Principle 3: Multiple Validation Layers

Each component must validate independently. Never assume parent validated.

✅ **Template Layout Protection**
```php
// app/views/layout/header.php
<?php if (is_logged_in()): ?>
    <nav class="topnav"><!-- Navigation --></nav>
<?php endif; ?>
```

✅ **Page-Level Protection**
```php
// app/views/researchers/index.php
<?php require_login(); ?>
```

✅ **Route-Level Protection**
```php
// public/index.php
if (!in_array($page, $publicPages) && !is_logged_in()) {
    redirect_to('login');
}
```

**Result:** Protected content cannot leak through any single failure point.

---

## Principle 4: Whitelist Public Pages

❌ **WRONG - Blacklist Approach**
```php
$privatePages = ['admin', 'messages', 'profile'];
if (in_array($page, $privatePages) && !is_logged_in()) {
    redirect_to('login');
}
// New pages added later might forget protection
```
Risk: Developer adds new page, forgets blacklist entry → VULNERABLE

✅ **CORRECT - Whitelist Approach**
```php
$publicPages = ['login', 'register', 'forgot', 'reset', 'verify'];
if (!in_array($page, $publicPages) && !is_logged_in()) {
    redirect_to('login');
}
// All new pages are protected by default
```
Default: PROTECTED unless explicitly whitelisted → SECURE

---

## Principle 5: Session Validation Every Request

❌ **WRONG**
```php
if (isset($_SESSION['user_id'])) {
    // Assume user is valid
}
```
Doesn't check if user still exists, is deleted, or token is valid → VULNERABLE

✅ **CORRECT**
```php
if (is_logged_in() && !check_session_validity($conn)) {
    expire_session();
    redirect_to('login');
}
// Validates user exists, not deleted, token matches
```
Every request re-validates session → SECURE

---

## Principle 6: Fail Secure

❌ **WRONG - Fail Open**
```php
try {
    $user = fetch_user($_SESSION['user_id']);
} catch (Exception $e) {
    // Continue anyway
    show_page_content();
}
```
If fetch fails, user still sees content → VULNERABLE

✅ **CORRECT - Fail Secure**
```php
try {
    $user = fetch_user($_SESSION['user_id']);
} catch (Exception $e) {
    // Reject the request
    redirect_to('login');
}
```
If validation fails, deny access → SECURE

---

## Principle 7: Defensive CSS (Backup Layer)

✅ **Sidebar Protection**
```css
/* Primary: PHP conditional prevents HTML rendering */
/* Backup: CSS hides if HTML somehow leaks */
.auth-wrap .sidebar {
    display: none !important;
}
```

Use `!important` to prevent CSS override attempts.

---

## Principle 8: No Secrets in Frontend

❌ **WRONG**
```php
<script>
    const API_KEY = '<?= $secretKey ?>';
    const USER_ID = <?= $_SESSION['user_id'] ?>;
</script>
```
User can read JavaScript source → VULNERABLE

✅ **CORRECT**
```php
<!-- No secrets in frontend -->
<script>
    // Make API call - server validates
    fetch('index.php?page=api&action=getData')
        .then(response => response.json())
        .then(data => display(data));
</script>

// Backend validates authentication
<?php
if (!is_logged_in()) {
    http_response_code(401);
    exit;
}
// Only then return data
```
All validation server-side → SECURE

---

## Principle 9: CSRF Protection for All Mutations

❌ **WRONG**
```html
<a href="index.php?action=delete&id=123">Delete</a>
```
Can be exploited by CSRF attack from another site → VULNERABLE

✅ **CORRECT**
```html
<form method="POST">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" value="123">
    <button type="submit">Delete</button>
</form>
```
Token validation prevents CSRF → SECURE

---

## Principle 10: Rate Limiting on Auth

✅ **Login Rate Limiting**
```php
$rateLimiter = new RateLimiter($conn);
if (!$rateLimiter->check('login_' . $ip, 10, 900)) {
    // 10 attempts per 15 minutes
    reject_login('Too many attempts');
}
```

✅ **Registration Rate Limiting**
```php
if (!$rateLimiter->check('register_' . $ip, 5, 3600)) {
    // 5 registrations per hour per IP
    reject_registration('Too many registrations');
}
```

Prevents brute force and mass account creation → SECURE

---

## Code Review Checklist

Before merging ANY code, verify:

- [ ] No protected content rendered outside `if (is_logged_in())` conditional
- [ ] All sensitive pages start with `require_login()` or `require_admin()`
- [ ] No sensitive data in JavaScript/frontend
- [ ] All forms have CSRF tokens
- [ ] All mutations (POST/DELETE) validated server-side
- [ ] Page routing uses whitelist for public pages
- [ ] Session validation called on protected actions
- [ ] Error messages don't leak system info
- [ ] Defensive CSS backup rules for protected components
- [ ] No hardcoded credentials or secrets

---

## Testing Checklist

For each protected page:

- [ ] Test accessing without login → Redirects to login
- [ ] Test with fake session cookie → Rejected
- [ ] Test after logout → Access denied
- [ ] Test after account deletion → Access denied  
- [ ] Test after account deactivation → Access denied
- [ ] Test API endpoint without auth → 401 response
- [ ] Test API endpoint with expired session → 401 response
- [ ] Test navigation not visible when logged out
- [ ] Test CSS cannot override visibility
- [ ] Test JavaScript cannot create protected elements

---

## Examples: Right vs Wrong

### Example 1: Protected Page

❌ **WRONG**
```php
// admin/index.php
<h1>Admin Panel</h1>
<div id="admin-content" style="display: none;">
    Secret data
</div>
<script>
    if (localStorage.getItem('isAdmin') === 'true') {
        document.getElementById('admin-content').style.display = 'block';
    }
</script>
```
- No server validation
- Relies on CSS and localStorage
- VULNERABLE

✅ **CORRECT**
```php
// admin/index.php
<?php require_admin(); ?>
<h1>Admin Panel</h1>
<div class="admin-content">
    Secret data (only rendered if admin)
</div>
```
- Server-side `require_admin()` check
- HTML never renders if not admin
- Multiple protection layers
- SECURE

---

### Example 2: Protected Navigation

❌ **WRONG**
```php
// app/views/layout/header.php
<nav class="topnav">
    <a href="?page=admin">Admin</a>
    <a href="?page=messages">Messages</a>
    <a href="?page=profile">Profile</a>
</nav>

<!-- CSS tries to hide based on class -->
<style>
    .logged-out .topnav { display: none; }
</style>
```
- CSS-based protection (weak)
- Navigation links exist in HTML
- VULNERABLE

✅ **CORRECT**
```php
// app/views/layout/header.php
<?php if (is_logged_in()): ?>
    <nav class="topnav">
        <a href="?page=researchers">Researchers</a>
        <a href="?page=funding">Funding</a>
        <?php if (is_admin()): ?>
        <a href="?page=admin">Admin</a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
```
- PHP conditional (strong)
- Navigation HTML never renders if not logged in
- Admin link only renders if admin
- SECURE

---

### Example 3: API Protection

❌ **WRONG**
```php
// public/index.php
if ($page === 'api') {
    $user = $_SESSION['user'] ?? null;
    // Some actions allowed for non-users
    if ($action === 'get_public_data') {
        return get_data(); // No validation
    }
    if (is_logged_in()) {
        if ($action === 'get_private_data') {
            return get_private_data();
        }
    }
}
```
- Inconsistent validation
- Public endpoint exists
- VULNERABLE

✅ **CORRECT**
```php
// public/index.php
if ($page === 'api') {
    // ALL API endpoints require auth
    if (!is_logged_in()) {
        http_response_code(401);
        echo json_encode(['error' => 'unauthenticated']);
        exit;
    }
    
    // Now validate action
    if ($action === 'get_data') {
        return get_data();
    }
}
```
- All API endpoints protected
- Consistent validation
- SECURE

---

## Summary

**Defensive Coding = SECURE by Default**

- ✅ Protect server-side, not client-side
- ✅ Multiple validation layers
- ✅ Whitelist public pages
- ✅ Validate every request
- ✅ Fail secure
- ✅ Use CSS only as backup
- ✅ No secrets in frontend
- ✅ CSRF protect all mutations
- ✅ Rate limit auth endpoints
- ✅ Test thoroughly

**Remember:** The attacker only needs one way in. You need to block ALL ways in.

---

**Last Updated:** May 28, 2026  
**Review Cycle:** Quarterly  
**Owner:** Security Team

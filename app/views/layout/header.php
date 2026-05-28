<?php
$user = current_user();
$_msgUnread = 0;
if (is_logged_in()) {
    $_em = $user['email'];
    $_mq = $conn->prepare("SELECT COUNT(*) c FROM messages WHERE sender_email != ? AND is_read = 0 AND is_deleted = 0 AND (recipient_type = 'network' OR recipient_email = ?)");
    $_mq->bind_param('ss', $_em, $_em); $_mq->execute();
    $_msgUnread = (int)($_mq->get_result()->fetch_assoc()['c'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <title>FACT Alliance Hub</title>
    <link rel="stylesheet" href="assets/style.css">
    <script src="assets/app.js"></script>

    <!-- Global CMD+K search shortcut -->
    <script>
    document.addEventListener('keydown', (e) => {
        if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
            e.preventDefault();
            window.location.href = 'index.php?page=search';
        }
    });
    </script>

    <?php if (is_logged_in()): ?>
    <script>
    // Session timeout warning + cross-tab logout sync
    (function() {
        const SESSION_TIMEOUT = 30 * 60;        // 30 minutes in seconds
        const WARNING_BEFORE = 5 * 60;          // Warn 5 min before timeout
        let warningShown = false;
        let timeoutHandle = null;

        // Listen for logout events from other tabs (via storage events)
        window.addEventListener('storage', (e) => {
            if (e.key === 'logout_event' && e.newValue === 'true') {
                // Another tab logged out — refresh to redirect to login
                window.location.reload();
            }
            if (e.key === 'session_warning_shown' && e.newValue === 'true') {
                // Another tab showed warning — sync to this tab too
                warningShown = true;
            }
        });

        function showLogoutWarning() {
            if (warningShown) return;
            warningShown = true;
            localStorage.setItem('session_warning_shown', 'true');

            // Create modal overlay
            const modal = document.createElement('div');
            modal.id = 'session-warning-modal';
            modal.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center;
                z-index: 10000;
            `;
            modal.innerHTML = `
                <div style="background: white; padding: 30px; border-radius: 8px; max-width: 400px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <h2 style="margin: 0 0 10px 0; font-size: 20px;">Session Expiring Soon</h2>
                    <p style="color: #666; margin: 10px 0;">Your session will expire in 5 minutes due to inactivity.</p>
                    <p style="color: #666; margin: 10px 0;">Would you like to continue working?</p>
                    <div style="display: flex; gap: 10px; justify-content: center; margin-top: 20px;">
                        <button id="continue-session" style="padding: 10px 20px; background: #1a6b5a; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: 600;">
                            Continue Session
                        </button>
                        <button id="logout-now" style="padding: 10px 20px; background: #dc2626; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: 600;">
                            Logout Now
                        </button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);

            document.getElementById('continue-session').onclick = () => {
                modal.remove();
                warningShown = false;
                localStorage.removeItem('session_warning_shown');
                resetTimeout();  // Reset the timeout
                // Make a request to refresh activity
                fetch('index.php?page=ping');
            };

            document.getElementById('logout-now').onclick = () => {
                localStorage.setItem('logout_event', 'true');
                window.location.href = 'index.php?page=logout';
            };
        }

        function resetTimeout() {
            clearTimeout(timeoutHandle);
            timeoutHandle = setTimeout(() => {
                showLogoutWarning();
                setTimeout(() => {
                    // Hard logout after another 5 minutes
                    localStorage.setItem('logout_event', 'true');
                    window.location.href = 'index.php?page=logout';
                }, WARNING_BEFORE * 1000);
            }, (SESSION_TIMEOUT - WARNING_BEFORE) * 1000);
        }

        // Start timeout on page load
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', resetTimeout);
        } else {
            resetTimeout();
        }

        // Reset timeout on any user activity
        ['click', 'keypress', 'scroll', 'touchstart', 'mousemove'].forEach(event => {
            document.addEventListener(event, resetTimeout, true);
        });

        // Cleanup on logout (other tabs see this via storage event)
        window.addEventListener('unload', () => {
            if (document.body.classList.contains('logged-in')) {
                localStorage.setItem('logout_event', 'true');
            }
        });
    })();
    </script>
    <?php endif; ?>
</head>
<body class="<?= is_logged_in() ? 'logged-in' : 'logged-out' ?>">
<div class="site-shell">
    <!-- Mobile Navigation Drawer -->
    <?php require __DIR__ . '/../components/nav-drawer.php'; ?>

    <header class="topbar">
        <div class="topbar-inner">
            <div class="brand-wrap">
                <?php if (is_logged_in()): ?>
                <button id="hamburger" onclick="document.getElementById('nav-drawer').style.display='flex'"
                        style="display:none;background:none;border:none;font-size:24px;cursor:pointer;padding:8px;margin-right:8px">☰</button>
                <?php endif; ?>
                <div style="display: flex; align-items: center; gap: 16px;">
                    <img src="assets/fact-alliance-logo.png" alt="FACT Alliance" class="brand-logo" style="height: 40px; width: auto;">
                    <div style="width: 1px; height: 28px; background: linear-gradient(180deg, rgba(26,107,90,0.2) 0%, rgba(26,107,90,0.6) 50%, rgba(26,107,90,0.2) 100%);"></div>
                    <img src="assets/Massachusetts_Institute_of_Technology-Logo.wine.png" alt="MIT" style="height: 32px; width: auto; filter: brightness(0.9);">
                </div>
            </div>
            <?php if (is_logged_in()): ?>
            <nav class="topnav">
                <a href="index.php?page=researchers"  class="<?= $page === 'researchers'  ? 'active' : '' ?>">Researchers</a>
                <a href="index.php?page=funding"      class="<?= $page === 'funding'      ? 'active' : '' ?>">Funding</a>
                <a href="index.php?page=matching"     class="<?= $page === 'matching'     ? 'active' : '' ?>">Matching</a>
                <a href="index.php?page=search"       class="<?= $page === 'search'       ? 'active' : '' ?>">Search</a>
                <a href="index.php?page=institutions" class="<?= $page === 'institutions' ? 'active' : '' ?>">Institutions</a>
                <a href="index.php?page=messages" class="<?= $page === 'messages' ? 'active' : '' ?>" style="position:relative">
                    Messages
                    <span id="msg-nav-badge" style="display:<?= $_msgUnread > 0 ? 'inline-flex' : 'none' ?>;align-items:center;justify-content:center;min-width:17px;height:17px;background:#b54646;color:#fff;border-radius:999px;font-size:10px;font-weight:800;padding:0 4px;margin-left:4px;vertical-align:middle;line-height:1"><?= min($_msgUnread, 99) ?></span>
                </a>
                <?php if (is_admin()): ?>
                <a href="index.php?page=admin" class="<?= $page === 'admin' ? 'active' : '' ?>" style="color:var(--primary)">⚙ Admin</a>
                <?php endif; ?>
            </nav>
            <div class="userbox">
                <span class="role-badge role-badge-<?= h($user['role']) ?>"><?= h(ucfirst($user['role'])) ?></span>
                <span><?= h($user['name'] ?: $user['email']) ?></span>
                <a href="index.php?page=profile" class="ghost-btn" title="View Profile">Profile</a>
                <a class="ghost-btn" href="index.php?page=logout">Logout</a>
            </div>
            <?php endif; ?>
        </div>
    </header>

    <div class="page-wrap <?= is_logged_in() ? '' : 'auth-wrap' ?>">
        <?php if (is_logged_in()): ?>
        <aside class="sidebar">
            <div class="panel sidebar-panel">
                <div class="sidebar-title">FACT TOOLS</div>
                <a href="index.php?page=researchers"  class="side-link <?= $page === 'researchers'  ? 'active' : '' ?>">Researchers</a>
                <a href="index.php?page=funding"      class="side-link <?= $page === 'funding'      ? 'active' : '' ?>">Funding</a>
                <a href="index.php?page=matching"     class="side-link <?= $page === 'matching'     ? 'active' : '' ?>">Matching</a>
                <a href="index.php?page=search"       class="side-link <?= $page === 'search'       ? 'active' : '' ?>">Search</a>
                <a href="index.php?page=institutions" class="side-link <?= $page === 'institutions' ? 'active' : '' ?>">Institutions</a>
                <a href="index.php?page=messages" class="side-link <?= $page === 'messages' ? 'active' : '' ?>" style="display:flex;align-items:center;justify-content:space-between">
                    <span>Messages</span>
                    <span id="msg-side-badge" style="display:<?= $_msgUnread > 0 ? 'inline-flex' : 'none' ?>;align-items:center;justify-content:center;min-width:18px;height:18px;background:#b54646;color:#fff;border-radius:999px;font-size:10px;font-weight:800;padding:0 4px;line-height:1"><?= min($_msgUnread, 99) ?></span>
                </a>
                <a href="index.php?page=profile" class="side-link <?= $page === 'profile' ? 'active' : '' ?>" style="margin-top:8px;border-top:1px solid var(--line);padding-top:12px">My Profile</a>
                <?php if (is_admin()): ?>
                <a href="index.php?page=admin" class="side-link <?= $page === 'admin' ? 'active' : '' ?>" style="margin-top:8px">⚙ Admin Panel</a>
                <?php endif; ?>
                <div class="sidebar-tip">Use <strong>topic</strong> + <strong>geography</strong> tags to connect researchers to funding calls.</div>
            </div>
        </aside>
        <?php endif; ?>
        <main class="main-area">
<?php if (is_logged_in() && $page !== 'messages'): ?>
<script>
(function(){
  var POLL_MS = 45000;
  function updateBadges(n){
    ['msg-nav-badge','msg-side-badge'].forEach(function(id){
      var el=document.getElementById(id);
      if(!el) return;
      el.textContent = n > 0 ? Math.min(n,99) : '';
      el.style.display = n > 0 ? 'inline-flex' : 'none';
    });
    // Update browser tab title count
    var base = document.title.replace(/^\(\d+\) /,'');
    document.title = n > 0 ? '('+Math.min(n,99)+') '+base : base;
  }
  function poll(){
    fetch('index.php?page=ping',{credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(d){updateBadges(d.unread||0);})
      .catch(function(){});
  }
  setInterval(poll, POLL_MS);
}());
</script>
<?php endif; ?>

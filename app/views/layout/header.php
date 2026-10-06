<?php
$user = current_user();
$_isAuthPage = in_array($page, ['login', 'register', 'forgot', 'verify']);
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Work+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,500;1,600&display=swap" rel="stylesheet">
    <?php
    // Cache-bust static assets on every deploy: the file's mtime changes on git pull,
    // so browsers fetch the new version instead of serving a stale cached copy
    $assetsDir = dirname(__DIR__, 3) . '/public/assets';
    $cssV = @filemtime($assetsDir . '/style.css') ?: 1;
    $jsV  = @filemtime($assetsDir . '/app.js') ?: 1;
    ?>
    <link rel="stylesheet" href="assets/style.css?v=<?= $cssV ?>">
    <script src="assets/app.js?v=<?= $jsV ?>"></script>

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
                <?php if (is_logged_in() && !$_isAuthPage): ?>
                <button id="hamburger" onclick="document.getElementById('nav-drawer').style.display='flex'"
                        style="display:none;background:none;border:none;font-size:24px;cursor:pointer;padding:8px;margin-right:8px">☰</button>
                <?php endif; ?>
                <a href="https://jwafs.mit.edu/alliance" target="_blank" rel="noopener noreferrer" style="display: flex; align-items: center; text-decoration: none; transition: opacity 0.2s;" onmouseover="this.style.opacity='0.8'" onmouseout="this.style.opacity='1'" title="FACT Alliance - MIT J-WAFS Initiative">
                    <img src="assets/fact-alliance-logo.png" alt="FACT Alliance" class="brand-logo" style="height: 36px; width: auto;">
                </a>
            </div>
            <?php if (is_logged_in() && !$_isAuthPage): ?>
            <div class="userbox">
                <span class="role-badge role-badge-<?= h($user['role']) ?>"><?= h(ucfirst($user['role'])) ?></span>
                <span><?= h($user['name'] ?: $user['email']) ?></span>
                <a href="index.php?page=profile" class="ghost-btn" title="View Profile">Profile</a>
                <a class="ghost-btn" href="index.php?page=logout">Logout</a>
            </div>
            <?php endif; ?>
        </div>
    </header>

    <div class="page-wrap <?= (!is_logged_in() || $_isAuthPage) ? 'auth-wrap' : '' ?>">
        <?php if (is_logged_in() && !$_isAuthPage): ?>
        <aside class="sidebar">
            <div class="panel sidebar-panel">
                <div class="sidebar-title">FACT TOOLS</div>
                <a href="index.php?page=impact"      class="side-link <?= $page === 'impact'      ? 'active' : '' ?>"><span class="side-ico" aria-hidden="true"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M2 13.5h12"/><rect x="3" y="8" width="2.6" height="4"/><rect x="6.7" y="5" width="2.6" height="7"/><rect x="10.4" y="2.5" width="2.6" height="9.5"/></svg></span><span>Impact</span></a>
                <a href="index.php?page=researchers"  class="side-link <?= $page === 'researchers'  ? 'active' : '' ?>"><span class="side-ico" aria-hidden="true"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false"><circle cx="6" cy="5.4" r="2.3"/><path d="M1.9 13.2c0-2.2 1.8-3.6 4.1-3.6s4.1 1.4 4.1 3.6"/><circle cx="11.8" cy="6.1" r="1.8"/><path d="M11.4 9.8c1.8.1 3 1.4 3 3.4"/></svg></span><span>Researchers</span></a>
                <a href="index.php?page=funding"      class="side-link <?= $page === 'funding'      ? 'active' : '' ?>"><span class="side-ico" aria-hidden="true"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false"><circle cx="8" cy="8" r="6"/><path d="M8 4.6v6.8M9.9 6.1H7.2a1.3 1.3 0 0 0 0 2.6h1.6a1.3 1.3 0 0 1 0 2.6H6.1"/></svg></span><span>Funding</span></a>
                <a href="index.php?page=matching"     class="side-link <?= $page === 'matching'     ? 'active' : '' ?>"><span class="side-ico" aria-hidden="true"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M2.5 5.2h7.3l-2-2M13.5 10.8H6.2l2 2"/><circle cx="12.6" cy="5.2" r="1.6"/><circle cx="3.4" cy="10.8" r="1.6"/></svg></span><span>Matching</span></a>
                <a href="index.php?page=search"       class="side-link <?= $page === 'search'       ? 'active' : '' ?>"><span class="side-ico" aria-hidden="true"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false"><circle cx="7.2" cy="7.2" r="4.6"/><path d="M10.6 10.6 14 14"/></svg></span><span>Search</span></a>
                <a href="index.php?page=institutions" class="side-link <?= $page === 'institutions' ? 'active' : '' ?>"><span class="side-ico" aria-hidden="true"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M1.8 6.4 8 2.6l6.2 3.8"/><path d="M3.3 6.9v6.3M6.4 6.9v6.3M9.6 6.9v6.3M12.7 6.9v6.3"/><path d="M1.8 13.4h12.4"/></svg></span><span>Institutions</span></a>
                <a href="index.php?page=messages" class="side-link <?= $page === 'messages' ? 'active' : '' ?>">
                    <span class="side-ico" aria-hidden="true"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false"><rect x="1.8" y="3.4" width="12.4" height="9.2" rx="1.6"/><path d="m2.4 4.6 5.1 3.7a.9.9 0 0 0 1 0l5.1-3.7"/></svg></span><span>Messages</span>
                    <span id="msg-side-badge" style="margin-left:auto;display:<?= $_msgUnread > 0 ? 'inline-flex' : 'none' ?>;align-items:center;justify-content:center;min-width:18px;height:18px;background:#b54646;color:#fff;border-radius:999px;font-size:10px;font-weight:800;padding:0 4px;line-height:1"><?= min($_msgUnread, 99) ?></span>
                </a>
                <a href="index.php?page=profile" class="side-link <?= $page === 'profile' ? 'active' : '' ?>" style="margin-top:8px;border-top:1px solid var(--line);padding-top:12px"><span class="side-ico" aria-hidden="true"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false"><circle cx="8" cy="5.3" r="2.6"/><path d="M2.9 13.4c0-2.6 2.3-4.3 5.1-4.3s5.1 1.7 5.1 4.3"/></svg></span><span>My Profile</span></a>
                <?php if (is_admin()): ?>
                <a href="index.php?page=admin" class="side-link <?= $page === 'admin' ? 'active' : '' ?>" style="margin-top:8px"><span class="side-ico" aria-hidden="true"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false"><circle cx="8" cy="8" r="2.1"/><path d="M12.9 9.4a1.2 1.2 0 0 0 .24 1.32l.05.04a1.4 1.4 0 1 1-2 2l-.04-.05a1.2 1.2 0 0 0-1.32-.24 1.2 1.2 0 0 0-.73 1.1v.12a1.4 1.4 0 1 1-2.8 0v-.06a1.2 1.2 0 0 0-.79-1.1 1.2 1.2 0 0 0-1.32.24l-.04.05a1.4 1.4 0 1 1-2-2l.05-.04a1.2 1.2 0 0 0 .24-1.32 1.2 1.2 0 0 0-1.1-.73h-.12a1.4 1.4 0 1 1 0-2.8h.06a1.2 1.2 0 0 0 1.1-.79 1.2 1.2 0 0 0-.24-1.32l-.05-.04a1.4 1.4 0 1 1 2-2l.04.05a1.2 1.2 0 0 0 1.32.24h.06a1.2 1.2 0 0 0 .73-1.1v-.12a1.4 1.4 0 1 1 2.8 0v.06a1.2 1.2 0 0 0 .73 1.1 1.2 1.2 0 0 0 1.32-.24l.04-.05a1.4 1.4 0 1 1 2 2l-.05.04a1.2 1.2 0 0 0-.24 1.32v.06a1.2 1.2 0 0 0 1.1.73h.12a1.4 1.4 0 1 1 0 2.8h-.06a1.2 1.2 0 0 0-1.1.73Z"/></svg></span><span>Admin Panel</span></a>
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

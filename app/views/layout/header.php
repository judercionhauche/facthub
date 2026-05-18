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
</head>
<body>
<div class="site-shell">
    <header class="topbar">
        <div class="topbar-inner">
            <div class="brand-wrap">
                <img src="assets/fact-alliance-logo.png" alt="FACT Alliance" class="brand-logo">
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

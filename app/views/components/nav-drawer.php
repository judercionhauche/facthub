<?php
// Mobile navigation drawer
if (is_logged_in()):
    $user = current_user();
?>
<div id="nav-drawer" class="nav-drawer" style="display:none">
    <nav class="nav-drawer-inner">
        <div class="nav-drawer-head">
            <h3>Menu</h3>
            <button class="nav-drawer-close" onclick="closeNavDrawer()" style="background:none;border:none;font-size:24px;cursor:pointer;padding:8px">✕</button>
        </div>
        <div class="nav-drawer-content">
            <a href="index.php?page=researchers" class="nav-drawer-link" onclick="closeNavDrawer()">Researchers</a>
            <a href="index.php?page=funding" class="nav-drawer-link" onclick="closeNavDrawer()">Funding</a>
            <a href="index.php?page=matching" class="nav-drawer-link" onclick="closeNavDrawer()">Matching</a>
            <a href="index.php?page=search" class="nav-drawer-link" onclick="closeNavDrawer()">Search</a>
            <a href="index.php?page=institutions" class="nav-drawer-link" onclick="closeNavDrawer()">Institutions</a>
            <a href="index.php?page=messages" class="nav-drawer-link" onclick="closeNavDrawer()">Messages</a>
            <?php if (is_admin()): ?>
            <a href="index.php?page=admin" class="nav-drawer-link admin" onclick="closeNavDrawer()">⚙ Admin</a>
            <?php endif; ?>
        </div>
        <div class="nav-drawer-footer">
            <a href="index.php?page=profile" class="nav-drawer-link" onclick="closeNavDrawer()">My Profile</a>
            <a href="index.php?page=logout" class="nav-drawer-link logout" onclick="closeNavDrawer()">Logout</a>
        </div>
    </nav>
</div>

<script>
function closeNavDrawer() {
    document.getElementById('nav-drawer').style.display = 'none';
}
window.addEventListener('click', function(e) {
    const drawer = document.getElementById('nav-drawer');
    if (drawer && e.target === drawer) {
        drawer.style.display = 'none';
    }
});
</script>
<?php endif; ?>

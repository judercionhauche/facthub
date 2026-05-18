<?php
// Allow unauthenticated access for registration (mode=add without login)
$mode = ($_GET['mode'] ?? $_POST['mode'] ?? '');
$isRegistering = ($mode === 'add' && !is_logged_in());

if (!$isRegistering) {
    require_login();
}

$currentUser = current_user();

$FACT_CATEGORIES = [
    'Food Security, Nutrition & Health',
    'Ecosystems & Biodiversity',
    'Governance & Innovation',
    'Markets & Trade',
    'Crosscutting Themes'
];

/* ── POST handler ─────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            $isNewRegistration = ($id === 0 && !is_logged_in());

            // Authorization checks
            if ($id === 0 && is_logged_in() && !is_admin()) {
                set_flash('error', 'Only admins can add researchers directly.');
                redirect_to('researchers');
            }
            if ($id > 0 && !is_admin()) {
                $ownerCheck = $conn->prepare('SELECT email FROM researchers WHERE id = ? LIMIT 1');
                $ownerCheck->bind_param('i', $id);
                $ownerCheck->execute();
                $ownerRow = $ownerCheck->get_result()->fetch_assoc();
                if (!$ownerRow || strtolower($ownerRow['email']) !== strtolower($currentUser['email'])) {
                    set_flash('error', 'You can only edit your own profile.');
                    redirect_to('researchers');
                }
            }

            $first       = trim($_POST['first_name'] ?? '');
            $last        = trim($_POST['last_name']  ?? '');
            $email       = trim($_POST['email']      ?? '');
            $institution = trim($_POST['institution']?? '');
            $department  = trim($_POST['department'] ?? '');
            $title       = trim($_POST['title']      ?? '');
            $bio         = trim($_POST['bio']        ?? '');

            // Multi-category: stored pipe-separated
            $focusAreaArr = array_values(array_filter(array_map('trim', (array)($_POST['focus_area'] ?? []))));
            $focusArea    = implode('|', $focusAreaArr);

            $focusDetail = '';
            if (isset($_POST['focus_area_detail'])) {
                if (is_array($_POST['focus_area_detail'])) {
                    $focusDetail = implode(', ', array_map('trim', $_POST['focus_area_detail']));
                } else {
                    $focusDetail = trim($_POST['focus_area_detail']);
                }
            }

            $topics       = trim($_POST['topics']    ?? '');
            $geography    = trim($_POST['geography'] ?? '');
            $coAdvising   = isset($_POST['co_advising']) ? 1 : 0;
            $coDetails    = trim($_POST['co_advising_details'] ?? '');
            $profileUrl   = trim($_POST['profile_url']         ?? '');
            $websiteUrl   = trim($_POST['website_url']          ?? '');
            $orcidId      = trim($_POST['orcid_id']             ?? '');
            $googleScholarUrl = trim($_POST['google_scholar_url'] ?? '');
            $notifyMatches    = isset($_POST['notify_matches']) ? 1 : 0;

            if ($first === '' || $last === '') {
                set_flash('error', 'First and last name are required.');
                if ($isNewRegistration) {
                    redirect_to('researchers', ['mode' => 'add']);
                } else {
                    redirect_to('researchers');
                }
            }

            // NEW REGISTRATION: create user account + researcher profile
            if ($isNewRegistration) {
                $password = $_POST['password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';

                if ($password === '' || $confirmPassword === '') {
                    set_flash('error', 'Password is required.');
                    redirect_to('researchers', ['mode' => 'add']);
                }
                if ($password !== $confirmPassword) {
                    set_flash('error', 'Passwords do not match.');
                    redirect_to('researchers', ['mode' => 'add']);
                }
                if (strlen($password) < 8) {
                    set_flash('error', 'Password must be at least 8 characters.');
                    redirect_to('researchers', ['mode' => 'add']);
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    set_flash('error', 'Please enter a valid email address.');
                    redirect_to('researchers', ['mode' => 'add']);
                }

                // Check if user already exists
                $checkUser = $conn->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?)');
                if (!$checkUser) throw new Exception('Prepare check email failed: ' . $conn->error);
                $checkUser->bind_param('s', $email);
                if (!$checkUser->execute()) throw new Exception('Error checking email: ' . $checkUser->error);
                if ($checkUser->get_result()->num_rows > 0) {
                    set_flash('error', 'This email is already registered.');
                    redirect_to('researchers', ['mode' => 'add']);
                }

                // Create user account
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $userStmt = $conn->prepare('INSERT INTO users (email, password, name, role, status) VALUES (?, ?, ?, ?, ?)');
                if (!$userStmt) throw new Exception('Prepare user failed: ' . $conn->error);
                $role = 'researcher';
                $status = 'unverified';
                $fullName = trim("$first $last");
                $userStmt->bind_param('sssss', $email, $passwordHash, $fullName, $role, $status);
                if (!$userStmt->execute()) {
                    throw new Exception('Error creating account: ' . $userStmt->error);
                }
                $userId = $conn->insert_id;

                // Create email verification token
                $token = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', time() + 86400);
                $evStmt = $conn->prepare('INSERT INTO email_verifications (email, token, expires_at) VALUES (?, ?, ?)');
                if (!$evStmt) throw new Exception('Prepare email_verifications failed: ' . $conn->error);
                $evStmt->bind_param('sss', $email, $token, $expiresAt);
                if (!$evStmt->execute()) {
                    throw new Exception('Error creating verification token: ' . $evStmt->error);
                }

                // Create researcher profile linked to user
                ensure_tags($conn, $topics, 'topic');
                ensure_tags($conn, $geography, 'geography');

                $stmt = $conn->prepare('INSERT INTO researchers (user_id, first_name, last_name, email, institution, department, title, bio, focus_area, focus_area_detail, topics, geography, co_advising, co_advising_details, profile_url, website_url, orcid_id, google_scholar_url, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                if (!$stmt) throw new Exception('Prepare researchers failed: ' . $conn->error);
                $status_researcher = 'active';
                $stmt->bind_param('issssssssssisssssss', $userId, $first, $last, $email, $institution, $department, $title, $bio, $focusArea, $focusDetail, $topics, $geography, $coAdvising, $coDetails, $profileUrl, $websiteUrl, $orcidId, $googleScholarUrl, $status_researcher);
                if (!$stmt->execute()) {
                    throw new Exception('Error creating researcher profile: ' . $stmt->error);
                }

                // Generate AI summary immediately
                $newResearcherId = $conn->insert_id;
                generate_researcher_summary($conn, $newResearcherId);

                // Send verification email
                $mailCfg = require __DIR__ . '/../../config/mail.php';
                $appUrl = rtrim($mailCfg['app_url'] ?? 'http://localhost/fact_hub2/public', '/');
                $verifyUrl = $appUrl . '/index.php?page=verify&token=' . urlencode($token);
                send_notification_email($email, 'Verify your FACT Alliance Hub account',
                    mail_tpl_verify_email($verifyUrl, $first));

                audit($conn, 'researcher_signup', ['type' => 'user', 'id' => $userId, 'email' => $email, 'detail' => "New researcher registration: $fullName"]);
                set_flash('success', 'Account created! Check your email to verify your account.');
                redirect_to('verify', ['e' => $email, 'pending' => '1']);
            }
            // EXISTING RESEARCHER: update profile
            else if ($id > 0) {
                $stmt = $conn->prepare('UPDATE researchers SET first_name=?, last_name=?, email=?, institution=?, department=?, title=?, bio=?, focus_area=?, focus_area_detail=?, topics=?, geography=?, co_advising=?, co_advising_details=?, profile_url=?, website_url=?, orcid_id=?, google_scholar_url=?, notify_matches=? WHERE id=?');
                if (!$stmt) throw new Exception('Prepare update failed: ' . $conn->error);
                $stmt->bind_param('sssssssssssisssssii', $first, $last, $email, $institution, $department, $title, $bio, $focusArea, $focusDetail, $topics, $geography, $coAdvising, $coDetails, $profileUrl, $websiteUrl, $orcidId, $googleScholarUrl, $notifyMatches, $id);
                if (!$stmt->execute()) throw new Exception('Error updating profile: ' . $stmt->error);
                enqueue_job($conn, 'generate_summary', ['entity_type' => 'researcher', 'entity_id' => $id]);
                set_flash('success', 'Researcher updated.');
            }
            // ADMIN ADDING RESEARCHER
            else {
                ensure_tags($conn, $topics, 'topic');
                ensure_tags($conn, $geography, 'geography');

                // Check if user account exists
                $checkUser = $conn->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
                if (!$checkUser) throw new Exception('Prepare checkUser failed: ' . $conn->error);
                $checkUser->bind_param('s', $email);
                if (!$checkUser->execute()) throw new Exception('Error checking user: ' . $checkUser->error);
                $existingUser = $checkUser->get_result()->fetch_assoc();
                $userId = null;

                // Create user account if it doesn't exist
                if (!$existingUser) {
                    $tempPassword = bin2hex(random_bytes(16));
                    $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
                    $role = 'researcher';
                    $status = 'unverified';
                    $fullName = trim("$first $last");
                    $userStmt = $conn->prepare('INSERT INTO users (email, password, name, role, status) VALUES (?, ?, ?, ?, ?)');
                    if (!$userStmt) throw new Exception('Prepare user failed: ' . $conn->error);
                    $userStmt->bind_param('sssss', $email, $passwordHash, $fullName, $role, $status);
                    if ($userStmt->execute()) {
                        $userId = $conn->insert_id;
                        // Queue verification email
                        $token = bin2hex(random_bytes(32));
                        $expiresAt = date('Y-m-d H:i:s', time() + 86400);
                        $evStmt = $conn->prepare('INSERT INTO email_verifications (email, token, expires_at) VALUES (?, ?, ?)');
                        if ($evStmt) {
                            $evStmt->bind_param('sss', $email, $token, $expiresAt);
                            @$evStmt->execute();
                        }
                    } else {
                        throw new Exception('Error creating user: ' . $userStmt->error);
                    }
                } else {
                    $userId = (int)$existingUser['id'];
                }

                // Create researcher profile
                $stmt = $conn->prepare('INSERT INTO researchers (user_id, first_name, last_name, email, institution, department, title, bio, focus_area, focus_area_detail, topics, geography, co_advising, co_advising_details, profile_url, website_url, orcid_id, google_scholar_url, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                if (!$stmt) throw new Exception('Prepare researchers failed: ' . $conn->error);
                $status_researcher = 'active';
                $stmt->bind_param('issssssssssisssssss', $userId, $first, $last, $email, $institution, $department, $title, $bio, $focusArea, $focusDetail, $topics, $geography, $coAdvising, $coDetails, $profileUrl, $websiteUrl, $orcidId, $googleScholarUrl, $status_researcher);
                if (!$stmt->execute()) throw new Exception('Error creating profile: ' . $stmt->error);
                $newResearcherId = $conn->insert_id;

                // Generate AI summary immediately
                generate_researcher_summary($conn, $newResearcherId);

                audit($conn, 'add_researcher', ['type' => 'researcher', 'id' => $newResearcherId, 'email' => $email]);
                set_flash('success', 'Researcher added.' . ($userId ? ' A verification email has been sent.' : ''));
            }
            redirect_to('researchers');
        } catch (Throwable $e) {
            error_log('[Researcher Registration Error] ' . $e->getMessage());
            set_flash('error', 'Registration error: ' . $e->getMessage());
            redirect_to('researchers', ['mode' => 'add']);
        }
    }

    if ($action === 'delete') {
        if (!is_admin()) {
            set_flash('error', 'Only admins can delete researchers.');
            redirect_to('researchers');
        }
        $id   = (int)($_POST['id'] ?? 0);
        if (!$id) {
            set_flash('error', 'Invalid researcher ID.');
            redirect_to('researchers');
        }
        try {
            // Soft delete researcher profile
            $stmt = $conn->prepare("UPDATE researchers SET status = 'deleted', deleted_at = NOW() WHERE id = ?");
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) throw new Exception('Execute failed: ' . $stmt->error);
            $researcherRows = $stmt->affected_rows;

            if ($researcherRows === 0) {
                set_flash('error', 'Researcher not found.');
                redirect_to('researchers');
            }

            // Deactivate linked user account by finding the user_id first
            $findUserStmt = $conn->prepare("SELECT user_id FROM researchers WHERE id = ? LIMIT 1");
            if (!$findUserStmt) throw new Exception('Prepare findUser failed: ' . $conn->error);
            $findUserStmt->bind_param('i', $id);
            if (!$findUserStmt->execute()) throw new Exception('Execute findUser failed: ' . $findUserStmt->error);
            $userRow = $findUserStmt->get_result()->fetch_assoc();

            if ($userRow && $userRow['user_id']) {
                $userId = $userRow['user_id'];
                $userStmt = $conn->prepare("UPDATE users SET status = 'inactive', session_token = NULL, deactivated_at = NOW() WHERE id = ?");
                if (!$userStmt) throw new Exception('Prepare user failed: ' . $conn->error);
                $userStmt->bind_param('i', $userId);
                if (!$userStmt->execute()) throw new Exception('Execute user failed: ' . $userStmt->error);
            }

            audit($conn, 'delete_researcher', ['type' => 'researcher', 'id' => $id]);
            set_flash('success', 'Researcher deleted successfully.');
        } catch (Exception $e) {
            error_log('[Researcher Delete Error] ' . $e->getMessage());
            set_flash('error', 'Failed to delete researcher: ' . $e->getMessage());
        }
        redirect_to('researchers');
    }
}

/* ── Load data ────────────────────────────────────────────────────── */
$mode   = $_GET['mode']  ?? '';
$editId = (int)($_GET['edit'] ?? 0);
$viewId = (int)($_GET['view'] ?? 0);

// Admin-only checks: admin adding researcher (not user registration)
if ($mode === 'add' && is_logged_in() && !is_admin()) {
    set_flash('error', 'Only admins can add researchers.');
    redirect_to('researchers');
}
if ($editId > 0 && !is_admin()) {
    $editCheck = $conn->prepare('SELECT email FROM researchers WHERE id = ? LIMIT 1');
    $editCheck->bind_param('i', $editId);
    $editCheck->execute();
    $editCheckRow = $editCheck->get_result()->fetch_assoc();
    if (!$editCheckRow || strtolower($editCheckRow['email']) !== strtolower($currentUser['email'])) {
        set_flash('error', 'You can only edit your own profile.');
        redirect_to('researchers');
    }
}

$topicTags   = get_all_tags($conn, 'topic');
$researchers = [];
// Try with deleted_at column first, fall back if it doesn't exist
$res = @$conn->query("SELECT * FROM researchers WHERE status = 'active' AND deleted_at IS NULL ORDER BY first_name ASC, last_name ASC");
if (!$res) {
    // Fallback if deleted_at column doesn't exist yet
    $res = @$conn->query("SELECT * FROM researchers WHERE status = 'active' ORDER BY first_name ASC, last_name ASC");
}
if (!$res) {
    // Further fallback if status column doesn't exist
    $res = @$conn->query("SELECT * FROM researchers ORDER BY first_name ASC, last_name ASC");
}
if ($res) {
    while ($row = $res->fetch_assoc()) $researchers[] = $row;
}

// Unique non-empty institutions (sorted)
$institutions = array_values(array_unique(array_filter(array_map(fn($r) => trim($r['institution'] ?? ''), $researchers))));
sort($institutions);

$editing = null;
if ($editId > 0) foreach ($researchers as $r) if ((int)$r['id'] === $editId) $editing = $r;
$viewing = null;
if ($viewId > 0) foreach ($researchers as $r) if ((int)$r['id'] === $viewId) $viewing = $r;

// Top funding matches for viewing panel
$topFundingMatches = [];
if ($viewing) {
    $tfStmt = $conn->prepare(
        'SELECT ms.score_ai, ms.score_keyword, ms.explanation,
                fc.id, fc.title, fc.funder, fc.deadline, fc.status
         FROM match_scores ms JOIN funding_calls fc ON fc.id = ms.funding_call_id
         WHERE ms.researcher_id = ? AND fc.deleted_at IS NULL
         ORDER BY COALESCE(ms.score_ai, ms.score_keyword) DESC LIMIT 5'
    );
    $tfStmt->bind_param('i', $viewing['id']); $tfStmt->execute();
    $topFundingMatches = $tfStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Load researcher AI summary for viewing panel
$researcherSummary = null;
if ($viewing) {
    $sq = $conn->prepare(
        'SELECT summary, created_at FROM ai_summaries WHERE entity_type=? AND entity_id=? LIMIT 1'
    );
    $sqType = 'researcher';
    $sq->bind_param('si', $sqType, $viewing['id']); $sq->execute();
    $researcherSummary = $sq->get_result()->fetch_assoc();
}

// "For You" recommendations: top funding matches for the logged-in researcher
$myMatches    = [];
$myResearcher = null;
if (!is_admin()) {
    $meStmt = $conn->prepare("SELECT id FROM researchers WHERE LOWER(email) = ? AND status = 'active' AND deleted_at IS NULL LIMIT 1");
    $meEmail = strtolower($currentUser['email']);
    $meStmt->bind_param('s', $meEmail); $meStmt->execute();
    $meRow = $meStmt->get_result()->fetch_assoc();
    if ($meRow) {
        $myResearcher = $meRow;
        $myStmt = $conn->prepare(
            'SELECT ms.score_ai, ms.score_keyword, ms.explanation,
                    fc.id, fc.title, fc.funder, fc.deadline, fc.status
             FROM match_scores ms JOIN funding_calls fc ON fc.id = ms.funding_call_id
             WHERE ms.researcher_id = ? AND fc.deleted_at IS NULL
             ORDER BY COALESCE(ms.score_ai, ms.score_keyword) DESC LIMIT 3'
        );
        $myStmt->bind_param('i', $meRow['id']); $myStmt->execute();
        $myMatches = $myStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
?>
<style>
.score-badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 13px; font-weight: 700; }
.ai-score { background: #eaf6f0; color: #1a6b5a; }
.kw-score { background: #f0f4f8; color: #374151; }
</style>

<?php if (!$isRegistering): ?>
<!-- ── Page head ────────────────────────────────────────────────── -->
<div class="panel page-head">
    <div class="head-row">
        <h1>Researchers</h1>
        <?php if (is_admin()): ?>
            <a class="primary-btn" href="index.php?page=researchers&mode=add">+ Add Researcher</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($myMatches): ?>
<div class="panel" style="border-left:4px solid var(--primary);padding:16px 20px">
    <div style="font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--primary);margin-bottom:10px">Your Top Funding Matches</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
    <?php foreach ($myMatches as $m):
        $score = $m['score_ai'] ?? $m['score_keyword'];
        $isAi  = $m['score_ai'] !== null; ?>
        <div style="background:#f9fbfa;border:1px solid var(--line);border-radius:10px;padding:12px 14px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                <span class="badge score-badge <?= $isAi ? 'ai-score' : 'kw-score' ?>">
                    <?= $score ?><?= $isAi ? '%' : ' pts' ?>
                </span>
                <span class="badge <?= status_class($m['status']) ?>"><?= h($m['status']) ?></span>
            </div>
            <div style="font-weight:700;font-size:14px;margin-bottom:2px"><?= h($m['title']) ?></div>
            <div class="muted" style="font-size:12px"><?= h($m['funder']) ?> · <?= h(format_deadline($m['deadline'])) ?></div>
            <?php if ($m['explanation']): ?>
            <p class="muted small" style="margin:6px 0 0;font-style:italic;font-size:12px"><?= h($m['explanation']) ?></p>
            <?php endif; ?>
            <a href="index.php?page=funding&view=<?= (int)$m['id'] ?>"
               style="display:inline-block;margin-top:8px;font-size:12px;font-weight:600;color:var(--primary)">
               View →
            </a>
        </div>
    <?php endforeach; ?>
    </div>
    <p class="muted small" style="margin-top:10px">
        <a href="index.php?page=matching">See all your matches →</a> · Based on your profile topics and geography.
    </p>
</div>
<?php endif; ?>
<?php endif; // end !$isRegistering ?>

<!-- ── Edit/Registration form ────────────────────────────────────── -->
<?php if ($mode === 'add' || $editing): ?>
<?php
$selectedCats   = array_values(array_filter(array_map('trim', explode('|', $editing['focus_area'] ?? ''))));
$selectedSubcats = array_values(array_filter(array_map('trim', explode(',', $editing['focus_area_detail'] ?? ''))));
?>
<div class="panel modalish">
    <h2><?php
        if ($editing) echo 'Edit Researcher';
        elseif ($mode === 'add' && !is_logged_in()) echo 'Register as Researcher';
        else echo 'Add Researcher';
    ?></h2>
    <form method="post" class="form-grid two">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= h($editing['id'] ?? '') ?>">
        <input type="hidden" name="mode" value="<?= h($mode) ?>">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><?php // Include CSRF token for form submission ?>

        <div><label>First name *</label><input name="first_name" value="<?= h($editing['first_name'] ?? '') ?>" required></div>
        <div><label>Last name *</label><input name="last_name" value="<?= h($editing['last_name'] ?? '') ?>" required></div>
        <div><label>Email<?= ($mode === 'add' && !$editing) ? ' *' : '' ?></label><input name="email" type="email" value="<?= h($editing['email'] ?? '') ?>"<?= ($mode === 'add' && !$editing) ? ' required' : '' ?>></div>

        <?php if ($mode === 'add' && !$editing): ?>
        <div><label>Password *</label><input name="password" type="password" id="reg-password" required placeholder="At least 8 characters"></div>
        <div>
            <label>Confirm Password *</label>
            <input name="confirm_password" type="password" id="reg-confirm-password" required placeholder="Re-enter your password">
            <div id="pwd-match-msg" style="font-size:13px;margin-top:4px;display:none"></div>
        </div>
        <?php endif; ?>

        <div><label>Institution</label><input name="institution" value="<?= h($editing['institution'] ?? '') ?>"></div>
        <div><label>Department</label><input name="department" value="<?= h($editing['department'] ?? '') ?>"></div>
        <div><label>Title</label><input name="title" value="<?= h($editing['title'] ?? '') ?>"></div>
        <div class="span-2"><label>Bio</label><textarea name="bio"><?= h($editing['bio'] ?? '') ?></textarea></div>

        <!-- Category: multi-checkbox -->
        <div class="span-2">
            <label style="display:block;margin-bottom:8px;font-weight:600;">Category <span style="font-weight:400;">(check all that apply)</span></label>
            <div class="cat-chk-grid">
                <?php foreach ($FACT_CATEGORIES as $cat): ?>
                <label class="cat-chk-row">
                    <input type="checkbox" name="focus_area[]" value="<?= h($cat) ?>" <?= in_array($cat, $selectedCats, true) ? 'checked' : '' ?>>
                    <span><?= h($cat) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Subcategory: checkboxes, grouped by selected parent categories -->
        <div class="span-2">
            <label style="display:block;margin-bottom:8px;font-weight:600;">Subcategory <span style="font-weight:400;">(check all that apply)</span></label>
            <div id="subcategory-checkboxes" class="subcategory-grid">
                <span class="muted">-- choose a category above first --</span>
            </div>
        </div>

        <div><label>Topics (comma-separated)</label><input name="topics" value="<?= h($editing['topics'] ?? '') ?>"></div>
        <div><label>Geographic focus (comma-separated)</label><input name="geography" value="<?= h($editing['geography'] ?? '') ?>"></div>

        <div class="span-2">
            <div class="coadvising-section">
                <label class="coadvising-toggle">
                    <input type="checkbox" id="co_advising" name="co_advising" <?= !empty($editing['co_advising']) ? 'checked' : '' ?>>
                    <span class="coadvising-label">Open to co-advising students</span>
                </label>
                <div class="coadvising-details-wrap" id="coadvising-details-wrap" style="<?= !empty($editing['co_advising']) ? '' : 'display:none' ?>;margin-top:12px;overflow:hidden;transition:all .2s">
                    <label for="co_advising_details">Co-advising details</label>
                    <textarea id="co_advising_details" name="co_advising_details" placeholder="Describe your interests, topics, or preferences for co-advising..."><?= h($editing['co_advising_details'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <div class="span-2">
            <div class="links-card">
                <div class="links-card-title">Links &amp; Profiles</div>
                <div class="links-grid-inner">
                    <div><label>Institutional Profile URL</label><input name="profile_url" value="<?= h($editing['profile_url'] ?? '') ?>" placeholder="https://..."></div>
                    <div><label>Research / Lab Website</label><input name="website_url" value="<?= h($editing['website_url'] ?? '') ?>" placeholder="https://..."></div>
                    <div><label>ORCID</label><input name="orcid_id" value="<?= h($editing['orcid_id'] ?? '') ?>" placeholder="0000-0001-2345-6789"></div>
                    <div><label>Google Scholar URL</label><input name="google_scholar_url" value="<?= h($editing['google_scholar_url'] ?? '') ?>" placeholder="https://scholar.google.com/..."></div>
                </div>
            </div>
        </div>

        <div class="span-2">
            <div style="background:#f8fafb;border:1.5px solid #dde6dd;border-radius:10px;padding:14px 18px;display:flex;align-items:center;gap:12px">
                <input type="checkbox" id="notify_matches" name="notify_matches"
                       <?= !empty($editing['notify_matches']) ? 'checked' : '' ?>
                       style="width:17px;height:17px;accent-color:#1a6b5a;flex-shrink:0;cursor:pointer">
                <label for="notify_matches" style="margin:0;font-size:13.5px;font-weight:600;color:#374151;cursor:pointer;line-height:1.4">
                    Email me when new funding calls match my research profile
                    <span style="display:block;font-weight:400;color:#9aaba4;font-size:12.5px;margin-top:1px">You can unsubscribe at any time via the link in the email.</span>
                </label>
            </div>
        </div>

        <div class="span-2 actions-row">
            <button class="primary-btn" type="submit">
                <?php
                    if ($mode === 'add' && !$editing && !is_logged_in()) {
                        echo 'Register';
                    } elseif ($editing) {
                        echo 'Save Changes';
                    } else {
                        echo 'Save';
                    }
                ?>
            </button>
            <a class="ghost-btn" href="index.php?page=researchers">Cancel</a>
        </div>
    </form>
</div>

<style>
.subcategory-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:6px 28px}
.subcategory-grid label{display:flex;align-items:flex-start;gap:10px;font-size:13.5px;font-weight:500;background:#f8f9fa;border-radius:6px;padding:6px 12px;margin:0;cursor:pointer;transition:background .15s}
.subcategory-grid label:hover{background:#e6f0fa}
.subcategory-grid input[type="checkbox"]{margin-top:2px;accent-color:#2a7ae2;width:16px;height:16px;flex-shrink:0;cursor:pointer}
.coadvising-section{background:#f6fbf7;border:1.5px solid #d2e7d7;border-radius:10px;padding:16px 20px}
.coadvising-toggle{display:flex;align-items:center;gap:10px;cursor:pointer;margin:0;font-weight:normal}
.coadvising-toggle input[type="checkbox"]{width:18px;height:18px;margin:0;flex-shrink:0;accent-color:#1a6b5a;cursor:pointer}
.coadvising-label{font-size:1.05em;font-weight:600;color:#205c3b}
.coadvising-details-wrap{display:flex;flex-direction:column;gap:6px}
.coadvising-details-wrap label{font-size:.95em;font-weight:600;color:#205c3b;margin:0}
.coadvising-details-wrap textarea{min-height:72px;border-radius:8px;border:1px solid #c7e2d0;background:#fff;resize:vertical}
.links-card{background:#f8fafb;border:1.5px solid #dde6dd;border-radius:10px;padding:16px 20px}
.links-card-title{font-size:.82em;font-weight:800;letter-spacing:.1em;color:#60706a;text-transform:uppercase;margin-bottom:14px}
.links-grid-inner{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:640px){.links-grid-inner{grid-template-columns:1fr}}
</style>

<script>
const subcategories = <?= json_encode(array_combine(
    array_keys(array_fill_keys($FACT_CATEGORIES, null)),
    array_map(fn($cat) => match($cat) {
        'Food Security, Nutrition & Health' => ['Diet diversity','Affordability of Healthy and Sustainable Diets','Food environments','Forecasting food insecurity/shocks/vulnerabilities','Food issues requiring solutions outside the food system','Alternative proteins','Migration & displacement','Food system tipping points','Safe working environments'],
        'Ecosystems & Biodiversity' => ['Orphan crops','Water management','Biome Specialization: Drylands','Biome Specialization: Forests','Biome Specialization: Aquatic','Biome Specialization: Grasslands','Biome Specialization: Wetlands','Biome Specialization: Other','Ecosystem services','Ecosystem restoration','Nexus approaches','Commercial forestry','Pests & diseases','Soils'],
        'Governance & Innovation' => ['Innovation and change','Political economy (practices, institutions, power and/or politics)','Human mobility','Geopolitical crises','Regulatory/reporting frameworks/standards & certification','Land use and land access'],
        'Markets & Trade' => ['Supply chain risks','Inclusive & resilient value chains','Food industry sustainability tracking','Trade networks & shock propagation','Food trade early warning systems','Energy use'],
        'Crosscutting Themes' => ['Diversity, gender, equity, and inclusion','Social-ecological systems','Finance and resource mobilization','Co-development of knowledge/co-design','Data ecosystems','Business models','AI / machine learning and digital tools','Stress testing the global/national food system','Scenarios and storylines','Transition management'],
        default => []
    }, $FACT_CATEGORIES)
)) ?>;

function populateSubcategories(selectedCats, keepSubcats = []) {
    const div = document.getElementById('subcategory-checkboxes');
    div.innerHTML = '';
    if (!selectedCats.length) {
        div.innerHTML = '<span class="muted">-- choose a category above first --</span>';
        return;
    }
    for (const cat of selectedCats) {
        const subs = subcategories[cat];
        if (!subs || !subs.length) continue;
        if (selectedCats.length > 1) {
            const hd = document.createElement('div');
            hd.className = 'subcat-grp-hd';
            hd.textContent = cat;
            div.appendChild(hd);
        }
        for (const sub of subs) {
            const id  = 'subcat_' + sub.replace(/[^a-zA-Z0-9]/g, '_');
            const lbl = document.createElement('label');
            lbl.setAttribute('for', id);
            const chk = document.createElement('input');
            chk.type  = 'checkbox';
            chk.name  = 'focus_area_detail[]';
            chk.value = sub;
            chk.id    = id;
            if (keepSubcats.includes(sub)) chk.checked = true;
            lbl.appendChild(chk);
            const sp = document.createElement('span'); sp.textContent = sub;
            lbl.appendChild(sp);
            div.appendChild(lbl);
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const catChks = [...document.querySelectorAll('.cat-chk-row input[type="checkbox"]')];
    const getSelCats = () => catChks.filter(c => c.checked).map(c => c.value);
    const initSubcats = <?= json_encode($selectedSubcats) ?>;

    populateSubcategories(getSelCats(), initSubcats);

    catChks.forEach(chk => chk.addEventListener('change', () => {
        // Preserve currently-checked subcats across category change
        const curChecked = [...document.querySelectorAll('#subcategory-checkboxes input:checked')].map(c => c.value);
        populateSubcategories(getSelCats(), curChecked);
    }));

    const cb = document.getElementById('co_advising');
    const wrap = document.getElementById('coadvising-details-wrap');
    if (cb && wrap) {
        cb.addEventListener('change', function() {
            wrap.style.display = this.checked ? '' : 'none';
            if (this.checked) wrap.querySelector('textarea').focus();
        });
    }

    // Password match validation (as you type)
    const pwdInput = document.getElementById('reg-password');
    const confirmInput = document.getElementById('reg-confirm-password');
    const msgDiv = document.getElementById('pwd-match-msg');

    if (pwdInput && confirmInput && msgDiv) {
        function validatePasswords() {
            if (confirmInput.value === '') {
                msgDiv.style.display = 'none';
                return;
            }
            msgDiv.style.display = 'block';
            if (pwdInput.value === confirmInput.value) {
                msgDiv.textContent = '✓ Passwords match';
                msgDiv.style.color = '#15803d';
            } else {
                msgDiv.textContent = '✗ Passwords do not match';
                msgDiv.style.color = '#b54646';
            }
        }
        pwdInput.addEventListener('input', validatePasswords);
        confirmInput.addEventListener('input', validatePasswords);
    }
});
</script>
<?php endif; ?>

<!-- ── View panel ─────────────────────────────────────────────────── -->
<?php if (!$isRegistering && $viewing): ?>
<div class="panel modalish">
    <div class="head-row">
        <div>
            <h2 style="margin-bottom:2px"><?= h(trim(($viewing['first_name'] ?? '') . ' ' . ($viewing['last_name'] ?? ''))) ?></h2>
            <?php if ($viewing['title']): ?><div class="muted" style="font-size:14px"><?= h($viewing['title']) ?><?= $viewing['institution'] ? ' · ' . h($viewing['institution']) : '' ?></div><?php endif; ?>
        </div>
        <a class="ghost-btn" href="index.php?page=researchers">Close</a>
    </div>
    <div class="detail-grid" style="margin-top:14px">
        <?php if ($viewing['institution']): ?><div><strong>Institution:</strong> <?= h($viewing['institution']) ?></div><?php endif; ?>
        <?php if ($viewing['department']):  ?><div><strong>Department:</strong>  <?= h($viewing['department']) ?></div><?php endif; ?>
        <?php if ($viewing['email']):       ?><div><strong>Email:</strong> <a href="mailto:<?= h($viewing['email']) ?>"><?= h($viewing['email']) ?></a></div><?php endif; ?>
        <?php if ($viewing['focus_area']):
            $displayCats = implode(', ', array_filter(array_map('trim', explode('|', $viewing['focus_area']))));
        ?><div><strong>Focus Area:</strong> <?= h($displayCats) ?></div><?php endif; ?>
        <?php if ($viewing['focus_area_detail']): ?>
            <div class="span-detail"><strong>Specializations:</strong> <?= h($viewing['focus_area_detail']) ?></div>
        <?php endif; ?>
    </div>
    <?php if (!empty($viewing['co_advising'])): ?>
    <div style="margin:12px 0 4px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <span class="badge" style="background:#eef9f3;border:1px solid #d2e7d7;color:#1a6b5a;font-size:13px;padding:6px 12px">✓ Open to co-advising</span>
        <?php if ($viewing['co_advising_details']): ?><span class="muted" style="font-size:13px"><?= h($viewing['co_advising_details']) ?></span><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($viewing['bio']): ?><p class="muted block" style="margin-top:12px"><?= nl2br(h($viewing['bio'])) ?></p><?php endif; ?>
    <?php if ($researcherSummary): ?>
    <div style="margin-top:12px;padding:12px 14px;background:#eaf6f0;border:1px solid #c3dfd0;border-radius:10px">
        <div style="font-size:10px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#1a6b5a;margin-bottom:6px">AI Summary</div>
        <p style="margin:0;font-size:14px;line-height:1.6;color:#1c2a24"><?= h($researcherSummary['summary']) ?></p>
    </div>
    <?php endif; ?>
    <div class="tag-row"><?php foreach (parse_tags($viewing['topics']) as $tag): ?><span class="tag topic-tag"><?= h($tag) ?></span><?php endforeach; ?></div>
    <div class="tag-row"><?php foreach (parse_tags($viewing['geography']) as $tag): ?><span class="tag geo-tag"><?= h($tag) ?></span><?php endforeach; ?></div>
    <?php if ($topFundingMatches): ?>
    <div style="margin-top:16px;border-top:1px solid var(--line);padding-top:14px">
        <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:10px">Top Matching Funding Calls</div>
        <?php foreach ($topFundingMatches as $tf):
            $score = $tf['score_ai'] ?? $tf['score_keyword'];
            $isAi  = $tf['score_ai'] !== null;
        ?>
        <div style="display:flex;align-items:flex-start;gap:10px;padding:8px 0;border-bottom:1px solid #f4f4f4">
            <span class="badge score-badge <?= $isAi ? 'ai-score' : 'kw-score' ?>"><?= $score ?><?= $isAi ? '%' : ' pts' ?></span>
            <div style="flex:1;min-width:0">
                <a href="index.php?page=funding&view=<?= (int)$tf['id'] ?>" style="font-weight:600;font-size:14px"><?= h($tf['title']) ?></a><br>
                <span class="muted" style="font-size:12px"><?= h($tf['funder']) ?> · <?= h($tf['deadline']) ?></span>
                <?php if ($tf['explanation']): ?><p class="muted small" style="margin:2px 0 0;font-style:italic"><?= h($tf['explanation']) ?></p><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php
    $profileLinks = [];
    if (!empty($viewing['profile_url']))        $profileLinks['Institutional Profile'] = $viewing['profile_url'];
    if (!empty($viewing['website_url']))         $profileLinks['Research Website']      = $viewing['website_url'];
    if (!empty($viewing['google_scholar_url'])) $profileLinks['Google Scholar']         = $viewing['google_scholar_url'];
    $orcid = $viewing['orcid_id'] ?? '';
    if ($profileLinks || $orcid): ?>
    <div class="profile-links-row">
        <?php foreach ($profileLinks as $label => $url): ?>
            <a class="profile-link-btn" href="<?= h($url) ?>" target="_blank" rel="noopener"><?= h($label) ?> ↗</a>
        <?php endforeach; ?>
        <?php if ($orcid): ?>
            <a class="profile-link-btn orcid-btn" href="https://orcid.org/<?= h($orcid) ?>" target="_blank" rel="noopener">ORCID ↗</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── LinkedIn-style layout ─────────────────────────────────────── -->
<?php if (!$isRegistering): ?>
<div class="lk-overlay" id="lk-overlay"></div>

<div class="lk-layout">
    <!-- Sidebar -->
    <aside class="lk-sidebar" id="lk-sidebar">
        <div class="lk-sidebar-head">
            <span class="lk-sidebar-title">Filters</span>
            <button class="lk-clear-btn" data-fp="clear" type="button">Clear all</button>
        </div>

        <!-- Search -->
        <div class="lk-search-wrap">
            <input type="text" data-fp="search" placeholder="Name, institution, topics…" autocomplete="off">
        </div>

        <!-- Category -->
        <div class="lk-section">
            <div class="lk-section-hd">Category</div>
            <div class="lk-section-body">
                <?php foreach ($FACT_CATEGORIES as $cat): ?>
                <label class="lk-row">
                    <input type="checkbox" data-fp-group="cats" value="<?= h($cat) ?>">
                    <span><?= h($cat) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Subcategory (grouped, shown based on selected cats) -->
        <div class="lk-section">
            <div class="lk-section-hd">Subcategory</div>
            <div class="lk-section-body">
                <?php foreach ($FACT_CATEGORIES as $cat):
                    $subs = match($cat) {
                        'Food Security, Nutrition & Health' => ['Diet diversity','Affordability of Healthy and Sustainable Diets','Food environments','Forecasting food insecurity/shocks/vulnerabilities','Food issues requiring solutions outside the food system','Alternative proteins','Migration & displacement','Food system tipping points','Safe working environments'],
                        'Ecosystems & Biodiversity' => ['Orphan crops','Water management','Biome Specialization: Drylands','Biome Specialization: Forests','Biome Specialization: Aquatic','Biome Specialization: Grasslands','Biome Specialization: Wetlands','Biome Specialization: Other','Ecosystem services','Ecosystem restoration','Nexus approaches','Commercial forestry','Pests & diseases','Soils'],
                        'Governance & Innovation' => ['Innovation and change','Political economy (practices, institutions, power and/or politics)','Human mobility','Geopolitical crises','Regulatory/reporting frameworks/standards & certification','Land use and land access'],
                        'Markets & Trade' => ['Supply chain risks','Inclusive & resilient value chains','Food industry sustainability tracking','Trade networks & shock propagation','Food trade early warning systems','Energy use'],
                        'Crosscutting Themes' => ['Diversity, gender, equity, and inclusion','Social-ecological systems','Finance and resource mobilization','Co-development of knowledge/co-design','Data ecosystems','Business models','AI / machine learning and digital tools','Stress testing the global/national food system','Scenarios and storylines','Transition management'],
                        default => []
                    };
                ?>
                <span class="lk-grp-label"><?= h($cat) ?></span>
                <?php foreach ($subs as $sub): ?>
                <label class="lk-row" data-subcat-parent="<?= h($cat) ?>">
                    <input type="checkbox" data-fp-group="subcats" value="<?= h($sub) ?>">
                    <span><?= h($sub) ?></span>
                </label>
                <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Geography (populated by JS) -->
        <div class="lk-section">
            <div class="lk-section-hd">Geography</div>
            <div class="lk-section-body">
                <input type="text" class="lk-geo-search" placeholder="Search regions & countries…" autocomplete="off">
                <div id="lk-geo-items"></div>
            </div>
        </div>

        <!-- Topics -->
        <?php if ($topicTags): ?>
        <div class="lk-section">
            <div class="lk-section-hd">Topics</div>
            <div class="lk-section-body">
                <?php foreach ($topicTags as $tag): ?>
                <label class="lk-row">
                    <input type="checkbox" data-fp-group="topics" value="<?= h(strtolower($tag)) ?>">
                    <span><?= h($tag) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Institution -->
        <?php if ($institutions): ?>
        <div class="lk-section">
            <div class="lk-section-hd">Institution</div>
            <div class="lk-section-body">
                <?php foreach ($institutions as $inst): ?>
                <label class="lk-row">
                    <input type="checkbox" data-fp-group="institutions" value="<?= h(strtolower($inst)) ?>">
                    <span><?= h($inst) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Co-advising toggle -->
        <div class="lk-toggle-section">
            <label class="lk-toggle-row">
                <span class="lk-toggle-label">Open to co-advising only</span>
                <span class="lk-sw">
                    <input type="checkbox" data-fp="coadvising">
                    <span class="lk-sw-track"></span>
                </span>
            </label>
        </div>
    </aside>

    <!-- Results -->
    <div class="lk-results">
        <div class="lk-results-head">
            <span class="lk-count" id="lk-count"><?= count($researchers) ?> result<?= count($researchers) !== 1 ? 's' : '' ?></span>
            <button class="lk-mobile-btn" id="lk-mobile-btn" type="button">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="4" y1="6" x2="20" y2="6"/><line x1="8" y1="12" x2="20" y2="12"/><line x1="12" y1="18" x2="20" y2="18"/></svg>
                Filters
            </button>
        </div>

        <?php if (!$researchers): ?>
        <div class="empty-state panel">No researchers found.</div>
        <?php endif; ?>

        <?php foreach ($researchers as $r):
            $rCats    = array_values(array_filter(array_map('trim', explode('|', $r['focus_area'] ?? ''))));
            $rSubcats = array_values(array_filter(array_map('trim', explode(',', $r['focus_area_detail'] ?? ''))));
            $rTopics  = parse_tags($r['topics'] ?? '');
            $rGeos    = parse_tags($r['geography'] ?? '');
            $filterData = json_encode([
                'name'       => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                'institution'=> $r['institution'] ?? '',
                'cats'       => $rCats,
                'subcats'    => $rSubcats,
                'topics'     => $rTopics,
                'geos'       => $rGeos,
                'coadvising' => !empty($r['co_advising']),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?: '{}';
        ?>
        <div class="panel list-card" data-filter="<?= h($filterData) ?>">
            <div class="card-row">
                <div class="card-main">
                    <div class="title-line">
                        <h3><?= h(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))) ?></h3>
                        <?php if (!empty($r['co_advising'])): ?><span class="badge badge-outline">Co-advising</span><?php endif; ?>
                    </div>
                    <div class="muted"><?= h(implode(' · ', array_filter([$r['title'] ?? '', $r['institution'] ?? '']))) ?></div>
                    <?php if ($rCats): ?>
                    <div class="mini-label">Category:</div>
                    <div class="tag-row">
                        <?php foreach ($rCats as $cat): ?>
                        <span class="tag" style="background:#eef3ff;color:#3b5bdb;border-color:#c5d0f5"><?= h($cat) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <div class="mini-label">Topics:</div>
                    <div class="tag-row"><?php foreach (array_slice($rTopics, 0, 4) as $tag): ?><span class="tag topic-tag"><?= h($tag) ?></span><?php endforeach; ?></div>
                    <div class="mini-label">Geography:</div>
                    <div class="tag-row"><?php foreach (array_slice($rGeos, 0, 4) as $tag): ?><span class="tag geo-tag"><?= h($tag) ?></span><?php endforeach; ?></div>
                </div>
                <div class="card-actions">
                    <a class="ghost-btn" href="index.php?page=researchers&view=<?= (int)$r['id'] ?>">View</a>
                    <?php $isOwnProfile = strtolower($r['email'] ?? '') === strtolower($currentUser['email']); ?>
                    <?php if (is_admin() || $isOwnProfile): ?>
                        <a class="ghost-btn" href="index.php?page=researchers&edit=<?= (int)$r['id'] ?>"><?= $isOwnProfile && !is_admin() ? 'Edit my profile' : 'Edit' ?></a>
                    <?php endif; ?>
                    <?php if (is_admin()): ?>
                        <form method="post" onsubmit="return confirm('Delete researcher?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="danger-btn" type="submit">Delete</button></form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div><!-- .lk-results -->
</div><!-- .lk-layout -->

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Populate geography section from WB_GEO
    const geoContainer = document.getElementById('lk-geo-items');
    if (geoContainer) {
        // Regions first
        const regHd = document.createElement('span');
        regHd.className = 'lk-grp-label';
        regHd.dataset.geoHd = 'Regions';
        regHd.textContent = 'Regions';
        geoContainer.appendChild(regHd);
        for (const region of Object.keys(WB_GEO)) {
            const lbl = document.createElement('label');
            lbl.className = 'lk-row';
            const chk = document.createElement('input');
            chk.type = 'checkbox';
            chk.dataset.fpGroup  = 'geos';
            chk.dataset.geoGroup = region;
            chk.value = region;
            const sp = document.createElement('span'); sp.textContent = region;
            lbl.append(chk, sp);
            geoContainer.appendChild(lbl);
        }
        // Countries grouped by region
        for (const [region, countries] of Object.entries(WB_GEO)) {
            const hd = document.createElement('span');
            hd.className = 'lk-grp-label';
            hd.dataset.geoHd = region;
            hd.textContent = region;
            geoContainer.appendChild(hd);
            for (const c of countries) {
                const lbl = document.createElement('label');
                lbl.className = 'lk-row';
                const chk = document.createElement('input');
                chk.type = 'checkbox';
                chk.dataset.fpGroup  = 'geos';
                chk.dataset.geoGroup = region;
                chk.value = c;
                const sp = document.createElement('span'); sp.textContent = c;
                lbl.append(chk, sp);
                geoContainer.appendChild(lbl);
            }
        }
    }

    // Mobile sidebar open/close
    const sidebar  = document.getElementById('lk-sidebar');
    const overlay  = document.getElementById('lk-overlay');
    const mobileBtn = document.getElementById('lk-mobile-btn');
    if (mobileBtn && sidebar && overlay) {
        mobileBtn.addEventListener('click', () => {
            sidebar.classList.add('is-open');
            overlay.classList.add('is-open');
        });
        overlay.addEventListener('click', () => {
            sidebar.classList.remove('is-open');
            overlay.classList.remove('is-open');
        });
    }

    // Init FilterPanel (geo items must be in DOM first)
    new FilterPanel({
        sidebarSel: '#lk-sidebar',
        cardSel:    '.lk-results .list-card',
        countSel:   '#lk-count',
        storageKey: 'facthub_researcher_filters'
    });

    // Auto-refresh page after deletion success
    const successAlerts = document.querySelectorAll('[class*="alert-success"]');
    if (successAlerts.length > 0) {
        successAlerts.forEach(alert => {
            if (alert.textContent.includes('deleted') || alert.textContent.includes('Trash')) {
                setTimeout(() => {
                    location.reload();
                }, 1500);
            }
        });
    }
});
</script>
<?php endif; // end !$isRegistering ?>

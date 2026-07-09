<?php
require_login();

// ─── TRACKING ────────────────────────────────────────────────────────
error_log('[FUNDING] Page loaded - REQUEST_METHOD=' . $_SERVER['REQUEST_METHOD']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log('[FUNDING] POST detected - action=' . ($_POST['action'] ?? 'NONE'));
}
// ──────────────────────────────────────────────────────────────────────

// Check if user is approved to access funding calls
if (!is_approved()) {
    set_flash('info', 'Your account is pending admin approval. You can access funding calls once approved.');
    redirect_to('researchers');
}

$user = current_user();

// Ownership check — admins can manage any funding call, funders only their own
$canManage = function(array $fc) use ($user): bool {
    if (is_admin()) return true;
    if (is_funder() && $fc['added_by_email'] === $user['email']) return true;
    return false;
};

// Role guard for write operations — only admin or funder may add/edit/delete funding calls
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';
    $writeActions = ['save', 'delete'];
    if (in_array($postAction, $writeActions, true)) {
        if (!is_admin() && $user['role'] !== 'funder') {
            set_flash('error', 'Only administrators and funders can add, edit, or delete funding calls.');
            redirect_to('funding');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        error_log('[FUNDING] SAVE handler triggered');
        $id = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $funder = trim($_POST['funder'] ?? '');
        $deadline = trim($_POST['deadline'] ?? '');
        $status = trim($_POST['status'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $topics = trim($_POST['topics'] ?? '');
        $geography = trim($_POST['geography'] ?? '');
        $amount = substr(trim($_POST['amount'] ?? ''), 0, 100);
        $url = trim($_POST['url'] ?? '');
        if ($title === '') { set_flash('error', 'Title is required.'); redirect_to('funding'); }
        // Capitalize geography values for consistent formatting
        $geoParts = array_map(function($g) { return mb_convert_case(trim($g), MB_CASE_TITLE, "UTF-8"); }, explode(',', $geography));
        $geography = implode(', ', array_filter($geoParts));
        ensure_tags($conn, $topics, 'topic');
        ensure_tags($conn, $geography, 'geography');
        if ($id > 0) {
            if (!is_admin()) {
                $own = $conn->prepare('SELECT added_by_email FROM funding_calls WHERE id = ? LIMIT 1');
                $own->bind_param('i', $id); $own->execute();
                $ownRow = $own->get_result()->fetch_assoc();
                if (!$ownRow || $ownRow['added_by_email'] !== $user['email']) {
                    set_flash('error', 'You can only edit your own funding calls.');
                    redirect_to('funding');
                }
            }
            $stmt = $conn->prepare('UPDATE funding_calls SET title=?, funder=?, deadline=?, status=?, description=?, topics=?, geography=?, amount=?, url=? WHERE id=?');
            $stmt->bind_param('sssssssssi', $title, $funder, $deadline, $status, $description, $topics, $geography, $amount, $url, $id);
            $stmt->execute();

            // Regenerate AI summary and embedding since content changed
            enqueue_job($conn, 'generate_summary', ['entity_type' => 'funding_call', 'entity_id' => $id]);
            enqueue_job($conn, 'generate_embedding', ['entity_type' => 'funding_call', 'entity_id' => $id]);

            set_flash('success', 'Funding call updated.');
        } else {
            $added = $user['email'];
            $stmt = $conn->prepare('INSERT INTO funding_calls (title, funder, deadline, status, description, topics, geography, amount, url, added_by_email) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('ssssssssss', $title, $funder, $deadline, $status, $description, $topics, $geography, $amount, $url, $added);
            $stmt->execute();
            $newFcId = $conn->insert_id;

            // ── Match notifications ──────────────────────────────────
            $fcTopics = parse_tags($topics);
            $fcGeo    = parse_tags($geography);
            $notifiedCount = 0;

            if (!empty($fcTopics) || !empty($fcGeo)) {
                @$mailCfg     = require __DIR__ . '/../../../config/mail.php';
                if (!is_array($mailCfg)) $mailCfg = [];
                $appUrl       = rtrim($mailCfg['app_url'] ?? ('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')), '/');
                $notifySecret = $mailCfg['notify_secret'] ?? '';
                $fundingUrl   = $appUrl . '/index.php?page=funding&view=' . $newFcId;

                // Determine deadline urgency (< 30 days = force immediate notification)
                $deadlineTs = strtotime($deadline);
                $isUrgent = $deadlineTs && ($deadlineTs - time()) < (30 * 86400);

                // Select researchers with notification preferences
                $rq = @$conn->prepare("
                    SELECT first_name, email, topics, geography, notify_threshold,
                           COALESCE(notify_frequency, 'immediate') as notify_frequency,
                           quiet_hours_start, quiet_hours_end
                    FROM researchers
                    WHERE status = 'active' AND deleted_at IS NULL AND notify_matches = 1
                          AND email != '' AND email IS NOT NULL AND notify_frequency != 'never'
                ");
                if (!$rq) {
                    $rq = $conn->prepare("
                        SELECT first_name, email, topics, geography, COALESCE(notify_threshold, 60) as notify_threshold,
                               'immediate' as notify_frequency, NULL as quiet_hours_start, NULL as quiet_hours_end
                        FROM researchers
                        WHERE status = 'active' AND notify_matches = 1
                              AND email != '' AND email IS NOT NULL
                    ");
                }
                if ($rq) {
                    $rq->execute();
                    $rqResult = $rq->get_result();
                } else {
                    $rqResult = null;
                }

                $immediateNotifications = [];
                $weeklyQueuedCount = 0;

                if ($rqResult) {
                while ($r = $rqResult->fetch_assoc()) {
                    $rEmail = trim($r['email'] ?? '');
                    if (!$rEmail) continue;

                    $rTopics = parse_tags($r['topics'] ?? '');
                    $rGeo = parse_tags($r['geography'] ?? '');
                    $score = compute_match_score($fcTopics, $fcGeo, $rTopics, $rGeo);

                    // Calculate match percentage based on researcher's threshold
                    $totalPossible = count($rTopics) + count($rGeo);
                    $matchPercentage = $totalPossible > 0 ? (($score['topicMatches'] + $score['geographyMatches']) / $totalPossible) * 100 : 0;
                    $notifyThreshold = (int)($r['notify_threshold'] ?? 60);

                    // Skip if score too low or doesn't meet researcher's threshold
                    if ($score['totalScore'] < 1 || $matchPercentage < $notifyThreshold) continue;

                    // Determine delivery method
                    $frequency = $r['notify_frequency'] ?? 'immediate';
                    $shouldSendImmediate = ($frequency === 'immediate' || $isUrgent);
                    $inQuietHours = false;

                    // Check quiet hours (only for non-urgent notifications)
                    if ($shouldSendImmediate && !$isUrgent && $r['quiet_hours_start'] && $r['quiet_hours_end']) {
                        $currentTime = date('H:i', time());
                        $startTime = $r['quiet_hours_start'];
                        $endTime = $r['quiet_hours_end'];

                        // If quiet hours span midnight (e.g. 22:00 to 08:00)
                        if ($startTime > $endTime) {
                            if ($currentTime >= $startTime || $currentTime < $endTime) {
                                $inQuietHours = true;
                            }
                        } else {
                            if ($currentTime >= $startTime && $currentTime < $endTime) {
                                $inQuietHours = true;
                            }
                        }
                    }

                    if ($shouldSendImmediate && !$inQuietHours) {
                        // Send immediately
                        $unsubToken = generate_unsubscribe_token($rEmail, $notifySecret);
                        $unsubUrl   = $appUrl . '/index.php?page=unsubscribe&e=' . urlencode($rEmail) . '&t=' . $unsubToken;
                        $firstName  = $r['first_name'] ?: 'Researcher';

                        $immediateNotifications[] = [
                            'to'      => $rEmail,
                            'subject' => 'New funding match: ' . $title,
                            'html'    => mail_tpl_match_notify(
                                $firstName, $title, $funder, $deadline, $status, $amount,
                                $score['matchedTopics'], $score['matchedGeographies'],
                                $fundingUrl, $unsubUrl
                            ),
                        ];
                    } else {
                        // Queue for weekly digest (either frequency=weekly or in quiet hours)
                        $qStmt = $conn->prepare('INSERT INTO notification_queue (researcher_email, funding_call_id) VALUES (?, ?)');
                        $qStmt->bind_param('si', $rEmail, $newFcId);
                        @$qStmt->execute();
                        $weeklyQueuedCount++;
                    }
                }
                }

                if (!empty($immediateNotifications)) {
                    $notifiedCount = count($immediateNotifications);
                    enqueue_job($conn, 'send_digest', ['messages' => $immediateNotifications]);
                }

                if ($weeklyQueuedCount > 0) {
                    $notifiedCount += $weeklyQueuedCount;
                }
            }
            // ────────────────────────────────────────────────────────

            enqueue_job($conn, 'compute_matches', ['funding_call_id' => $newFcId]);
            enqueue_job($conn, 'generate_summary', ['entity_type' => 'funding_call', 'entity_id' => $newFcId]);
            enqueue_job($conn, 'generate_embedding', ['entity_type' => 'funding_call', 'entity_id' => $newFcId]);

            $flashMsg = 'Funding call added.';
            if ($notifiedCount > 0) {
                $flashMsg .= " {$notifiedCount} researcher" . ($notifiedCount > 1 ? 's' : '') . " notified by email.";
            }
            set_flash('success', $flashMsg);
        }
        error_log('[FUNDING] About to redirect to funding - this is the LAST thing that should happen');
        redirect_to('funding');
    }
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            set_flash('error', 'Invalid funding call ID.');
            redirect_to('funding');
        }
        if (!is_admin()) {
            $fcq = $conn->prepare('SELECT added_by_email FROM funding_calls WHERE id = ? LIMIT 1');
            $fcq->bind_param('i', $id); $fcq->execute();
            $fcRow = $fcq->get_result()->fetch_assoc();
            if (!$fcRow || $fcRow['added_by_email'] !== $user['email']) {
                set_flash('error', 'You do not have permission to delete this funding call.');
                redirect_to('funding');
            }
        }
        try {
            // Soft delete funding call
            $deletedBy = $user['email'];
            $stmt = $conn->prepare('UPDATE funding_calls SET deleted_at = NOW(), deleted_by = ? WHERE id = ?');
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
            $stmt->bind_param('si', $deletedBy, $id);
            if (!$stmt->execute()) throw new Exception('Execute failed: ' . $stmt->error);
            $rows = $stmt->affected_rows;

            if ($rows === 0) {
                set_flash('error', 'Funding call not found.');
            } else {
                audit($conn, 'delete_funding_call', ['type' => 'funding_call', 'id' => $id, 'detail' => 'Soft deleted']);
                set_flash('success', 'Funding call deleted successfully.');
            }
        } catch (Exception $e) {
            error_log('[Funding Delete Error] ' . $e->getMessage());
            set_flash('error', 'Failed to delete funding call: ' . $e->getMessage());
        }
        redirect_to('funding');
    }
    if ($action === 'save_opportunity') {
        $fundingCallId = (int)($_POST['funding_call_id'] ?? 0);
        $title = trim($_POST['funding_call_title'] ?? '');
        $email = $user['email'];
        $name = $user['name'];
        $check = $conn->prepare('SELECT id FROM saved_opportunities WHERE researcher_email = ? AND funding_call_id = ? LIMIT 1');
        $check->bind_param('si', $email, $fundingCallId);
        $check->execute();
        $row = $check->get_result()->fetch_assoc();
        if ($row) {
            $del = $conn->prepare('DELETE FROM saved_opportunities WHERE id = ?');
            $del->bind_param('i', $row['id']);
            $del->execute();
            set_flash('success', 'Removed from saved.');
        } else {
            $ins = $conn->prepare('INSERT INTO saved_opportunities (researcher_email, researcher_name, funding_call_id, funding_call_title, notes) VALUES (?, ?, ?, ?, ?)');
            $notes = '';
            $ins->bind_param('ssiss', $email, $name, $fundingCallId, $title, $notes);
            $ins->execute();
            set_flash('success', 'Saved to your opportunities.');
        }
        redirect_to('funding', ['tab' => $_GET['tab'] ?? 'all']);
    }
    if ($action === 'save_note') {
        $id = (int)($_POST['id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $stmt = $conn->prepare('UPDATE saved_opportunities SET notes=? WHERE id=? AND researcher_email=?');
        $stmt->bind_param('sis', $notes, $id, $user['email']);
        $stmt->execute();
        set_flash('success', 'Note saved.');
        redirect_to('funding', ['tab' => 'saved']);
    }
}

$tab          = $_GET['tab'] ?? 'all';
$search       = trim($_GET['search'] ?? '');
$topicFilters = array_values(array_filter(array_map('trim', (array)($_GET['topics'] ?? []))));
$geoFilters   = array_values(array_filter(array_map('trim', (array)($_GET['geos']   ?? []))));
$statusFilter = trim($_GET['status'] ?? '');
$mode   = $_GET['mode'] ?? '';
$editId = (int)($_GET['edit'] ?? 0);
$viewId = (int)($_GET['view'] ?? 0);
error_log('[FUNDING] URL params: mode=' . $mode . ', editId=' . $editId . ', viewId=' . $viewId);
$topicTags = get_all_tags($conn, 'topic');
$geoTags   = get_all_tags($conn, 'geography');
$fundingCalls = [];
// Try with deleted_at column first, fall back if it doesn't exist
$res = @$conn->query('SELECT * FROM funding_calls WHERE deleted_at IS NULL ORDER BY created_at DESC');
if (!$res) {
    // Fallback if deleted_at column doesn't exist yet
    $res = @$conn->query('SELECT * FROM funding_calls ORDER BY created_at DESC');
}
if ($res) {
    while ($row = $res->fetch_assoc()) $fundingCalls[] = $row;
}

require_once __DIR__ . '/../../core/Paginator.php';

$filtered = array_values(array_filter($fundingCalls, function($fc) use ($search, $topicFilters, $geoFilters, $statusFilter) {
    $q = strtolower($search);
    $matchesSearch  = $q === ''
        || str_contains(strtolower($fc['title']  ?? ''), $q)
        || str_contains(strtolower($fc['funder'] ?? ''), $q)
        || str_contains(strtolower($fc['topics'] ?? ''), $q);
    $fcTopics = parse_tags($fc['topics']    ?? '');
    $fcGeo    = parse_tags($fc['geography'] ?? '');
    $matchesTopic  = empty($topicFilters)
        || !empty(array_intersect(array_map('strtolower', $topicFilters), $fcTopics));
    $matchesGeo    = empty($geoFilters)
        || !empty(array_intersect(array_map('strtolower', $geoFilters), $fcGeo));
    $matchesStatus = $statusFilter === '' || ($fc['status'] ?? '') === $statusFilter;
    return $matchesSearch && $matchesTopic && $matchesGeo && $matchesStatus;
}));

// Pagination for filtered results
$page = max(1, (int)($_GET['p'] ?? 1));
$itemsPerPage = 20;
$fundingPaginator = new Paginator(count($filtered), $itemsPerPage, $page);
$paginatedFiltered = array_slice($filtered, $fundingPaginator->getOffset(), $fundingPaginator->getLimit());

$editing = null; foreach ($fundingCalls as $fc) if ((int)$fc['id'] === $editId) $editing = $fc;
$viewing = null; foreach ($fundingCalls as $fc) if ((int)$fc['id'] === $viewId) $viewing = $fc;
error_log('[FUNDING] BEFORE modal logic: $editing is ' . ($editing === null ? 'NULL' : 'NOT NULL (id='.$editing['id'].')') . ', $viewing is ' . ($viewing === null ? 'NULL' : 'NOT NULL (id='.$viewing['id'].')'));
error_log('[FUNDING] fundingCalls count=' . count($fundingCalls));
error_log('[FUNDING] Modal state: editing=' . ($editing ? 'YES (id='.$editing['id'].')' : 'NO') . ', viewing=' . ($viewing ? 'YES (id='.$viewing['id'].')' : 'NO'));
$saved = [];
$stmt = $conn->prepare('SELECT * FROM saved_opportunities WHERE researcher_email = ? ORDER BY created_at DESC');
$stmt->bind_param('s', $user['email']); $stmt->execute(); $res2 = $stmt->get_result(); while ($row = $res2->fetch_assoc()) $saved[] = $row;
$savedMap = []; foreach ($saved as $s) $savedMap[$s['funding_call_id']] = $s;

// Top researcher matches for viewing panel
$topResearcherMatches = [];
if ($viewing) {
    // Try with deleted_at column first, fall back if it doesn't exist
    $tmStmt = @$conn->prepare(
        "SELECT ms.score_ai, ms.score_keyword, ms.explanation,
                r.id, r.first_name, r.last_name, r.institution, r.title, r.email
         FROM match_scores ms JOIN researchers r ON r.id = ms.researcher_id
         WHERE ms.funding_call_id = ? AND r.status = 'active' AND r.deleted_at IS NULL
         ORDER BY COALESCE(ms.score_ai, ms.score_keyword) DESC LIMIT 5"
    );
    if (!$tmStmt) {
        // Fallback if deleted_at column doesn't exist
        $tmStmt = $conn->prepare(
            "SELECT ms.score_ai, ms.score_keyword, ms.explanation,
                    r.id, r.first_name, r.last_name, r.institution, r.title, r.email
             FROM match_scores ms JOIN researchers r ON r.id = ms.researcher_id
             WHERE ms.funding_call_id = ? AND r.status = 'active'
             ORDER BY COALESCE(ms.score_ai, ms.score_keyword) DESC LIMIT 5"
        );
    }
    if ($tmStmt) {
        $tmStmt->bind_param('i', $viewing['id']); $tmStmt->execute();
        $topResearcherMatches = $tmStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

// Load funding call AI summary for viewing panel
$fundingCallSummary = null;
if ($viewing) {
    $sq = $conn->prepare(
        'SELECT summary, created_at FROM ai_summaries WHERE entity_type=? AND entity_id=? LIMIT 1'
    );
    $sqType = 'funding_call';
    $sq->bind_param('si', $sqType, $viewing['id']); $sq->execute();
    $fundingCallSummary = $sq->get_result()->fetch_assoc();
}

// Helper: URL with one filter chip removed
function fundingChipUrl(string $key, string $val = ''): string {
    $p = $_GET;
    if ($val === '') { unset($p[$key]); }
    else {
        if (isset($p[$key]) && is_array($p[$key])) {
            $p[$key] = array_values(array_filter($p[$key], fn($v) => strtolower(trim($v)) !== strtolower(trim($val))));
            if (empty($p[$key])) unset($p[$key]);
        } else {
            unset($p[$key]);
        }
    }
    return 'index.php?' . http_build_query($p);
}

$hasFilters = $search !== '' || !empty($topicFilters) || !empty($geoFilters) || $statusFilter !== '';
?>
<style>
.score-badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 13px; font-weight: 700; }
.ai-score { background: #eaf6f0; color: #1a6b5a; }
.kw-score { background: #f0f4f8; color: #374151; }
</style>
<div class="panel page-head">
    <div class="head-row">
        <div class="title-tabs">
            <h1>Funding Calls</h1>
            <div class="tabs">
                <a class="tab <?= $tab==='all'?'active':'' ?>" href="index.php?page=funding&tab=all">All Calls</a>
                <a class="tab <?= $tab==='saved'?'active':'' ?>" href="index.php?page=funding&tab=saved">My Saved</a>
            </div>
        </div>
        <a class="primary-btn" href="index.php?page=funding&mode=add">+ Add Funding</a>
    </div>
</div>

<div class="panel adv-filter" style="position: sticky; top: 76px; z-index: 11;">
    <div class="adv-filter-hd">
        <span class="adv-filter-title">Filter Funding Calls</span>
        <?php if ($hasFilters): ?>
        <div class="adv-filter-hd-right">
            <span class="result-count"><?= count($filtered) ?> result<?= count($filtered) !== 1 ? 's' : '' ?></span>
            <a href="index.php?page=funding&tab=<?= h($tab) ?>" class="adv-clear-all">Clear all filters</a>
        </div>
        <?php endif; ?>
    </div>

    <form method="get" id="funding-filter">
        <input type="hidden" name="page" value="funding">
        <input type="hidden" name="tab"  value="<?= h($tab) ?>">
        <div class="adv-filter-grid">
            <div class="adv-filter-col">
                <label for="f-search">Search</label>
                <input type="text" id="f-search" name="search" value="<?= h($search) ?>" placeholder="Title, funder, topics…">
            </div>
            <div class="adv-filter-col">
                <label>Topics</label>
                <div id="f-topics-msel"></div>
            </div>
            <div class="adv-filter-col">
                <label>Geography</label>
                <div id="f-geo-msel"></div>
            </div>
            <div class="adv-filter-col">
                <label for="f-status">Status</label>
                <select id="f-status" name="status" style="height:42px">
                    <option value="">All statuses</option>
                    <?php foreach (['open','rolling','closed','upcoming'] as $st): ?>
                    <option value="<?= $st ?>" <?= $statusFilter===$st?'selected':'' ?>><?= ucfirst($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="adv-filter-actions">
                <button class="primary-btn" type="submit" style="white-space:nowrap">Apply Filters</button>
            </div>
        </div>

        <?php if ($hasFilters): ?>
        <div class="adv-active">
            <span class="adv-active-label">Active:</span>
            <?php if ($search !== ''): ?>
            <a class="adv-chip" href="<?= h(fundingChipUrl('search')) ?>">
                Search: "<?= h($search) ?>" <span class="adv-chip-rm">×</span>
            </a>
            <?php endif; ?>
            <?php if ($statusFilter !== ''): ?>
            <a class="adv-chip" href="<?= h(fundingChipUrl('status')) ?>">
                Status: <?= h(ucfirst($statusFilter)) ?> <span class="adv-chip-rm">×</span>
            </a>
            <?php endif; ?>
            <?php foreach ($topicFilters as $t): ?>
            <a class="adv-chip" href="<?= h(fundingChipUrl('topics', $t)) ?>">
                <?= h($t) ?> <span class="adv-chip-rm">×</span>
            </a>
            <?php endforeach; ?>
            <?php foreach ($geoFilters as $g): ?>
            <a class="adv-chip" href="<?= h(fundingChipUrl('geos', $g)) ?>">
                <?= h($g) ?> <span class="adv-chip-rm">×</span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </form>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var topicItems = <?= json_encode(array_map(fn($t) => ['value' => strtolower($t), 'label' => $t], $topicTags)) ?>;
        var geoItems   = buildGeoItems();

        new MultiSelect(document.getElementById('f-topics-msel'), {
            name: 'topics',
            items: topicItems,
            selected: <?= json_encode(array_map('strtolower', $topicFilters)) ?>,
            placeholder: 'All topics'
        });
        new MultiSelect(document.getElementById('f-geo-msel'), {
            name: 'geos',
            items: geoItems,
            selected: <?= json_encode($geoFilters) ?>,
            placeholder: 'All regions & countries'
        });
    });
    </script>
</div>

<div class="lk-layout" style="grid-template-columns: 1fr;">

<?php
error_log('[FUNDING] FORM CONDITION CHECK: is_admin/funder=' . (is_admin() || is_funder() ? 'YES' : 'NO') . ', mode=add=' . ($mode==='add' ? 'YES' : 'NO') . ', editing=' . ($editing ? 'YES' : 'NO'));
if ((is_admin() || is_funder()) && ($mode==='add' || $editing)):
error_log('[FUNDING] FORM IS SHOWING!');
?>
<div class="panel modalish"><h2><?= $editing?'Edit Funding Call':'Add Funding Call' ?></h2><form method="post" class="form-grid two"><input type="hidden" name="action" value="save"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= h($editing['id'] ?? '') ?>"><div class="span-2"><label>Title *</label><input name="title" value="<?= h($editing['title'] ?? '') ?>" required></div><div><label>Funder</label><input name="funder" value="<?= h($editing['funder'] ?? '') ?>"></div><div><label>Deadline</label><input type="date" name="deadline" value="<?= h($editing['deadline'] ?? '') ?>"></div><div><label>Status</label><select name="status"><option value="">-- status --</option><?php foreach(['open','rolling','closed','upcoming'] as $st): ?><option value="<?= $st ?>" <?= ($editing['status'] ?? '')===$st?'selected':'' ?>><?= ucfirst($st) ?></option><?php endforeach; ?></select></div><div><label>Committed Amount</label><input name="amount" maxlength="100" value="<?= h($editing['amount'] ?? '') ?>"></div><div class="span-2"><label>Description</label><textarea name="description"><?= h($editing['description'] ?? '') ?></textarea></div><div class="span-2"><label>External URL</label><input name="url" value="<?= h($editing['url'] ?? '') ?>"></div><div><label>Topics (comma-separated)</label><input name="topics" value="<?= h($editing['topics'] ?? '') ?>"></div><div><label>Geography (comma-separated)</label><input name="geography" value="<?= h($editing['geography'] ?? '') ?>"></div><div class="span-2 actions-row"><button class="primary-btn" type="submit">Save</button><a class="ghost-btn" href="index.php?page=funding">Cancel</a></div></form></div>
<?php endif; ?>
<?php if ($viewing): ?>
<div class="panel modalish"><div class="head-row"><h2><?= h($viewing['title']) ?></h2><a class="ghost-btn" href="index.php?page=funding">Close</a></div><p><span class="badge <?= status_class($viewing['status']) ?>"><?= h($viewing['status'] ?: 'n/a') ?></span></p><div class="detail-grid"><div><strong>Funder:</strong> <?= h($viewing['funder']) ?></div><div><strong>Deadline:</strong> <?= h(format_deadline($viewing['deadline'])) ?></div><div><strong>Amount:</strong> <?= h($viewing['amount']) ?></div></div><p class="muted block"><?= nl2br(h($viewing['description'])) ?></p><?php if($fundingCallSummary): ?><div style="margin-top:12px;padding:12px 14px;background:#eaf6f0;border:1px solid #c3dfd0;border-radius:10px"><div style="font-size:10px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#1a6b5a;margin-bottom:6px">AI Summary</div><p style="margin:0;font-size:14px;line-height:1.6;color:#1c2a24"><?= h($fundingCallSummary['summary']) ?></p></div><?php endif; ?><div class="tag-row"><?php foreach(parse_tags($viewing['topics']) as $tag): ?><span class="tag topic-tag"><?= h($tag) ?></span><?php endforeach; ?></div><div class="tag-row"><?php foreach(format_geography_tags($viewing['geography']) as $tag): ?><span class="tag geo-tag"><?= h($tag) ?></span><?php endforeach; ?></div><div style="margin-top:18px;border-top:1px solid var(--line);padding-top:14px"><div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:10px">Top Researcher Matches</div><?php if($topResearcherMatches): ?><?php foreach($topResearcherMatches as $tm): ?><?php $score=$tm['score_ai']??$tm['score_keyword']; $isAi=$tm['score_ai']!==null; $name=trim($tm['first_name'].' '.$tm['last_name']); ?><div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f4f4f4"><span class="badge score-badge <?= $isAi?'ai-score':'kw-score' ?>"><?= $score ?><?= $isAi?'%':' pts' ?></span><div style="flex:1;min-width:0"><a href="index.php?page=researchers&view=<?= (int)$tm['id'] ?>" style="font-weight:600;font-size:14px"><?= h($name) ?></a><?php if($tm['institution']): ?><span class="muted" style="font-size:12px"> · <?= h($tm['institution']) ?></span><?php endif; ?><?php if($tm['explanation']): ?><p class="muted small" style="margin:2px 0 0;font-style:italic"><?= h($tm['explanation']) ?></p><?php endif; ?></div><a class="ghost-btn" href="index.php?page=messages&mode=compose&recipient_email=<?= urlencode($tm['email']) ?>&recipient_name=<?= urlencode($name) ?>" style="font-size:12px;padding:5px 10px">Contact</a></div><?php endforeach; ?><?php else: ?><p class="muted small">No AI scores yet. <a href="index.php?page=matching&funding_call_id=<?= (int)$viewing['id'] ?>">Run matching →</a></p><?php endif; ?></div><?php if($viewing['url']): ?><div style="margin-top:18px;display:flex;gap:12px"><a href="<?= h($viewing['url']) ?>" target="_blank" class="primary-btn">Apply Now</a><a href="<?= h($viewing['url']) ?>" target="_blank" class="ghost-btn">View Full Details</a></div><?php endif; ?></div>
<?php endif; ?>

<div class="lk-results">
    <div class="lk-results-head">
        <span><?= count($filtered) ?> result<?= count($filtered) !== 1 ? 's' : '' ?></span>
    </div>

<?php if ($tab==='saved'): ?>
    <?php if (!$saved): ?><div class="empty-state panel">No saved opportunities yet.</div><?php endif; ?>
    <?php foreach ($saved as $s): ?>
        <div class="panel list-card"><div class="card-row"><div class="card-main"><h3><?= h($s['funding_call_title']) ?></h3><div class="muted">Saved opportunity</div><form method="post" class="inline-form"><input type="hidden" name="action" value="save_note"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><textarea name="notes" placeholder="Add a note..."><?= h($s['notes']) ?></textarea><button class="ghost-btn" type="submit">Save note</button></form></div></div></div>
    <?php endforeach; ?>
<?php else: ?>
    <?php if (!$filtered): ?><div class="empty-state panel">No funding calls found.</div><?php endif; ?>
    <?php foreach ($paginatedFiltered as $fc): ?>
    <div class="panel list-card">
        <div class="card-row"><div class="card-main"><div class="title-line"><h3><?= h($fc['title']) ?></h3><span class="badge <?= status_class($fc['status']) ?>"><?= h($fc['status'] ?: 'n/a') ?></span></div><div class="muted">Funder: <?= h($fc['funder']) ?><?php if($fc['deadline']): ?> · Deadline: <?= h(format_deadline($fc['deadline'])) ?><?php endif; ?><?php if($fc['amount']): ?> · <?= h($fc['amount']) ?><?php endif; ?></div><div class="mini-label">Topics:</div><div class="tag-row"><?php foreach(array_slice(parse_tags($fc['topics']),0,4) as $tag): ?><span class="tag topic-tag"><?= h($tag) ?></span><?php endforeach; ?></div><div class="mini-label">Geography:</div><div class="tag-row"><?php foreach(array_slice(format_geography_tags($fc['geography']),0,3) as $tag): ?><span class="tag geo-tag"><?= h($tag) ?></span><?php endforeach; ?></div></div><div class="card-actions wrap-actions"><form method="post"><input type="hidden" name="action" value="save_opportunity"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="funding_call_id" value="<?= (int)$fc['id'] ?>"><input type="hidden" name="funding_call_title" value="<?= h($fc['title']) ?>"><button class="ghost-btn" type="submit"><?= isset($savedMap[$fc['id']]) ? 'Unsave' : 'Save' ?></button></form><a class="ghost-btn" href="index.php?page=funding&view=<?= (int)$fc['id'] ?>">View</a><?php if ($canManage($fc)): ?><a class="ghost-btn" href="index.php?page=funding&edit=<?= (int)$fc['id'] ?>">Edit</a><form method="post" onsubmit="return confirm('Delete funding call?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$fc['id'] ?>"><button class="danger-btn" type="submit">Delete</button></form><?php endif; ?></div></div>
    </div>
    <?php endforeach; ?>

    <!-- Pagination -->
    <?php if ($filtered && $fundingPaginator->getTotalPages() > 1): ?>
    <div style="margin-top:20px">
        <?php require __DIR__ . '/../components/pagination.php';
        $extraParams = array_filter($_GET, fn($k) => !in_array($k, ['page', 'p']), ARRAY_FILTER_USE_KEY);
        render_pagination($fundingPaginator, 'p', 'index.php?page=funding', $extraParams);
        ?>
    </div>
    <?php endif; ?>
<?php endif; ?>
    </div><!-- .lk-results -->
</div><!-- .lk-layout -->

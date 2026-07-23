<?php
/* ── Email link token verification (before require_login) ─── */
$emailLinkFor    = strtolower(trim($_GET['for'] ?? ''));
$emailLinkToken  = trim($_GET['mt'] ?? '');
$emailLinkThread = (int)($_GET['thread'] ?? 0);
$emailLinkWrongUser = false;

if ($emailLinkFor && $emailLinkToken && $emailLinkThread) {
    @$_mcfg = require __DIR__ . '/../../../config/mail.php';
    $notifySecret = is_array($_mcfg) ? ($_mcfg['notify_secret'] ?? '') : '';
    $tokenValid = $notifySecret && hash_equals(
        generate_message_link_token($emailLinkFor, $emailLinkThread, $notifySecret),
        $emailLinkToken
    );
    if ($tokenValid) {
        if (!is_logged_in()) {
            init_session();
            $_SESSION['login_return'] = http_build_query($_GET);
            redirect_to('login');
        } elseif (strtolower($_SESSION['user_email'] ?? '') !== $emailLinkFor) {
            $emailLinkWrongUser = true;
        }
    }
}

require_login();

// Check if user is approved to access messaging
if (!is_approved()) {
    set_flash('info', 'Your account is pending admin approval. You can access messaging once approved.');
    redirect_to('researchers');
}

$user = current_user();

// Check if user accessed via email link for wrong account — log out and redirect to login
if ($emailLinkWrongUser) {
    session_unset();
    session_destroy();
    session_start();
    session_regenerate_id(true);
    set_flash('info', 'Please log in with the account that received this message link.');
    $redirectUrl = 'index.php?page=login';
    if (!empty($_SERVER['QUERY_STRING'])) {
        $redirectUrl .= '&' . $_SERVER['QUERY_STRING'];
    }
    header("Location: $redirectUrl");
    ob_end_clean();
    exit;
}

/* ── POST ACTIONS ─────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* Send new message */
    if ($action === 'send') {
        $recipientType  = in_array($_POST['recipient_type'] ?? '', ['network','individual'])
                          ? $_POST['recipient_type'] : 'network';
        $recipientEmail = trim($_POST['recipient_email'] ?? '');
        $recipientName  = trim($_POST['recipient_name']  ?? '');
        $subject        = trim($_POST['subject']         ?? '');
        $body           = trim($_POST['body']            ?? '');
        $messageType    = trim($_POST['message_type']    ?? 'general');
        $fcId           = (int)($_POST['funding_call_id']   ?? 0);
        $fcTitle        = trim($_POST['funding_call_title'] ?? '');

        if ($subject === '' || $body === '') {
            set_flash('error', 'Subject and message body are required.');
            redirect_to('messages', ['tab' => 'compose']);
        }
        if ($recipientType === 'individual' && $recipientEmail === '') {
            set_flash('error', 'Please select a recipient.');
            redirect_to('messages', ['tab' => 'compose']);
        }

        $senderEmail = $user['email'];
        $senderName  = $user['name'];
        $isRead = 0;

        $stmt = $conn->prepare(
            'INSERT INTO messages (sender_email, sender_name, recipient_type, recipient_email,
             recipient_name, subject, body, message_type, funding_call_id, funding_call_title, is_read)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('ssssssssisi',
            $senderEmail, $senderName, $recipientType, $recipientEmail,
            $recipientName, $subject, $body, $messageType, $fcId, $fcTitle, $isRead
        );
        $stmt->execute();
        $msgId = (int)$conn->insert_id;

        // Self-reference: thread_id = own id
        $fixThread = $conn->prepare('UPDATE messages SET thread_id = ? WHERE id = ?');
        $fixThread->bind_param('ii', $msgId, $msgId);
        $fixThread->execute();

        $mailCfg   = require __DIR__ . '/../../../config/mail.php';
        $appUrl    = rtrim($mailCfg['app_url'] ?? ('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['REQUEST_URI']) . '/public'), '/');

        if ($recipientType === 'individual' && $recipientEmail !== '') {
            // Individual — one email with deep thread link + token
            $notifySecret = $mailCfg['notify_secret'] ?? '';
            $token = $notifySecret
                ? generate_message_link_token($recipientEmail, $msgId, $notifySecret)
                : '';
            $threadUrl = $appUrl . '/index.php?page=messages&tab=inbox&thread=' . $msgId
                . ($token ? '&for=' . urlencode($recipientEmail) . '&mt=' . $token : '');
            send_notification_email(
                $recipientEmail,
                "New message from {$senderName}: {$subject}",
                mail_tpl_new_message($senderName, $subject, $body, $appUrl, $threadUrl),
                $senderEmail,
                $senderName
            );
        } elseif ($recipientType === 'network') {
            // Network broadcast — email every active user except the sender (no token)
            $threadUrl = $appUrl . '/index.php?page=messages&tab=inbox&thread=' . $msgId;
            $bq = $conn->prepare("SELECT name, email FROM users WHERE status = 'active' AND deleted_at IS NULL AND email != '' AND email IS NOT NULL AND email != ?");
            $bq->bind_param('s', $senderEmail); $bq->execute();
            $bqResult = $bq->get_result();
            $bcastMsgs = [];
            while ($bu = $bqResult->fetch_assoc()) {
                $bcastMsgs[] = [
                    'to'      => $bu['email'],
                    'subject' => '[FACT Network] ' . $subject,
                    'html'    => mail_tpl_broadcast_message($senderName, $subject, $body, $threadUrl),
                ];
            }
            if (!empty($bcastMsgs)) {
                enqueue_job($conn, 'send_digest', ['messages' => $bcastMsgs]);
            }
        }

        set_flash('success', 'Message sent.');
        redirect_to('messages');
    }

    /* Reply to a thread */
    if ($action === 'reply') {
        $threadId = (int)($_POST['thread_id'] ?? 0);
        $body     = trim($_POST['body'] ?? '');

        if (!$threadId || $body === '') {
            set_flash('error', 'Reply body is required.');
            redirect_to('messages', ['thread' => $threadId]);
        }

        // Fetch root message for context
        $rootStmt = $conn->prepare('SELECT * FROM messages WHERE id = ? LIMIT 1');
        $rootStmt->bind_param('i', $threadId); $rootStmt->execute();
        $root = $rootStmt->get_result()->fetch_assoc();

        if (!$root) {
            set_flash('error', 'Thread not found.');
            redirect_to('messages');
        }

        // Ownership guard — only participants can reply
        $canReply = ($root['recipient_type'] === 'network')
                 || ($root['sender_email']    === $user['email'])
                 || ($root['recipient_email'] === $user['email']);
        if (!$canReply) {
            set_flash('error', 'You do not have permission to reply to that thread.');
            redirect_to('messages');
        }

        // Determine reply target
        $replyToEmail = ($root['sender_email'] === $user['email'])
                        ? $root['recipient_email']
                        : $root['sender_email'];
        $replyToName  = ($root['sender_email'] === $user['email'])
                        ? $root['recipient_name']
                        : $root['sender_name'];
        $replySubject = (strpos($root['subject'], 'Re:') === 0)
                        ? $root['subject']
                        : 'Re: ' . $root['subject'];

        $senderEmail = $user['email'];
        $senderName  = $user['name'];
        $replyType   = 'individual';
        $isRead      = 0;
        $parentId    = $threadId;
        $msgType     = $root['message_type'] ?: 'general';

        $stmt = $conn->prepare(
            'INSERT INTO messages (thread_id, parent_id, sender_email, sender_name, recipient_type,
             recipient_email, recipient_name, subject, body, message_type, is_read)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iissssssssi',
            $threadId, $parentId, $senderEmail, $senderName, $replyType,
            $replyToEmail, $replyToName, $replySubject, $body, $msgType, $isRead
        );
        $stmt->execute();

        // Email notification with deep link to the thread + token
        if ($replyToEmail) {
            $mailCfg   = require __DIR__ . '/../../../config/mail.php';
            $appUrl    = rtrim($mailCfg['app_url'] ?? ('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['REQUEST_URI']) . '/public'), '/');
            $notifySecret = $mailCfg['notify_secret'] ?? '';
            $token = $notifySecret
                ? generate_message_link_token($replyToEmail, $threadId, $notifySecret)
                : '';
            $threadUrl = $appUrl . '/index.php?page=messages&tab=inbox&thread=' . $threadId
                . ($token ? '&for=' . urlencode($replyToEmail) . '&mt=' . $token : '');
            send_notification_email(
                $replyToEmail,
                "{$senderName} replied: {$replySubject}",
                mail_tpl_new_message($senderName, $replySubject, $body, $appUrl, $threadUrl),
                $senderEmail,
                $senderName
            );
        }

        set_flash('success', 'Reply sent.');
        redirect_to('messages', ['thread' => $threadId]);
    }

    /* Delete a single message (soft → Trash) */
    if ($action === 'delete') {
        $msgId    = (int)($_POST['message_id'] ?? 0);
        $threadId = (int)($_POST['thread_id']  ?? 0);
        if ($msgId) {
            $del = $conn->prepare(
                'UPDATE messages SET is_deleted = 1, deleted_at = NOW()
                 WHERE id = ? AND (sender_email = ? OR recipient_email = ? OR recipient_type = "network")'
            );
            $del->bind_param('iss', $msgId, $user['email'], $user['email']);
            $del->execute();
            $subStmt = $conn->prepare('SELECT subject, thread_id FROM messages WHERE id = ? LIMIT 1');
            $subStmt->bind_param('i', $msgId); $subStmt->execute();
            $subRow = $subStmt->get_result()->fetch_assoc();
            $rootId = (int)($subRow['thread_id'] ?? $msgId);
            $_SESSION['undo_trash'] = ['thread_id' => $rootId ?: $msgId, 'subject' => $subRow['subject'] ?? '', 'ts' => time()];
        }
        redirect_to('messages', $threadId ? ['tab' => 'inbox', 'thread' => $threadId] : ['tab' => 'inbox']);
    }

    /* Delete entire thread (soft → Trash) */
    if ($action === 'delete_thread') {
        $threadId = (int)($_POST['thread_id'] ?? 0);
        if ($threadId) {
            $del = $conn->prepare(
                'UPDATE messages SET is_deleted = 1, deleted_at = NOW()
                 WHERE id = ? OR thread_id = ?'
            );
            $del->bind_param('ii', $threadId, $threadId);
            $del->execute();
            $subStmt = $conn->prepare('SELECT subject FROM messages WHERE id = ? LIMIT 1');
            $subStmt->bind_param('i', $threadId); $subStmt->execute();
            $subRow = $subStmt->get_result()->fetch_assoc();
            $_SESSION['undo_trash'] = ['thread_id' => $threadId, 'subject' => $subRow['subject'] ?? '', 'ts' => time()];
        }
        redirect_to('messages', ['tab' => 'inbox']);
    }

    /* Restore a thread (used by Trash view + undo toast AJAX) */
    if ($action === 'restore_thread') {
        $threadId = (int)($_POST['thread_id'] ?? 0);
        if ($threadId) {
            $rest = $conn->prepare(
                'UPDATE messages SET is_deleted = 0, deleted_at = NULL WHERE id = ? OR thread_id = ?'
            );
            $rest->bind_param('ii', $threadId, $threadId);
            $rest->execute();
        }
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            ob_end_flush();
            exit;
        }
        set_flash('success', 'Conversation restored.');
        redirect_to('messages', ['tab' => 'inbox']);
    }

    /* Permanently delete a thread */
    if ($action === 'delete_forever') {
        $threadId = (int)($_POST['thread_id'] ?? 0);
        if ($threadId) {
            $delForever = $conn->prepare('DELETE FROM messages WHERE id = ? OR thread_id = ?');
            $delForever->bind_param('ii', $threadId, $threadId);
            $delForever->execute();
        }
        set_flash('success', 'Conversation permanently deleted.');
        redirect_to('messages', ['tab' => 'trash']);
    }

    /* Empty entire trash for this user */
    if ($action === 'empty_trash') {
        $em = $user['email'];
        $tq = $conn->prepare(
            "SELECT id FROM messages WHERE (thread_id = id OR thread_id IS NULL) AND is_deleted = 1
             AND (sender_email = ? OR recipient_email = ? OR recipient_type = 'network')"
        );
        $tq->bind_param('ss', $em, $em); $tq->execute();
        $tqRes = $tq->get_result();
        $trashIds = [];
        while ($r = $tqRes->fetch_assoc()) $trashIds[] = (int)$r['id'];
        foreach ($trashIds as $tid) {
            $delTid = $conn->prepare('DELETE FROM messages WHERE id = ? OR thread_id = ?');
            $delTid->bind_param('ii', $tid, $tid);
            $delTid->execute();
        }
        set_flash('success', 'Trash emptied.');
        redirect_to('messages', ['tab' => 'trash']);
    }

    /* Mark all inbox as read */
    if ($action === 'mark_all_read') {
        $mar = $conn->prepare(
            'UPDATE messages SET is_read = 1
             WHERE sender_email != ? AND is_read = 0
             AND (recipient_type = "network" OR recipient_email = ?)'
        );
        $mar->bind_param('ss', $user['email'], $user['email']);
        $mar->execute();
        set_flash('success', 'All messages marked as read.');
        redirect_to('messages');
    }
}

/* ── LOAD DATA ────────────────────────────────────────────────────── */
require_once __DIR__ . '/../../core/Paginator.php';

$tab      = $_GET['tab']    ?? 'inbox';
$threadId = (int)($_GET['thread'] ?? 0);
$page     = max(1, (int)($_GET['p'] ?? 1));
$itemsPerPage = 30;

// Auto-switch to compose if contact params present
if (isset($_GET['recipient_email']) || isset($_GET['compose'])) {
    $tab = 'compose';
}

/* Mark messages as read BEFORE fetching counts (so counts reflect actual DB state) */
if ($threadId > 0) {
    $mr = $conn->prepare(
        'UPDATE messages SET is_read = 1
         WHERE thread_id = ? AND sender_email != ? AND is_read = 0'
    );
    $mr->bind_param('is', $threadId, $user['email']); $mr->execute();
}

/* Fix orphaned messages: convert replies with deleted threads to root messages */
/* This happens when a message is deleted but its replies still exist */
@$conn->query(
    "UPDATE messages m
     LEFT JOIN messages root ON root.id = m.thread_id
     SET m.thread_id = m.id, m.parent_id = NULL
     WHERE root.id IS NULL
       AND m.thread_id IS NOT NULL
       AND m.thread_id != m.id"
);

/* Inbox threads — count total */
$inboxCountSql = "
    SELECT COUNT(DISTINCT m.id) c
    FROM messages m
    WHERE (m.thread_id = m.id OR m.thread_id IS NULL)
      AND m.is_deleted = 0
      AND (
        (m.sender_email != ? AND (m.recipient_type = 'network' OR m.recipient_email = ?))
        OR
        (m.sender_email = ? AND EXISTS (SELECT 1 FROM messages r WHERE r.thread_id = m.id AND r.id != m.id AND r.sender_email != ? AND r.is_deleted = 0))
      )
";
$inboxCountStmt = $conn->prepare($inboxCountSql);
$inboxCountStmt->bind_param('ssss', $user['email'], $user['email'], $user['email'], $user['email']);
$inboxCountStmt->execute();
$inboxCountRow = $inboxCountStmt->get_result()->fetch_assoc();
$inboxTotal = (int)($inboxCountRow['c'] ?? 0);
$inboxPaginator = new Paginator($inboxTotal, $itemsPerPage, $page);

/* Inbox threads — root messages: either (1) received by user, or (2) sent by user with replies */
$inboxSql = "
    SELECT m.*,
        COALESCE(
            (SELECT MAX(r.created_at) FROM messages r WHERE r.thread_id = m.id AND r.is_deleted = 0),
            m.created_at
        ) AS last_at,
        (SELECT COUNT(*) FROM messages r WHERE r.thread_id = m.id AND r.id != m.id AND r.is_deleted = 0) AS reply_count,
        (SELECT COUNT(*) FROM messages r WHERE r.thread_id = m.id AND r.sender_email != ? AND r.is_read = 0 AND r.is_deleted = 0) AS unread_count
    FROM messages m
    WHERE (m.thread_id = m.id OR m.thread_id IS NULL)
      AND m.is_deleted = 0
      AND (
        (m.sender_email != ? AND (m.recipient_type = 'network' OR m.recipient_email = ?))
        OR
        (m.sender_email = ? AND EXISTS (SELECT 1 FROM messages r WHERE r.thread_id = m.id AND r.id != m.id AND r.sender_email != ? AND r.is_deleted = 0))
      )
    ORDER BY last_at DESC
    " . $inboxPaginator->getSQLLimit() . "
";
$inboxStmt = $conn->prepare($inboxSql);
$inboxStmt->bind_param('sssss', $user['email'], $user['email'], $user['email'], $user['email'], $user['email']);
$inboxStmt->execute();
$inboxThreads = $inboxStmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* Sent threads — count total */
$sentCountSql = "
    SELECT COUNT(DISTINCT m.id) c
    FROM messages m
    WHERE (m.thread_id = m.id OR m.thread_id IS NULL)
      AND m.is_deleted = 0
      AND m.sender_email = ?
";
$sentCountStmt = $conn->prepare($sentCountSql);
$sentCountStmt->bind_param('s', $user['email']);
$sentCountStmt->execute();
$sentCountRow = $sentCountStmt->get_result()->fetch_assoc();
$sentTotal = (int)($sentCountRow['c'] ?? 0);
$sentPaginator = new Paginator($sentTotal, $itemsPerPage, $page);

/* Sent threads — root messages sent by this user */
$sentSql = "
    SELECT m.*,
        COALESCE(
            (SELECT MAX(r.created_at) FROM messages r WHERE r.thread_id = m.id AND r.is_deleted = 0),
            m.created_at
        ) AS last_at,
        (SELECT COUNT(*) FROM messages r WHERE r.thread_id = m.id AND r.id != m.id AND r.is_deleted = 0) AS reply_count
    FROM messages m
    WHERE (m.thread_id = m.id OR m.thread_id IS NULL)
      AND m.is_deleted = 0
      AND m.sender_email = ?
    ORDER BY last_at DESC
    " . $sentPaginator->getSQLLimit() . "
";
$sentStmt = $conn->prepare($sentSql);
$sentStmt->bind_param('s', $user['email']);
$sentStmt->execute();
$sentThreads = $sentStmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* Total unread */
$totalUnread = array_sum(array_column($inboxThreads, 'unread_count'));

/* Thread view */
$threadMessages = [];
$threadRoot     = null;
if ($threadId > 0) {
    $rs = $conn->prepare('SELECT * FROM messages WHERE id = ? AND is_deleted = 0 LIMIT 1');
    $rs->bind_param('i', $threadId); $rs->execute();
    $threadRoot = $rs->get_result()->fetch_assoc();

    if ($threadRoot) {
        // Ownership guard: user must be sender, recipient, or it's a network broadcast
        $canView = ($threadRoot['recipient_type'] === 'network')
                || ($threadRoot['sender_email']    === $user['email'])
                || ($threadRoot['recipient_email'] === $user['email']);
        if (!$canView) {
            set_flash('error', 'You do not have permission to view that thread.');
            redirect_to('messages');
        }

        $ts = $conn->prepare(
            'SELECT * FROM messages WHERE thread_id = ? AND is_deleted = 0 ORDER BY created_at ASC'
        );
        $ts->bind_param('i', $threadId); $ts->execute();
        $threadMessages = $ts->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

/* Compose form data — all active users except self, grouped by role */
$recipients = [];
$rq = $conn->prepare("SELECT name, email, role FROM users WHERE status = 'active' AND deleted_at IS NULL AND email != '' AND email IS NOT NULL AND email != ? ORDER BY FIELD(role,'admin','researcher','funder'), name ASC");
$rq->bind_param('s', $user['email']); $rq->execute();
$rqRes = $rq->get_result();
while ($r = $rqRes->fetch_assoc()) $recipients[] = $r;

$fundingCalls = [];
$res2 = $conn->query('SELECT id, title FROM funding_calls WHERE deleted_at IS NULL ORDER BY title ASC');
while ($row = $res2->fetch_assoc()) $fundingCalls[] = $row;

$prefillEmail   = trim($_GET['recipient_email'] ?? '');
$prefillName    = trim($_GET['recipient_name']  ?? '');
$prefillSubject = trim($_GET['subject']         ?? '');
$prefillBody    = trim($_GET['body']            ?? '');

/* ── HELPERS ──────────────────────────────────────────────────────── */
function msg_avatar_initial(string $name): string {
    return strtoupper(mb_substr(trim($name) ?: '?', 0, 1));
}
function msg_format_time(string $ts): string {
    $t   = strtotime($ts);
    $now = time();
    if ($t > $now - 86400)  return date('g:i A', $t);
    if ($t > $now - 604800) return date('D', $t);
    return date('M j', $t);
}
function msg_format_full_time(string $ts): string {
    return date('M j, Y \a\t g:i A', strtotime($ts));
}

/* Trash threads — root messages soft-deleted within last 30 days */
$trashSql = "
    SELECT m.*,
        GREATEST(0, DATEDIFF(DATE_ADD(COALESCE(m.deleted_at, NOW()), INTERVAL 30 DAY), NOW())) AS expires_days
    FROM messages m
    WHERE (m.thread_id = m.id OR m.thread_id IS NULL)
      AND m.is_deleted = 1
      AND (m.deleted_at IS NULL OR m.deleted_at > DATE_SUB(NOW(), INTERVAL 30 DAY))
      AND (m.sender_email = ? OR m.recipient_email = ? OR m.recipient_type = 'network')
    ORDER BY m.deleted_at DESC
";
$trashStmt = $conn->prepare($trashSql);
$trashStmt->bind_param('ss', $user['email'], $user['email']);
$trashStmt->execute();
$trashThreads = $trashStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$trashCount   = count($trashThreads);

/* Individually deleted messages within an active thread (for thread view) */
$deletedInThread = [];
if ($threadId > 0 && $threadRoot && !$threadRoot['is_deleted']) {
    $ds = $conn->prepare(
        'SELECT * FROM messages WHERE thread_id = ? AND id != ? AND is_deleted = 1 ORDER BY created_at ASC'
    );
    $ds->bind_param('ii', $threadId, $threadId); $ds->execute();
    $deletedInThread = $ds->get_result()->fetch_all(MYSQLI_ASSOC);
}

/* Undo toast — consumed once after a soft delete */
$undoTrash = null;
if (!empty($_SESSION['undo_trash']) && (time() - ($_SESSION['undo_trash']['ts'] ?? 0)) < 30) {
    $undoTrash = $_SESSION['undo_trash'];
    unset($_SESSION['undo_trash']);
}

/* Active list for current tab */
$activeList = $tab === 'sent' ? $sentThreads : $inboxThreads;
?>

<style>
/* ── Messages layout ── */
.msg-page{display:flex;flex-direction:column;gap:16px}
.msg-topbar{padding:18px 20px 14px}
.msg-head-row{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px}
.msg-tabs{display:flex;gap:6px}
.msg-tab{padding:8px 18px;border-radius:999px;font-size:13px;font-weight:700;background:#f2f5f3;color:var(--text);text-decoration:none;border:1px solid transparent;transition:background .15s}
.msg-tab:hover{background:#e6f0ec;color:var(--primary);text-decoration:none}
.msg-tab.active{background:var(--primary);color:#fff}
.msg-unread-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:#b54646;margin-left:5px;vertical-align:middle}

/* ── Thread panel ── */
.thread-panel{border-radius:16px;overflow:hidden;margin-bottom:4px}
.thread-header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:16px 20px;border-bottom:1px solid var(--line)}
.thread-header-left h2{margin:0 0 4px;font-size:18px}
.thread-header-meta{font-size:13px;color:var(--muted)}
.thread-messages{padding:0}
.thread-msg{padding:16px 20px;border-bottom:1px solid #f0f4f1;transition:background .1s}
.thread-msg:last-child{border-bottom:none}
.thread-msg-header{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.thread-avatar{width:34px;height:34px;border-radius:50%;background:#e6f0ec;color:var(--primary);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;flex-shrink:0}
.thread-avatar.mine{background:#edf3fb;color:#315f90}
.thread-msg-name{font-size:14px;font-weight:700;line-height:1.2}
.thread-msg-time{font-size:12px;color:var(--muted)}
.thread-body{font-size:14px;line-height:1.7;white-space:pre-wrap;padding-left:44px;color:#2a3a32}
.thread-msg-actions{padding-left:44px;margin-top:8px}
.thread-reply-wrap{padding:16px 20px;border-top:2px solid var(--line);background:#fafcfb}
.thread-reply-label{font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:8px}
.thread-reply-wrap textarea{min-height:80px;resize:vertical;border-radius:10px;border:1px solid var(--line);width:100%;padding:10px 12px;font:inherit;font-size:14px}
.thread-reply-wrap textarea:focus{outline:2px solid var(--primary);border-color:transparent}
.thread-reply-row{display:flex;gap:10px;align-items:center;margin-top:10px}

/* ── Message list rows ── */
.msg-list{padding:0 4px}
.msg-row{display:flex;align-items:flex-start;gap:12px;padding:13px 16px;cursor:pointer;border-radius:12px;text-decoration:none;color:var(--text);transition:background .12s;margin-bottom:2px}
.msg-row:hover{background:#f6faf8;text-decoration:none}
.msg-row.active-row{background:#f0f7f3;border-left:3px solid var(--primary)}
.msg-row.unread-row{background:#f5f8ff}
.msg-row.unread-row .msg-subject{font-weight:700}
.msg-avatar-sm{width:36px;height:36px;border-radius:50%;background:#e6f0ec;color:var(--primary);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;flex-shrink:0}
.msg-avatar-sm.net{background:#f3f0ea;color:#8a6020}
.msg-info{flex:1;min-width:0}
.msg-from{font-size:13px;font-weight:700;margin-bottom:2px}
.msg-subject{font-size:14px;margin-bottom:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.msg-preview{font-size:13px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.msg-right{text-align:right;flex-shrink:0;min-width:54px}
.msg-time-sm{font-size:12px;color:var(--muted);margin-bottom:5px}
.msg-reply-badge{display:inline-block;background:#e8f0ec;color:var(--primary);border-radius:999px;font-size:11px;font-weight:700;padding:2px 8px}
.msg-unread-badge{display:inline-block;background:#b54646;color:#fff;border-radius:999px;font-size:10px;font-weight:800;padding:2px 7px}
.msg-divider{border:none;border-top:1px solid var(--line);margin:2px 0}
.msg-empty{padding:40px 20px;text-align:center;color:var(--muted)}
.msg-empty svg{display:block;margin:0 auto 12px;opacity:.3}

/* ── Compose panel ── */
.compose-panel{padding:20px 24px}
.compose-panel h2{margin:0 0 18px;font-size:20px}
.compose-recipient-row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}
.compose-recipient-row .type-indicator{font-size:13px;color:var(--muted);padding:4px 0}

/* ── Recipient autocomplete ── */
#recipient-dropdown {
    background: white;
    border: 1px solid var(--line);
    border-radius: 8px;
    max-height: 320px;
    overflow-y: auto;
}

#recipient-dropdown > div {
    padding: 10px 12px;
    border-bottom: 1px solid var(--line);
    cursor: pointer;
    transition: background 0.1s;
}

#recipient-dropdown > div:hover {
    background: #f9f9f9;
}

#recipient-dropdown > div:last-child {
    border-bottom: none;
}

@media(max-width:680px){
  .thread-body,.thread-msg-actions{padding-left:0;margin-top:8px}
  .msg-right{display:none}
}

/* ── Trash tab ── */
.trash-list{padding:0 4px}
.trash-row{display:flex;align-items:center;gap:12px;padding:13px 16px;border-radius:12px;background:#fff;margin-bottom:3px;border:1px solid #f0f0f0}
.trash-row:hover{background:#fef9f9}
.trash-avatar{width:36px;height:36px;border-radius:50%;background:#fde8e8;color:#b54646;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;flex-shrink:0}
.trash-info{flex:1;min-width:0}
.trash-from{font-size:13px;font-weight:700;margin-bottom:2px;color:#666}
.trash-subject{font-size:14px;margin-bottom:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.trash-meta{font-size:12px;color:#999}
.trash-expires{display:inline-block;font-size:11px;padding:2px 8px;border-radius:999px;margin-left:8px;font-weight:600}
.trash-expires.soon{background:#fde8e8;color:#b54646}
.trash-expires.ok{background:#f0f0f0;color:#888}
.trash-actions{display:flex;gap:6px;flex-shrink:0}
.trash-restore-btn{padding:5px 12px;font-size:12px;font-weight:700;border-radius:8px;border:1px solid var(--primary);color:var(--primary);background:#fff;cursor:pointer;transition:background .12s}
.trash-restore-btn:hover{background:#e8f4ee}
.trash-del-btn{padding:5px 12px;font-size:12px;font-weight:700;border-radius:8px;border:1px solid #d9d9d9;color:#b54646;background:#fff;cursor:pointer;transition:background .12s}
.trash-del-btn:hover{background:#fde8e8}
.trash-empty-state{padding:40px 20px;text-align:center;color:var(--muted)}
.trash-topbar{display:flex;align-items:center;justify-content:space-between;padding:10px 16px 8px;border-bottom:1px solid #f0f0f0;margin-bottom:4px}
.trash-note{font-size:12px;color:#999;padding:8px 16px 4px}

/* ── Deleted-in-thread section ── */
.deleted-in-thread{border-top:1px dashed #f0f0f0;margin-top:4px}
.deleted-in-thread-hd{display:flex;align-items:center;gap:8px;padding:10px 20px;cursor:pointer;font-size:13px;color:#999;user-select:none}
.deleted-in-thread-hd:hover{color:#b54646}
.deleted-in-thread-hd svg{transition:transform .2s}
.deleted-in-thread-hd.open svg{transform:rotate(90deg)}
.deleted-msgs-list{display:none}
.deleted-msgs-list.open{display:block}
.thread-msg.deleted-msg{background:#fdf8f8;opacity:.8}
.thread-msg.deleted-msg .thread-body{text-decoration:line-through;color:#aaa}

/* ── Undo toast ── */
.undo-toast{position:fixed;bottom:28px;left:50%;transform:translateX(-50%);background:#1e2d24;color:#fff;border-radius:12px;padding:12px 18px;display:flex;align-items:center;gap:14px;font-size:14px;z-index:9999;box-shadow:0 4px 24px rgba(0,0,0,.25);animation:toastIn .25s ease;min-width:300px}
@keyframes toastIn{from{opacity:0;transform:translateX(-50%) translateY(16px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}
.undo-toast.fading{animation:toastOut .35s ease forwards}
@keyframes toastOut{to{opacity:0;transform:translateX(-50%) translateY(16px)}}
.undo-toast-msg{flex:1}
.undo-btn{background:#3d9e67;color:#fff;border:none;border-radius:7px;padding:5px 14px;font-size:13px;font-weight:700;cursor:pointer}
.undo-btn:hover{background:#2f7d52}
.undo-close{background:none;border:none;color:#aaa;font-size:18px;cursor:pointer;padding:0 2px;line-height:1}
.undo-close:hover{color:#fff}
</style>

<div style="background-image:linear-gradient(135deg, rgba(255,255,255,0.60) 0%, rgba(255,255,255,0.55) 100%), url('wheat.avif');background-size:cover;background-position:center;">
<div class="msg-page" data-current-tab="<?= h($tab) ?>" data-current-thread="<?= $threadId ?>">

<!-- ── Top bar ── -->
<div class="panel msg-topbar">
    <div class="msg-head-row">
        <div style="display:flex;align-items:center;gap:10px">
            <h1 style="margin:0">Messages</h1>
            <span class="badge" data-unread-count style="background:#b54646;color:#fff;<?= $totalUnread === 0 ? 'display:none' : '' ?>"><?= $totalUnread ?> unread</span>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <?php if ($totalUnread > 0): ?>
            <form method="post" style="display:inline">
                <input type="hidden" name="action" value="mark_all_read">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <button class="ghost-btn" type="submit" style="font-size:13px;padding:8px 12px">Mark all read</button>
            </form>
            <?php endif; ?>
            <a class="primary-btn" href="index.php?page=messages&tab=compose">Compose</a>
        </div>
    </div>
    <div class="msg-tabs">
        <a class="msg-tab <?= $tab === 'inbox'   ? 'active' : '' ?>"
           href="index.php?page=messages&tab=inbox<?= $threadId ? '&thread='.$threadId : '' ?>">
            Inbox
            <?php if ($totalUnread > 0): ?><span class="msg-unread-dot"></span><?php endif; ?>
        </a>
        <a class="msg-tab <?= $tab === 'sent'    ? 'active' : '' ?>"
           href="index.php?page=messages&tab=sent">Sent</a>
        <a class="msg-tab <?= $tab === 'compose' ? 'active' : '' ?>"
           href="index.php?page=messages&tab=compose">Compose</a>
        <a class="msg-tab <?= $tab === 'trash'   ? 'active' : '' ?>"
           href="index.php?page=messages&tab=trash"
           style="<?= $tab !== 'trash' && $trashCount > 0 ? 'color:#b54646' : '' ?>">
            Trash<?php if ($trashCount > 0): ?> <span style="font-size:11px;font-weight:800">(<?= $trashCount ?>)</span><?php endif; ?>
        </a>
    </div>
</div>

<!-- ── Thread view ── -->
<?php if ($threadId && $threadRoot && $tab !== 'compose'): ?>
<div class="panel thread-panel">
    <div class="thread-header">
        <div class="thread-header-left">
            <h2><?= h($threadRoot['subject'] ?: '(No subject)') ?></h2>
            <div class="thread-header-meta">
                <?php
                $typeLabel = ucwords(str_replace('-', ' ', $threadRoot['message_type'] ?: 'general'));
                echo h($typeLabel);
                if ($threadRoot['funding_call_title']) {
                    echo ' &nbsp;&middot;&nbsp; Re: <strong>' . h($threadRoot['funding_call_title']) . '</strong>';
                }
                ?>
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-shrink:0;align-items:center">
            <form method="post" onsubmit="return confirm('Move this entire conversation to Trash?')">
                <input type="hidden" name="action" value="delete_thread">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="thread_id" value="<?= $threadId ?>">
                <button class="danger-btn" type="submit" style="padding:7px 14px;font-size:13px">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;margin-right:4px"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                    Delete Thread
                </button>
            </form>
            <a class="ghost-btn" href="index.php?page=messages&tab=<?= h($tab) ?>" style="flex-shrink:0">Close</a>
        </div>
    </div>

    <div class="thread-messages">
        <?php foreach ($threadMessages as $tm):
            $isMine = ($tm['sender_email'] === $user['email']);
            $sName  = $tm['sender_name'] ?: $tm['sender_email'];
        ?>
        <div class="thread-msg">
            <div class="thread-msg-header">
                <div class="thread-avatar <?= $isMine ? 'mine' : '' ?>">
                    <?= msg_avatar_initial($sName) ?>
                </div>
                <div>
                    <div class="thread-msg-name"><?= h($isMine ? 'You' : $sName) ?></div>
                    <div class="thread-msg-time"><?= msg_format_full_time($tm['created_at']) ?></div>
                </div>
                <?php if ($tm['recipient_type'] === 'network'): ?>
                    <span class="badge badge-outline" style="font-size:11px;margin-left:auto">Network broadcast</span>
                <?php endif; ?>
            </div>
            <div class="thread-body"><?= nl2br(h($tm['body'])) ?></div>
            <div class="thread-msg-actions">
                <form method="post" style="display:inline" onsubmit="return confirm('Delete this message?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="message_id" value="<?= (int)$tm['id'] ?>">
                    <input type="hidden" name="thread_id" value="<?= $threadId ?>">
                    <button class="danger-btn" type="submit" style="padding:5px 12px;font-size:12px">Delete</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Individually deleted messages within this thread -->
    <?php if ($deletedInThread): ?>
    <div class="deleted-in-thread">
        <div class="deleted-in-thread-hd" id="del-in-thread-hd">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            <?= count($deletedInThread) ?> deleted message<?= count($deletedInThread) !== 1 ? 's' : '' ?> in this thread
        </div>
        <div class="deleted-msgs-list" id="del-in-thread-list">
            <?php foreach ($deletedInThread as $dm):
                $dmMine = ($dm['sender_email'] === $user['email']);
                $dmName = $dm['sender_name'] ?: $dm['sender_email'];
            ?>
            <div class="thread-msg deleted-msg">
                <div class="thread-msg-header">
                    <div class="thread-avatar <?= $dmMine ? 'mine' : '' ?>" style="opacity:.5">
                        <?= msg_avatar_initial($dmName) ?>
                    </div>
                    <div>
                        <div class="thread-msg-name" style="color:#aaa"><?= h($dmMine ? 'You' : $dmName) ?></div>
                        <div class="thread-msg-time"><?= msg_format_full_time($dm['created_at']) ?> &mdash; <em>deleted</em></div>
                    </div>
                </div>
                <div class="thread-body"><?= nl2br(h($dm['body'])) ?></div>
                <div class="thread-msg-actions">
                    <form method="post" style="display:inline">
                        <input type="hidden" name="action" value="restore_thread">
                        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="thread_id" value="<?= $threadId ?>">
                        <button class="ghost-btn" type="submit" style="padding:5px 12px;font-size:12px;color:var(--primary)">Restore</button>
                    </form>
                    <form method="post" style="display:inline" onsubmit="return confirm('Permanently delete this message?')">
                        <input type="hidden" name="action" value="delete_forever">
                        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="thread_id" value="<?= (int)$dm['id'] ?>">
                        <button class="danger-btn" type="submit" style="padding:5px 12px;font-size:12px">Delete Forever</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Reply form (only for individual threads, not network broadcasts) -->
    <?php if ($threadRoot['recipient_type'] === 'individual' || $threadRoot['sender_email'] !== $user['email']): ?>
    <div class="thread-reply-wrap">
        <div class="thread-reply-label">Reply</div>
        <form method="post">
            <input type="hidden" name="action" value="reply">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="thread_id" value="<?= $threadId ?>">
            <textarea name="body" placeholder="Write your reply…" required id="reply-body"></textarea>
            <div class="thread-reply-row">
                <button class="primary-btn" type="submit">Send Reply</button>
                <a class="ghost-btn" href="index.php?page=messages&tab=<?= h($tab) ?>">Cancel</a>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Compose form ── -->
<?php if ($tab === 'compose'): ?>
<div class="panel compose-panel">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px">
        <h2 style="margin:0">New Message</h2>
        <a class="ghost-btn" href="index.php?page=messages">Cancel</a>
    </div>
    <form method="post" class="form-grid one">
        <input type="hidden" name="action" value="send">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

        <div>
            <label>To</label>
            <div style="position:relative;margin-bottom:6px">
                <input
                    type="text"
                    id="recipient-input"
                    placeholder="Search by name or email, or broadcast to entire network…"
                    value="<?= h($prefillName ?: $prefillEmail) ?>"
                    style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:8px;font-family:inherit;font-size:14px"
                    autocomplete="off"
                >
                <div id="recipient-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;border:1px solid var(--line);border-top:none;border-radius:0 0 8px 8px;background:white;max-height:300px;overflow-y:auto;z-index:100;box-shadow:0 4px 6px rgba(0,0,0,0.1)">
                    <div style="padding:10px;border-bottom:1px solid var(--line);cursor:pointer;hover:background:#f9f9f9" onclick="selectRecipient('network', '', 'Entire Network')">
                        📢 Broadcast to Entire Network
                    </div>
                    <div id="recipient-results"></div>
                </div>
            </div>
            <input type="hidden" name="recipient_type"  id="f-rtype"  value="<?= $prefillEmail ? 'individual' : 'network' ?>">
            <input type="hidden" name="recipient_email" id="f-remail" value="<?= h($prefillEmail) ?>">
            <input type="hidden" name="recipient_name"  id="f-rname"  value="<?= h($prefillName) ?>">
        </div>

        <div>
            <label>Message Type</label>
            <select name="message_type">
                <option value="general">General</option>
                <option value="collaboration-request">Collaboration Request</option>
                <option value="opportunity-share">Opportunity Share</option>
                <option value="question">Question</option>
            </select>
        </div>

        <div>
            <label>Attach Funding Call <span style="font-weight:400">(optional)</span></label>
            <select id="fc-select">
                <option value="">None</option>
                <?php foreach ($fundingCalls as $fc): ?>
                <option value="<?= (int)$fc['id'] ?>" data-title="<?= h($fc['title']) ?>">
                    <?= h($fc['title']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="funding_call_id"    id="f-fcid"    value="0">
            <input type="hidden" name="funding_call_title" id="f-fctitle" value="">
        </div>

        <div>
            <label>Subject</label>
            <input name="subject" required value="<?= h($prefillSubject) ?>" placeholder="Message subject…">
        </div>

        <div>
            <label>Message</label>
            <textarea name="body" required class="big-textarea" placeholder="Write your message…"><?= h($prefillBody) ?></textarea>
        </div>

        <div>
            <button class="primary-btn" type="submit" style="padding:11px 24px">Send Message</button>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- ── Trash tab view ── -->
<?php if ($tab === 'trash'): ?>
<div class="panel">
    <div class="trash-topbar">
        <span style="font-size:14px;font-weight:700;color:#666">
            <?= $trashCount ?> item<?= $trashCount !== 1 ? 's' : '' ?> in Trash
        </span>
        <?php if ($trashCount > 0): ?>
        <form method="post" onsubmit="return confirm('Permanently delete all <?= $trashCount ?> items? This cannot be undone.')">
            <input type="hidden" name="action" value="empty_trash">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <button class="danger-btn" type="submit" style="padding:6px 14px;font-size:12px">Empty Trash</button>
        </form>
        <?php endif; ?>
    </div>
    <p class="trash-note">Items are automatically removed after 30 days.</p>

    <?php if (!$trashThreads): ?>
    <div class="trash-empty-state">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" style="display:block;margin:0 auto 12px;opacity:.25"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
        Trash is empty
    </div>
    <?php else: ?>
    <div class="trash-list">
        <?php foreach ($trashThreads as $t):
            $tName     = $t['sender_name'] ?: $t['sender_email'];
            $isNetwork = ($t['recipient_type'] === 'network');
            $expDays   = (int)($t['expires_days'] ?? 0);
            $deletedAt = $t['deleted_at'] ? date('M j, Y', strtotime($t['deleted_at'])) : 'Unknown';
        ?>
        <div class="trash-row">
            <div class="trash-avatar">
                <?= $isNetwork ? '#' : msg_avatar_initial($tName) ?>
            </div>
            <div class="trash-info">
                <div class="trash-from"><?= h($isNetwork ? 'Network Broadcast' : $tName) ?></div>
                <div class="trash-subject"><?= h($t['subject'] ?: '(No subject)') ?></div>
                <div class="trash-meta">
                    Deleted <?= $deletedAt ?>
                    <span class="trash-expires <?= $expDays <= 3 ? 'soon' : 'ok' ?>">
                        <?= $expDays <= 0 ? 'Expires soon' : "Expires in {$expDays}d" ?>
                    </span>
                </div>
            </div>
            <div class="trash-actions">
                <form method="post" style="display:inline">
                    <input type="hidden" name="action" value="restore_thread">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="thread_id" value="<?= (int)$t['id'] ?>">
                    <button class="trash-restore-btn" type="submit">Restore</button>
                </form>
                <form method="post" style="display:inline" onsubmit="return confirm('Permanently delete this conversation? This cannot be undone.')">
                    <input type="hidden" name="action" value="delete_forever">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="thread_id" value="<?= (int)$t['id'] ?>">
                    <button class="trash-del-btn" type="submit">Delete Forever</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Message list ── -->
<?php if ($tab !== 'compose' && $tab !== 'trash'): ?>
<div class="panel" style="padding:10px 8px" data-inbox-list>
    <?php if (!$activeList): ?>
        <div class="msg-empty">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <?= $tab === 'sent' ? 'No sent messages yet.' : 'Your inbox is empty.' ?>
        </div>
    <?php else: ?>
        <?php foreach ($activeList as $i => $m):
            $isActive  = ($threadId === (int)$m['id']);
            $isUnread  = $tab === 'inbox' && (int)$m['unread_count'] > 0;
            $sName     = $m['sender_name'] ?: $m['sender_email'];
            $isNetwork = ($m['recipient_type'] === 'network');
            $preview   = mb_substr(str_replace(["\n","\r"], ' ', $m['body']), 0, 80);
            $replies   = (int)($m['reply_count'] ?? 0);
            $timeStr   = msg_format_time($m['last_at'] ?? $m['created_at']);
        ?>
        <?php if ($i > 0): ?><hr class="msg-divider"><?php endif; ?>
        <a class="msg-row <?= $isActive ? 'active-row' : '' ?> <?= $isUnread ? 'unread-row' : '' ?>"
           href="index.php?page=messages&tab=<?= h($tab) ?>&thread=<?= (int)$m['id'] ?>">
            <div class="msg-avatar-sm <?= $isNetwork ? 'net' : '' ?>">
                <?= $isNetwork ? '#' : msg_avatar_initial($sName) ?>
            </div>
            <div class="msg-info">
                <div class="msg-from"><?= h($isNetwork ? 'Network Broadcast' : $sName) ?></div>
                <div class="msg-subject"><?= h($m['subject'] ?: '(No subject)') ?></div>
                <div class="msg-preview"><?= h($preview) ?></div>
            </div>
            <div class="msg-right">
                <div class="msg-time-sm"><?= $timeStr ?></div>
                <?php if ($isUnread): ?>
                    <div class="msg-unread-badge"><?= (int)$m['unread_count'] ?></div>
                <?php elseif ($replies > 0): ?>
                    <div class="msg-reply-badge"><?= $replies ?> <?= $replies === 1 ? 'reply' : 'replies' ?></div>
                <?php endif; ?>
            </div>
        </a>
        <?php endforeach; ?>

        <!-- Pagination -->
        <?php
        $activePaginator = $tab === 'sent' ? $sentPaginator : $inboxPaginator;
        if ($activePaginator->getTotalPages() > 1):
        ?>
        <div style="border-top:1px solid var(--line);margin-top:16px;padding-top:16px">
            <?php require __DIR__ . '/../components/pagination.php';
            render_pagination($activePaginator, 'p', 'index.php?page=messages&tab=' . h($tab), array_filter($_GET, fn($k) => !in_array($k, ['page', 'p']), ARRAY_FILTER_USE_KEY));
            ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

</div><!-- .msg-page -->
</div>

<!-- ── Undo Toast ── -->
<?php if ($undoTrash): ?>
<div class="undo-toast" id="undo-toast">
    <span class="undo-toast-msg">
        Moved to Trash
        <?php if ($undoTrash['subject']): ?>&mdash; <em><?= h(mb_substr($undoTrash['subject'], 0, 40)) ?></em><?php endif; ?>
    </span>
    <button class="undo-btn" onclick="undoTrashDelete(<?= (int)$undoTrash['thread_id'] ?>)">Undo</button>
    <button class="undo-close" onclick="dismissUndoToast()" title="Dismiss">&times;</button>
</div>
<?php endif; ?>

<script>
/* Global function for recipient selection */
function selectRecipient(type, email, name) {
    var rtype  = document.getElementById('f-rtype');
    var remail = document.getElementById('f-remail');
    var rname  = document.getElementById('f-rname');
    var input  = document.getElementById('recipient-input');
    var dropdown = document.getElementById('recipient-dropdown');

    rtype.value  = type;
    remail.value = email;
    rname.value  = name;
    input.value  = name;
    dropdown.style.display = 'none';
}

(function () {
    /* Searchable recipient autocomplete */
    var input = document.getElementById('recipient-input');
    var dropdown = document.getElementById('recipient-dropdown');
    var results = document.getElementById('recipient-results');
    var rtype  = document.getElementById('f-rtype');
    var remail = document.getElementById('f-remail');
    var rname  = document.getElementById('f-rname');
    var searchTimer;

    function showDropdown() {
        dropdown.style.display = 'block';
    }

    function hideDropdown() {
        dropdown.style.display = 'none';
    }

    function searchRecipients(query) {
        if (query.length < 1) {
            results.innerHTML = '';
            showDropdown();
            return;
        }

        fetch('/search_recipients.php?q=' + encodeURIComponent(query), {
            credentials: 'same-origin'
        })
            .then(r => r.json())
            .then(data => {
                if (!data.results || data.results.length === 0) {
                    results.innerHTML = '<div style="padding:12px;color:var(--muted);text-align:center;font-size:13px">No recipients found</div>';
                } else {
                    results.innerHTML = data.results.map(r =>
                        '<div style="padding:10px 12px;border-bottom:1px solid var(--line);cursor:pointer;hover:background:#f9f9f9;transition:background .1s" ' +
                        'onclick="selectRecipient(\'individual\', \'' + escapeAttr(r.email) + '\', \'' + escapeAttr(r.name) + '\')">' +
                        escapeHtml(r.name) + '<br>' +
                        '<span style="font-size:12px;color:var(--muted)">' + escapeHtml(r.email) + ' • ' + escapeHtml(r.role) + '</span>' +
                        '</div>'
                    ).join('');
                }
                showDropdown();
            })
            .catch(err => {
                results.innerHTML = '<div style="padding:12px;color:#b54646;text-align:center;font-size:13px">Error searching</div>';
                showDropdown();
            });
    }

    if (input) {
        input.addEventListener('focus', showDropdown);
        input.addEventListener('input', function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => searchRecipients(input.value), 200);
        });
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (input && dropdown && e.target !== input && !dropdown.contains(e.target)) {
            hideDropdown();
        }
    });

    // Helper functions
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function escapeAttr(text) {
        return (text + '').replace(/[&<>"']/g, c => {
            switch(c) {
                case '&': return '&amp;';
                case '<': return '&lt;';
                case '>': return '&gt;';
                case '"': return '&quot;';
                case "'": return '&#039;';
                default: return c;
            }
        });
    }

    /* Funding call selector → hidden fields */
    var fcs   = document.getElementById('fc-select');
    var fcid  = document.getElementById('f-fcid');
    var fctit = document.getElementById('f-fctitle');

    function syncFC() {
        if (!fcs) return;
        var opt = fcs.options[fcs.selectedIndex];
        fcid.value  = fcs.value ? parseInt(fcs.value, 10) : 0;
        fctit.value = fcs.value ? (opt.dataset.title || '') : '';
    }
    if (fcs) { fcs.addEventListener('change', syncFC); syncFC(); }

    /* Auto-focus reply textarea */
    var rb = document.getElementById('reply-body');
    if (rb) rb.focus();

    /* Deleted-in-thread toggle */
    var dithHd   = document.getElementById('del-in-thread-hd');
    var dithList = document.getElementById('del-in-thread-list');
    if (dithHd && dithList) {
        dithHd.addEventListener('click', function () {
            var open = dithList.classList.toggle('open');
            dithHd.classList.toggle('open', open);
        });
    }
})();

/* Undo toast helpers — global so onclick can reach them */
function dismissUndoToast() {
    var t = document.getElementById('undo-toast');
    if (!t) return;
    t.classList.add('fading');
    setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 380);
}

function undoTrashDelete(threadId) {
    var btn = document.querySelector('#undo-toast .undo-btn');
    if (btn) { btn.disabled = true; btn.textContent = '…'; }
    var body = 'action=restore_thread&thread_id=' + encodeURIComponent(threadId);
    var csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    fetch('index.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': csrfToken
        },
        body: body
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (d.ok) {
            dismissUndoToast();
            location.reload();
        }
    })
    .catch(function () { location.reload(); });
}

/* Auto-dismiss undo toast after 8 seconds */
(function () {
    var t = document.getElementById('undo-toast');
    if (!t) return;
    setTimeout(function () { dismissUndoToast(); }, 8000);
})();

/* Real-time unread count polling (every 5 seconds) - truly dynamic */
(function () {
    var lastUnreadCount = <?= (int)$totalUnread ?>;

    function updateAllBadges(newCount) {
        // Update Messages page badge
        var pageBadge = document.querySelector('[data-unread-count]');
        if (pageBadge) {
            pageBadge.textContent = newCount + ' unread';
            pageBadge.style.display = newCount > 0 ? 'inline-block' : 'none';
        }

        // If count changed significantly, reload page to sync all badges and nav
        if (lastUnreadCount !== newCount && Math.abs(lastUnreadCount - newCount) >= 1) {
            location.reload();
        }
    }

    function refreshInbox() {
        // Bypass browser cache with timestamp
        fetch('index.php?page=ping&_t=' + Date.now())
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var newCount = data.unread || 0;
                // If unread count changed, immediately reload to ensure everything is in sync
                if (lastUnreadCount !== newCount) {
                    console.log('[Messages] Unread count changed from ' + lastUnreadCount + ' to ' + newCount + ' - reloading page');
                    lastUnreadCount = newCount;
                    location.reload();
                }
            })
            .catch(function (err) { console.error('[Messages Polling Error]', err); });
    }

    setInterval(refreshInbox, 2000); // Poll every 2 seconds for instant badge updates
})();
</script>

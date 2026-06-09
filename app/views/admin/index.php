<?php
require_admin();

$mailCfg = @include __DIR__ . '/../../config/mail.php';
if (!is_array($mailCfg)) {
    $mailCfg = ['app_url' => 'http://localhost/facthub/public'];
}
$appUrl  = rtrim($mailCfg['app_url'] ?? 'http://localhost/facthub/public', '/');

$adminUser   = current_user();
$adminSection = in_array($_GET['section'] ?? '', ['dashboard','users','researchers','funders','audit','api_usage','jobs','settings','embeddings','newsletter'])
               ? $_GET['section'] : 'dashboard';

/* ── POST ACTIONS ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* Soft-delete researcher profile (blocks login, hides from lists) */
    if ($action === 'delete_researcher') {
        $rid = (int)($_POST['researcher_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        if ($rid) {
            $dataQ = $conn->prepare('SELECT CONCAT(first_name," ",last_name) n, email, user_id, status FROM researchers WHERE id = ? LIMIT 1');
            $dataQ->bind_param('i', $rid); $dataQ->execute();
            $rRow = $dataQ->get_result()->fetch_assoc();
            if ($rRow) {
                // Soft-delete the researcher profile
                $d = $conn->prepare("UPDATE researchers SET status='deleted', deleted_at=NOW() WHERE id = ?");
                $d->bind_param('i', $rid); $d->execute();
                // If there's a linked user account, mark it as inactive and revoke session
                if ($rRow['user_id']) {
                    $u = $conn->prepare("UPDATE users SET status='inactive', deactivated_at=NOW(), session_token=NULL, status_changed_by=?, last_status_change_at=NOW() WHERE id = ?");
                    $u->bind_param('si', $adminUser['email'], $rRow['user_id']); $u->execute();
                }
                audit($conn, 'soft_delete_researcher', [
                    'type' => 'researcher', 'id' => $rid, 'email' => $rRow['email'],
                    'detail' => $rRow['n'] . ($reason ? ' | Reason: ' . $reason : '')
                ]);
                set_flash('success', 'Researcher profile moved to Trash.');
            }
        }
        redirect_to('admin', ['section' => 'researchers']);
    }

    /* Soft-delete funder profile (blocks login, hides from lists) */
    if ($action === 'delete_funder') {
        $fid = (int)($_POST['funder_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        if ($fid) {
            $dataQ = $conn->prepare('SELECT CONCAT(first_name," ",last_name) n, email, user_id, status FROM funders WHERE id = ? LIMIT 1');
            $dataQ->bind_param('i', $fid); $dataQ->execute();
            $fRow = $dataQ->get_result()->fetch_assoc();
            if ($fRow) {
                // Soft-delete the funder profile
                $d = $conn->prepare("UPDATE funders SET status='deleted', deleted_at=NOW() WHERE id = ?");
                $d->bind_param('i', $fid); $d->execute();
                // If there's a linked user account, mark it as inactive and revoke session
                if ($fRow['user_id']) {
                    $u = $conn->prepare("UPDATE users SET status='inactive', deactivated_at=NOW(), session_token=NULL, status_changed_by=?, last_status_change_at=NOW() WHERE id = ?");
                    $u->bind_param('si', $adminUser['email'], $fRow['user_id']); $u->execute();
                }
                audit($conn, 'soft_delete_funder', [
                    'type' => 'funder', 'id' => $fid, 'email' => $fRow['email'],
                    'detail' => $fRow['n'] . ($reason ? ' | Reason: ' . $reason : '')
                ]);
                set_flash('success', 'Funder profile moved to Trash.');
            }
        }
        redirect_to('admin', ['section' => 'funders']);
    }

    /* Restore deleted researcher from trash */
    if ($action === 'restore_researcher') {
        $rid = (int)($_POST['researcher_id'] ?? 0);
        if ($rid) {
            $dataQ = $conn->prepare('SELECT CONCAT(first_name," ",last_name) n, email, user_id FROM researchers WHERE id = ? LIMIT 1');
            $dataQ->bind_param('i', $rid); $dataQ->execute();
            $rRow = $dataQ->get_result()->fetch_assoc();
            if ($rRow) {
                // Restore the researcher profile
                $d = $conn->prepare("UPDATE researchers SET status='active', deleted_at=NULL, restored_at=NOW() WHERE id = ?");
                $d->bind_param('i', $rid); $d->execute();
                // Restore linked user account
                if ($rRow['user_id']) {
                    $u = $conn->prepare("UPDATE users SET status='active', deactivated_at=NULL, session_token=NULL, last_status_change_at=NOW(), status_changed_by=? WHERE id = ?");
                    $u->bind_param('si', $adminUser['email'], $rRow['user_id']); $u->execute();
                }
                audit($conn, 'restore_researcher', ['type' => 'researcher', 'id' => $rid, 'email' => $rRow['email'], 'detail' => $rRow['n']]);
                set_flash('success', 'Researcher profile restored from Trash.');
            }
        }
        redirect_to('admin', ['section' => 'researchers', 'rtab' => 'trash']);
    }

    /* Restore deleted funder from trash */
    if ($action === 'restore_funder') {
        $fid = (int)($_POST['funder_id'] ?? 0);
        if ($fid) {
            $dataQ = $conn->prepare('SELECT CONCAT(first_name," ",last_name) n, email, user_id FROM funders WHERE id = ? LIMIT 1');
            $dataQ->bind_param('i', $fid); $dataQ->execute();
            $fRow = $dataQ->get_result()->fetch_assoc();
            if ($fRow) {
                // Restore the funder profile
                $d = $conn->prepare("UPDATE funders SET status='active', deleted_at=NULL, restored_at=NOW() WHERE id = ?");
                $d->bind_param('i', $fid); $d->execute();
                // Restore linked user account
                if ($fRow['user_id']) {
                    $u = $conn->prepare("UPDATE users SET status='active', deactivated_at=NULL, session_token=NULL, last_status_change_at=NOW(), status_changed_by=? WHERE id = ?");
                    $u->bind_param('si', $adminUser['email'], $fRow['user_id']); $u->execute();
                }
                audit($conn, 'restore_funder', ['type' => 'funder', 'id' => $fid, 'email' => $fRow['email'], 'detail' => $fRow['n']]);
                set_flash('success', 'Funder profile restored from Trash.');
            }
        }
        redirect_to('admin', ['section' => 'funders', 'ftab' => 'trash']);
    }

    /* Manually verify or unverify an account */
    if ($action === 'verify_user' || $action === 'unverify_user') {
        $uid       = (int)($_POST['user_id'] ?? 0);
        $newStatus = ($action === 'verify_user') ? 'active' : 'unverified';
        if ($uid) {
            $getOld = $conn->prepare('SELECT status FROM users WHERE id = ? LIMIT 1');
            $getOld->bind_param('i', $uid); $getOld->execute();
            $oldRow = $getOld->get_result()->fetch_assoc();

            $upd = $conn->prepare('UPDATE users SET status = ? WHERE id = ?');
            $upd->bind_param('si', $newStatus, $uid); $upd->execute();
            if ($action === 'verify_user') {
                $uq = $conn->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
                $uq->bind_param('i', $uid); $uq->execute();
                $uEmail = $uq->get_result()->fetch_assoc()['email'] ?? '';
                if ($uEmail) {
                    $dv = $conn->prepare('DELETE FROM email_verifications WHERE email = ?');
                    $dv->bind_param('s', $uEmail); $dv->execute();
                }
                audit($conn, 'verify_user', ['type' => 'user', 'id' => $uid, 'email' => $uEmail]);
                set_flash('success', 'Account has been manually verified.');
            } else {
                $uq2 = $conn->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
                $uq2->bind_param('i', $uid); $uq2->execute();
                $uEmail2 = $uq2->get_result()->fetch_assoc()['email'] ?? '';
                audit($conn, 'unverify_user', ['type' => 'user', 'id' => $uid, 'email' => $uEmail2]);
                set_flash('success', 'Account set back to unverified.');
            }
        }
        redirect_to('admin', ['edit' => $uid, 'section' => 'users']);
    }

    /* Send password reset link to user via email */
    if ($action === 'send_reset') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $uq  = $conn->prepare('SELECT name, email FROM users WHERE id = ? LIMIT 1');
        $uq->bind_param('i', $uid); $uq->execute();
        $uRow = $uq->get_result()->fetch_assoc();
        if ($uRow) {
            $del = $conn->prepare('DELETE FROM password_resets WHERE email = ?');
            $del->bind_param('s', $uRow['email']); $del->execute();

            $token     = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + 3600);
            $ins = $conn->prepare('INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)');
            $ins->bind_param('sss', $uRow['email'], $token, $expiresAt);
            $ins->execute();

            $mailCfg  = require __DIR__ . '/../../../config/mail.php';
            $appUrl   = rtrim($mailCfg['app_url'] ?? '', '/');
            $resetUrl = $appUrl . '/index.php?page=reset&token=' . urlencode($token);
            $html     = mail_tpl_password_reset($resetUrl, $uRow['name']);
            $sent     = send_notification_email($uRow['email'],
                            'Reset your FACT Alliance Hub password', $html);

            audit($conn, 'send_reset', ['type' => 'user', 'id' => $uid, 'email' => $uRow['email'], 'detail' => $sent ? 'email sent' : 'email failed']);
            set_flash($sent ? 'success' : 'warning',
                $sent ? 'Password reset email sent to ' . $uRow['email'] . '.'
                      : 'Reset token created but email delivery failed. Check your SMTP configuration in config/mail.php.');
        }
        redirect_to('admin', ['edit' => $uid]);
    }

    /* Soft-delete user + linked profiles (moved to Trash) */
    if ($action === 'delete_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $row = $conn->prepare('SELECT email, role, name FROM users WHERE id = ? LIMIT 1');
        $row->bind_param('i', $uid);
        $row->execute();
        $uRow = $row->get_result()->fetch_assoc();
        if ($uRow) {
            // Soft-delete the user account
            $upd = $conn->prepare("UPDATE users SET status='deleted', deleted_at=NOW(), deletion_reason=?, session_token=NULL, status_changed_by=?, last_status_change_at=NOW() WHERE id = ?");
            $upd->bind_param('ssi', $reason, $adminUser['email'], $uid);
            $upd->execute();

            // Also soft-delete linked researcher/funder profile
            $prof = $conn->prepare("UPDATE researchers SET status='deleted', deleted_at=NOW() WHERE user_id = ?");
            $prof->bind_param('i', $uid); $prof->execute();
            $prof2 = $conn->prepare("UPDATE funders SET status='deleted', deleted_at=NOW() WHERE user_id = ?");
            $prof2->bind_param('i', $uid); $prof2->execute();

            audit($conn, 'soft_delete_user', [
                'type' => 'user', 'id' => $uid, 'email' => $uRow['email'],
                'detail' => ($uRow['name'] ?? 'Unknown') . ($reason ? ' | Reason: ' . $reason : '')
            ]);
            set_flash('success', 'User moved to Trash.');
        }
        redirect_to('admin', ['section' => 'users']);
    }

    /* Deactivate user (login blocked, data preserved) */
    if ($action === 'deactivate_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid) {
            $getOld = $conn->prepare('SELECT status FROM users WHERE id = ? LIMIT 1');
            $getOld->bind_param('i', $uid); $getOld->execute();
            $oldRow = $getOld->get_result()->fetch_assoc();

            $upd = $conn->prepare("UPDATE users SET status='inactive', deactivated_at=NOW(), session_token=NULL, status_changed_by=?, last_status_change_at=NOW() WHERE id = ? AND status != 'deleted'");
            $upd->bind_param('si', $adminUser['email'], $uid); $upd->execute();

            // Also deactivate linked profiles
            $prof = $conn->prepare("UPDATE researchers SET status='inactive', deactivated_at=NOW() WHERE user_id = ?");
            $prof->bind_param('i', $uid); $prof->execute();
            $prof2 = $conn->prepare("UPDATE funders SET status='inactive', deactivated_at=NOW() WHERE user_id = ?");
            $prof2->bind_param('i', $uid); $prof2->execute();

            audit($conn, 'deactivate_user', ['type' => 'user', 'id' => $uid]);
            set_flash('success', 'User deactivated. They cannot log in.');
        }
        redirect_to('admin', ['section' => 'users']);
    }

    /* Activate user (allows login) */
    if ($action === 'activate_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid) {
            // Only allow activation if not deleted
            $upd = $conn->prepare("UPDATE users SET status='active', deactivated_at=NULL, status_changed_by=?, last_status_change_at=NOW() WHERE id = ? AND status = 'inactive'");
            $upd->bind_param('si', $adminUser['email'], $uid); $upd->execute();

            // Also activate linked profiles if inactive
            $prof = $conn->prepare("UPDATE researchers SET status='active', deactivated_at=NULL WHERE user_id = ? AND status = 'inactive'");
            $prof->bind_param('i', $uid); $prof->execute();
            $prof2 = $conn->prepare("UPDATE funders SET status='active', deactivated_at=NULL WHERE user_id = ? AND status = 'inactive'");
            $prof2->bind_param('i', $uid); $prof2->execute();

            audit($conn, 'activate_user', ['type' => 'user', 'id' => $uid]);
            set_flash('success', 'User activated. They can now log in.');
        }
        redirect_to('admin', ['section' => 'users']);
    }

    /* Restore user from Trash (returns to inactive status) */
    if ($action === 'restore_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid) {
            $upd = $conn->prepare("UPDATE users SET status='inactive', deleted_at=NULL, restored_at=NOW(), deletion_reason=NULL, status_changed_by=?, last_status_change_at=NOW() WHERE id = ? AND status = 'deleted'");
            $upd->bind_param('si', $adminUser['email'], $uid); $upd->execute();

            // Also restore linked profiles
            $prof = $conn->prepare("UPDATE researchers SET status='inactive', deleted_at=NULL, restored_at=NOW() WHERE user_id = ? AND status = 'deleted'");
            $prof->bind_param('i', $uid); $prof->execute();
            $prof2 = $conn->prepare("UPDATE funders SET status='inactive', deleted_at=NULL, restored_at=NOW() WHERE user_id = ? AND status = 'deleted'");
            $prof2->bind_param('i', $uid); $prof->execute();

            audit($conn, 'restore_user', ['type' => 'user', 'id' => $uid]);
            set_flash('success', 'User restored to Inactive status. Activate manually to allow login.');
        }
        redirect_to('admin', ['section' => 'users']);
    }

    /* Restore researcher from Trash */
    if ($action === 'restore_researcher') {
        $rid = (int)($_POST['researcher_id'] ?? 0);
        if ($rid) {
            $upd = $conn->prepare("UPDATE researchers SET status='inactive', deleted_at=NULL, restored_at=NOW() WHERE id = ? AND status = 'deleted'");
            $upd->bind_param('i', $rid); $upd->execute();

            // Also restore linked user if it was soft-deleted
            $getUser = $conn->prepare('SELECT user_id FROM researchers WHERE id = ? LIMIT 1');
            $getUser->bind_param('i', $rid); $getUser->execute();
            $userRow = $getUser->get_result()->fetch_assoc();
            if ($userRow && $userRow['user_id']) {
                $u = $conn->prepare("UPDATE users SET status='inactive', deleted_at=NULL WHERE id = ?");
                $u->bind_param('i', $userRow['user_id']); $u->execute();
            }

            audit($conn, 'restore_researcher', ['type' => 'researcher', 'id' => $rid]);
            set_flash('success', 'Researcher restored. Activate manually to allow login.');
        }
        redirect_to('admin', ['section' => 'researchers']);
    }

    /* Restore funder from Trash */
    if ($action === 'restore_funder') {
        $fid = (int)($_POST['funder_id'] ?? 0);
        if ($fid) {
            $upd = $conn->prepare("UPDATE funders SET status='inactive', deleted_at=NULL, restored_at=NOW() WHERE id = ? AND status = 'deleted'");
            $upd->bind_param('i', $fid); $upd->execute();

            // Also restore linked user if it was soft-deleted
            $getUser = $conn->prepare('SELECT user_id FROM funders WHERE id = ? LIMIT 1');
            $getUser->bind_param('i', $fid); $getUser->execute();
            $userRow = $getUser->get_result()->fetch_assoc();
            if ($userRow && $userRow['user_id']) {
                $u = $conn->prepare("UPDATE users SET status='inactive', deleted_at=NULL WHERE id = ?");
                $u->bind_param('i', $userRow['user_id']); $u->execute();
            }

            audit($conn, 'restore_funder', ['type' => 'funder', 'id' => $fid]);
            set_flash('success', 'Funder restored. Activate manually to allow login.');
        }
        redirect_to('admin', ['section' => 'funders']);
    }

    /* Change role */
    if ($action === 'update_role') {
        $uid     = (int)($_POST['user_id'] ?? 0);
        $newRole = in_array($_POST['role'] ?? '', ['admin','researcher','funder']) ? $_POST['role'] : '';
        if ($uid && $newRole) {
            $oldRoleQ = $conn->prepare('SELECT email, role FROM users WHERE id = ? LIMIT 1');
            $oldRoleQ->bind_param('i', $uid); $oldRoleQ->execute();
            $oldRow = $oldRoleQ->get_result()->fetch_assoc();
            $stmt = $conn->prepare('UPDATE users SET role = ? WHERE id = ?');
            $stmt->bind_param('si', $newRole, $uid); $stmt->execute();
            audit($conn, 'update_role', ['type' => 'user', 'id' => $uid, 'email' => $oldRow['email'] ?? '', 'detail' => ($oldRow['role'] ?? '?') . ' → ' . $newRole]);
            set_flash('success', 'Role updated successfully.');
        }
        redirect_to('admin', ['edit' => $uid]);
    }

    /* Set new password */
    if ($action === 'set_password') {
        $uid  = (int)($_POST['user_id']        ?? 0);
        $pw   = $_POST['new_password']          ?? '';
        $conf = $_POST['confirm_new_password']  ?? '';
        if ($pw !== $conf) {
            set_flash('error', 'Passwords do not match.');
            redirect_to('admin', ['edit' => $uid]);
        }
        if (strlen($pw) < 8) {
            set_flash('error', 'Password must be at least 8 characters.');
            redirect_to('admin', ['edit' => $uid]);
        }
        $hash = password_hash($pw, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->bind_param('si', $hash, $uid); $stmt->execute();
        $emailQ = $conn->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
        $emailQ->bind_param('i', $uid); $emailQ->execute();
        $emailRow = $emailQ->get_result()->fetch_assoc();
        audit($conn, 'set_password', ['type' => 'user', 'id' => $uid, 'email' => $emailRow['email'] ?? '']);
        set_flash('success', 'Password updated successfully.');
        redirect_to('admin', ['edit' => $uid]);
    }

    /* Update user name — syncs to researchers/funders profile table */
    if ($action === 'update_name') {
        $uid  = (int)($_POST['user_id'] ?? 0);
        $name = trim($_POST['name']      ?? '');
        if ($uid && $name !== '') {
            $stmt = $conn->prepare('UPDATE users SET name = ? WHERE id = ?');
            $stmt->bind_param('si', $name, $uid); $stmt->execute();

            $parts = explode(' ', $name, 2);
            $first = trim($parts[0]);
            $last  = trim($parts[1] ?? '');

            $uq = $conn->prepare('SELECT email, role FROM users WHERE id = ? LIMIT 1');
            $uq->bind_param('i', $uid); $uq->execute();
            $uRow = $uq->get_result()->fetch_assoc();

            if ($uRow) {
                if ($uRow['role'] === 'researcher') {
                    $upd = $conn->prepare('UPDATE researchers SET first_name = ?, last_name = ? WHERE email = ?');
                    $upd->bind_param('sss', $first, $last, $uRow['email']); $upd->execute();
                } elseif ($uRow['role'] === 'funder') {
                    $upd = $conn->prepare('UPDATE funders SET first_name = ?, last_name = ? WHERE email = ?');
                    $upd->bind_param('sss', $first, $last, $uRow['email']); $upd->execute();
                }
                audit($conn, 'update_name', ['type' => 'user', 'id' => $uid, 'email' => $uRow['email'], 'detail' => $name]);
            }

            set_flash('success', 'Name updated across account and profile.');
        }
        redirect_to('admin', ['edit' => $uid, 'section' => 'users']);
    }

    /* Bulk compute AI matches for all active funding calls */
    if ($action === 'compute_all_matches') {
        $activeFcs = $conn->query("SELECT id FROM funding_calls WHERE deleted_at IS NULL AND status IN ('open','upcoming','rolling')")->fetch_all(MYSQLI_ASSOC);
        $count = 0;
        foreach ($activeFcs as $fc) {
            enqueue_job($conn, 'compute_matches', ['funding_call_id' => (int)$fc['id']]);
            $count++;
        }
        audit($conn, 'compute_all_matches', ['detail' => "Queued {$count} funding calls"]);
        set_flash('success', "Queued AI matching for {$count} active funding calls. Jobs will process within minutes.");
        redirect_to('admin', ['section' => 'jobs']);
    }

    /* Bulk generate AI summaries for all researchers and funding calls */
    if ($action === 'generate_all_summaries') {
        $researchers = $conn->query("SELECT id FROM researchers WHERE status = 'active' AND deleted_at IS NULL")->fetch_all(MYSQLI_ASSOC);
        $fcs         = $conn->query('SELECT id FROM funding_calls WHERE deleted_at IS NULL')->fetch_all(MYSQLI_ASSOC);
        $count = 0;
        foreach ($researchers as $r) {
            enqueue_job($conn, 'generate_summary', ['entity_type' => 'researcher', 'entity_id' => (int)$r['id']]);
            $count++;
        }
        foreach ($fcs as $fc) {
            enqueue_job($conn, 'generate_summary', ['entity_type' => 'funding_call', 'entity_id' => (int)$fc['id']]);
            $count++;
        }
        audit($conn, 'generate_all_summaries', ['detail' => "Queued {$count} summary jobs"]);
        set_flash('success', "Queued AI summaries for {$count} records. Jobs will process within minutes.");
        redirect_to('admin', ['section' => 'jobs']);
    }

    /* Send pending match notifications as digest */
    if ($action === 'send_pending_digest') {
        // Try with deleted_at column first, fall back if it doesn't exist
        $res = @$conn->query(
            'SELECT ms.score_ai, ms.score_keyword,
                    r.id AS rid, r.email, r.first_name, r.topics AS r_topics, r.geography AS r_geo,
                    fc.id AS fc_id, fc.title, fc.funder, fc.deadline, fc.status, fc.amount,
                    fc.topics AS fc_topics, fc.geography AS fc_geo
             FROM match_scores ms
             JOIN researchers r ON r.id = ms.researcher_id
             JOIN funding_calls fc ON fc.id = ms.funding_call_id
             WHERE r.notify_matches = 1
               AND fc.deleted_at IS NULL
               AND (ms.score_ai >= 60 OR (ms.score_ai IS NULL AND ms.score_keyword >= 3))
               AND ms.notified_at IS NULL
             ORDER BY r.email ASC, COALESCE(ms.score_ai, ms.score_keyword) DESC'
        );
        if (!$res) {
            // Fallback if deleted_at column doesn't exist
            $res = $conn->query(
                'SELECT ms.score_ai, ms.score_keyword,
                        r.id AS rid, r.email, r.first_name, r.topics AS r_topics, r.geography AS r_geo,
                        fc.id AS fc_id, fc.title, fc.funder, fc.deadline, fc.status, fc.amount,
                        fc.topics AS fc_topics, fc.geography AS fc_geo
                 FROM match_scores ms
                 JOIN researchers r ON r.id = ms.researcher_id
                 JOIN funding_calls fc ON fc.id = ms.funding_call_id
                 WHERE r.notify_matches = 1
                   AND (ms.score_ai >= 60 OR (ms.score_ai IS NULL AND ms.score_keyword >= 3))
                   AND ms.notified_at IS NULL
                 ORDER BY r.email ASC, COALESCE(ms.score_ai, ms.score_keyword) DESC'
            );
        }
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

        $messages = [];
        foreach ($rows as $row) {
            $matchedTopics = array_values(array_intersect(parse_tags($row['fc_topics']), parse_tags($row['r_topics'])));
            $matchedGeos   = array_values(array_intersect(parse_tags($row['fc_geo']),    parse_tags($row['r_geo'])));
            $fundingUrl = $appUrl . '/index.php?page=funding&view=' . (int)$row['fc_id'];
            $unsubUrl   = $appUrl . '/index.php?page=unsubscribe&email='
                        . urlencode($row['email']) . '&token='
                        . hash_hmac('sha256', strtolower(trim($row['email'])), 'match_notify');
            $messages[] = [
                'to'      => $row['email'],
                'subject' => 'Funding match: ' . $row['title'],
                'html'    => mail_tpl_match_notify($row['first_name'], $row['title'], $row['funder'],
                                 $row['deadline'] ?? '', $row['status'] ?? '', $row['amount'] ?? '',
                                 $matchedTopics, $matchedGeos, $fundingUrl, $unsubUrl),
            ];
            $conn->query("UPDATE match_scores SET notified_at=NOW()
                          WHERE funding_call_id=" . (int)$row['fc_id'] . " AND researcher_id=" . (int)$row['rid']);
        }

        if (!empty($messages)) {
            enqueue_job($conn, 'send_digest', ['messages' => $messages]);
        }
        $count = count($messages);
        audit($conn, 'send_pending_digest', ['detail' => "Queued digest for {$count} matches"]);
        set_flash('success', "Queued digest for {$count} pending match notifications.");
        redirect_to('admin', ['section' => 'jobs']);
    }

    /* Check API balances manually */
    if ($action === 'check_balances') {
        enqueue_job($conn, 'check_balance', []);
        audit($conn, 'check_balances', ['detail' => 'Manually triggered API balance check']);
        set_flash('success', 'Balance check queued. Will complete within minutes.');
        redirect_to('admin', ['section' => 'dashboard']);
    }

    /* Approve pending user */
    if ($action === 'approve_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid) {
            $uq = $conn->prepare('SELECT email, name FROM users WHERE id = ? AND status = ? LIMIT 1');
            $status_check = 'pending_approval';
            $uq->bind_param('is', $uid, $status_check);
            $uq->execute();
            $uRow = $uq->get_result()->fetch_assoc();

            if ($uRow) {
                // Approve user
                $upd = $conn->prepare("UPDATE users SET status='active', last_status_change_at=NOW(), status_changed_by=? WHERE id=?");
                $upd->bind_param('si', $adminUser['email'], $uid);
                $upd->execute();

                // Also approve linked researcher/funder profiles
                $uprof = $conn->prepare("UPDATE researchers SET status='active' WHERE user_id=? AND status != 'deleted'");
                $uprof->bind_param('i', $uid); $uprof->execute();

                $fprof = $conn->prepare("UPDATE funders SET status='active' WHERE user_id=? AND status != 'deleted'");
                $fprof->bind_param('i', $uid); $fprof->execute();

                // Log approval
                audit($conn, 'approve_user', [
                    'type' => 'user', 'id' => $uid, 'email' => $uRow['email'],
                    'detail' => 'User approved and can now access platform'
                ]);

                // Email user that they're approved
                send_admin_notification_email($uRow['email'], 'approved', $uRow['name']);

                set_flash('success', 'User approved. They can now access the platform.');
            }
        }
        redirect_to('admin', ['section' => 'users', 'utab' => 'pending']);
    }

    /* Reject pending user */
    if ($action === 'reject_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? '');

        if ($uid) {
            $uq = $conn->prepare('SELECT email, name FROM users WHERE id = ? AND status = ? LIMIT 1');
            $status_check = 'pending_approval';
            $uq->bind_param('is', $uid, $status_check);
            $uq->execute();
            $uRow = $uq->get_result()->fetch_assoc();

            if ($uRow) {
                // Set to inactive instead of deletion (gives user chance to reapply)
                $upd = $conn->prepare("UPDATE users SET status='inactive', deactivated_at=NOW(), session_token=NULL, status_changed_by=?, last_status_change_at=NOW() WHERE id=?");
                $upd->bind_param('si', $adminUser['email'], $uid);
                $upd->execute();

                // Also deactivate linked profiles
                $uprof = $conn->prepare("UPDATE researchers SET status='inactive', deactivated_at=NOW() WHERE user_id=? AND status != 'deleted'");
                $uprof->bind_param('i', $uid); $uprof->execute();

                // Log rejection
                audit($conn, 'reject_user', [
                    'type' => 'user', 'id' => $uid, 'email' => $uRow['email'],
                    'detail' => 'Application rejected. ' . ($reason ? 'Reason: ' . $reason : 'No reason provided')
                ]);

                // Email user that application was rejected
                send_admin_notification_email($uRow['email'], 'rejected', $uRow['name'], $reason);

                set_flash('success', 'Application rejected. User has been notified.');
            }
        }
        redirect_to('admin', ['section' => 'users', 'utab' => 'pending']);
    }

    // Add trusted domain
    if ($action === 'add_domain') {
        $domain = strtolower(trim($_POST['domain'] ?? ''));
        $institution = trim($_POST['institution'] ?? '');
        $country = trim($_POST['country'] ?? '');
        $tier = in_array($_POST['tier'] ?? '', ['tier1', 'tier2', 'tier3']) ? $_POST['tier'] : 'tier2';

        if ($domain && $institution) {
            $check = $conn->prepare('SELECT 1 FROM trusted_domains WHERE domain = ? LIMIT 1');
            $check->bind_param('s', $domain);
            $check->execute();
            if ($check->get_result()->num_rows === 0) {
                $ins = $conn->prepare('INSERT INTO trusted_domains (domain, institution_name, country, tier, created_by) VALUES (?, ?, ?, ?, ?)');
                $ins->bind_param('sssss', $domain, $institution, $country, $tier, $adminUser['email']);
                $ins->execute();
                set_flash('success', 'Domain added to trusted list.');
            } else {
                set_flash('error', 'This domain is already in the trusted list.');
            }
        } else {
            set_flash('error', 'Domain and institution name are required.');
        }
        redirect_to('admin', ['section' => 'settings']);
    }

    // Remove trusted domain
    if ($action === 'remove_domain') {
        $domainId = (int)($_POST['domain_id'] ?? 0);
        if ($domainId) {
            $del = $conn->prepare('DELETE FROM trusted_domains WHERE id = ?');
            $del->bind_param('i', $domainId);
            $del->execute();
            set_flash('success', 'Domain removed from trusted list.');
        }
        redirect_to('admin', ['section' => 'settings']);
    }
}

/* ── LOAD DATA ── */
$editId   = (int)($_GET['edit']   ?? 0);
$search   = trim($_GET['search']  ?? '');
$roleFilter = $_GET['role']        ?? '';
$statusTab = $_GET['utab']         ?? 'active';
if (!in_array($statusTab, ['active', 'pending', 'inactive', 'trash'])) { $statusTab = 'active'; }

$editUser = null;
if ($editId > 0) {
    $eq = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $eq->bind_param('i', $editId); $eq->execute();
    $editUser = $eq->get_result()->fetch_assoc();
}

/* Stats — all queries use prepared statements */
$stats = [];
foreach (['total' => null, 'admin' => 'admin', 'researcher' => 'researcher', 'funder' => 'funder'] as $key => $role) {
    if ($role === null) {
        $stats[$key] = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE status = 'active' AND deleted_at IS NULL")->fetch_assoc()['c'];
    } else {
        $sq = $conn->prepare("SELECT COUNT(*) c FROM users WHERE role = ? AND status = 'active' AND deleted_at IS NULL");
        $sq->bind_param('s', $role); $sq->execute();
        $stats[$key] = (int)$sq->get_result()->fetch_assoc()['c'];
    }
}

/* Status counts */
$statusCounts = [];
foreach (['active' => 'active', 'pending' => 'pending_approval', 'inactive' => 'inactive', 'trash' => 'deleted'] as $tab => $status) {
    $sq = $conn->prepare('SELECT COUNT(*) c FROM users WHERE status = ?');
    $sq->bind_param('s', $status); $sq->execute();
    $statusCounts[$tab] = (int)$sq->get_result()->fetch_assoc()['c'];
}

/* User list */
$conditions = []; $params = []; $types = '';
if ($search !== '') {
    $conditions[] = '(name LIKE ? OR email LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $types .= 'ss';
}
if (in_array($roleFilter, ['admin','researcher','funder'])) {
    $conditions[] = 'role = ?';
    $params[] = $roleFilter; $types .= 's';
}
// Status tab filter
$statusMap = ['active' => 'active', 'pending' => 'pending_approval', 'inactive' => 'inactive', 'trash' => 'deleted'];
$conditions[] = 'status = ?';
$params[] = $statusMap[$statusTab];
$types .= 's';
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$sql = "SELECT * FROM users $where ORDER BY role ASC, name ASC";
$stmt = $conn->prepare($sql);
if ($params) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<style>
/* ── Admin stats bar ── */
.admin-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
.stat-card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:18px 20px;text-align:center}
.stat-num{font-size:36px;font-weight:900;color:var(--primary);line-height:1}
.stat-num.funder-num{color:#c8a85a}
.stat-num.admin-num{color:#315f90}
.stat-label{font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-top:4px}

/* ── Role filter tabs ── */
.role-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-top:14px}
.role-tab{padding:7px 16px;border-radius:999px;font-size:13px;font-weight:700;background:#f2f5f3;color:var(--text);cursor:pointer;text-decoration:none;border:1px solid transparent}
.role-tab:hover,.role-tab.active{background:var(--primary-2);color:var(--primary);text-decoration:none}
.role-tab.funder-tab.active{background:#fdf3e0;color:#8a5a0a}
.role-tab.admin-tab.active{background:#e8f0fd;color:#315f90}

/* ── User cards ── */
.user-card{padding:16px 18px;margin-bottom:10px;transition:box-shadow .15s}
.user-card:hover{box-shadow:0 4px 18px rgba(22,37,30,.09)}
.user-meta{font-size:13px;color:var(--muted);margin-top:2px}
.user-avatar{width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:18px;flex-shrink:0;color:#fff}
.ua-admin{background:#1a6b5a}.ua-researcher{background:#315f90}.ua-funder{background:#c8a85a}

/* ── Edit panel ── */
.edit-panel{padding:24px 28px;margin-bottom:18px;border-left:4px solid var(--primary)}
.edit-section{background:#f8fafb;border:1px solid var(--line);border-radius:12px;padding:18px 20px;margin-top:16px}
.edit-section-title{font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-bottom:12px}
.pw-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.danger-section{border-color:#f0c8c8;background:#fff8f8}

/* ── Empty state ── */
.admin-empty{padding:40px;text-align:center;color:var(--muted)}
/* ── Section tabs ── */
.admin-section-tabs{display:flex;gap:8px;margin-top:18px;border-bottom:2px solid var(--line);padding-bottom:0}
.admin-stab{padding:9px 20px;font-size:13px;font-weight:700;color:var(--muted);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;transition:color .15s,border-color .15s}
.admin-stab:hover{color:var(--primary);text-decoration:none}
.admin-stab.active{color:var(--primary);border-bottom-color:var(--primary)}
/* ── Researcher table ── */
.r-table{width:100%;border-collapse:collapse;font-size:14px}
.r-table th{text-align:left;font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);padding:10px 14px;border-bottom:2px solid var(--line)}
.r-table td{padding:11px 14px;border-bottom:1px solid var(--line);vertical-align:middle}
.r-table tr:last-child td{border-bottom:none}
.r-table tr:hover td{background:#f9fbfa}
@media(max-width:900px){.admin-stats{grid-template-columns:repeat(2,1fr)}}
@media(max-width:580px){.pw-grid{grid-template-columns:1fr}}

/* Newsletter messages */
#export-message.success { background: #e8f5e9; color: #2e7d32; border: 1px solid #81c784; padding: 12px; border-radius: 4px; }
#export-message.error { background: #ffebee; color: #c62828; border: 1px solid #ef9a9a; padding: 12px; border-radius: 4px; }

/* Organized admin navigation */
.admin-nav { display: flex; flex-direction: column; gap: 0; border-bottom: 1px solid #dde6dd; margin-bottom: 28px; }
.admin-nav-section { display: flex; flex-direction: column; gap: 0; padding-bottom: 12px; margin-bottom: 12px; border-bottom: 1px solid #f0f2f1; }
.admin-nav-section:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
.admin-nav-label { font-size: 11px; font-weight: 800; letter-spacing: 0.12em; text-transform: uppercase; color: #9aaba4; margin: 0 0 8px 0; padding: 0 4px; }
.admin-nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; text-decoration: none; color: #374151; font-weight: 500; font-size: 14px; border-left: 3px solid transparent; transition: all 0.2s ease; margin: 0; }
.admin-nav-item:hover { background: #f8fafb; color: #1a6b5a; }
.admin-nav-item.active { background: #f0f7f6; color: #1a6b5a; border-left-color: #1a6b5a; font-weight: 600; }
.admin-nav-item .icon { font-size: 16px; flex-shrink: 0; }
</style>

<!-- Page header + stats -->
<div class="panel page-head" style="padding:20px 22px;margin-bottom:16px">
    <div class="head-row">
        <h1>Admin Panel</h1>
    </div>
    <div class="admin-stats" style="margin-top:18px">
        <div class="stat-card">
            <div class="stat-num"><?= $stats['total'] ?></div>
            <div class="stat-label">Total Users</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= $stats['researcher'] ?></div>
            <div class="stat-label">Researchers</div>
        </div>
        <div class="stat-card">
            <div class="stat-num funder-num"><?= $stats['funder'] ?></div>
            <div class="stat-label">Funders</div>
        </div>
        <div class="stat-card">
            <div class="stat-num admin-num"><?= $stats['admin'] ?></div>
            <div class="stat-label">Admins</div>
        </div>
    </div>

    <!-- Section tabs -->
    <div class="admin-section-tabs">
        <a class="admin-stab <?= $adminSection==='dashboard'   ? 'active' : '' ?>" href="index.php?page=admin&section=dashboard">Dashboard</a>
        <div class="admin-stab-divider"></div>
        <a class="admin-stab <?= $adminSection==='users'       ? 'active' : '' ?>" href="index.php?page=admin&section=users">Users</a>
        <a class="admin-stab <?= $adminSection==='researchers' ? 'active' : '' ?>" href="index.php?page=admin&section=researchers">Researchers</a>
        <a class="admin-stab <?= $adminSection==='funders'     ? 'active' : '' ?>" href="index.php?page=admin&section=funders">Funders</a>
        <div class="admin-stab-divider"></div>
        <a class="admin-stab <?= $adminSection==='jobs'        ? 'active' : '' ?>" href="index.php?page=admin&section=jobs">Job Queue</a>
        <a class="admin-stab <?= $adminSection==='api_usage'   ? 'active' : '' ?>" href="index.php?page=admin&section=api_usage">API Usage</a>
        <a class="admin-stab <?= $adminSection==='audit'       ? 'active' : '' ?>" href="index.php?page=admin&section=audit">Audit Log</a>
        <div class="admin-stab-divider"></div>
        <a class="admin-stab <?= $adminSection==='embeddings'  ? 'active' : '' ?>" href="index.php?page=admin&section=embeddings">Semantic Search</a>
        <a class="admin-stab <?= $adminSection==='newsletter'   ? 'active' : '' ?>" href="index.php?page=admin&section=newsletter">Newsletter</a>
        <div class="admin-stab-divider"></div>
        <a class="admin-stab <?= $adminSection==='settings'    ? 'active' : '' ?>" href="index.php?page=admin&section=settings">Settings</a>
    </div>
</div>

<?php if ($adminSection === 'dashboard'): ?>
<!-- ── Dashboard section ── -->
<?php
require_once __DIR__ . '/../../services/BalanceMonitor.php';

$kpiResearchers = (int)$conn->query("SELECT COUNT(*) FROM researchers WHERE status = 'active' AND deleted_at IS NULL")->fetch_row()[0];
// KPI queries with fallbacks for missing deleted_at columns
$res = @$conn->query('SELECT COUNT(*) FROM funding_calls WHERE deleted_at IS NULL');
$kpiFunding = $res ? (int)$res->fetch_row()[0] : (int)$conn->query('SELECT COUNT(*) FROM funding_calls')->fetch_row()[0];

$res = @$conn->query("SELECT COUNT(*) FROM match_scores ms JOIN researchers r ON r.id = ms.researcher_id WHERE r.status = 'active' AND r.deleted_at IS NULL");
$kpiMatches = $res ? (int)$res->fetch_row()[0] : (int)$conn->query("SELECT COUNT(*) FROM match_scores ms JOIN researchers r ON r.id = ms.researcher_id WHERE r.status = 'active'")->fetch_row()[0];

$res = @$conn->query("SELECT COUNT(*) FROM match_scores ms JOIN researchers r ON r.id = ms.researcher_id WHERE ms.score_ai IS NOT NULL AND r.status = 'active' AND r.deleted_at IS NULL");
$kpiAiMatches = $res ? (int)$res->fetch_row()[0] : (int)$conn->query("SELECT COUNT(*) FROM match_scores ms JOIN researchers r ON r.id = ms.researcher_id WHERE ms.score_ai IS NOT NULL AND r.status = 'active'")->fetch_row()[0];

$res = @$conn->query("SELECT COUNT(*) FROM ai_summaries WHERE entity_type = 'researcher' AND entity_id IN (SELECT id FROM researchers WHERE status = 'active' AND deleted_at IS NULL)");
$kpiSummaries = $res ? (int)$res->fetch_row()[0] : (int)$conn->query("SELECT COUNT(*) FROM ai_summaries WHERE entity_type = 'researcher' AND entity_id IN (SELECT id FROM researchers WHERE status = 'active')")->fetch_row()[0];
$kpiCostMonth   = (float)$conn->query("SELECT COALESCE(SUM(cost_usd),0) FROM api_usage WHERE created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')")->fetch_row()[0];
$aiCoverage     = $kpiMatches > 0 ? round(($kpiAiMatches / $kpiMatches) * 100) : 0;

// Fetch balance status safely (skip if table not ready)
$balanceStatus = [];
$balanceAlerts = [];
try {
    $balanceStatus = BalanceMonitor::getStatus($conn) ?? [];
    $balanceAlerts = BalanceMonitor::getAlertCounts($conn) ?? [];
} catch (Throwable $e) {
    // BalanceMonitor table not ready yet, skip for now
}

// Try with deleted_at columns first, fall back if they don't exist
$res = @$conn->query(
    "SELECT r.first_name, r.last_name, fc.title, fc.funder, ms.score_ai, ms.explanation
     FROM match_scores ms
     JOIN researchers r ON r.id = ms.researcher_id
     JOIN funding_calls fc ON fc.id = ms.funding_call_id
     WHERE ms.score_ai IS NOT NULL AND r.status = 'active' AND r.deleted_at IS NULL AND fc.deleted_at IS NULL
     ORDER BY ms.score_ai DESC LIMIT 6"
);
if (!$res) {
    $res = $conn->query(
        "SELECT r.first_name, r.last_name, fc.title, fc.funder, ms.score_ai, ms.explanation
         FROM match_scores ms
         JOIN researchers r ON r.id = ms.researcher_id
         JOIN funding_calls fc ON fc.id = ms.funding_call_id
         WHERE ms.score_ai IS NOT NULL AND r.status = 'active'
         ORDER BY ms.score_ai DESC LIMIT 6"
    );
}
$topMatches = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

// Try with deleted_at column first, fall back if it doesn't exist
$res = @$conn->query(
    "SELECT FLOOR(ms.score_ai/20)*20 AS bucket, COUNT(*) AS cnt
     FROM match_scores ms
     JOIN researchers r ON r.id = ms.researcher_id
     WHERE ms.score_ai IS NOT NULL AND r.status = 'active' AND r.deleted_at IS NULL
     GROUP BY bucket ORDER BY bucket ASC"
);
if (!$res) {
    $res = $conn->query(
        "SELECT FLOOR(ms.score_ai/20)*20 AS bucket, COUNT(*) AS cnt
         FROM match_scores ms
         JOIN researchers r ON r.id = ms.researcher_id
         WHERE ms.score_ai IS NOT NULL AND r.status = 'active'
         GROUP BY bucket ORDER BY bucket ASC"
    );
}
$distRows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$distMap = []; foreach ($distRows as $dr) $distMap[(int)$dr['bucket']] = (int)$dr['cnt'];
$distMax = max(1, ...array_values($distMap ?: [1]));

$recentAudit = $conn->query(
    'SELECT actor_email, action, detail, created_at FROM audit_log ORDER BY id DESC LIMIT 8'
)->fetch_all(MYSQLI_ASSOC);
?>

<div class="panel" style="padding:20px">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:28px">
        <div class="jq-status-card">
            <div class="jq-status-num"><?= $kpiResearchers ?></div>
            <div class="jq-status-label">Researchers</div>
        </div>
        <div class="jq-status-card">
            <div class="jq-status-num"><?= $kpiFunding ?></div>
            <div class="jq-status-label">Funding Calls</div>
        </div>
        <div class="jq-status-card">
            <div class="jq-status-num"><?= $kpiMatches ?></div>
            <div class="jq-status-label">Matches Computed</div>
        </div>
        <div class="jq-status-card">
            <div class="jq-status-num"><?= $aiCoverage ?>%</div>
            <div class="jq-status-label">AI Coverage</div>
        </div>
        <div class="jq-status-card">
            <div class="jq-status-num"><?= $kpiSummaries ?></div>
            <div class="jq-status-label">Summaries Generated</div>
        </div>
        <div class="jq-status-card">
            <div class="jq-status-num">$<?= number_format($kpiCostMonth, 2) ?></div>
            <div class="jq-status-label">API Cost This Month</div>
        </div>
    </div>

    <!-- API Balance Monitoring -->
    <div style="margin-bottom:24px">
        <h3 style="font-size:13px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:12px">API Balance Status</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px">
            <?php if (empty($balanceStatus)): ?>
            <div class="panel" style="padding:20px;text-align:center;color:var(--muted)">
                <p>No balance checks yet. Check will run automatically every hour.</p>
            </div>
            <?php else: ?>
                <?php foreach ($balanceStatus as $b):
                    $statusColor = [
                        'healthy' => '#10b981',
                        'warning' => '#f59e0b',
                        'critical' => '#ef4444',
                        'emergency' => '#7f1d1d',
                        'error' => '#6b7280'
                    ][$b['status']] ?? '#6b7280';
                    $statusLabel = ucfirst($b['status']);
                ?>
                <div class="panel" style="padding:14px;border-left:4px solid <?= $statusColor ?>">
                    <div style="font-weight:600;margin-bottom:8px"><?= h($b['provider']) ?></div>
                    <div style="font-size:12px;color:var(--muted);margin-bottom:6px">
                        Status: <span style="color:<?= $statusColor ?>;font-weight:600"><?= $statusLabel ?></span>
                    </div>
                    <?php if ($b['remaining_balance'] !== null): ?>
                    <div style="font-size:12px;color:var(--muted);margin-bottom:6px">
                        Remaining: <span style="font-weight:600">${<?= number_format((float)$b['remaining_balance'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($b['total_budget'] !== null): ?>
                    <div style="font-size:12px;color:var(--muted);margin-bottom:6px">
                        Budget: <span style="font-weight:600">${<?= number_format((float)$b['total_budget'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <div style="font-size:11px;color:#999">
                        Last checked: <?= $b['last_checked_at'] ? date('M j, H:i', strtotime($b['last_checked_at'])) : 'Never' ?>
                    </div>
                    <?php if ($b['last_check_error']): ?>
                    <div style="font-size:11px;color:#ef4444;margin-top:6px">
                        Error: <?= h(substr($b['last_check_error'], 0, 60)) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:60% 40%;gap:20px;margin-bottom:20px">
        <!-- Top AI Matches -->
        <div>
            <h3 style="font-size:13px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:12px">Top AI Matches</h3>
            <div style="overflow-x:auto">
                <table class="jq-table">
                    <thead>
                        <tr>
                            <th>Researcher</th>
                            <th>Funding Call</th>
                            <th>Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($topMatches)): ?>
                        <tr><td colspan="3" style="text-align:center;padding:20px;color:var(--muted)">No AI scores yet</td></tr>
                        <?php else: ?>
                            <?php foreach ($topMatches as $m): ?>
                            <tr>
                                <td><?= h($m['first_name'] . ' ' . $m['last_name']) ?></td>
                                <td><?= h($m['title']) ?></td>
                                <td><span class="jq-badge" style="background:#eaf6f0;color:#1a6b5a"><?= (int)$m['score_ai'] ?>%</span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Score Distribution -->
            <div style="margin-top:24px">
                <h3 style="font-size:13px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:12px">Score Distribution</h3>
                <?php foreach ([0, 20, 40, 60, 80] as $bucket): ?>
                    <?php $cnt = $distMap[$bucket] ?? 0; $pct = $distMax > 0 ? round(($cnt / $distMax) * 100) : 0; ?>
                    <div style="margin-bottom:8px;display:flex;align-items:center;gap:8px">
                        <div style="width:40px;font-size:11px;color:var(--muted)"><?= $bucket ?>-<?= $bucket+19 ?></div>
                        <div style="flex:1;height:16px;background:var(--line);border-radius:4px;overflow:hidden">
                            <div style="width:<?= max(5, $pct) ?>%;height:100%;background:var(--primary)"></div>
                        </div>
                        <div style="width:30px;text-align:right;font-size:11px;font-weight:600"><?= $cnt ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Recent Activity -->
        <div>
            <h3 style="font-size:13px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:12px">Recent Activity</h3>
            <div style="border:1px solid var(--line);border-radius:8px;overflow:hidden">
                <?php if (empty($recentAudit)): ?>
                <div style="padding:20px;text-align:center;color:var(--muted)">No recent activity</div>
                <?php else: ?>
                    <?php foreach ($recentAudit as $a): ?>
                    <div style="padding:10px 14px;border-bottom:1px solid var(--line);font-size:13px">
                        <div style="font-weight:600;margin-bottom:2px"><?= h($a['action']) ?></div>
                        <div class="muted" style="font-size:11px"><?= h($a['actor_email']) ?></div>
                        <?php if ($a['detail']): ?><div class="muted" style="font-size:11px;margin-top:2px"><?= h(substr($a['detail'], 0, 50)) ?></div><?php endif; ?>
                        <div class="muted" style="font-size:10px;margin-top:4px"><?= date('M j, H:i', strtotime($a['created_at'])) ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php elseif ($adminSection === 'users'): ?>
<!-- ── Users section ── -->
<div class="panel page-head" style="padding:14px 20px;margin-bottom:12px">
    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <input type="hidden" name="page" value="admin">
        <input type="hidden" name="section" value="users">
        <?php if ($editId): ?><input type="hidden" name="edit" value="<?= $editId ?>"><?php endif; ?>
        <input type="text" name="search" value="<?= h($search) ?>" placeholder="Search by name or email…" style="flex:1;min-width:200px">
        <button class="ghost-btn" type="submit">Search</button>
        <?php if ($search || $roleFilter): ?>
            <a class="ghost-btn" href="index.php?page=admin&section=users<?= $editId ? '&edit='.$editId : '' ?>">Clear</a>
        <?php endif; ?>
    </form>
    <div class="role-tabs">
        <a class="role-tab <?= $statusTab==='active' ? 'active' : '' ?>" href="index.php?page=admin&section=users&utab=active<?= $roleFilter ? '&role='.$roleFilter : '' ?><?= $search ? '&search='.urlencode($search) : '' ?>">Active <?php if ($statusCounts['active'] > 0): ?><span style="margin-left:4px;opacity:.7">(<?= $statusCounts['active'] ?>)</span><?php endif; ?></a>
        <a class="role-tab <?= $statusTab==='pending' ? 'active' : '' ?>" href="index.php?page=admin&section=users&utab=pending<?= $roleFilter ? '&role='.$roleFilter : '' ?><?= $search ? '&search='.urlencode($search) : '' ?>">⏳ Pending <?php if ($statusCounts['pending'] > 0): ?><span style="margin-left:4px;opacity:.7">(<?= $statusCounts['pending'] ?>)</span><?php endif; ?></a>
        <a class="role-tab <?= $statusTab==='inactive' ? 'active' : '' ?>" href="index.php?page=admin&section=users&utab=inactive<?= $roleFilter ? '&role='.$roleFilter : '' ?><?= $search ? '&search='.urlencode($search) : '' ?>">Inactive <?php if ($statusCounts['inactive'] > 0): ?><span style="margin-left:4px;opacity:.7">(<?= $statusCounts['inactive'] ?>)</span><?php endif; ?></a>
        <a class="role-tab <?= $statusTab==='trash' ? 'active' : '' ?>" href="index.php?page=admin&section=users&utab=trash<?= $roleFilter ? '&role='.$roleFilter : '' ?><?= $search ? '&search='.urlencode($search) : '' ?>">Trash <?php if ($statusCounts['trash'] > 0): ?><span style="margin-left:4px;opacity:.7">(<?= $statusCounts['trash'] ?>)</span><?php endif; ?></a>
        <div style="flex:1;border-left:1px solid var(--line);margin-left:12px;padding-left:12px;display:flex;gap:6px;flex-wrap:wrap">
            <a class="role-tab <?= !$roleFilter ? 'active' : '' ?>" href="index.php?page=admin&section=users&utab=<?= $statusTab ?><?= $search ? '&search='.urlencode($search) : '' ?>">All Roles</a>
            <a class="role-tab <?= $roleFilter==='researcher' ? 'active' : '' ?>" href="index.php?page=admin&section=users&utab=<?= $statusTab ?>&role=researcher<?= $search ? '&search='.urlencode($search) : '' ?>">Researchers</a>
            <a class="role-tab funder-tab <?= $roleFilter==='funder' ? 'active' : '' ?>" href="index.php?page=admin&section=users&utab=<?= $statusTab ?>&role=funder<?= $search ? '&search='.urlencode($search) : '' ?>">Funders</a>
            <a class="role-tab admin-tab <?= $roleFilter==='admin' ? 'active' : '' ?>" href="index.php?page=admin&section=users&utab=<?= $statusTab ?>&role=admin<?= $search ? '&search='.urlencode($search) : '' ?>">Admins</a>
        </div>
    </div>
</div>

<!-- Edit panel -->
<?php if ($editUser): ?>
<div class="panel edit-panel">
    <div class="head-row">
        <div>
            <h2 style="margin-bottom:2px"><?= h($editUser['name']) ?></h2>
            <div class="user-meta"><?= h($editUser['email']) ?> &nbsp;·&nbsp; <span class="role-badge role-badge-<?= h($editUser['role']) ?>"><?= h(ucfirst($editUser['role'])) ?></span></div>
        </div>
        <a class="ghost-btn" href="index.php?page=admin<?= $roleFilter ? '&role='.$roleFilter : '' ?>">✕ Close</a>
    </div>

    <!-- Update name -->
    <div class="edit-section">
        <div class="edit-section-title">Display Name</div>
        <form method="post" style="display:flex;gap:10px;align-items:flex-end">
            <input type="hidden" name="action" value="update_name">
            <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
            <div style="flex:1"><label style="font-size:12px;margin-bottom:4px">Full Name</label><input name="name" value="<?= h($editUser['name']) ?>" required></div>
            <button class="ghost-btn" type="submit">Save Name</button>
        </form>
    </div>

    <!-- Change role -->
    <div class="edit-section">
        <div class="edit-section-title">Role</div>
        <form method="post" style="display:flex;gap:10px;align-items:flex-end">
            <input type="hidden" name="action" value="update_role">
            <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
            <div style="flex:1">
                <label style="font-size:12px;margin-bottom:4px">Assign Role</label>
                <select name="role">
                    <option value="researcher" <?= $editUser['role']==='researcher'?'selected':''?>>Researcher</option>
                    <option value="funder"     <?= $editUser['role']==='funder'    ?'selected':''?>>Funder</option>
                    <option value="admin"      <?= $editUser['role']==='admin'     ?'selected':''?>>Admin</option>
                </select>
            </div>
            <button class="ghost-btn" type="submit">Update Role</button>
        </form>
        <p class="muted small" style="margin-top:8px">Note: changing role does not move the user's researcher/funder profile — it only changes login permissions.</p>
    </div>

    <!-- Reset password -->
    <div class="edit-section">
        <div class="edit-section-title">Reset Password</div>
        <form method="post">
            <input type="hidden" name="action" value="set_password">
            <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
            <div class="pw-grid">
                <div><label style="font-size:12px;margin-bottom:4px">New Password</label><input type="password" name="new_password" placeholder="Min 6 characters…" minlength="6" id="ap-pw"></div>
                <div><label style="font-size:12px;margin-bottom:4px">Confirm Password</label><input type="password" name="confirm_new_password" placeholder="Repeat password…" id="ap-cpw"></div>
            </div>
            <div id="ap-pw-msg" style="display:none;color:#b54646;font-size:13px;margin-bottom:10px">⚠ Passwords do not match</div>
            <button class="primary-btn" type="submit">Set New Password</button>
        </form>
    </div>

    <?php if ($editUser['role'] === 'researcher'): ?>
    <!-- Quick link to researcher profile -->
    <div class="edit-section">
        <div class="edit-section-title">Researcher Profile</div>
        <?php
        $rp = $conn->prepare('SELECT id FROM researchers WHERE email = ? LIMIT 1');
        $rp->bind_param('s', $editUser['email']); $rp->execute();
        $rpRow = $rp->get_result()->fetch_assoc();
        ?>
        <?php if ($rpRow): ?>
            <a class="ghost-btn" href="index.php?page=researchers&view=<?= $rpRow['id'] ?>">View Profile ↗</a>
            <a class="ghost-btn" href="index.php?page=researchers&edit=<?= $rpRow['id'] ?>" style="margin-left:6px">Edit Profile ↗</a>
        <?php else: ?>
            <p class="muted">No researcher profile linked to this email yet.</p>
        <?php endif; ?>
    </div>
    <?php elseif ($editUser['role'] === 'funder'): ?>
    <div class="edit-section">
        <div class="edit-section-title">Funder Profile</div>
        <?php
        $fp = $conn->prepare('SELECT organization FROM funders WHERE email = ? LIMIT 1');
        $fp->bind_param('s', $editUser['email']); $fp->execute();
        $fpRow = $fp->get_result()->fetch_assoc();
        ?>
        <?php if ($fpRow): ?>
            <p class="muted">Organisation: <strong><?= h($fpRow['organization']) ?></strong></p>
        <?php else: ?>
            <p class="muted">No funder profile linked to this email yet.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Verification status -->
    <div class="edit-section">
        <div class="edit-section-title">Email Verification</div>
        <?php $isVerified = ($editUser['status'] ?? 'active') === 'active'; ?>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
            <?php if ($isVerified): ?>
                <span style="display:inline-flex;align-items:center;gap:6px;background:#eaf6f0;border:1px solid #c3dfd0;border-radius:6px;padding:5px 12px;font-size:13px;font-weight:600;color:#1a6b5a">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#1a6b5a" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Verified
                </span>
            <?php else: ?>
                <span style="display:inline-flex;align-items:center;gap:6px;background:#fef3c7;border:1px solid #f0d080;border-radius:6px;padding:5px 12px;font-size:13px;font-weight:600;color:#b45309">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#b45309" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    Unverified
                </span>
            <?php endif; ?>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <?php if (!$isVerified): ?>
            <form method="post" style="display:inline">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="verify_user">
                <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
                <button class="primary-btn" type="submit" style="font-size:13px;padding:8px 14px">✓ Manually Verify</button>
            </form>
            <?php else: ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Mark this account as unverified? The user will not be able to sign in until they verify again.')">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="unverify_user">
                <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
                <button class="ghost-btn" type="submit" style="font-size:13px">Mark Unverified</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Send reset link -->
    <div class="edit-section">
        <div class="edit-section-title">Password Reset Link</div>
        <p class="muted small" style="margin-bottom:10px">Generate and email a secure reset link to this user (expires in 1 hour).</p>
        <form method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="send_reset">
            <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
            <button class="ghost-btn" type="submit">Send Reset Email</button>
        </form>
    </div>

    <!-- Account Lifecycle Actions -->
    <?php if ($editUser['id'] !== (int)$adminUser['id']): ?>
    <div class="edit-section">
        <div class="edit-section-title">Account Status</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
            <?php if ($editUser['status'] === 'active'): ?>
                <span style="display:inline-flex;align-items:center;gap:6px;background:#eef9f6;border:1px solid #c3dfd0;border-radius:6px;padding:6px 12px;font-size:13px;font-weight:600;color:#1a6b5a">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Active
                </span>
            <?php elseif ($editUser['status'] === 'pending_approval'): ?>
                <span style="display:inline-flex;align-items:center;gap:6px;background:#fef3c7;border:1px solid #f0d080;border-radius:6px;padding:6px 12px;font-size:13px;font-weight:600;color:#b45309">
                    ⏳ Pending Approval
                </span>
            <?php elseif ($editUser['status'] === 'inactive'): ?>
                <span style="display:inline-flex;align-items:center;gap:6px;background:#fef9ec;border:1px solid #f0d080;border-radius:6px;padding:6px 12px;font-size:13px;font-weight:600;color:#c8a85a">
                    ⊘ Inactive
                </span>
            <?php elseif ($editUser['status'] === 'deleted'): ?>
                <span style="display:inline-flex;align-items:center;gap:6px;background:#fff5f5;border:1px solid #e5b9b9;border-radius:6px;padding:6px 12px;font-size:13px;font-weight:600;color:#b54646">
                    ✕ Deleted
                </span>
            <?php endif; ?>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <?php if ($editUser['status'] === 'active'): ?>
                <form method="post" style="display:inline">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="deactivate_user">
                    <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
                    <button class="ghost-btn" type="submit" style="font-size:13px;padding:8px 14px">Deactivate</button>
                </form>
                <form method="post" style="display:inline" onsubmit="return prompt('Reason for deletion (optional):', '') !== null || false;">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
                    <button class="danger-btn" type="submit" style="font-size:13px;padding:8px 14px">Move to Trash</button>
                </form>
            <?php elseif ($editUser['status'] === 'pending_approval'): ?>
                <form method="post" style="display:inline">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="approve_user">
                    <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
                    <button class="primary-btn" type="submit" style="font-size:13px;padding:8px 14px">✓ Approve</button>
                </form>
                <form method="post" style="display:inline">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="reject_user">
                    <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
                    <input type="text" name="rejection_reason" placeholder="Reason (optional)" maxlength="500" style="padding:8px 10px;font-size:13px;border:1px solid var(--line);border-radius:4px;width:200px">
                    <button class="danger-btn" type="submit" style="font-size:13px;padding:8px 14px">✗ Reject</button>
                </form>
            <?php elseif ($editUser['status'] === 'inactive'): ?>
                <form method="post" style="display:inline">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="activate_user">
                    <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
                    <button class="primary-btn" type="submit" style="font-size:13px;padding:8px 14px">Activate</button>
                </form>
                <form method="post" style="display:inline" onsubmit="return prompt('Reason for deletion (optional):', '') !== null || false;">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
                    <button class="danger-btn" type="submit" style="font-size:13px;padding:8px 14px">Move to Trash</button>
                </form>
            <?php elseif ($editUser['status'] === 'deleted'): ?>
                <form method="post" style="display:inline">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="restore_user">
                    <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
                    <button class="primary-btn" type="submit" style="font-size:13px;padding:8px 14px">Restore</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <p class="muted small" style="margin-top:12px">You cannot modify your own account.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- User list -->
<?php if (!$users): ?>
    <div class="panel admin-empty">No users found<?= $search ? ' matching "'.h($search).'"' : '' ?>.</div>
<?php endif; ?>

<?php foreach ($users as $u): ?>
<?php $isEditing = ($editUser && $editUser['id'] === $u['id']); ?>
<div class="panel list-card user-card <?= $isEditing ? 'selected-panel' : '' ?>">
    <div class="card-row">
        <div class="user-avatar ua-<?= h($u['role']) ?>"><?= strtoupper(mb_substr($u['name'], 0, 1)) ?></div>
        <div class="card-main" style="margin-left:12px">
            <div class="title-line">
                <h3><?= h($u['name']) ?></h3>
                <span class="role-badge role-badge-<?= h($u['role']) ?>"><?= h(ucfirst($u['role'])) ?></span>
                <?php if ($u['status'] === 'active'): ?>
                    <span style="display:inline-flex;align-items:center;gap:3px;background:#eef9f6;border:1px solid #c3dfd0;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:700;color:#1a6b5a;letter-spacing:.04em">✓ Active</span>
                <?php elseif ($u['status'] === 'pending_approval'): ?>
                    <span style="display:inline-flex;align-items:center;gap:3px;background:#fef3c7;border:1px solid #f0d080;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:700;color:#b45309;letter-spacing:.04em">⏳ Pending</span>
                <?php elseif ($u['status'] === 'inactive'): ?>
                    <span style="display:inline-flex;align-items:center;gap:3px;background:#fef9ec;border:1px solid #f0d080;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:700;color:#c8a85a;letter-spacing:.04em">⊘ Inactive</span>
                <?php elseif ($u['status'] === 'deleted'): ?>
                    <span style="display:inline-flex;align-items:center;gap:3px;background:#fff5f5;border:1px solid #e5b9b9;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:700;color:#b54646;letter-spacing:.04em">✕ Deleted</span>
                <?php elseif ($u['status'] === 'unverified'): ?>
                    <span style="display:inline-flex;align-items:center;gap:3px;background:#fef3c7;border:1px solid #f0d080;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:700;color:#b45309;letter-spacing:.04em">⚠ Unverified</span>
                <?php endif; ?>
                <?php if ($u['id'] === (int)$adminUser['id']): ?><span class="badge badge-outline" style="font-size:11px">You</span><?php endif; ?>
            </div>
            <div class="user-meta"><?= h($u['email']) ?></div>
        </div>
        <div class="card-actions">
            <?php if ($isEditing): ?>
                <a class="primary-btn" href="index.php?page=admin&section=users&utab=<?= $statusTab ?><?= $roleFilter ? '&role='.$roleFilter : '' ?>">Done Editing</a>
            <?php else: ?>
                <a class="ghost-btn" href="index.php?page=admin&section=users&edit=<?= $u['id'] ?>&utab=<?= $statusTab ?><?= $roleFilter ? '&role='.$roleFilter : '' ?><?= $search ? '&search='.urlencode($search) : '' ?>">Edit</a>
                <?php if ($u['id'] !== (int)$adminUser['id']): ?>
                    <?php if ($u['status'] === 'active'): ?>
                        <form method="post" style="display:inline">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="deactivate_user">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <button class="ghost-btn" type="submit">Deactivate</button>
                        </form>
                    <?php elseif ($u['status'] === 'pending_approval'): ?>
                        <form method="post" style="display:inline">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="approve_user">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <button class="primary-btn" type="submit">✓ Approve</button>
                        </form>
                        <form method="post" style="display:inline">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="reject_user">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <input type="text" name="rejection_reason" placeholder="Reason (optional)" maxlength="500" style="padding:6px 10px;font-size:12px;border:1px solid var(--line);border-radius:4px;width:180px;min-width:180px">
                            <button class="danger-btn" type="submit">✗ Reject</button>
                        </form>
                    <?php elseif ($u['status'] === 'inactive'): ?>
                        <form method="post" style="display:inline">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="activate_user">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <button class="primary-btn" type="submit">Activate</button>
                        </form>
                    <?php elseif ($u['status'] === 'deleted'): ?>
                        <form method="post" style="display:inline">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="restore_user">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <button class="primary-btn" type="submit">Restore</button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php elseif ($adminSection === 'researchers'): ?>
<!-- ── Researcher Profiles section ── -->
<?php
$rSearch = trim($_GET['rsearch'] ?? '');
$rTab = $_GET['rtab'] ?? 'active';

// Build base WHERE clause for status
$rWhere = $rTab === 'trash' ? "WHERE status = 'deleted'" : "WHERE status = 'active' AND deleted_at IS NULL";
if ($rSearch) {
    $rWhere .= " AND (CONCAT(first_name,' ',last_name) LIKE ? OR institution LIKE ? OR email LIKE ?)";
}

$rSql    = "SELECT * FROM researchers $rWhere ORDER BY first_name ASC";
$rStmt   = $conn->prepare($rSql);
if ($rSearch) {
    $rLike = '%' . $rSearch . '%';
    $rStmt->bind_param('sss', $rLike, $rLike, $rLike);
}
$rStmt->execute();
$allResearchers = $rStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$rActive = $conn->query("SELECT COUNT(*) c FROM researchers WHERE status = 'active' AND deleted_at IS NULL")->fetch_assoc()['c'];
$rTrash = $conn->query("SELECT COUNT(*) c FROM researchers WHERE status = 'deleted'")->fetch_assoc()['c'];
$rTotal = $rTab === 'trash' ? $rTrash : $rActive;
?>
<div class="panel page-head" style="padding:14px 20px;margin-bottom:12px">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div>
            <span class="strongish"><?= $rTotal ?> researcher profile<?= $rTotal !== 1 ? 's' : '' ?></span>
            <span class="muted" style="margin-left:8px;font-size:13px"><?= $rTab === 'trash' ? 'in trash' : 'in the database' ?></span>
        </div>
        <?php if ($rTab !== 'trash'): ?>
        <a class="primary-btn" href="index.php?page=researchers&mode=add">+ Add Researcher</a>
        <?php endif; ?>
    </div>
    <!-- Tabs -->
    <div style="display:flex;gap:0;border-bottom:2px solid #dde6dd;margin-top:14px;margin-bottom:12px">
        <a href="index.php?page=admin&section=researchers&rtab=active" style="padding:10px 16px;font-weight:600;color:<?= $rTab === 'active' ? 'var(--primary)' : 'var(--muted)' ?>;border-bottom:3px solid <?= $rTab === 'active' ? 'var(--primary)' : 'transparent' ?>;text-decoration:none">Active (<?= $rActive ?>)</a>
        <a href="index.php?page=admin&section=researchers&rtab=trash" style="padding:10px 16px;font-weight:600;color:<?= $rTab === 'trash' ? 'var(--primary)' : 'var(--muted)' ?>;border-bottom:3px solid <?= $rTab === 'trash' ? 'var(--primary)' : 'transparent' ?>;text-decoration:none">Trash (<?= $rTrash ?>)</a>
    </div>
    <form method="get" style="display:flex;gap:10px">
        <input type="hidden" name="page" value="admin">
        <input type="hidden" name="section" value="researchers">
        <input type="hidden" name="rtab" value="<?= h($rTab) ?>">
        <input type="text" name="rsearch" value="<?= h($rSearch) ?>" placeholder="Search by name, institution, or email…" style="flex:1">
        <button class="ghost-btn" type="submit">Search</button>
        <?php if ($rSearch): ?><a class="ghost-btn" href="index.php?page=admin&section=researchers&rtab=<?= h($rTab) ?>">Clear</a><?php endif; ?>
    </form>
</div>

<div class="panel" style="padding:0;overflow:hidden">
    <?php if (!$allResearchers): ?>
        <div class="admin-empty">No researcher profiles found<?= $rSearch ? ' matching "'.h($rSearch).'"' : '' ?>.</div>
    <?php else: ?>
    <table class="r-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Institution</th>
                <th>Email</th>
                <th>Topics</th>
                <th style="width:160px">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($allResearchers as $r):
            $rFullName = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: '—';
            $rTopics   = array_slice(array_filter(array_map('trim', explode(',', $r['topics'] ?? ''))), 0, 3);
        ?>
        <tr>
            <td>
                <strong><?= h($rFullName) ?></strong>
                <?php if ($r['title']): ?><br><span class="muted small"><?= h($r['title']) ?></span><?php endif; ?>
            </td>
            <td><?= h($r['institution'] ?: '—') ?></td>
            <td><a href="mailto:<?= h($r['email']) ?>" class="muted small"><?= h($r['email'] ?: '—') ?></a></td>
            <td>
                <?php foreach ($rTopics as $t): ?>
                    <span class="tag topic-tag" style="font-size:11px;padding:3px 8px"><?= h($t) ?></span>
                <?php endforeach; ?>
            </td>
            <td>
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                    <?php if ($rTab === 'trash'): ?>
                        <form method="post" onsubmit="return confirm('Restore this researcher profile?')">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="restore_researcher">
                            <input type="hidden" name="researcher_id" value="<?= (int)$r['id'] ?>">
                            <button class="primary-btn" type="submit" style="padding:6px 10px;font-size:12px">Restore</button>
                        </form>
                    <?php else: ?>
                        <a class="ghost-btn" href="index.php?page=researchers&view=<?= (int)$r['id'] ?>" style="padding:6px 10px;font-size:12px">View</a>
                        <a class="ghost-btn" href="index.php?page=researchers&edit=<?= (int)$r['id'] ?>" style="padding:6px 10px;font-size:12px">Edit</a>
                        <form method="post" onsubmit="return confirm('Delete this researcher profile?')">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="delete_researcher">
                            <input type="hidden" name="researcher_id" value="<?= (int)$r['id'] ?>">
                            <button class="danger-btn" type="submit" style="padding:6px 10px;font-size:12px">Delete</button>
                        </form>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php elseif ($adminSection === 'funders'): ?>
<!-- ── Funder Profiles section ── -->
<?php
$fSearch = trim($_GET['fsearch'] ?? '');
$fTab = $_GET['ftab'] ?? 'active';

// Build base WHERE clause for status
$fWhere = $fTab === 'trash' ? "WHERE status = 'deleted'" : "WHERE status = 'active' AND deleted_at IS NULL";
if ($fSearch) {
    $fWhere .= " AND (CONCAT(first_name,' ',last_name) LIKE ? OR organization LIKE ? OR email LIKE ?)";
}

$fSql    = "SELECT * FROM funders $fWhere ORDER BY first_name ASC, last_name ASC";
$fStmt   = $conn->prepare($fSql);
if ($fSearch) {
    $fLike = '%' . $fSearch . '%';
    $fStmt->bind_param('sss', $fLike, $fLike, $fLike);
}
$fStmt->execute();
$allFunders = $fStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$fActive = $conn->query("SELECT COUNT(*) c FROM funders WHERE status = 'active' AND deleted_at IS NULL")->fetch_assoc()['c'];
$fTrash = $conn->query("SELECT COUNT(*) c FROM funders WHERE status = 'deleted'")->fetch_assoc()['c'];
$fTotal = $fTab === 'trash' ? $fTrash : $fActive;
?>
<div class="panel page-head" style="padding:14px 20px;margin-bottom:12px">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div>
            <span class="strongish"><?= $fTotal ?> funder profile<?= $fTotal !== 1 ? 's' : '' ?></span>
            <span class="muted" style="margin-left:8px;font-size:13px"><?= $fTab === 'trash' ? 'in trash' : 'in the database' ?></span>
        </div>
    </div>
    <!-- Tabs -->
    <div style="display:flex;gap:0;border-bottom:2px solid #dde6dd;margin-top:14px;margin-bottom:12px">
        <a href="index.php?page=admin&section=funders&ftab=active" style="padding:10px 16px;font-weight:600;color:<?= $fTab === 'active' ? 'var(--primary)' : 'var(--muted)' ?>;border-bottom:3px solid <?= $fTab === 'active' ? 'var(--primary)' : 'transparent' ?>;text-decoration:none">Active (<?= $fActive ?>)</a>
        <a href="index.php?page=admin&section=funders&ftab=trash" style="padding:10px 16px;font-weight:600;color:<?= $fTab === 'trash' ? 'var(--primary)' : 'var(--muted)' ?>;border-bottom:3px solid <?= $fTab === 'trash' ? 'var(--primary)' : 'transparent' ?>;text-decoration:none">Trash (<?= $fTrash ?>)</a>
    </div>
    <form method="get" style="display:flex;gap:10px">
        <input type="hidden" name="page" value="admin">
        <input type="hidden" name="section" value="funders">
        <input type="hidden" name="ftab" value="<?= h($fTab) ?>">
        <input type="text" name="fsearch" value="<?= h($fSearch) ?>" placeholder="Search by name, organisation, or email…" style="flex:1">
        <button class="ghost-btn" type="submit">Search</button>
        <?php if ($fSearch): ?><a class="ghost-btn" href="index.php?page=admin&section=funders&ftab=<?= h($fTab) ?>">Clear</a><?php endif; ?>
    </form>
</div>

<div class="panel" style="padding:0;overflow:hidden">
    <?php if (!$allFunders): ?>
        <div class="admin-empty">No funder profiles found<?= $fSearch ? ' matching "'.h($fSearch).'"' : '' ?>.</div>
    <?php else: ?>
    <table class="r-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Organisation</th>
                <th>Country</th>
                <th>Email</th>
                <th style="width:120px">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($allFunders as $f):
            $fFullName = trim(($f['first_name'] ?? '') . ' ' . ($f['last_name'] ?? '')) ?: '—';
        ?>
        <tr>
            <td><strong><?= h($fFullName) ?></strong></td>
            <td><?= h($f['organization'] ?: '—') ?></td>
            <td><?= h($f['country'] ?: '—') ?></td>
            <td><a href="mailto:<?= h($f['email']) ?>" class="muted small"><?= h($f['email'] ?: '—') ?></a></td>
            <td>
                <?php if ($fTab === 'trash'): ?>
                    <form method="post" onsubmit="return confirm('Restore this funder profile?')">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="restore_funder">
                        <input type="hidden" name="funder_id" value="<?= (int)$f['id'] ?>">
                        <button class="primary-btn" type="submit" style="padding:6px 10px;font-size:12px">Restore</button>
                    </form>
                <?php else: ?>
                    <form method="post" onsubmit="return confirm('Delete this funder profile?')">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="delete_funder">
                        <input type="hidden" name="funder_id" value="<?= (int)$f['id'] ?>">
                        <button class="danger-btn" type="submit" style="padding:6px 10px;font-size:12px">Delete</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php elseif ($adminSection === 'audit'): ?>
<!-- ── Audit Log section ── -->
<?php
$auditPage  = max(1, (int)($_GET['apage'] ?? 1));
$auditLimit = 50;
$auditOffset = ($auditPage - 1) * $auditLimit;
$auditTotal  = (int)$conn->query('SELECT COUNT(*) c FROM audit_log')->fetch_assoc()['c'];
$auditPages  = max(1, (int)ceil($auditTotal / $auditLimit));

$auditStmt = $conn->prepare(
    'SELECT * FROM audit_log ORDER BY created_at DESC LIMIT ? OFFSET ?'
);
$auditStmt->bind_param('ii', $auditLimit, $auditOffset);
$auditStmt->execute();
$auditRows = $auditStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$actionLabels = [
    'delete_user'       => ['Delete User',       '#b54646'],
    'delete_researcher' => ['Delete Researcher',  '#b54646'],
    'delete_funder'     => ['Delete Funder',      '#b54646'],
    'verify_user'       => ['Verify Account',     '#1a6b5a'],
    'unverify_user'     => ['Unverify Account',   '#d97706'],
    'send_reset'        => ['Send Reset',         '#315f90'],
    'update_role'       => ['Change Role',        '#6b21a8'],
    'set_password'      => ['Set Password',       '#d97706'],
    'update_name'       => ['Update Name',        '#374151'],
];
?>
<style>
.audit-table{width:100%;border-collapse:collapse;font-size:13px}
.audit-table th{text-align:left;padding:10px 14px;background:#f8fafb;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);border-bottom:2px solid var(--line)}
.audit-table td{padding:10px 14px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
.audit-table tr:last-child td{border-bottom:none}
.audit-table tr:hover td{background:#fafcfb}
.audit-badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;color:#fff}
.audit-empty{padding:40px;text-align:center;color:var(--muted)}
.audit-pagination{display:flex;gap:6px;justify-content:center;padding:14px}
</style>
<div class="panel" style="padding:0;overflow:hidden">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--line)">
        <div>
            <strong style="font-size:16px">Admin Audit Log</strong>
            <span class="muted" style="font-size:13px;margin-left:10px"><?= number_format($auditTotal) ?> event<?= $auditTotal !== 1 ? 's' : '' ?> recorded</span>
        </div>
        <span class="muted" style="font-size:12px">Page <?= $auditPage ?> of <?= $auditPages ?></span>
    </div>

    <?php if (!$auditRows): ?>
    <div class="audit-empty">
        No admin actions have been recorded yet. Actions will appear here automatically.
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
        <table class="audit-table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Admin</th>
                    <th>Action</th>
                    <th>Target</th>
                    <th>Detail</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($auditRows as $al):
                [$label, $color] = $actionLabels[$al['action']] ?? [ucwords(str_replace('_',' ',$al['action'])), '#888'];
                $timeStr = date('M j, Y g:i A', strtotime($al['created_at']));
            ?>
            <tr>
                <td class="muted" style="white-space:nowrap;font-size:12px"><?= $timeStr ?></td>
                <td style="white-space:nowrap">
                    <strong style="font-size:13px"><?= h(explode('@', $al['actor_email'])[0]) ?></strong><br>
                    <span class="muted" style="font-size:11px"><?= h($al['actor_email']) ?></span>
                </td>
                <td><span class="audit-badge" style="background:<?= $color ?>"><?= h($label) ?></span></td>
                <td style="font-size:12px">
                    <?php if ($al['target_email']): ?>
                        <?= h($al['target_email']) ?>
                    <?php elseif ($al['target_id']): ?>
                        ID #<?= (int)$al['target_id'] ?>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="muted" style="font-size:12px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?= $al['detail'] ? h($al['detail']) : '—' ?>
                </td>
                <td class="muted" style="font-size:12px;white-space:nowrap"><?= h($al['ip'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($auditPages > 1): ?>
    <div class="audit-pagination">
        <?php for ($p = 1; $p <= $auditPages; $p++): ?>
        <a href="index.php?page=admin&section=audit&apage=<?= $p ?>"
           class="<?= $p === $auditPage ? 'primary-btn' : 'ghost-btn' ?>"
           style="padding:6px 12px;font-size:13px"><?= $p ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php elseif ($adminSection === 'api_usage'): ?>
<!-- ── API Usage section ── -->
<?php
$auPage  = max(1, (int)($_GET['aupage'] ?? 1));
$auLimit = 50;
$auOffset = ($auPage - 1) * $auLimit;
$auTotal = (int)$conn->query('SELECT COUNT(*) c FROM api_usage')->fetch_assoc()['c'];
$auPages = max(1, (int)ceil($auTotal / $auLimit));

// 30-day summary by model
$modelStmt = $conn->prepare(
    'SELECT model, COUNT(*) calls, SUM(token_input) total_in, SUM(token_output) total_out, SUM(cost_usd) total_cost, SUM(CASE WHEN status=\'error\' THEN 1 ELSE 0 END) errors FROM api_usage WHERE created_at >= DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY model ORDER BY total_cost DESC'
);
$modelStmt->execute();
$modelRows = $modelStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// All-time totals
$totalStmt = $conn->query('SELECT SUM(cost_usd) total_cost, SUM(token_input+token_output) total_tokens, COUNT(*) total_calls FROM api_usage');
$totalRow = $totalStmt->fetch_assoc();

// Recent calls paginated
$recentStmt = $conn->prepare(
    'SELECT model, purpose, token_input, token_output, cost_usd, duration_ms, status, error_code, triggered_by, created_at FROM api_usage ORDER BY created_at DESC LIMIT ? OFFSET ?'
);
$recentStmt->bind_param('ii', $auLimit, $auOffset);
$recentStmt->execute();
$recentRows = $recentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<style>
.au-stat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px}
.au-stat-box{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:16px}
.au-stat-label{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:6px}
.au-stat-value{font-size:24px;font-weight:900;color:var(--primary);line-height:1}
.au-cost{color:#c8a85a}
.au-table{width:100%;border-collapse:collapse;font-size:13px;margin-top:14px}
.au-table th{text-align:left;padding:10px 14px;background:#f8fafb;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);border-bottom:2px solid var(--line)}
.au-table td{padding:10px 14px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
.au-table tr:last-child td{border-bottom:none}
.au-table tr:hover td{background:#fafcfb}
.au-badge{display:inline-block;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700}
.au-badge.ok{background:#eaf6f0;color:#1a6b5a}
.au-badge.error{background:#fee2e2;color:#b91c1c}
.au-pagination{display:flex;gap:6px;justify-content:center;padding:14px}
</style>
<div class="panel" style="padding:20px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
        <strong style="font-size:16px">API Usage Analytics</strong>
        <span class="muted" style="font-size:12px"><?= number_format($totalRow['total_calls'] ?? 0) ?> total calls</span>
    </div>

    <!-- All-time summary -->
    <div class="au-stat-grid">
        <div class="au-stat-box">
            <div class="au-stat-label">Total Cost (All-Time)</div>
            <div class="au-stat-value au-cost">$<?= number_format($totalRow['total_cost'] ?? 0, 4) ?></div>
        </div>
        <div class="au-stat-box">
            <div class="au-stat-label">Total Tokens</div>
            <div class="au-stat-value"><?= number_format($totalRow['total_tokens'] ?? 0) ?></div>
        </div>
        <div class="au-stat-box">
            <div class="au-stat-label">Average Cost Per Call</div>
            <div class="au-stat-value au-cost">
                <?php $avgCost = ($totalRow['total_calls'] ?? 0) > 0 ? ($totalRow['total_cost'] ?? 0) / ($totalRow['total_calls'] ?? 1) : 0; ?>
                $<?= number_format($avgCost, 4) ?>
            </div>
        </div>
    </div>

    <!-- 30-day breakdown by model -->
    <h3 style="font-size:14px;font-weight:700;margin-top:20px;margin-bottom:12px">30-Day Summary by Model</h3>
    <?php if (!$modelRows): ?>
    <p class="muted">No API calls in the last 30 days.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
        <table class="au-table">
            <thead>
                <tr>
                    <th>Model</th>
                    <th>Calls</th>
                    <th>Input Tokens</th>
                    <th>Output Tokens</th>
                    <th>Cost</th>
                    <th>Errors</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($modelRows as $m): ?>
            <tr>
                <td><strong style="font-size:12px;font-family:monospace"><?= h($m['model']) ?></strong></td>
                <td><?= number_format($m['calls'] ?? 0) ?></td>
                <td><?= number_format($m['total_in'] ?? 0) ?></td>
                <td><?= number_format($m['total_out'] ?? 0) ?></td>
                <td style="color:#c8a85a;font-weight:600">$<?= number_format($m['total_cost'] ?? 0, 4) ?></td>
                <td>
                    <?php if (($m['errors'] ?? 0) > 0): ?>
                        <span class="au-badge error"><?= $m['errors'] ?> error<?= ($m['errors'] ?? 0) !== 1 ? 's' : '' ?></span>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Recent calls -->
    <h3 style="font-size:14px;font-weight:700;margin-top:20px;margin-bottom:12px">Recent Calls (<?= $auPage ?> of <?= $auPages ?>)</h3>
    <?php if (!$recentRows): ?>
    <p class="muted">No API calls recorded yet.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
        <table class="au-table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Model</th>
                    <th>Purpose</th>
                    <th>Tokens (In/Out)</th>
                    <th>Cost</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recentRows as $au):
                $timeStr = date('M j g:i A', strtotime($au['created_at']));
            ?>
            <tr>
                <td class="muted" style="white-space:nowrap;font-size:12px"><?= $timeStr ?></td>
                <td><span style="font-family:monospace;font-size:11px"><?= h(substr($au['model'], -12)) ?></span></td>
                <td style="font-size:12px"><?= h($au['purpose']) ?></td>
                <td style="font-size:12px"><?= number_format($au['token_input'] ?? 0) ?> / <?= number_format($au['token_output'] ?? 0) ?></td>
                <td style="color:#c8a85a;font-weight:600;font-size:12px">$<?= number_format($au['cost_usd'] ?? 0, 4) ?></td>
                <td>
                    <?php if ($au['status'] === 'ok'): ?>
                        <span class="au-badge ok">OK</span>
                    <?php elseif ($au['status'] === 'error'): ?>
                        <span class="au-badge error">ERROR</span>
                    <?php else: ?>
                        <span class="au-badge" style="background:#fef3c7;color:#b45309"><?= ucfirst(h($au['status'])) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($auPages > 1): ?>
    <div class="au-pagination">
        <?php for ($p = 1; $p <= $auPages; $p++): ?>
        <a href="index.php?page=admin&section=api_usage&aupage=<?= $p ?>"
           class="<?= $p === $auPage ? 'primary-btn' : 'ghost-btn' ?>"
           style="padding:6px 12px;font-size:13px"><?= $p ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php elseif ($adminSection === 'jobs'): ?>
<!-- ── Job Queue section ── -->
<?php
// Status summary
$statusStmt = $conn->prepare(
    'SELECT status, job_type, COUNT(*) cnt FROM job_queue GROUP BY status, job_type ORDER BY job_type ASC, status ASC'
);
$statusStmt->execute();
$statusRows = $statusStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Failed jobs (most recent)
$failedStmt = $conn->prepare(
    'SELECT id, job_type, payload, attempts, max_attempts, last_error, created_at, updated_at FROM job_queue WHERE status=\'failed\' ORDER BY updated_at DESC LIMIT 20'
);
$failedStmt->execute();
$failedRows = $failedStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Recent activity (all statuses)
$recentJobStmt = $conn->prepare(
    'SELECT id, job_type, status, attempts, max_attempts, last_error, created_at, updated_at FROM job_queue ORDER BY updated_at DESC LIMIT 100'
);
$recentJobStmt->execute();
$recentJobRows = $recentJobStmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<style>
.jq-status-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px}
.jq-status-card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:14px;text-align:center}
.jq-status-num{font-size:28px;font-weight:900;color:var(--primary);line-height:1;margin-bottom:4px}
.jq-status-label{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.jq-table{width:100%;border-collapse:collapse;font-size:13px;margin-top:14px}
.jq-table th{text-align:left;padding:10px 14px;background:#f8fafb;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);border-bottom:2px solid var(--line)}
.jq-table td{padding:10px 14px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
.jq-table tr:last-child td{border-bottom:none}
.jq-table tr:hover td{background:#fafcfb}
.jq-badge{display:inline-block;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700}
.jq-badge.pending{background:#fef3c7;color:#b45309}
.jq-badge.running{background:#dbeafe;color:#1e40af}
.jq-badge.done{background:#eaf6f0;color:#1a6b5a}
.jq-badge.failed{background:#fee2e2;color:#b91c1c}
.jq-error-box{background:#fff8f8;border:1px solid #f0c8c8;border-radius:6px;padding:8px 10px;font-size:12px;color:#b54646;font-family:monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
</style>
<div class="panel" style="padding:20px">
    <strong style="font-size:16px;display:block;margin-bottom:14px">Job Queue Monitor</strong>

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;gap:16px;flex-wrap:wrap;padding:14px;background:#f9fbfa;border:1px solid var(--line);border-radius:8px">
        <p class="muted" style="margin:0">Queue AI matching jobs for all currently open, upcoming, and rolling funding calls.</p>
        <form method="post">
            <input type="hidden" name="action" value="compute_all_matches">
            <?= csrf_input() ?>
            <button class="primary-btn" type="submit" onclick="return confirm('Queue AI matching for all active funding calls? This may take several minutes.')">⚡ Compute All Matches</button>
        </form>
    </div>

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;gap:16px;flex-wrap:wrap;padding:14px;background:#f9fbfa;border:1px solid var(--line);border-radius:8px">
        <p class="muted" style="margin:0">Generate AI summaries for all researchers and funding calls.</p>
        <form method="post">
            <input type="hidden" name="action" value="generate_all_summaries">
            <?= csrf_input() ?>
            <button class="ghost-btn" type="submit" onclick="return confirm('Generate AI summaries for all researchers and funding calls?')">✦ Generate All Summaries</button>
        </form>
    </div>

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;gap:16px;flex-wrap:wrap;padding:14px;background:#f9fbfa;border:1px solid var(--line);border-radius:8px">
        <p class="muted" style="margin:0">Send email notifications for all unnotified high-score matches.</p>
        <form method="post">
            <input type="hidden" name="action" value="send_pending_digest">
            <?= csrf_input() ?>
            <button class="ghost-btn" type="submit" onclick="return confirm('Send digest emails for all unnotified high-score matches?')">✉ Send Pending Digest</button>
        </form>
    </div>

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;gap:16px;flex-wrap:wrap;padding:14px;background:#f9fbfa;border:1px solid var(--line);border-radius:8px">
        <p class="muted" style="margin:0">Check API balance status for all providers and send alerts if low.</p>
        <form method="post">
            <input type="hidden" name="action" value="check_balances">
            <?= csrf_input() ?>
            <button class="ghost-btn" type="submit">⚠ Check API Balances</button>
        </form>
    </div>

    <!-- Status breakdown by job type -->
    <h3 style="font-size:13px;font-weight:700;margin-bottom:12px">Queue Status Summary</h3>
    <?php
    $statusByType = [];
    foreach ($statusRows as $row) {
        if (!isset($statusByType[$row['job_type']])) {
            $statusByType[$row['job_type']] = ['pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0];
        }
        $statusByType[$row['job_type']][$row['status']] = $row['cnt'];
    }
    ?>
    <div style="overflow-x:auto;margin-bottom:20px">
        <table class="jq-table">
            <thead>
                <tr>
                    <th>Job Type</th>
                    <th>Pending</th>
                    <th>Running</th>
                    <th>Done</th>
                    <th>Failed</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($statusByType as $jobType => $counts): ?>
            <tr>
                <td><strong><?= h($jobType) ?></strong></td>
                <td><span class="jq-badge pending"><?= $counts['pending'] ?></span></td>
                <td><span class="jq-badge running"><?= $counts['running'] ?></span></td>
                <td><span class="jq-badge done"><?= $counts['done'] ?></span></td>
                <td><span class="jq-badge failed"><?= $counts['failed'] ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Failed jobs with details -->
    <h3 style="font-size:13px;font-weight:700;margin-bottom:12px;margin-top:20px">Failed Jobs (Last 20)</h3>
    <?php if (!$failedRows): ?>
    <p class="muted">No failed jobs. The queue is healthy.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
        <table class="jq-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Type</th>
                    <th>Attempts</th>
                    <th>Last Error</th>
                    <th>Updated</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($failedRows as $fj):
                $timeStr = date('M j g:i A', strtotime($fj['updated_at']));
            ?>
            <tr>
                <td><strong style="font-family:monospace;font-size:12px">#<?= $fj['id'] ?></strong></td>
                <td><?= h($fj['job_type']) ?></td>
                <td><?= $fj['attempts'] ?>/<?= $fj['max_attempts'] ?></td>
                <td>
                    <?php if ($fj['last_error']): ?>
                        <div class="jq-error-box" title="<?= h($fj['last_error']) ?>"><?= h(substr($fj['last_error'], 0, 60)) ?></div>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="muted" style="font-size:12px;white-space:nowrap"><?= $timeStr ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Recent activity timeline -->
    <h3 style="font-size:13px;font-weight:700;margin-bottom:12px;margin-top:20px">Recent Activity (Last 100)</h3>
    <?php if (!$recentJobRows): ?>
    <p class="muted">No job activity yet.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
        <table class="jq-table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>ID</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Attempts</th>
                    <th>Error</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recentJobRows as $rj):
                $timeStr = date('M j g:i A', strtotime($rj['updated_at']));
            ?>
            <tr>
                <td class="muted" style="font-size:12px;white-space:nowrap"><?= $timeStr ?></td>
                <td><strong style="font-family:monospace;font-size:12px">#<?= $rj['id'] ?></strong></td>
                <td><?= h($rj['job_type']) ?></td>
                <td>
                    <?php
                    $badgeClass = 'jq-badge ' . $rj['status'];
                    $statusLabel = ucfirst($rj['status']);
                    ?>
                    <span class="<?= $badgeClass ?>"><?= $statusLabel ?></span>
                </td>
                <td><span style="font-size:12px"><?= $rj['attempts'] ?>/<?= $rj['max_attempts'] ?></span></td>
                <td>
                    <?php if ($rj['last_error']): ?>
                        <div class="jq-error-box" title="<?= h($rj['last_error']) ?>"><?= h(substr($rj['last_error'], 0, 40)) ?></div>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

<?php elseif ($adminSection === 'settings'): ?>
    <div class="admin-section">
        <h2>Settings</h2>
        <p class="text-muted">Manage auto-approval rules and trusted institution domains</p>

        <div style="max-width:900px;margin-top:24px">
            <h3 style="margin-bottom:16px">Trusted Institution Domains</h3>
            <p style="color:#666;margin-bottom:16px;font-size:13px">Researchers from these domains are automatically approved after email verification:</p>

            <?php
                // Fetch trusted domains
                $domainQ = $conn->query("SELECT * FROM trusted_domains ORDER BY tier ASC, institution_name ASC");
                $domainsByTier = ['tier1' => [], 'tier2' => [], 'tier3' => []];
                while ($d = $domainQ->fetch_assoc()) {
                    $domainsByTier[$d['tier']][] = $d;
                }
            ?>

            <!-- Add new domain form -->
            <div style="background:#f8fafb;border:1px solid #dde6dd;border-radius:10px;padding:16px;margin-bottom:24px">
                <h4 style="margin:0 0 12px 0;font-size:14px">Add New Domain</h4>
                <form method="post" style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:12px;align-items:flex-end">
                    <input type="hidden" name="action" value="add_domain">
                    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                    <div>
                        <label style="display:block;font-size:12px;font-weight:600;margin-bottom:6px;color:#374151">Domain</label>
                        <input type="text" name="domain" placeholder="example.edu" required style="width:100%;padding:8px 12px;border:1.5px solid #dde6dd;border-radius:6px;font-size:13px">
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;font-weight:600;margin-bottom:6px;color:#374151">Institution</label>
                        <input type="text" name="institution" placeholder="Institution Name" required style="width:100%;padding:8px 12px;border:1.5px solid #dde6dd;border-radius:6px;font-size:13px">
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;font-weight:600;margin-bottom:6px;color:#374151">Country</label>
                        <input type="text" name="country" placeholder="Country" style="width:100%;padding:8px 12px;border:1.5px solid #dde6dd;border-radius:6px;font-size:13px">
                    </div>
                    <div>
                        <select name="tier" style="padding:8px 12px;border:1.5px solid #dde6dd;border-radius:6px;font-size:13px;background:white;cursor:pointer">
                            <option value="tier1">Tier 1</option>
                            <option value="tier2" selected>Tier 2</option>
                            <option value="tier3">Tier 3</option>
                        </select>
                    </div>
                    <button type="submit" class="primary-btn" style="white-space:nowrap">Add Domain</button>
                </form>
            </div>

            <!-- Display by tier -->
            <?php foreach (['tier1' => 'Tier 1 (Top Universities)', 'tier2' => 'Tier 2 (Major Institutions)', 'tier3' => 'Tier 3 (Other Institutions)'] as $tier => $tierLabel): ?>
                <div style="margin-bottom:24px">
                    <h4 style="margin:0 0 12px 0;font-size:13px;color:#1a6b5a;font-weight:600"><?= $tierLabel ?></h4>
                    <?php if (!empty($domainsByTier[$tier])): ?>
                        <table style="width:100%;border-collapse:collapse;border:1px solid #dde6dd;border-radius:8px;overflow:hidden">
                            <thead style="background:#f3f4f3;border-bottom:1.5px solid #dde6dd">
                                <tr style="text-align:left">
                                    <th style="padding:10px 14px;font-weight:600;font-size:12px;color:#374151">Domain</th>
                                    <th style="padding:10px 14px;font-weight:600;font-size:12px;color:#374151">Institution</th>
                                    <th style="padding:10px 14px;font-weight:600;font-size:12px;color:#374151">Country</th>
                                    <th style="padding:10px 14px;font-weight:600;font-size:12px;color:#374151;text-align:right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($domainsByTier[$tier] as $d): ?>
                                    <tr style="border-bottom:1px solid #eee;hover:background:#fafafa">
                                        <td style="padding:10px 14px;font-family:monospace;font-size:13px;color:#1a6b5a"><?= h($d['domain']) ?></td>
                                        <td style="padding:10px 14px;font-size:13px;color:#374151"><?= h($d['institution_name']) ?></td>
                                        <td style="padding:10px 14px;font-size:13px;color:#666"><?= $d['country'] ? h($d['country']) : '—' ?></td>
                                        <td style="padding:10px 14px;text-align:right">
                                            <form method="post" style="display:inline">
                                                <input type="hidden" name="action" value="remove_domain">
                                                <input type="hidden" name="domain_id" value="<?= (int)$d['id'] ?>">
                                                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                                                <button type="submit" class="ghost-btn" style="font-size:12px;color:#d97706;padding:4px 8px;border:1px solid #fed7aa;border-radius:4px;background:#fef3c7;cursor:pointer" onclick="return confirm('Remove this domain from trusted list?')">Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p style="color:#9aaba4;font-size:12px;margin:0">No domains in this tier</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

<?php elseif ($adminSection === 'embeddings'): ?>
<!-- ── Semantic Search Embeddings section ── -->
<div class="panel">
    <h2>Semantic Search — Embedding Management</h2>
    <p style="color:#6b7280;margin-top:8px">Generate embeddings for vector-based semantic search. Embeddings enable the system to understand research concepts, not just keywords.</p>

    <div style="margin-top:20px;padding:16px;background:#f0fdf4;border-left:4px solid #16a34a;border-radius:4px">
        <div style="font-weight:600;margin-bottom:8px">How it works</div>
        <ul style="margin:0;padding-left:20px;font-size:13px;line-height:1.6">
            <li>Converts researcher profiles → semantic vectors (embeddings)</li>
            <li>Converts search queries → vectors</li>
            <li>Compares vectors to find semantically similar researchers</li>
            <li>Results: "food systems" finds researchers working on water-food systems, agriculture, food security, etc.</li>
        </ul>
    </div>

    <div style="margin-top:20px">
        <h3 style="margin-top:0;font-size:16px">Generation Progress</h3>
        <div id="embedding-status" style="padding:12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:4px;font-family:monospace;font-size:12px">
            <div>Loading status...</div>
        </div>
        <div style="margin-top:16px;display:flex;gap:12px">
            <button onclick="generateResearcherEmbeddings()" class="primary-btn" id="btn-gen-researchers">Generate Researcher Embeddings</button>
            <button onclick="generateFundingEmbeddings()" class="primary-btn" id="btn-gen-funding">Generate Funding Call Embeddings</button>
        </div>
    </div>

    <div style="margin-top:24px;padding:12px;background:#fef3c7;border:1px solid #fcd34d;border-radius:4px;font-size:13px">
        <strong>Note:</strong> Generation uses Claude API and may take 5-10 minutes for 1000+ items. Progress is shown above. You can close this page and come back later.
    </div>
</div>

<script>
let generationInProgress = false;

function updateStatus() {
    fetch('index.php?page=api&action=admin_embedding_status')
        .then(r => r.json())
        .then(data => {
            const status = document.getElementById('embedding-status');
            status.innerHTML = `
                <div><strong>Researchers:</strong> ${data.researchers.embedded} / ${data.researchers.total} (${data.researchers.percentage}%)</div>
                <div style="margin-top:6px"><strong>Funding Calls:</strong> ${data.funding_calls.embedded} / ${data.funding_calls.total} (${data.funding_calls.percentage}%)</div>
            `;
        })
        .catch(e => console.error(e));
}

async function generateResearcherEmbeddings() {
    if (generationInProgress) return alert('Generation already in progress');
    generationInProgress = true;
    document.getElementById('btn-gen-researchers').disabled = true;

    let offset = 0;
    const limit = 20;
    let completed = 0;
    let failed = 0;

    while (true) {
        try {
            const res = await fetch(`index.php?page=api&action=admin_generate_embeddings&type=researchers&limit=${limit}&offset=${offset}`);
            const data = await res.json();

            if (!data || data.status !== 'ok') break;

            completed += data.success;
            failed += data.failed;

            console.log(`Progress: ${completed} completed, ${failed} failed`);
            updateStatus();

            if (data.total_processed < limit) break; // No more items
            offset += limit;

        } catch (e) {
            console.error(e);
            break;
        }
    }

    alert(`✓ Embedding generation complete: ${completed} succeeded, ${failed} failed`);
    generationInProgress = false;
    document.getElementById('btn-gen-researchers').disabled = false;
    updateStatus();
}

async function generateFundingEmbeddings() {
    if (generationInProgress) return alert('Generation already in progress');
    generationInProgress = true;
    document.getElementById('btn-gen-funding').disabled = true;

    let offset = 0;
    const limit = 20;
    let completed = 0;
    let failed = 0;

    while (true) {
        try {
            const res = await fetch(`index.php?page=api&action=admin_generate_embeddings&type=funding_calls&limit=${limit}&offset=${offset}`);
            const data = await res.json();

            if (!data || data.status !== 'ok') break;

            completed += data.success;
            failed += data.failed;

            console.log(`Progress: ${completed} completed, ${failed} failed`);
            updateStatus();

            if (data.total_processed < limit) break;
            offset += limit;

        } catch (e) {
            console.error(e);
            break;
        }
    }

    alert(`✓ Embedding generation complete: ${completed} succeeded, ${failed} failed`);
    generationInProgress = false;
    document.getElementById('btn-gen-funding').disabled = false;
    updateStatus();
}

// Load initial status on page load
document.addEventListener('DOMContentLoaded', updateStatus);
// Refresh status every 5 seconds
setInterval(updateStatus, 5000);
</script>

<?php elseif ($adminSection === 'newsletter'): ?>
<!-- Newsletter Management Section -->
<div class="admin-panel" style="max-width: 800px">
    <h2 style="margin-bottom: 24px">📧 Newsletter Subscribers</h2>

    <div style="background: #f8fafb; border: 1.5px solid #dde6dd; border-radius: 10px; padding: 20px; margin-bottom: 24px">
        <div style="display: flex; justify-content: space-between; align-items: center; gap: 20px">
            <div>
                <p style="margin: 0 0 8px; font-weight: 600; color: var(--text)">Download Subscriber List</p>
                <p style="margin: 0; color: var(--muted); font-size: 13px">Export all active subscribers to Excel for Mailchimp import. Includes email, name, institution, department, research topics, and how they found you.</p>
            </div>
            <button id="export-newsletter-btn" class="primary-btn" style="white-space: nowrap; padding: 12px 24px">
                ⬇️ Download Excel
            </button>
        </div>
    </div>

    <div style="margin-top: 24px">
        <h3 style="margin-bottom: 12px">Subscriber Summary</h3>
        <div id="newsletter-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px">
            <div style="background: #f8fafb; border: 1px solid #dde6dd; border-radius: 8px; padding: 16px">
                <p style="margin: 0 0 8px; font-size: 12px; font-weight: 600; color: var(--muted); text-transform: uppercase">Active Subscribers</p>
                <p id="subscriber-count" style="margin: 0; font-size: 28px; font-weight: 700; color: var(--primary)">-</p>
            </div>
            <div style="background: #f8fafb; border: 1px solid #dde6dd; border-radius: 8px; padding: 16px">
                <p style="margin: 0 0 8px; font-size: 12px; font-weight: 600; color: var(--muted); text-transform: uppercase">Last Updated</p>
                <p id="last-updated" style="margin: 0; font-size: 14px; color: var(--text)">-</p>
            </div>
        </div>
    </div>

    <div id="export-message" style="display: none; margin-top: 16px; padding: 12px; border-radius: 6px; font-size: 14px"></div>
</div>

<script>
document.getElementById('export-newsletter-btn').addEventListener('click', async function() {
    const btn = this;
    const msg = document.getElementById('export-message');

    btn.disabled = true;
    btn.textContent = '⏳ Exporting...';
    msg.style.display = 'block';
    msg.className = '';
    msg.textContent = 'Generating Excel file...';

    try {
        const response = await fetch('/api/admin-newsletter.php?action=export');
        if (!response.ok) throw new Error('Export failed');

        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `FACT_Newsletter_Subscribers_${new Date().toISOString().slice(0,10)}.xlsx`;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        a.remove();

        msg.className = 'success';
        msg.textContent = '✓ File downloaded successfully!';
    } catch (err) {
        msg.className = 'error';
        msg.textContent = '✗ Failed to download file: ' + err.message;
        console.error(err);
    } finally {
        btn.disabled = false;
        btn.textContent = '⬇️ Download Excel';
        setTimeout(() => { msg.style.display = 'none'; }, 5000);
    }
});

// Load subscriber count
async function loadNewsletterStats() {
    try {
        const response = await fetch('/api/admin-newsletter.php?action=list');
        const data = await response.json();
        if (data.success) {
            document.getElementById('subscriber-count').textContent = data.total;
            document.getElementById('last-updated').textContent = new Date().toLocaleString();
        }
    } catch (err) {
        console.error('Failed to load stats:', err);
    }
}

// Load stats on page load
loadNewsletterStats();
// Refresh stats every 30 seconds
setInterval(loadNewsletterStats, 30000);
</script>

<?php endif; /* end section switch */ ?>

<script>
(function(){
    var pw=document.getElementById('ap-pw'), cp=document.getElementById('ap-cpw'), msg=document.getElementById('ap-pw-msg');
    if(pw&&cp&&msg){
        function check(){ msg.style.display=(cp.value&&pw.value!==cp.value)?'flex':'none'; }
        pw.addEventListener('input',check); cp.addEventListener('input',check);
    }
})();

// Auto-refresh page after successful deletion
document.addEventListener('DOMContentLoaded', function() {
    const successAlerts = document.querySelectorAll('[class*="alert-success"]');
    if (successAlerts.length > 0) {
        successAlerts.forEach(alert => {
            if (alert.textContent.includes('deleted') || alert.textContent.includes('Trash') || alert.textContent.includes('moved')) {
                setTimeout(() => {
                    location.reload();
                }, 1500);
            }
        });
    }
});
</script>

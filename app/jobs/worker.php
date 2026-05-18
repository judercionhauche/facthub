#!/usr/bin/env php
<?php
/**
 * Background job worker for fact_hub2.
 * Processes compute_matches, generate_summary, send_notification, send_digest jobs.
 *
 * Run via cron:  * * * * * /Applications/XAMPP/xamppfiles/bin/php /path/to/worker.php >> /tmp/fact_worker.log 2>&1
 * Or as daemon: nohup /Applications/XAMPP/xamppfiles/bin/php /path/to/worker.php &
 */

// CLI-only guard — must be the very first thing
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only' . PHP_EOL);
}

require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/mailer.php';
require_once __DIR__ . '/../services/ClaudeService.php';
require_once __DIR__ . '/../services/BalanceMonitor.php';

// Initialize database connection
$dbConfig = require_once __DIR__ . '/../../config/database.php';
$conn = new mysqli($dbConfig['db_host'], $dbConfig['db_user'], $dbConfig['db_pass'], $dbConfig['db_name']);
if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error . PHP_EOL);
}
$conn->set_charset('utf8mb4');

@$mailCfg = require __DIR__ . '/../../config/mail.php';
if (!is_array($mailCfg)) {
    $mailCfg = ['app_url' => getenv('APP_URL') ?: 'http://localhost/public'];
}
$appUrl  = rtrim($mailCfg['app_url'] ?? 'http://localhost/public', '/');

define('WORKER_ID', gethostname() . ':' . getmypid());
define('LOCK_TIMEOUT_MINUTES', 10);
define('BATCH_SIZE', 5);

$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function() use (&$running) { $running = false; });
    pcntl_signal(SIGINT,  function() use (&$running) { $running = false; });
}

echo "[" . date('Y-m-d H:i:s') . "] Worker started (PID " . getmypid() . ")" . PHP_EOL;

while ($running) {
    // Reconnect if connection was lost
    if (!$conn->ping()) {
        $conn = new mysqli($dbConfig['db_host'], $dbConfig['db_user'], $dbConfig['db_pass'], $dbConfig['db_name']);
        if ($conn->connect_error) {
            echo "[" . date('Y-m-d H:i:s') . "] Database reconnection failed: " . $conn->connect_error . PHP_EOL;
            sleep(5);
            continue;
        }
        $conn->set_charset('utf8mb4');
        echo "[" . date('Y-m-d H:i:s') . "] Database reconnected" . PHP_EOL;
    }

    // Unlock stale jobs
    $conn->query(
        "UPDATE job_queue SET status='pending', locked_at=NULL, locked_by=NULL
         WHERE status='processing'
         AND locked_at < DATE_SUB(NOW(), INTERVAL " . LOCK_TIMEOUT_MINUTES . " MINUTE)"
    );

    // Claim next batch atomically
    try {
        $conn->begin_transaction();
        $claim = $conn->prepare(
            "SELECT id FROM job_queue
             WHERE status = 'pending'
               AND attempts < max_attempts
               AND (run_after IS NULL OR run_after <= NOW())
             ORDER BY id ASC
             LIMIT " . BATCH_SIZE . "
             FOR UPDATE"
        );
        $claim->execute();
        $ids = [];
        $result = $claim->get_result();
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int)$row['id'];
        }

        if (empty($ids)) {
            $conn->commit();
            echo "[" . date('Y-m-d H:i:s') . "] No pending jobs" . PHP_EOL;
            sleep(5);
            if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
            continue;
        }
        echo "[" . date('Y-m-d H:i:s') . "] Found " . count($ids) . " job(s)" . PHP_EOL;
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Transaction error: " . $e->getMessage() . PHP_EOL;
        $conn->rollback();
        sleep(5);
        continue;
    }

    $idList = implode(',', $ids);
    $lockedAt = date('Y-m-d H:i:s');
    $lockerId = WORKER_ID;
    $conn->query(
        "UPDATE job_queue SET status='processing', locked_at='{$lockedAt}', locked_by='" .
        $conn->real_escape_string($lockerId) . "', attempts=attempts+1
         WHERE id IN ({$idList})"
    );
    $conn->commit();

    // Fetch full rows
    $rows = $conn->query("SELECT * FROM job_queue WHERE id IN ({$idList})")->fetch_all(MYSQLI_ASSOC);

    foreach ($rows as $job) {
        dispatch_job($conn, $job);
    }

    if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
}

echo "[" . date('Y-m-d H:i:s') . "] Worker shutting down" . PHP_EOL;
exit(0);

// ── Job Dispatcher ──

function dispatch_job(mysqli $conn, array $job): void {
    $payload = json_decode($job['payload'], true) ?? [];
    $claude = new ClaudeService($conn, 'worker:' . $job['job_type']);
    $jobId = (int)$job['id'];

    try {
        switch ($job['job_type']) {

            case 'compute_matches':
                $fcId = (int)($payload['funding_call_id'] ?? 0);
                if (!$fcId) throw new RuntimeException('Missing funding_call_id');

                $fcStmt = $conn->prepare('SELECT * FROM funding_calls WHERE id = ? LIMIT 1');
                $fcStmt->bind_param('i', $fcId); $fcStmt->execute();
                $fc = $fcStmt->get_result()->fetch_assoc();
                if (!$fc) throw new RuntimeException("Funding call {$fcId} not found");

                $resStmt = $conn->prepare('SELECT * FROM researchers WHERE status = "active" AND deleted_at IS NULL');
                $resStmt->execute();
                $researchers = $resStmt->get_result()->fetch_all(MYSQLI_ASSOC);

                foreach ($researchers as $r) {
                    $claude->scoreMatch((int)$fc['id'], $fc, (int)$r['id'], $r);
                    usleep(200000); // 200ms throttle
                }
                mark_job_done($conn, $jobId);

                // Notify opted-in researchers with high scores
                $notifyStmt = $conn->prepare(
                    'SELECT ms.score_ai, ms.score_keyword, ms.explanation,
                            r.id AS rid, r.email, r.first_name, r.topics AS r_topics, r.geography AS r_geo,
                            fc.title, fc.funder, fc.deadline, fc.status, fc.amount,
                            fc.topics AS fc_topics, fc.geography AS fc_geo
                     FROM match_scores ms
                     JOIN researchers r ON r.id = ms.researcher_id
                     JOIN funding_calls fc ON fc.id = ms.funding_call_id
                     WHERE ms.funding_call_id = ?
                       AND r.status = "active" AND r.deleted_at IS NULL
                       AND r.notify_matches = 1
                       AND (ms.score_ai >= 60 OR (ms.score_ai IS NULL AND ms.score_keyword >= 3))
                       AND ms.notified_at IS NULL'
                );
                $notifyStmt->bind_param('i', $fcId); $notifyStmt->execute();
                $toNotify = $notifyStmt->get_result()->fetch_all(MYSQLI_ASSOC);

                foreach ($toNotify as $n) {
                    $matchedTopics = array_values(array_intersect(
                        parse_tags($n['fc_topics']), parse_tags($n['r_topics'])
                    ));
                    $matchedGeos = array_values(array_intersect(
                        parse_tags($n['fc_geo']), parse_tags($n['r_geo'])
                    ));

                    $fundingUrl = $appUrl . '/index.php?page=funding&view=' . (int)$fcId;
                    $unsubUrl   = $appUrl . '/index.php?page=unsubscribe&email='
                                . urlencode($n['email']) . '&token='
                                . hash_hmac('sha256', strtolower(trim($n['email'])), 'match_notify');

                    $html = mail_tpl_match_notify(
                        $n['first_name'],
                        $n['title'],
                        $n['funder'],
                        $n['deadline'] ?? '',
                        $n['status']   ?? '',
                        $n['amount']   ?? '',
                        $matchedTopics,
                        $matchedGeos,
                        $fundingUrl,
                        $unsubUrl
                    );

                    enqueue_job($conn, 'send_notification', [
                        'to'      => $n['email'],
                        'subject' => 'New funding match: ' . $n['title'],
                        'html'    => $html,
                    ]);

                    $rid = (int)$n['rid'];
                    $conn->query("UPDATE match_scores SET notified_at=NOW() WHERE funding_call_id={$fcId} AND researcher_id={$rid}");
                }

                echo "[" . date('Y-m-d H:i:s') . "] Job {$jobId} (compute_matches) done" . PHP_EOL;
                break;

            case 'generate_summary':
                $type = $payload['entity_type'] ?? '';
                $eid = (int)($payload['entity_id'] ?? 0);
                if ($type === 'researcher') {
                    $rStmt = $conn->prepare('SELECT * FROM researchers WHERE id = ? LIMIT 1');
                    $rStmt->bind_param('i', $eid); $rStmt->execute();
                    $r = $rStmt->get_result()->fetch_assoc();
                    if ($r) {
                        $claude->summarizeResearcher($eid, $r);
                    }
                } elseif ($type === 'funding_call') {
                    $fcStmt = $conn->prepare('SELECT * FROM funding_calls WHERE id = ? LIMIT 1');
                    $fcStmt->bind_param('i', $eid); $fcStmt->execute();
                    $fc = $fcStmt->get_result()->fetch_assoc();
                    if ($fc) {
                        $claude->summarizeFundingCall($eid, $fc);
                    }
                }
                mark_job_done($conn, $jobId);
                echo "[" . date('Y-m-d H:i:s') . "] Job {$jobId} (generate_summary) done" . PHP_EOL;
                break;

            case 'send_notification':
                $to = $payload['to'] ?? '';
                $subject = $payload['subject'] ?? '';
                $html = $payload['html'] ?? '';
                if ($to && $subject && $html) {
                    send_notification_email($to, $subject, $html);
                }
                mark_job_done($conn, $jobId);
                echo "[" . date('Y-m-d H:i:s') . "] Job {$jobId} (send_notification) done" . PHP_EOL;
                break;

            case 'send_digest':
                $msgs = $payload['messages'] ?? [];
                if (!empty($msgs)) {
                    send_bulk_notifications($msgs);
                }
                mark_job_done($conn, $jobId);
                echo "[" . date('Y-m-d H:i:s') . "] Job {$jobId} (send_digest) done" . PHP_EOL;
                break;

            case 'check_balance':
                $monitor = new BalanceMonitor($conn);
                $monitor->checkAllBalances();
                mark_job_done($conn, $jobId);
                echo "[" . date('Y-m-d H:i:s') . "] Job {$jobId} (check_balance) done" . PHP_EOL;
                break;

            default:
                throw new RuntimeException("Unknown job type: " . $job['job_type']);
        }
    } catch (Throwable $e) {
        $error = substr($e->getMessage(), 0, 1000);
        mark_job_failed($conn, $jobId, $error, (int)$job['attempts'], (int)$job['max_attempts']);
        echo "[" . date('Y-m-d H:i:s') . "] Job {$jobId} FAILED: {$error}" . PHP_EOL;
    }
}

function mark_job_done(mysqli $conn, int $id): void {
    $conn->query("UPDATE job_queue SET status='completed', locked_at=NULL, updated_at=NOW() WHERE id={$id}");
}

function mark_job_failed(mysqli $conn, int $id, string $error, int $attempts, int $maxAttempts): void {
    $error = $conn->real_escape_string($error);
    if ($attempts >= $maxAttempts) {
        $conn->query("UPDATE job_queue SET status='failed', last_error='{$error}', locked_at=NULL, updated_at=NOW() WHERE id={$id}");
    } else {
        // Exponential backoff: 5 min, 15 min, 45 min
        $backoff = (int)(300 * pow(3, $attempts - 1));
        $conn->query(
            "UPDATE job_queue SET status='pending', last_error='{$error}', locked_at=NULL,
             run_after=DATE_ADD(NOW(), INTERVAL {$backoff} SECOND), updated_at=NOW() WHERE id={$id}"
        );
    }
}
?>

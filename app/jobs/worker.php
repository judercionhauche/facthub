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

// Load environment variables from .env file
$envFile = __DIR__ . '/../../config/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') === false || strpos($line, '#') === 0) continue;
        list($key, $val) = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val, '\'" ');
        if (!getenv($key)) putenv("$key=$val");
    }
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
        dispatch_job($conn, $job, $appUrl);
    }

    if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
}

echo "[" . date('Y-m-d H:i:s') . "] Worker shutting down" . PHP_EOL;
exit(0);

// ── Job Dispatcher ──

function dispatch_job(mysqli $conn, array $job, string $appUrl): void {
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
                            r.id AS rid, r.email, r.first_name, r.notify_frequency, r.topics AS r_topics, r.geography AS r_geo,
                            fc.title, fc.funder, fc.deadline, fc.status, fc.amount,
                            fc.topics AS fc_topics, fc.geography AS fc_geo
                     FROM match_scores ms
                     JOIN researchers r ON r.id = ms.researcher_id
                     JOIN funding_calls fc ON fc.id = ms.funding_call_id
                     WHERE ms.funding_call_id = ?
                       AND r.status = "active" AND r.deleted_at IS NULL
                       AND r.notify_matches = 1
                       AND r.notify_frequency IN ("immediate", "weekly")
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
                        $n['funder'] ?? '',
                        $n['deadline'] ?? '',
                        $n['status']   ?? '',
                        $n['amount']   ?? '',
                        $matchedTopics,
                        $matchedGeos,
                        $fundingUrl,
                        $unsubUrl
                    );

                    // Mark as notified BEFORE queuing to prevent duplicates on retry
                    $rid = (int)$n['rid'];
                    $updateStmt = $conn->prepare('UPDATE match_scores SET notified_at=NOW() WHERE funding_call_id=? AND researcher_id=?');
                    $updateStmt->bind_param('ii', $fcId, $rid);
                    $updateStmt->execute();

                    // Send notification (immediate and weekly both send right now)
                    // TODO: Implement true weekly digest that aggregates and sends once per week
                    enqueue_job($conn, 'send_notification', [
                        'to'      => $n['email'],
                        'subject' => 'New funding match: ' . $n['title'],
                        'html'    => $html,
                    ]);
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
                        $apiAvail = $claude->isAvailable() ? 'YES' : 'NO';
                        echo "[" . date('Y-m-d H:i:s') . "] Generating researcher summary (ID $eid), API available: $apiAvail" . PHP_EOL;
                        $result = $claude->summarizeResearcher($eid, $r);
                        echo "[" . date('Y-m-d H:i:s') . "] Result: " . ($result ? 'SUCCESS' : 'NULL/FAILED') . PHP_EOL;
                    } else {
                        echo "[" . date('Y-m-d H:i:s') . "] Researcher $eid not found" . PHP_EOL;
                    }
                } elseif ($type === 'funding_call') {
                    $fcStmt = $conn->prepare('SELECT * FROM funding_calls WHERE id = ? LIMIT 1');
                    $fcStmt->bind_param('i', $eid); $fcStmt->execute();
                    $fc = $fcStmt->get_result()->fetch_assoc();
                    if ($fc) {
                        $apiAvail = $claude->isAvailable() ? 'YES' : 'NO';
                        echo "[" . date('Y-m-d H:i:s') . "] Generating funding call summary (ID $eid), API available: $apiAvail" . PHP_EOL;
                        $result = $claude->summarizeFundingCall($eid, $fc);
                        echo "[" . date('Y-m-d H:i:s') . "] Result: " . ($result ? 'SUCCESS' : 'NULL/FAILED') . PHP_EOL;
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

            case 'fetch_orcid_publications':
                $researcherId = (int)($payload['researcher_id'] ?? 0);
                $orcidId = $payload['orcid_id'] ?? '';
                if ($researcherId && $orcidId) {
                    echo "[" . date('Y-m-d H:i:s') . "] Fetching ORCID publications for researcher {$researcherId} ({$orcidId})" . PHP_EOL;
                    fetch_orcid_publications($conn, $researcherId, $orcidId);
                    echo "[" . date('Y-m-d H:i:s') . "] ORCID fetch complete for {$orcidId}" . PHP_EOL;
                } else {
                    echo "[" . date('Y-m-d H:i:s') . "] Missing researcher_id or orcid_id for fetch_orcid_publications" . PHP_EOL;
                }
                mark_job_done($conn, $jobId);
                echo "[" . date('Y-m-d H:i:s') . "] Job {$jobId} (fetch_orcid_publications) done" . PHP_EOL;
                break;

            case 'send_weekly_digests':
                echo "[" . date('Y-m-d H:i:s') . "] Processing weekly digests" . PHP_EOL;
                send_weekly_digest($conn);
                mark_job_done($conn, $jobId);
                echo "[" . date('Y-m-d H:i:s') . "] Job {$jobId} (send_weekly_digests) done" . PHP_EOL;
                break;

            case 'generate_embedding':
                require_once __DIR__ . '/../services/EmbeddingService.php';
                $entityType = $payload['entity_type'] ?? '';
                $entityId = (int)($payload['entity_id'] ?? 0);
                if ($entityType && $entityId) {
                    echo "[" . date('Y-m-d H:i:s') . "] Generating embedding for {$entityType} (ID {$entityId})" . PHP_EOL;
                    $embeddingService = new EmbeddingService($conn, $claude);
                    if ($entityType === 'researcher') {
                        $result = $embeddingService->generateResearcherEmbedding($entityId, 'profile');
                    } elseif ($entityType === 'funding_call') {
                        $result = $embeddingService->generateFundingCallEmbedding($entityId, 'full');
                    } else {
                        $result = false;
                    }
                    echo "[" . date('Y-m-d H:i:s') . "] Result: " . ($result ? 'SUCCESS' : 'FAILED') . PHP_EOL;
                } else {
                    echo "[" . date('Y-m-d H:i:s') . "] Missing entity_type or entity_id for generate_embedding" . PHP_EOL;
                }
                mark_job_done($conn, $jobId);
                echo "[" . date('Y-m-d H:i:s') . "] Job {$jobId} (generate_embedding) done" . PHP_EOL;
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

// ── ORCID Publication Fetcher ──

function fetch_orcid_publications(mysqli $conn, int $researcherId, string $orcidId): void {
    try {
        // Normalize ORCID (remove URL prefix if present)
        $orcid = preg_replace('#https?://orcid\.org/#', '', trim($orcidId));
        if (!preg_match('#^\d{4}-\d{4}-\d{4}-\d{3}[0-9X]$#', $orcid)) {
            echo "[WARN] Invalid ORCID format: {$orcidId}" . PHP_EOL;
            return;
        }

        // Fetch from ORCID API (request JSON explicitly)
        $url = "https://pub.orcid.org/v3.0/{$orcid}/works";
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'FACT-Hub/1.0',
                'header' => "Accept: application/json\r\n"
            ]
        ]);
        $json = @file_get_contents($url, false, $ctx);
        if (!$json) {
            echo "[WARN] Failed to fetch ORCID profile {$orcid}" . PHP_EOL;
            return;
        }

        $data = json_decode($json, true);
        if (!$data || empty($data['group'])) {
            echo "[INFO] No works found for {$orcid}" . PHP_EOL;
            return;
        }

        // Clear old publications for this researcher
        $delStmt = $conn->prepare('DELETE FROM researcher_publications WHERE researcher_id = ?');
        $delStmt->bind_param('i', $researcherId);
        $delStmt->execute();

        // Insert new publications
        $insStmt = $conn->prepare(
            'INSERT INTO researcher_publications
             (researcher_id, orcid_id, title, publication_year, journal_name, doi, url, citation_count)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $count = 0;
        foreach ($data['group'] as $group) {
            $work = $group['work-summary'][0] ?? null;
            if (!$work) continue;

            $title = $work['title']['title']['value'] ?? '';
            $year = (int)($work['publication-date']['year']['value'] ?? 0);
            $doi = '';
            $url = '';
            $journal = '';
            $citations = 0;

            // Extract DOI and URL
            if (!empty($work['external-ids']['external-id'])) {
                foreach ($work['external-ids']['external-id'] as $extId) {
                    if ($extId['external-id-type'] === 'doi') {
                        $doi = $extId['external-id-value'] ?? '';
                    }
                }
            }

            // Get URL
            if (!empty($work['url'])) {
                $url = $work['url']['value'] ?? '';
            }

            // Get journal name
            if (!empty($work['journal-title'])) {
                $journal = $work['journal-title']['value'] ?? '';
            }

            if (!$title) continue;

            $insStmt->bind_param(
                'isssissi',
                $researcherId, $orcid, $title, $year,
                $journal, $doi, $url, $citations
            );
            $insStmt->execute();
            $count++;
        }

        echo "[OK] Fetched {$count} publications for researcher {$researcherId}" . PHP_EOL;

    } catch (Throwable $e) {
        echo "[ERROR] ORCID fetch failed: " . $e->getMessage() . PHP_EOL;
    }
}
?>

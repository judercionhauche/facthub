<?php
/**
 * API Balance Monitoring Service
 * Tracks token balances, estimates usage, sends alerts when low.
 */
class BalanceMonitor {
    private mysqli $conn;
    private string $logPrefix = '[BalanceMonitor]';

    // Thresholds (in percent of budget remaining)
    private const THRESHOLD_WARNING = 25;      // Send warning at 25%
    private const THRESHOLD_CRITICAL = 10;     // Send critical at 10%
    private const THRESHOLD_EMERGENCY = 5;     // Send emergency at 5%

    // Cooldown (seconds between repeated alerts for same provider/severity)
    private const COOLDOWN_EMERGENCY = 3600;   // 1 hour for emergency
    private const COOLDOWN_CRITICAL = 21600;   // 6 hours for critical
    private const COOLDOWN_WARNING = 86400;    // 24 hours for warning

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    /**
     * Check balance for all providers and send alerts if needed.
     */
    public function checkAllBalances(): void {
        error_log("{$this->logPrefix} Starting balance check for all providers");

        // Claude/Anthropic
        $this->checkClaudeBalance();

        error_log("{$this->logPrefix} Balance check complete");
    }

    /**
     * Check Claude API balance using usage trends.
     * Since Anthropic doesn't expose a balance API, we estimate using recent spend.
     */
    private function checkClaudeBalance(): void {
        $provider = 'claude';

        try {
            // Get API key to validate
            $apiKey = getenv('ANTHROPIC_API_KEY');
            if (!$apiKey) {
                $this->recordBalanceStatus($provider, 'error', null, null, 'API key not configured', null);
                return;
            }

            // Fetch recent usage (last 30 days)
            $stmt = $this->conn->prepare(
                'SELECT SUM(cost_usd) as total_cost, COUNT(*) as call_count FROM api_usage
                 WHERE model LIKE ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)'
            );
            $pattern = '%claude%';
            $stmt->bind_param('s', $pattern);
            $stmt->execute();
            $usage = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $monthlyCost = (float)($usage['total_cost'] ?? 0);
            $callCount = (int)($usage['call_count'] ?? 0);

            // Estimate: assume $100/month budget (adjust based on actual credit system)
            $monthlyBudget = 100.0;
            $remainingBalance = $monthlyBudget - $monthlyCost;
            $usagePercent = ($monthlyBudget > 0) ? round(($monthlyCost / $monthlyBudget) * 100, 2) : 0;
            $remainingPercent = 100 - $usagePercent;

            // Determine status and threshold
            $severity = null;
            $thresholdPct = null;

            if ($remainingPercent <= self::THRESHOLD_EMERGENCY) {
                $severity = 'emergency';
                $thresholdPct = self::THRESHOLD_EMERGENCY;
            } elseif ($remainingPercent <= self::THRESHOLD_CRITICAL) {
                $severity = 'critical';
                $thresholdPct = self::THRESHOLD_CRITICAL;
            } elseif ($remainingPercent <= self::THRESHOLD_WARNING) {
                $severity = 'warning';
                $thresholdPct = self::THRESHOLD_WARNING;
            }

            $status = ($severity === 'emergency') ? 'emergency' : (($severity === 'critical') ? 'critical' : (($severity === 'warning') ? 'warning' : 'healthy'));

            // Record balance status
            $this->recordBalanceStatus(
                $provider,
                $status,
                $monthlyBudget,
                $remainingBalance,
                null,
                'system'
            );

            // Send alert if threshold crossed and not in cooldown
            if ($severity) {
                $this->sendAlertIfNeeded($provider, $severity, $thresholdPct, $remainingBalance, $remainingPercent, $callCount);
            }

            error_log("{$this->logPrefix} Claude balance check: {$remainingPercent}% remaining, {$callCount} calls this month");

        } catch (Exception $e) {
            error_log("{$this->logPrefix} Claude balance check failed: " . $e->getMessage());
            $this->recordBalanceStatus($provider, 'error', null, null, $e->getMessage(), 'system');
        }
    }

    /**
     * Record the current balance status in the database.
     */
    private function recordBalanceStatus(
        string $provider,
        string $status,
        ?float $totalBudget,
        ?float $remainingBalance,
        ?string $errorMsg,
        ?string $checkedBy
    ): void {
        $stmt = $this->conn->prepare(
            'INSERT INTO api_balances (provider, total_budget, remaining_balance, status, last_checked_at, last_check_error, checked_by)
             VALUES (?, ?, ?, ?, NOW(), ?, ?)
             ON DUPLICATE KEY UPDATE
                total_budget = VALUES(total_budget),
                remaining_balance = VALUES(remaining_balance),
                status = VALUES(status),
                last_checked_at = NOW(),
                last_check_error = VALUES(last_check_error),
                checked_by = VALUES(checked_by)'
        );
        $stmt->bind_param(
            'sddsss',
            $provider,
            $totalBudget,
            $remainingBalance,
            $status,
            $errorMsg,
            $checkedBy
        );
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Send alert if threshold crossed and not in cooldown.
     */
    private function sendAlertIfNeeded(
        string $provider,
        string $severity,
        int $thresholdPct,
        float $remainingBalance,
        float $remainingPercent,
        int $callCount
    ): void {
        // Check cooldown
        if ($this->isInCooldown($provider, $severity)) {
            error_log("{$this->logPrefix} Skipping alert for {$provider} ({$severity}) — in cooldown");
            return;
        }

        // Compose message
        $severityLabel = strtoupper($severity);
        $subject = "[{$severityLabel}] API Balance Alert: {$provider}";
        $message = $this->buildAlertMessage(
            $provider,
            $severity,
            $thresholdPct,
            $remainingBalance,
            $remainingPercent,
            $callCount
        );

        // Send email
        $alertEmail = getenv('ALERT_EMAIL') ?: 'factintern@mit.edu';
        if (send_notification_email($alertEmail, $subject, $message)) {
            // Record alert sent
            $stmt = $this->conn->prepare(
                'INSERT INTO balance_alerts (provider, severity, threshold_pct, remaining_balance, message, sent_to, sent_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->bind_param(
                'ssidss',
                $provider,
                $severity,
                $thresholdPct,
                $remainingBalance,
                $message,
                $alertEmail
            );
            $stmt->execute();
            $stmt->close();

            error_log("{$this->logPrefix} Alert sent to {$alertEmail}: {$subject}");
        } else {
            error_log("{$this->logPrefix} Failed to send alert email for {$provider}");
        }
    }

    /**
     * Check if alert for this provider/severity is in cooldown period.
     */
    private function isInCooldown(string $provider, string $severity): bool {
        $cooldownSeconds = match($severity) {
            'emergency' => self::COOLDOWN_EMERGENCY,
            'critical' => self::COOLDOWN_CRITICAL,
            'warning' => self::COOLDOWN_WARNING,
            default => 0,
        };

        if ($cooldownSeconds === 0) return false;

        $stmt = $this->conn->prepare(
            'SELECT sent_at FROM balance_alerts
             WHERE provider = ? AND severity = ?
             ORDER BY sent_at DESC LIMIT 1'
        );
        $stmt->bind_param('ss', $provider, $severity);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$result) return false; // Never sent before

        $lastSent = strtotime($result['sent_at']);
        $now = time();
        $elapsed = $now - $lastSent;

        return $elapsed < $cooldownSeconds;
    }

    /**
     * Build HTML email message for balance alert.
     */
    private function buildAlertMessage(
        string $provider,
        string $severity,
        int $thresholdPct,
        float $remainingBalance,
        float $remainingPercent,
        int $callCount
    ): string {
        $severityColor = match($severity) {
            'emergency' => '#dc2626',
            'critical' => '#ea580c',
            'warning' => '#eab308',
            default => '#6366f1',
        };

        $recommendedAction = match($severity) {
            'emergency' => 'IMMEDIATE: Disable non-essential AI features or add credits immediately.',
            'critical' => 'Add credits within 24 hours or reduce AI feature usage.',
            'warning' => 'Monitor usage closely. Plan to add credits before the month ends.',
            default => 'All systems normal.',
        };

        $timestamp = date('Y-m-d H:i:s T');

        return "<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { border-bottom: 3px solid {$severityColor}; padding-bottom: 16px; margin-bottom: 20px; }
        .header h2 { margin: 0; color: {$severityColor}; }
        .alert-box { background: #f9f9f9; border-left: 4px solid {$severityColor}; padding: 16px; margin: 16px 0; border-radius: 4px; }
        .metric { display: inline-block; margin-right: 20px; margin-bottom: 12px; }
        .metric-label { font-size: 12px; color: #666; text-transform: uppercase; font-weight: 600; }
        .metric-value { font-size: 24px; font-weight: bold; color: {$severityColor}; }
        .action-box { background: #fff3cd; border: 1px solid #ffc107; padding: 12px; border-radius: 4px; margin: 16px 0; }
        .footer { font-size: 12px; color: #999; margin-top: 24px; padding-top: 16px; border-top: 1px solid #eee; }
    </style>
</head>
<body>
    <div class=\"container\">
        <div class=\"header\">
            <h2>" . htmlspecialchars($provider) . " API Balance — " . ucfirst($severity) . " Alert</h2>
        </div>

        <p>Your <strong>" . htmlspecialchars($provider) . "</strong> API balance has dropped below the <strong>{$thresholdPct}%</strong> warning threshold.</p>

        <div class=\"alert-box\">
            <div class=\"metric\">
                <div class=\"metric-label\">Remaining Balance</div>
                <div class=\"metric-value\">\${$remainingBalance}</div>
            </div>
            <div class=\"metric\">
                <div class=\"metric-label\">Usage This Month</div>
                <div class=\"metric-value\">{$remainingPercent}%</div>
            </div>
            <div class=\"metric\">
                <div class=\"metric-label\">API Calls</div>
                <div class=\"metric-value\">{$callCount}</div>
            </div>
        </div>

        <div class=\"action-box\">
            <strong>Recommended Action:</strong><br>
            {$recommendedAction}
        </div>

        <p><strong>Details:</strong></p>
        <ul>
            <li>Provider: <strong>" . htmlspecialchars($provider) . "</strong></li>
            <li>Severity: <strong>" . ucfirst($severity) . "</strong></li>
            <li>Remaining: <strong>{$remainingPercent}%</strong></li>
            <li>Checked: <strong>{$timestamp}</strong></li>
        </ul>

        <p>Log in to the admin dashboard to view detailed balance tracking and usage trends.</p>

        <div class=\"footer\">
            <p>This is an automated alert from FACT Alliance Hub. Do not reply to this email.</p>
        </div>
    </div>
</body>
</html>";
    }

    /**
     * Get current balance status for admin dashboard.
     */
    public static function getStatus(mysqli $conn): array {
        $stmt = $conn->prepare(
            'SELECT provider, total_budget, remaining_balance, status, last_checked_at
             FROM api_balances
             ORDER BY provider ASC'
        );
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $results;
    }

    /**
     * Get current alert count by severity.
     */
    public static function getAlertCounts(mysqli $conn): array {
        $stmt = $conn->prepare(
            'SELECT severity, COUNT(*) as count FROM balance_alerts
             WHERE sent_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY severity'
        );
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $counts = ['emergency' => 0, 'critical' => 0, 'warning' => 0];
        foreach ($results as $row) {
            $counts[$row['severity']] = (int)$row['count'];
        }
        return $counts;
    }
}
?>

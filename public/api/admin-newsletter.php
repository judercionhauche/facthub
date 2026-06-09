<?php
/**
 * Admin Newsletter Management API - Subscriber Export Only
 * Purpose: Export newsletter subscribers to Excel for Mailchimp
 *
 * Endpoints:
 * - GET ?action=list - Fetch subscribers as JSON
 * - GET ?action=export - Download subscribers as Excel file
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../app/core/db.php';
require_once __DIR__ . '/../../app/core/helpers.php';
require_once __DIR__ . '/../../app/core/session_manager.php';

// Require admin authentication
if (!is_logged_in() || !is_admin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? 'list';

try {
    if ($action === 'list') {
        // Fetch all active subscribers with detailed info
        $stmt = $conn->prepare("
            SELECT
                ns.id,
                ns.user_id,
                ns.email,
                u.name,
                r.institution,
                r.department,
                r.focus_area,
                r.topics,
                r.source,
                r.referrer_name,
                ns.subscribed_at
            FROM newsletter_subscribers ns
            LEFT JOIN users u ON ns.user_id = u.id
            LEFT JOIN researchers r ON u.id = r.user_id
            WHERE ns.status = 'active'
            ORDER BY ns.subscribed_at DESC
        ");
        $stmt->execute();
        $result = $stmt->get_result();

        $subscribers = [];
        while ($row = $result->fetch_assoc()) {
            $subscribers[] = [
                'name' => $row['name'] ?: 'Anonymous',
                'email' => $row['email'],
                'institution' => $row['institution'] ?: 'N/A',
                'department' => $row['department'] ?: 'N/A',
                'focus_area' => $row['focus_area'] ?: 'N/A',
                'topics' => $row['topics'] ?: 'N/A',
                'source' => ucfirst($row['source'] ?: 'Not specified'),
                'referrer_name' => $row['referrer_name'] ?: 'N/A',
                'subscribed_at' => $row['subscribed_at']
            ];
        }

        echo json_encode([
            'success' => true,
            'subscribers' => $subscribers,
            'total' => count($subscribers)
        ]);

    } elseif ($action === 'export') {
        // Check if there are active subscribers
        $countStmt = $conn->prepare("
            SELECT COUNT(*) as cnt
            FROM newsletter_subscribers ns
            LEFT JOIN users u ON ns.user_id = u.id
            LEFT JOIN researchers r ON u.id = r.user_id
            WHERE ns.status = 'active'
        ");

        if (!$countStmt->execute()) {
            http_response_code(500);
            echo json_encode(['error' => 'Query failed: ' . $conn->error]);
            exit;
        }

        $countResult = $countStmt->get_result()->fetch_assoc();
        $subscriberCount = (int)($countResult['cnt'] ?? 0);

        if ($subscriberCount === 0) {
            http_response_code(400);
            echo json_encode(['error' => 'No active subscribers to export']);
            exit;
        }

        // Export active subscribers with user and researcher data
        // Join by user_id first, then by email if user_id is NULL
        $stmt = $conn->prepare("
            SELECT
                ns.email,
                u.name,
                r.institution,
                r.source,
                ns.subscribed_at
            FROM newsletter_subscribers ns
            LEFT JOIN users u ON (ns.user_id = u.id OR (ns.user_id IS NULL AND ns.email = u.email))
            LEFT JOIN researchers r ON u.id = r.user_id
            WHERE ns.status = 'active'
            ORDER BY ns.subscribed_at DESC
        ");

        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['error' => 'Prepare failed: ' . $conn->error]);
            exit;
        }

        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(['error' => 'Execute failed: ' . $stmt->error]);
            exit;
        }

        $result = $stmt->get_result();

        // Build CSV with headers first
        $rows = [[
            'Email',
            'Full Name',
            'Institution',
            'How They Heard About Us',
            'Subscribed Date'
        ]];

        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                $row['email'] ?: 'N/A',
                $row['name'] ?: 'N/A',
                $row['institution'] ?: 'N/A',
                $row['source'] ?: 'Not specified',
                date('Y-m-d', strtotime($row['subscribed_at']))
            ];
        }

        // Generate CSV file
        generate_excel_file($rows);

    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }

} catch (Exception $e) {
    error_log('[Newsletter API] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to process request']);
}

// ═══════════════════════════════════════════════════════════════════════
// Generate XLSX Excel file from data rows (CSV fallback for compatibility)
// ═══════════════════════════════════════════════════════════════════════
function generate_excel_file($rows) {
    $filename = 'FACT_Newsletter_Subscribers_' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Build CSV content as string first
    $csv = '';

    // Add BOM for UTF-8 (Excel compatibility)
    $csv .= "\xEF\xBB\xBF";

    // Build CSV rows
    foreach ($rows as $row) {
        // Escape and quote fields
        $escaped_row = array_map(function($field) {
            $field = (string)$field;
            // Quote if contains comma, quote, or newline
            if (strpbrk($field, "\",\n\r") !== false) {
                $field = '"' . str_replace('"', '""', $field) . '"';
            }
            return $field;
        }, $row);

        $csv .= implode(',', $escaped_row) . "\r\n";
    }

    // Output CSV
    echo $csv;
    exit;
}
?>

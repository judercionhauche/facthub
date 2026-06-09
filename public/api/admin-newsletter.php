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
        // Fetch all active subscribers
        $stmt = $conn->prepare("
            SELECT
                ns.id,
                ns.user_id,
                ns.email,
                u.name,
                r.institution,
                r.focus_area,
                r.topics,
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
                'focus_area' => $row['focus_area'] ?: 'N/A',
                'topics' => $row['topics'] ?: 'N/A',
                'subscribed_at' => $row['subscribed_at']
            ];
        }

        echo json_encode([
            'success' => true,
            'subscribers' => $subscribers,
            'total' => count($subscribers)
        ]);

    } elseif ($action === 'export') {
        // Export active subscribers to Excel
        $stmt = $conn->prepare("
            SELECT
                u.name,
                ns.email,
                r.institution,
                r.focus_area,
                r.topics,
                ns.subscribed_at
            FROM newsletter_subscribers ns
            LEFT JOIN users u ON ns.user_id = u.id
            LEFT JOIN researchers r ON u.id = r.user_id
            WHERE ns.status = 'active'
            ORDER BY ns.subscribed_at DESC
        ");
        $stmt->execute();
        $result = $stmt->get_result();

        // Generate Excel file
        $rows = [['Full Name', 'Email', 'Institution', 'Research Focus', 'Topics', 'Subscribed Date']];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                $row['name'] ?: 'Anonymous',
                $row['email'],
                $row['institution'] ?: 'N/A',
                $row['focus_area'] ?: 'N/A',
                $row['topics'] ?: 'N/A',
                date('Y-m-d', strtotime($row['subscribed_at']))
            ];
        }

        // Generate XLSX file
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
// Generate XLSX Excel file from data rows
// ═══════════════════════════════════════════════════════════════════════
function generate_excel_file($rows) {
    $filename = 'FACT_Newsletter_Subscribers_' . date('Y-m-d_His') . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Create ZIP archive for XLSX
    $zip = new ZipArchive();
    $temp_file = sys_get_temp_dir() . '/' . uniqid('xlsx_') . '.zip';
    $zip->open($temp_file, ZipArchive::CREATE);

    // [Content_Types].xml
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>');

    // _rels/.rels
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');

    // xl/_rels/workbook.xml.rels
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>');

    // xl/workbook.xml
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<workbookPr date1904="false"/>
<sheets>
<sheet name="Subscribers" sheetId="1" r:id="rId1"/>
</sheets>
</workbook>');

    // xl/styles.xml
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="2">
<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0"/>
</cellXfs>
<fonts count="2"><font><sz val="11"/><color theme="1"/><name val="Calibri"/></font>
<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>
<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>
<fill><patternFill patternType="solid"><fgColor rgb="FF1F4E78"/></patternFill></fill></fills>
<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
</styleSheet>');

    // xl/worksheets/sheet1.xml (with data)
    $worksheet_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<sheetData>';

    $row_num = 1;
    foreach ($rows as $row_data) {
        $worksheet_xml .= '<row r="' . $row_num . '">';
        $col_letters = ['A', 'B', 'C', 'D', 'E', 'F'];

        foreach ($row_data as $col_idx => $cell_value) {
            $col_letter = $col_letters[$col_idx] ?? 'G';
            $cell_ref = $col_letter . $row_num;
            $style = ($row_num === 1) ? ' s="1"' : '';
            $cell_value = htmlspecialchars((string)$cell_value, ENT_XML1, 'UTF-8');

            $worksheet_xml .= '<c r="' . $cell_ref . '" t="inlineStr"' . $style . '><is><t>' . $cell_value . '</t></is></c>';
        }

        $worksheet_xml .= '</row>';
        $row_num++;
    }

    $worksheet_xml .= '</sheetData></worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $worksheet_xml);

    $zip->close();

    // Output and cleanup
    readfile($temp_file);
    unlink($temp_file);
    exit;
}
?>

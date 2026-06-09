<?php
/**
 * Admin Newsletter Management API
 * Handles subscriber list retrieval and Excel export
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

$action = $_GET['action'] ?? '';

// ═══════════════════════════════════════════════════════════════════════
// List Subscribers
// ═══════════════════════════════════════════════════════════════════════
if ($action === 'list' || empty($action)) {
    try {
        $stmt = $conn->prepare("
            SELECT
                ns.id,
                ns.user_id,
                ns.email,
                u.name,
                r.institution,
                r.focus_area,
                ns.subscribed_at,
                ns.status
            FROM newsletter_subscribers ns
            LEFT JOIN users u ON ns.user_id = u.id
            LEFT JOIN researchers r ON u.id = r.user_id
            WHERE ns.status = 'active'
            ORDER BY ns.subscribed_at DESC
        ");
        $stmt->execute();
        $result = $stmt->get_result();

        $subscribers = [];
        $total = 0;

        while ($row = $result->fetch_assoc()) {
            $subscribers[] = [
                'id' => $row['id'],
                'user_id' => $row['user_id'],
                'name' => $row['name'] ?: 'Anonymous',
                'email' => $row['email'],
                'institution' => $row['institution'] ?: 'N/A',
                'focus_area' => $row['focus_area'] ?: 'N/A',
                'subscribed_at' => $row['subscribed_at'],
                'status' => $row['status']
            ];
            $total++;
        }

        echo json_encode([
            'success' => true,
            'subscribers' => $subscribers,
            'total' => $total
        ]);

    } catch (Exception $e) {
        error_log('[Admin Newsletter API] Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch subscribers']);
    }
}

// ═══════════════════════════════════════════════════════════════════════
// Export to Excel
// ═══════════════════════════════════════════════════════════════════════
elseif ($action === 'export') {
    try {
        $stmt = $conn->prepare("
            SELECT
                ns.id,
                u.name,
                ns.email,
                r.institution,
                r.focus_area,
                r.topics,
                u.status as user_status,
                ns.subscribed_at
            FROM newsletter_subscribers ns
            LEFT JOIN users u ON ns.user_id = u.id
            LEFT JOIN researchers r ON u.id = r.user_id
            WHERE ns.status = 'active'
            ORDER BY ns.subscribed_at DESC
        ");
        $stmt->execute();
        $result = $stmt->get_result();

        // Generate Excel file (XLSX format)
        generate_newsletter_xlsx($result);

    } catch (Exception $e) {
        error_log('[Newsletter Export] Error: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Failed to export subscribers']);
    }
}

// ═══════════════════════════════════════════════════════════════════════
// Helper: Generate XLSX Excel file
// ═══════════════════════════════════════════════════════════════════════
function generate_newsletter_xlsx($result) {
    // Create XML for XLSX spreadsheet
    $timestamp = date('Y-m-d H:i:s');
    $filename = 'FACT_Newsletter_Subscribers_' . date('Y-m-d_His') . '.xlsx';

    // Start building Excel data
    $rows = [];
    $rows[] = ['Full Name', 'Email', 'Institution', 'Research Focus', 'Topics', 'Status', 'Subscribed Date'];

    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            $row['name'] ?: 'Anonymous',
            $row['email'],
            $row['institution'] ?: 'N/A',
            $row['focus_area'] ?: 'N/A',
            $row['topics'] ?: 'N/A',
            $row['user_status'] ?: 'Unknown',
            date('Y-m-d', strtotime($row['subscribed_at']))
        ];
    }

    // Generate simple CSV-based approach (Excel-compatible)
    // Using native Excel XML format for better compatibility
    generate_excel_xml($rows, $filename);
}

function generate_excel_xml($rows, $filename) {
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Create ZIP file for XLSX
    $zip = new ZipArchive();
    $temp_file = sys_get_temp_dir() . '/' . uniqid('xlsx_') . '.zip';
    $zip->open($temp_file, ZipArchive::CREATE);

    // Add [Content_Types].xml
    $content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>';
    $zip->addFromString('[Content_Types].xml', $content_types);

    // Add _rels/.rels
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';
    $zip->addFromString('_rels/.rels', $rels);

    // Add xl/_rels/workbook.xml.rels
    $wb_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>';
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wb_rels);

    // Add xl/workbook.xml
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<workbookPr date1904="false"/>
<sheets>
<sheet name="Newsletter Subscribers" sheetId="1" r:id="rId1"/>
</sheets>
</workbook>';
    $zip->addFromString('xl/workbook.xml', $workbook);

    // Add xl/styles.xml
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
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
</styleSheet>';
    $zip->addFromString('xl/styles.xml', $styles);

    // Generate worksheet with data
    $worksheet = generate_worksheet_xml($rows);
    $zip->addFromString('xl/worksheets/sheet1.xml', $worksheet);

    $zip->close();

    // Output file
    readfile($temp_file);
    unlink($temp_file);
}

function generate_worksheet_xml($rows) {
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<sheetData>';

    $row_num = 1;
    foreach ($rows as $row_data) {
        $xml .= '<row r="' . $row_num . '">';
        $col_letter = 'A';

        foreach ($row_data as $cell_value) {
            $cell_ref = $col_letter . $row_num;
            $style = ($row_num === 1) ? ' s="1"' : '';
            $cell_value = htmlspecialchars($cell_value, ENT_XML1, 'UTF-8');

            $xml .= '<c r="' . $cell_ref . '" t="inlineStr"' . $style . '><is><t>' . $cell_value . '</t></is></c>';
            $col_letter++;
        }

        $xml .= '</row>';
        $row_num++;
    }

    $xml .= '</sheetData></worksheet>';
    return $xml;
}
?>

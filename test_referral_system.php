<?php
/**
 * Test script: Add 4 random researchers with different referral sources
 * Tests the new source + referrer_name feature
 *
 * Usage: php test_referral_system.php
 * Or: Visit http://localhost:8000/test_referral_system.php
 */

require_once 'config/database.php';

$dbConfig = require 'config/database.php';
$conn = new mysqli($dbConfig['db_host'], $dbConfig['db_user'], $dbConfig['db_pass'], $dbConfig['db_name']);

if ($conn->connect_error) {
    die("[ERROR] Database connection failed: " . $conn->connect_error);
}

$conn->set_charset('utf8mb4');

echo "<h1>Referral System Test</h1>";
echo "<p>Adding 4 test researchers with different referral sources...</p>";

// Test data: 4 researchers with different referral scenarios
$testResearchers = [
    [
        'first_name' => 'Dr. Greg',
        'last_name' => 'Harrison',
        'email' => 'greg.harrison@example.edu',
        'institution' => 'MIT',
        'department' => 'Civil & Environmental Engineering',
        'title' => 'Assistant Professor',
        'bio' => 'Water systems and climate adaptation expert',
        'focus_area' => 'Ecosystems & Biodiversity',
        'topics' => 'water security, climate adaptation, agriculture',
        'geography' => 'East Africa, Southeast Asia',
        'source' => 'colleague',
        'referrer_name' => 'Dr. Jane Smith'
    ],
    [
        'first_name' => 'Dr. Sarah',
        'last_name' => 'Chen',
        'email' => 'sarah.chen@stanford.edu',
        'institution' => 'Stanford University',
        'department' => 'School of Earth Sciences',
        'title' => 'Associate Professor',
        'bio' => 'Food systems and nutrition researcher',
        'focus_area' => 'Food Security, Nutrition & Health',
        'topics' => 'food security, nutrition, supply chains',
        'geography' => 'South Asia, Sub-Saharan Africa',
        'source' => 'organization',
        'referrer_name' => 'Stanford Sustainability Office'
    ],
    [
        'first_name' => 'Prof. Ahmed',
        'last_name' => 'Hassan',
        'email' => 'ahmed.hassan@berkeley.edu',
        'institution' => 'UC Berkeley',
        'department' => 'School of Public Health',
        'title' => 'Professor',
        'bio' => 'Public health and governance specialist',
        'focus_area' => 'Governance & Innovation',
        'topics' => 'public health, governance, policy',
        'geography' => 'Africa, Middle East',
        'source' => 'conference',
        'referrer_name' => null
    ],
    [
        'first_name' => 'Dr. Lisa',
        'last_name' => 'Rodriguez',
        'email' => 'lisa.rodriguez@uchicago.edu',
        'institution' => 'University of Chicago',
        'department' => 'Harris School of Public Policy',
        'title' => 'Senior Researcher',
        'bio' => 'Markets, trade and economic development',
        'focus_area' => 'Markets & Trade',
        'topics' => 'trade policy, market systems, value chains',
        'geography' => 'Latin America, Sub-Saharan Africa',
        'source' => 'linkedin',
        'referrer_name' => null
    ]
];

$results = [
    'success' => 0,
    'failed' => 0,
    'errors' => []
];

foreach ($testResearchers as $i => $data) {
    try {
        $stmt = $conn->prepare(
            "INSERT INTO researchers
            (first_name, last_name, email, institution, department, title, bio,
             focus_area, topics, geography, source, referrer_name, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')"
        );

        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }

        $stmt->bind_param(
            'ssssssssssss',
            $data['first_name'],
            $data['last_name'],
            $data['email'],
            $data['institution'],
            $data['department'],
            $data['title'],
            $data['bio'],
            $data['focus_area'],
            $data['topics'],
            $data['geography'],
            $data['source'],
            $data['referrer_name']
        );

        if ($stmt->execute()) {
            $results['success']++;
            $newId = $conn->insert_id;
            echo "<div style='padding:10px;margin:8px 0;background:#f0fdf4;border-left:3px solid #16a34a;border-radius:4px'>";
            echo "[OK] <strong>" . h($data['first_name'] . ' ' . $data['last_name']) . "</strong> (ID: $newId)<br>";
            echo "  Source: <strong>" . h($data['source']) . "</strong>";
            if ($data['referrer_name']) {
                echo " | Referrer: <strong>" . h($data['referrer_name']) . "</strong>";
            }
            echo "<br>  Email: " . h($data['email']);
            echo "</div>";
        } else {
            throw new Exception("Execute failed: " . $stmt->error);
        }
    } catch (Exception $e) {
        $results['failed']++;
        $results['errors'][] = $data['first_name'] . ' ' . $data['last_name'] . ': ' . $e->getMessage();
        echo "<div style='padding:10px;margin:8px 0;background:#fff5f5;border-left:3px solid #b54646;border-radius:4px'>";
        echo "[ERROR] Failed to add " . h($data['first_name']) . ": " . h($e->getMessage());
        echo "</div>";
    }
}

// Verify data in database
echo "<h2 style='margin-top:24px'>Verification</h2>";

$checkStmt = $conn->prepare("SELECT id, first_name, last_name, source, referrer_name FROM researchers WHERE source IS NOT NULL ORDER BY id DESC LIMIT 10");
$checkStmt->execute();
$rows = $checkStmt->get_result()->fetch_all(MYSQLI_ASSOC);

if (!empty($rows)) {
    echo "<table style='width:100%;border-collapse:collapse;margin-top:12px'>";
    echo "<tr style='background:#f3f4f6'><th style='padding:8px;text-align:left;border:1px solid #ddd'>ID</th><th style='padding:8px;text-align:left;border:1px solid #ddd'>Name</th><th style='padding:8px;text-align:left;border:1px solid #ddd'>Source</th><th style='padding:8px;text-align:left;border:1px solid #ddd'>Referrer Name</th></tr>";
    foreach ($rows as $row) {
        echo "<tr>";
        echo "<td style='padding:8px;border:1px solid #ddd'>" . $row['id'] . "</td>";
        echo "<td style='padding:8px;border:1px solid #ddd'>" . h($row['first_name'] . ' ' . $row['last_name']) . "</td>";
        echo "<td style='padding:8px;border:1px solid #ddd'><strong>" . h($row['source']) . "</strong></td>";
        echo "<td style='padding:8px;border:1px solid #ddd'>" . (h($row['referrer_name']) ?? '—') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:#b54646'>[WARNING] No researchers with source data found!</p>";
}

// Summary
echo "<h2 style='margin-top:24px'>Summary</h2>";
echo "<div style='padding:12px;background:#f3f4f6;border-radius:4px'>";
echo "[OK] Added: <strong>" . $results['success'] . "</strong> researchers<br>";
echo "[ERROR] Failed: <strong>" . $results['failed'] . "</strong> researchers<br>";
echo "<br><strong>Test Status:</strong> ";
if ($results['success'] === 4 && $results['failed'] === 0) {
    echo "<span style='color:#16a34a;font-weight:bold'>[PASS] ALL TESTS PASSED!</span>";
} else {
    echo "<span style='color:#b54646;font-weight:bold'>[WARNING] Some tests failed</span>";
}
echo "</div>";

if (!empty($results['errors'])) {
    echo "<h3>Errors:</h3>";
    echo "<ul>";
    foreach ($results['errors'] as $err) {
        echo "<li style='color:#b54646'>" . h($err) . "</li>";
    }
    echo "</ul>";
}

$conn->close();

function h($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}
?>

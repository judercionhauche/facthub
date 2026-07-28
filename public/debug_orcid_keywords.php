<?php
// Check Greg's ORCID keywords
$dbConfig = require_once __DIR__ . '/../config/database.php';
$conn = new mysqli($dbConfig['db_host'], $dbConfig['db_user'], $dbConfig['db_pass'], $dbConfig['db_name']);

echo "<h2>Greg's ORCID Keywords</h2>";
echo "<pre>";

$result = $conn->query("SELECT keywords FROM researcher_orcid_cache WHERE orcid_id='0000-0003-2839-8164' LIMIT 1");

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $keywordsJson = $row['keywords'] ?? '[]';
    $keywords = json_decode($keywordsJson, true) ?? [];

    echo "Keywords count: " . count($keywords) . "\n\n";
    echo "Full keyword list:\n";
    foreach ($keywords as $kw) {
        echo "  - " . $kw . "\n";
    }

    echo "\n\nSearching for 'water' or 'harvest':\n";
    $found = false;
    foreach ($keywords as $kw) {
        if (stripos($kw, 'water') !== false || stripos($kw, 'harvest') !== false) {
            echo "  ✅ FOUND: " . $kw . "\n";
            $found = true;
        }
    }
    if (!$found) echo "  ❌ NOT FOUND\n";
} else {
    echo "❌ No cache for Greg\n";
}

$conn->close();
echo "</pre>";
?>

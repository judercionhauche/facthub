<?php
// Direct check of keywords in database
$dbConfig = require_once __DIR__ . '/../config/database.php';
$conn = new mysqli($dbConfig['db_host'], $dbConfig['db_user'], $dbConfig['db_pass'], $dbConfig['db_name']);

echo "<h2>Direct Keywords Check</h2>";
echo "<pre>";

// Check 1: Does cache exist for Greg?
$result = $conn->query("SELECT researcher_id, orcid_id, keywords FROM researcher_orcid_cache WHERE orcid_id='0000-0003-2839-8164' LIMIT 1");

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo "✅ Cache found for Greg (researcher_id: " . $row['researcher_id'] . ")\n";
    echo "Keywords JSON length: " . strlen($row['keywords']) . " bytes\n\n";

    if (!empty($row['keywords'])) {
        $keywords = json_decode($row['keywords'], true);
        if (is_array($keywords)) {
            echo "Keywords decoded successfully: " . count($keywords) . " items\n\n";

            echo "Checking for water/harvest keywords:\n";
            foreach ($keywords as $kw) {
                if (stripos($kw, 'water') !== false || stripos($kw, 'harvest') !== false) {
                    echo "  ✅ " . $kw . "\n";
                }
            }

            echo "\nAll keywords:\n";
            foreach (array_slice($keywords, 0, 25) as $kw) {
                echo "  - " . $kw . "\n";
            }
            if (count($keywords) > 25) echo "  ... and " . (count($keywords) - 25) . " more\n";
        } else {
            echo "❌ Keywords JSON decode failed\n";
        }
    } else {
        echo "❌ Keywords field is empty\n";
    }
} else {
    echo "❌ No cache found for Greg (0000-0003-2839-8164)\n";
}

// Check 2: Test the search function logic
echo "\n\n=== Testing Search Function Logic ===\n";

$result = $conn->query("SELECT r.id, r.first_name, r.last_name, c.keywords FROM researchers r
                        JOIN researcher_orcid_cache c ON r.id = c.researcher_id
                        WHERE r.deleted_at IS NULL AND r.status = 'active' AND c.keywords IS NOT NULL LIMIT 5");

if ($result) {
    echo "Found " . $result->num_rows . " researchers with ORCID keywords\n";
    while ($row = $result->fetch_assoc()) {
        echo "  - " . $row['first_name'] . " " . $row['last_name'] . " (ID: " . $row['id'] . ")\n";
    }
} else {
    echo "Query failed: " . $conn->error . "\n";
}

$conn->close();
echo "</pre>";
?>

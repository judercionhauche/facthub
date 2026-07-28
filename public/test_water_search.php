<?php
// Test the ORCID keyword search for "water harvesting"
$dbConfig = require_once __DIR__ . '/../config/database.php';
$conn = new mysqli($dbConfig['db_host'], $dbConfig['db_user'], $dbConfig['db_pass'], $dbConfig['db_name']);

echo "<h2>Testing ORCID Keyword Search for 'water'</h2>";
echo "<pre>";

// Simulate the search function
$searchTerms = ['water', 'harvesting'];

echo "Search terms: " . implode(", ", $searchTerms) . "\n\n";

// Query 1: Check if any researchers have ORCID cache
$result = $conn->query("SELECT COUNT(*) as cnt FROM researcher_orcid_cache WHERE keywords IS NOT NULL");
$row = $result->fetch_assoc();
echo "Researchers with ORCID keywords in cache: " . $row['cnt'] . "\n\n";

// Query 2: Get all researchers with keywords and check them
$result = $conn->query("SELECT r.id, r.first_name, r.last_name, c.keywords FROM researchers r
                        JOIN researcher_orcid_cache c ON r.id = c.researcher_id
                        WHERE r.deleted_at IS NULL AND r.status = 'active' AND c.keywords IS NOT NULL
                        LIMIT 100");

echo "Checking " . $result->num_rows . " researchers:\n\n";

$matches = [];
while ($row = $result->fetch_assoc()) {
    $name = $row['first_name'] . " " . $row['last_name'];
    $keywords = json_decode($row['keywords'], true) ?? [];

    echo "Checking $name (ID: " . $row['id'] . "):\n";
    echo "  Keywords: " . count($keywords) . " total\n";

    foreach ($searchTerms as $term) {
        $found = false;
        foreach ($keywords as $kw) {
            if (stripos($kw, strtolower($term)) !== false) {
                echo "    ✅ Found '$term' in keyword: '$kw'\n";
                $found = true;
                $matches[] = $row;
                break;
            }
        }
        if (!$found) {
            echo "    ❌ '$term' not found\n";
        }
    }
    echo "\n";
}

echo "=== FINAL RESULTS ===\n";
echo "Matches found: " . count(array_unique($matches, SORT_REGULAR)) . "\n";
foreach (array_unique($matches, SORT_REGULAR) as $match) {
    echo "  - " . $match['first_name'] . " " . $match['last_name'] . "\n";
}

$conn->close();
echo "</pre>";
?>

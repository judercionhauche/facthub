<?php
// Pre-populate Greg's ORCID cache with fresh data
$dbConfig = require_once __DIR__ . '/../config/database.php';
$conn = new mysqli($dbConfig['db_host'], $dbConfig['db_user'], $dbConfig['db_pass'], $dbConfig['db_name']);

require_once __DIR__ . '/../app/services/OrcidService.php';

$orcid = new OrcidService($conn);

echo "<h2>Populating Greg's ORCID Cache</h2>";
echo "<pre>";

// Get Greg
$result = $conn->query("SELECT id, orcid_id FROM researchers WHERE first_name='Greg' AND last_name LIKE 'Sixt%' LIMIT 1");

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $gregId = $row['id'];
    $orcidId = $row['orcid_id'];

    echo "Found Greg (ID: $gregId, ORCID: $orcidId)\n\n";
    echo "Calling enrichResearcher to fetch and cache ORCID data...\n";

    $orcid->enrichResearcher($gregId, $orcidId);

    echo "✅ Cache populated\n\n";

    // Verify
    $result = $conn->query("SELECT pub_count, keywords FROM researcher_orcid_cache WHERE researcher_id=$gregId");
    if ($result && $result->num_rows > 0) {
        $cache = $result->fetch_assoc();
        $keywords = json_decode($cache['keywords'], true) ?? [];

        echo "Publications: " . $cache['pub_count'] . "\n";
        echo "Keywords: " . count($keywords) . " total\n\n";

        echo "Water-related keywords:\n";
        $foundWater = false;
        foreach ($keywords as $kw) {
            if (stripos($kw, 'water') !== false || stripos($kw, 'harvest') !== false) {
                echo "  ✅ " . $kw . "\n";
                $foundWater = true;
            }
        }
        if (!$foundWater) {
            echo "  ❌ No water/harvest keywords found\n";
            echo "\nAll keywords:\n";
            foreach (array_slice($keywords, 0, 30) as $kw) {
                echo "  - " . $kw . "\n";
            }
        }
    }
} else {
    echo "❌ Could not find Greg\n";
}

$conn->close();
echo "</pre>";
?>

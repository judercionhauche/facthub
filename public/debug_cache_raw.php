<?php
// Check raw database cache for Greg
$dbConfig = require_once __DIR__ . '/../config/database.php';
$conn = new mysqli($dbConfig['db_host'], $dbConfig['db_user'], $dbConfig['db_pass'], $dbConfig['db_name']);

echo "<h2>Raw Cache Data for Greg</h2>";
echo "<pre>";

$result = $conn->query("SELECT * FROM researcher_orcid_cache WHERE orcid_id='0000-0003-2839-8164' LIMIT 1");

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();

    echo "Database Fields:\n";
    echo "- researcher_id: " . $row['researcher_id'] . "\n";
    echo "- orcid_id: " . $row['orcid_id'] . "\n";
    echo "- activity_score: " . $row['activity_score'] . "\n";
    echo "- pub_count: " . $row['pub_count'] . "\n";
    echo "- affiliation_count: " . $row['affiliation_count'] . "\n";

    echo "\nJSON Fields:\n";
    echo "- publication_data length: " . strlen($row['publication_data'] ?? '') . " bytes\n";

    if (!empty($row['publication_data'])) {
        echo "- publication_data first 200 chars: " . substr($row['publication_data'], 0, 200) . "...\n";

        $pubArray = json_decode($row['publication_data'], true);
        echo "- json_decode result: " . (is_array($pubArray) ? "SUCCESS - " . count($pubArray) . " items" : "FAILED") . "\n";

        if (is_array($pubArray) && count($pubArray) > 0) {
            echo "- First publication:\n";
            echo json_encode($pubArray[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        }
    } else {
        echo "- publication_data: EMPTY/NULL\n";
    }

    echo "\n- keywords length: " . strlen($row['keywords'] ?? '') . " bytes\n";
    if (!empty($row['keywords'])) {
        $keyArray = json_decode($row['keywords'], true);
        echo "- json_decode result: " . (is_array($keyArray) ? "SUCCESS - " . count($keyArray) . " items" : "FAILED") . "\n";
    }

} else {
    echo "❌ No cache found for Greg\n";
}

$conn->close();
echo "</pre>";
?>

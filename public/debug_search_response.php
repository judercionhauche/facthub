<?php
// Simulate what the search returns for Greg
$dbConfig = require_once __DIR__ . '/../config/database.php';
$conn = new mysqli($dbConfig['db_host'], $dbConfig['db_user'], $dbConfig['db_pass'], $dbConfig['db_name']);

require_once __DIR__ . '/../app/services/OrcidService.php';
require_once __DIR__ . '/../app/core/helpers.php';

$orcid = new OrcidService($conn);

echo "<h2>Simulating Search Response for Greg</h2>";
echo "<pre>";

// Get Greg
$gregResult = $conn->query("SELECT * FROM researchers WHERE first_name='Greg' AND last_name LIKE 'Sixt%' LIMIT 1");
if ($gregResult && $gregResult->num_rows > 0) {
    $r = $gregResult->fetch_assoc();

    echo "Step 1: Fetch researcher\n";
    echo "  Greg ID: " . $r['id'] . "\n";
    echo "  ORCID: " . $r['orcid_id'] . "\n\n";

    echo "Step 2: Check for ORCID data\n";
    echo "  Has orcid_id? " . (!empty($r['orcid_id']) ? 'YES' : 'NO') . "\n";
    echo "  Has orcid service? YES\n\n";

    if (!empty($r['orcid_id']) && $orcid) {
        echo "Step 3: Call getEnrichedResearcher\n";
        $orcidData = $orcid->getEnrichedResearcher((int)$r['id'], $r['orcid_id']);

        if ($orcidData) {
            echo "  ✅ Got ORCID data\n";
            echo "  Source: " . ($orcidData['source'] ?? 'unknown') . "\n";
            echo "  Publications count: " . count($orcidData['publications'] ?? []) . "\n";

            if (!empty($orcidData['publications'])) {
                $orcidPubs = array_slice($orcidData['publications'], 0, 10);
                $orcidCount = count($orcidData['publications']);

                echo "  ORCID slice count: " . count($orcidPubs) . "\n";
                echo "  Total ORCID count: $orcidCount\n\n";

                echo "Step 4: Would be included in response:\n";
                echo "  publication_source: ORCID\n";
                echo "  orcid_publication_count: $orcidCount\n";
                echo "  publications array: " . count($orcidPubs) . " items\n";

                echo "\n  Sample publication:\n";
                if (isset($orcidPubs[0])) {
                    echo "    - " . ($orcidPubs[0]['title'] ?? 'No title') . "\n";
                }
            } else {
                echo "  ❌ No publications in ORCID data!\n";
            }
        } else {
            echo "  ❌ getEnrichedResearcher returned null!\n";
        }
    }
}

$conn->close();
echo "</pre>";
?>

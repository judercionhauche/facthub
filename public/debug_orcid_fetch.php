<?php
// Debug ORCID fetch step by step
$orcidId = '0000-0003-2839-8164';

echo "<h2>Debugging ORCID Fetch for Greg</h2>";
echo "<pre>";

// Test 1: Can we reach ORCID API?
echo "1️⃣ Testing ORCID API connection...\n";
$url = 'https://pub.orcid.org/v3.0/' . $orcidId . '/works';
echo "URL: $url\n";

$context = stream_context_create(['http' => [
    'timeout' => 5,
    'user_agent' => 'FACT-Hub/1.0 (+https://factalliancehub.mit.edu)',
    'header' => 'Accept: application/json'
]]);

$response = @file_get_contents($url, false, $context);
if ($response) {
    echo "✅ ORCID API returned data\n";
    $data = json_decode($response, true);
    echo "Groups found: " . (isset($data['group']) ? count($data['group']) : 0) . "\n";

    if (isset($data['group'])) {
        $totalWorks = 0;
        foreach ($data['group'] as $group) {
            if (isset($group['work-summary'])) {
                $totalWorks += count($group['work-summary']);
            }
        }
        echo "Total works: $totalWorks\n";
        echo "\nFirst 3 works:\n";
        $count = 0;
        foreach ($data['group'] as $group) {
            if (isset($group['work-summary'])) {
                foreach ($group['work-summary'] as $work) {
                    if ($count++ < 3) {
                        echo "  - " . ($work['title']['title']['value'] ?? 'No title') . "\n";
                    }
                }
            }
        }
    }
} else {
    echo "❌ ORCID API fetch failed\n";
    echo "Check: Is ORCID ID valid? Is network accessible?\n";
}

// Test 2: Check database cache
echo "\n2️⃣ Checking database cache...\n";
$dbConfig = require_once __DIR__ . '/../config/database.php';
$conn = new mysqli($dbConfig['db_host'], $dbConfig['db_user'], $dbConfig['db_pass'], $dbConfig['db_name']);

$cacheResult = $conn->query("SELECT * FROM researcher_orcid_cache WHERE orcid_id='$orcidId' LIMIT 1");
if ($cacheResult && $cacheResult->num_rows > 0) {
    $cached = $cacheResult->fetch_assoc();
    echo "✅ Cache found (researcher_id: " . $cached['researcher_id'] . ")\n";
    echo "   Pub count: " . $cached['pub_count'] . "\n";
    echo "   Activity score: " . $cached['activity_score'] . "\n";
    echo "   Last synced: " . $cached['last_synced'] . "\n";
} else {
    echo "❌ No cache found for this ORCID\n";
}

$conn->close();
echo "</pre>";
?>

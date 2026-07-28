<?php
// Debug what OrcidService.getEnrichedResearcher returns
$dbConfig = require_once __DIR__ . '/../config/database.php';
$conn = new mysqli($dbConfig['db_host'], $dbConfig['db_user'], $dbConfig['db_pass'], $dbConfig['db_name']);

require_once __DIR__ . '/../app/services/OrcidService.php';

$orcid = new OrcidService($conn);

echo "<h2>Testing OrcidService::getEnrichedResearcher()</h2>";
echo "<pre>";

$gregId = 42;
$orcidId = '0000-0003-2839-8164';

echo "Calling: \$orcid->getEnrichedResearcher($gregId, '$orcidId')\n\n";

$result = $orcid->getEnrichedResearcher($gregId, $orcidId);

if ($result) {
    echo "✅ Result returned\n";
    echo "Result type: " . gettype($result) . "\n";
    echo "Keys: " . implode(', ', array_keys($result)) . "\n\n";

    echo "Publication count: " . (isset($result['publications']) ? count($result['publications']) : 'NOT SET') . "\n";
    echo "Activity score: " . ($result['activity_score'] ?? 'NOT SET') . "\n";
    echo "Is active: " . ($result['is_active'] ? 'YES' : 'NO') . "\n";

    echo "\nPublications array:\n";
    if (isset($result['publications']) && is_array($result['publications'])) {
        echo "  Type: array\n";
        echo "  Count: " . count($result['publications']) . "\n";
        echo "  First item:\n";
        if (isset($result['publications'][0])) {
            echo "    " . json_encode($result['publications'][0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        }
    } else {
        echo "  ❌ Publications not an array or not set\n";
        echo "  Type: " . gettype($result['publications'] ?? null) . "\n";
        echo "  Value: " . var_export($result['publications'] ?? null, true) . "\n";
    }
} else {
    echo "❌ Result is null/false\n";
}

$conn->close();
echo "</pre>";
?>

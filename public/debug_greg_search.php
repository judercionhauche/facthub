<?php
// Debug what search returns for Greg
$dbConfig = require_once __DIR__ . '/../config/database.php';
$conn = new mysqli($dbConfig['db_host'], $dbConfig['db_user'], $dbConfig['db_pass'], $dbConfig['db_name']);

require_once __DIR__ . '/../app/core/schema_updates.php';
require_once __DIR__ . '/../app/core/helpers.php';
require_once __DIR__ . '/../app/services/OrcidService.php';

$orcid = new OrcidService($conn);

// Find Greg
$result = $conn->query("SELECT id, first_name, last_name, orcid_id, institution FROM researchers WHERE first_name='Greg' AND last_name LIKE 'Sixt%' LIMIT 1");

if ($result && $result->num_rows > 0) {
    $greg = $result->fetch_assoc();
    echo "<h2>Greg's Data:</h2>";
    echo "<pre>";
    echo "ID: " . $greg['id'] . "\n";
    echo "ORCID ID: " . $greg['orcid_id'] . "\n";
    echo "Institution: " . $greg['institution'] . "\n";
    echo "</pre>";

    echo "<h3>ORCID Enriched Data:</h3>";
    $orcidData = $orcid->getEnrichedResearcher((int)$greg['id'], $greg['orcid_id']);
    if ($orcidData) {
        echo "<pre>";
        echo "Publication Count: " . count($orcidData['publications']) . "\n";
        echo "Activity Score: " . $orcidData['activity_score'] . "\n";
        echo "Is Active: " . ($orcidData['is_active'] ? 'YES' : 'NO') . "\n";
        echo "\nPublications:\n";
        foreach (array_slice($orcidData['publications'], 0, 5) as $pub) {
            echo "  - " . $pub['title'] . " (" . $pub['year'] . ")\n";
        }
        echo "</pre>";
    } else {
        echo "❌ No ORCID data found";
    }

    echo "<h3>Local Publications:</h3>";
    $pubResult = $conn->query("SELECT title, publication_year FROM researcher_publications WHERE researcher_id=" . (int)$greg['id']);
    if ($pubResult) {
        echo "<pre>";
        echo "Local publications: " . $pubResult->num_rows . "\n";
        while ($pub = $pubResult->fetch_assoc()) {
            echo "  - " . $pub['title'] . " (" . $pub['publication_year'] . ")\n";
        }
        echo "</pre>";
    }

} else {
    echo "Greg not found";
}

$conn->close();
?>

<?php
// Clear ORCID cache for a researcher
$dbConfig = require_once __DIR__ . '/../config/database.php';
$conn = new mysqli($dbConfig['db_host'], $dbConfig['db_user'], $dbConfig['db_pass'], $dbConfig['db_name']);

// Find Greg
$result = $conn->query("SELECT id FROM researchers WHERE first_name='Greg' AND last_name LIKE 'Sixt%' LIMIT 1");
if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $gregId = $row['id'];

    // Delete his ORCID cache
    $conn->query("DELETE FROM researcher_orcid_cache WHERE researcher_id = $gregId");

    echo "✅ Cleared ORCID cache for Greg (ID: $gregId)\n";
    echo "Next search will fetch fresh data from ORCID API\n";
} else {
    echo "❌ Greg not found\n";
}

$conn->close();
?>

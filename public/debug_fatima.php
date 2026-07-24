<?php
// Temp debug: check Fatima's status in database
$dbConfig = require_once __DIR__ . '/../config/database.php';
$conn = new mysqli($dbConfig['db_host'], $dbConfig['db_user'], $dbConfig['db_pass'], $dbConfig['db_name']);

$result = $conn->query("SELECT id, CONCAT(first_name, ' ', last_name) as name, status, deleted_at, email FROM researchers WHERE first_name='Fatima' LIMIT 3");

echo "<h2>Fatima Records in DB:</h2>";
echo "<table border='1'><tr><th>ID</th><th>Name</th><th>Status</th><th>Deleted At</th><th>Email</th></tr>";
while ($row = $result->fetch_assoc()) {
    echo "<tr><td>{$row['id']}</td><td>{$row['name']}</td><td>{$row['status']}</td><td>{$row['deleted_at']}</td><td>{$row['email']}</td></tr>";
}
echo "</table>";

// Also check ORCID cache
echo "<h2>ORCID Cache for Fatima:</h2>";
$orcidResult = $conn->query("SELECT oc.* FROM researcher_orcid_cache oc JOIN researchers r ON oc.researcher_id = r.id WHERE r.first_name='Fatima' LIMIT 1");
if ($orcidResult && $orcidResult->num_rows > 0) {
    $row = $orcidResult->fetch_assoc();
    echo "<pre>"; print_r($row); echo "</pre>";
} else {
    echo "No ORCID cache found for Fatima";
}

$conn->close();
?>

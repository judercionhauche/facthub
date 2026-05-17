<?php
/**
 * Admin utility to apply security schema updates.
 * Access: http://localhost/fact_hub2/admin_apply_schema.php?confirm=1
 */

// Minimal validation - this should be removed after running once
if (empty($_GET['confirm'])) {
    die('Add ?confirm=1 to run schema updates');
}

require_once __DIR__ . '/config/database.php';

$sql = file_get_contents(__DIR__ . '/SECURITY_FIXES_SQL.sql');
$lines = explode("\n", $sql);
$query = "";
$count = 0;

echo "<pre style='font-family: monospace; white-space: pre-wrap;'>";
echo "Applying database schema updates...\n\n";

foreach ($lines as $line) {
    $line = trim($line);
    if (empty($line) || substr($line, 0, 2) === "--") continue;

    $query .= " " . $line;

    if (substr($line, -1) === ";") {
        $query = trim($query);
        $shortQuery = substr($query, 0, 70);
        echo "[$count] $shortQuery...\n";

        if ($conn->query($query)) {
            echo "    ✓ OK\n";
        } else {
            echo "    ✗ Error: " . $conn->error . "\n";
        }
        $count++;
        $query = "";
    }
}

echo "\n✓ Database schema updates complete ($count queries executed)\n";
echo "</pre>";
?>

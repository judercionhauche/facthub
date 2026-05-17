<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "Starting test...\n";

try {
    require_once __DIR__ . '/config/database.php';
    echo "Database connected\n";

    require_once __DIR__ . '/app/core/helpers.php';
    echo "Helpers loaded\n";

    require_once __DIR__ . '/app/core/schema_updates.php';
    echo "Schema updates loaded\n";

    apply_security_schema_updates($conn);
    echo "Schema updates applied\n";

    echo "All OK!\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "Stack: " . $e->getTraceAsString() . "\n";
}
?>

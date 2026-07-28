<?php
// Display the last PHP error log entries
echo "<h2>Recent PHP Error Log Entries</h2>";
echo "<pre>";

// Try to find the error log
$possiblePaths = [
    '/var/log/php-fpm/error.log',
    '/var/log/apache2/error.log',
    '/var/log/nginx/error.log',
    '/Applications/XAMPP/logs/php_error.log',
    ini_get('error_log')
];

$found = false;
foreach ($possiblePaths as $logPath) {
    if (!empty($logPath) && file_exists($logPath) && is_readable($logPath)) {
        echo "Found log at: $logPath\n\n";

        // Show last 100 lines
        $lines = file($logPath);
        $lastLines = array_slice($lines, -100);

        // Filter for FACT-relevant lines
        echo "Recent OrcidService and search logs:\n";
        $foundRelevant = false;
        foreach ($lastLines as $line) {
            if (stripos($line, 'OrcidService') !== false ||
                stripos($line, 'fetchCandidates') !== false ||
                stripos($line, 'water') !== false) {
                echo trim($line) . "\n";
                $foundRelevant = true;
            }
        }

        if (!$foundRelevant) {
            echo "No OrcidService or water-related logs found.\n";
            echo "\nLast 20 lines:\n";
            foreach (array_slice($lastLines, -20) as $line) {
                echo trim($line) . "\n";
            }
        }

        $found = true;
        break;
    }
}

if (!$found) {
    echo "Could not find error log file.\n";
    echo "Tried:\n";
    foreach ($possiblePaths as $p) {
        echo "  - $p\n";
    }
}

echo "</pre>";
?>

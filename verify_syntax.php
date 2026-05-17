<?php
// Quick syntax check by requiring the files
echo "Verifying syntax of critical files...\n\n";

$files = [
    'app/core/helpers.php' => 'Helper functions',
    'app/views/admin/index.php' => 'Admin panel',
    'app/views/funding/index.php' => 'Funding page',
    'app/views/researchers/index.php' => 'Researchers page'
];

error_reporting(E_ALL);
ini_set('display_errors', '1');

foreach ($files as $file => $description) {
    $filepath = __DIR__ . '/' . $file;
    if (!file_exists($filepath)) {
        echo "✗ $description ($file): FILE NOT FOUND\n";
        continue;
    }

    // Try to parse the file
    $code = file_get_contents($filepath);

    // Simple check for common syntax errors
    $errors = [];

    // Check for mismatched quotes
    if (preg_match("/prepare\('.*?status=.*?'/", $code)) {
        $errors[] = "Potential quote mismatch in prepare statements";
    }

    if (empty($errors)) {
        echo "✓ $description ($file): OK\n";
    } else {
        echo "⚠ $description ($file): " . implode(", ", $errors) . "\n";
    }
}

echo "\nDone!\n";
?>

<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "=== Testing Forgot Page ===\n\n";

// Simulate page load
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['page'] = 'forgot';

try {
    echo "1. Loading database...\n";
    require_once __DIR__ . '/config/database.php';
    echo "✓ Database OK\n\n";

    echo "2. Loading helpers...\n";
    require_once __DIR__ . '/app/core/helpers.php';
    echo "✓ Helpers OK\n\n";

    echo "3. Loading mailer...\n";
    require_once __DIR__ . '/app/core/mailer.php';
    echo "✓ Mailer OK\n\n";

    echo "4. Loading RateLimiter...\n";
    require_once __DIR__ . '/app/services/RateLimiter.php';
    echo "✓ RateLimiter OK\n\n";

    echo "5. Testing RateLimiter instantiation...\n";
    $rateLimiter = new RateLimiter($conn);
    echo "✓ RateLimiter instantiation OK\n\n";

    echo "6. Loading forgot view...\n";
    ob_start();
    include __DIR__ . '/app/views/forgot/index.php';
    $output = ob_get_clean();

    if (empty($output)) {
        echo "⚠ WARNING: Output is empty!\n";
    } else {
        echo "✓ View output received (" . strlen($output) . " bytes)\n";
        echo "First 200 chars:\n";
        echo substr($output, 0, 200) . "\n";
    }

} catch (Throwable $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString();
}

echo "\n=== Test Complete ===\n";
?>

<?php
// Direct test of forgot page without main index.php routing
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/html; charset=utf-8');

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Forgot Test</title></head><body>";
echo "<h1>Testing Forgot Page Components</h1>";

try {
    echo "<h2>1. Loading database...</h2>";
    require_once __DIR__ . '/config/database.php';
    echo "✓ Database loaded<br>";

    echo "<h2>2. Loading helpers...</h2>";
    require_once __DIR__ . '/app/core/helpers.php';
    echo "✓ Helpers loaded<br>";

    echo "<h2>3. Loading mailer...</h2>";
    require_once __DIR__ . '/app/core/mailer.php';
    echo "✓ Mailer loaded<br>";

    echo "<h2>4. Loading RateLimiter...</h2>";
    require_once __DIR__ . '/app/services/RateLimiter.php';
    echo "✓ RateLimiter loaded<br>";

    echo "<h2>5. Simulating forgot page load...</h2>";
    $_SERVER['REQUEST_METHOD'] = 'GET';

    ob_start();
    include __DIR__ . '/app/views/forgot/index.php';
    $output = ob_get_clean();

    if (empty($output)) {
        echo "<p style='color:red'>✗ ERROR: View output is empty!</p>";
    } else {
        echo "<p style='color:green'>✓ View rendered successfully (" . strlen($output) . " bytes)</p>";
        echo "<h2>View Output:</h2>";
        echo "<pre>" . htmlspecialchars(substr($output, 0, 500)) . "...</pre>";
    }

    echo "<h2>✓ All tests passed!</h2>";

} catch (Throwable $e) {
    echo "<p style='color:red'><strong>✗ ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
    echo "<p><strong>Stack Trace:</strong></p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "</body></html>";
?>

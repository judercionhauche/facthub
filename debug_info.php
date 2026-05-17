<?php
header('Content-Type: text/plain');

echo "=== FACTHub 2 Debug Info ===\n\n";

// Test database connection
try {
    require_once __DIR__ . '/config/database.php';
    echo "✓ Database connection OK\n";
} catch (Throwable $e) {
    echo "✗ Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Test helpers
try {
    require_once __DIR__ . '/app/core/helpers.php';
    echo "✓ Helpers loaded OK\n";
} catch (Throwable $e) {
    echo "✗ Helpers failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Test mailer
try {
    require_once __DIR__ . '/app/core/mailer.php';
    echo "✓ Mailer loaded OK\n";
} catch (Throwable $e) {
    echo "✗ Mailer failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Test RateLimiter
try {
    require_once __DIR__ . '/app/services/RateLimiter.php';
    echo "✓ RateLimiter loaded OK\n";
} catch (Throwable $e) {
    echo "✗ RateLimiter failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Test schema updates
try {
    require_once __DIR__ . '/app/core/schema_updates.php';
    echo "✓ Schema updates loaded OK\n";
    apply_security_schema_updates($conn);
    echo "✓ Schema updates applied OK\n";
} catch (Throwable $e) {
    echo "✗ Schema updates failed: " . $e->getMessage() . "\n";
    // Not critical, continue
}

echo "\n✓ All systems operational\n";
?>

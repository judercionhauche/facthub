<?php
/**
 * Diagnostic test for admin panel 500 error
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Admin Panel Diagnostic</h1>";

// Test 1: Session
echo "<h2>Test 1: Session</h2>";
try {
    session_start();
    echo "✓ Session started<br>";
} catch (Throwable $e) {
    echo "✗ Session error: " . $e->getMessage() . "<br>";
}

// Test 2: Database
echo "<h2>Test 2: Database Connection</h2>";
try {
    $dbConfig = require __DIR__ . '/../config/database.php';
    $conn = new mysqli($dbConfig['db_host'], $dbConfig['db_user'], $dbConfig['db_pass'], $dbConfig['db_name']);
    if ($conn->connect_error) {
        echo "✗ Database error: " . $conn->connect_error . "<br>";
    } else {
        echo "✓ Database connected<br>";
    }
} catch (Throwable $e) {
    echo "✗ Database exception: " . $e->getMessage() . "<br>";
}

// Test 3: Core helpers
echo "<h2>Test 3: Core Helpers</h2>";
try {
    require_once __DIR__ . '/../app/core/helpers.php';
    echo "✓ Helpers loaded<br>";
} catch (Throwable $e) {
    echo "✗ Helpers error: " . $e->getMessage() . "<br>";
}

// Test 4: Session Manager
echo "<h2>Test 4: Session Manager</h2>";
try {
    require_once __DIR__ . '/../app/core/session_manager.php';
    echo "✓ Session manager loaded<br>";
} catch (Throwable $e) {
    echo "✗ Session manager error: " . $e->getMessage() . "<br>";
}

// Test 5: Admin check
echo "<h2>Test 5: Admin Authorization</h2>";
try {
    if (function_exists('require_admin')) {
        echo "✓ require_admin() function exists<br>";
    } else {
        echo "✗ require_admin() function NOT found<br>";
    }
} catch (Throwable $e) {
    echo "✗ Admin check error: " . $e->getMessage() . "<br>";
}

// Test 6: ClaudeService
echo "<h2>Test 6: ClaudeService</h2>";
try {
    require_once __DIR__ . '/../app/services/ClaudeService.php';
    echo "✓ ClaudeService loaded<br>";
} catch (Throwable $e) {
    echo "✗ ClaudeService error: " . $e->getMessage() . "<br>";
}

// Test 7: EmbeddingService
echo "<h2>Test 7: EmbeddingService</h2>";
try {
    require_once __DIR__ . '/../app/services/EmbeddingService.php';
    echo "✓ EmbeddingService loaded<br>";
} catch (Throwable $e) {
    echo "✗ EmbeddingService error: " . $e->getMessage() . "<br>";
}

// Test 8: SemanticSearchService
echo "<h2>Test 8: SemanticSearchService</h2>";
try {
    require_once __DIR__ . '/../app/services/SemanticSearchService.php';
    echo "✓ SemanticSearchService loaded<br>";
} catch (Throwable $e) {
    echo "✗ SemanticSearchService error: " . $e->getMessage() . "<br>";
}

// Test 9: Admin view file
echo "<h2>Test 9: Admin View File</h2>";
try {
    $viewFile = __DIR__ . '/../app/views/admin/index.php';
    if (file_exists($viewFile)) {
        echo "✓ Admin view file exists<br>";

        // Check if it has valid PHP syntax (simple check)
        $content = file_get_contents($viewFile);
        if (strpos($content, '<?php') !== false && strpos($content, '?>') !== false) {
            echo "✓ PHP tags found<br>";
        } else {
            echo "⚠ PHP tags not found<br>";
        }
    } else {
        echo "✗ Admin view file NOT found<br>";
    }
} catch (Throwable $e) {
    echo "✗ Admin view error: " . $e->getMessage() . "<br>";
}

echo "<h2>Summary</h2>";
echo "If any tests fail, that's the issue. Check the errors above.";
?>

<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

$pages = ['login', 'researchers', 'funding', 'admin', 'profile', 'account'];
$errors = [];

foreach ($pages as $page) {
    ob_start();
    try {
        $_GET['page'] = $page;
        $_SERVER['REQUEST_METHOD'] = 'GET';

        // Set up minimal session
        session_start();
        $_SESSION['user_id'] = null;

        include __DIR__ . '/public/index.php';
        $output = ob_get_clean();

        if (strlen($output) > 100) {
            echo "✓ $page loaded successfully (" . strlen($output) . " bytes)\n";
        } else {
            echo "⚠ $page returned minimal output (" . strlen($output) . " bytes)\n";
        }
    } catch (Throwable $e) {
        ob_end_clean();
        $errors[$page] = $e->getMessage();
        echo "✗ $page failed: " . $e->getMessage() . "\n";
    }
}

if (empty($errors)) {
    echo "\n✓ All pages loaded without fatal errors!\n";
} else {
    echo "\n✗ Some pages have errors:\n";
    foreach ($errors as $page => $error) {
        echo "  - $page: $error\n";
    }
}
?>

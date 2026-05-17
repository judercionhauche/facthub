<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<plaintext>";
echo "Testing layout files...\n";

try {
    require_once 'config/database.php';
    require_once 'app/core/helpers.php';
    require_once 'app/core/mailer.php';
    require_once 'app/services/RateLimiter.php';

    $_GET['page'] = 'forgot';
    $page = 'forgot';
    $flash = null;

    echo "Loading header...\n";
    ob_start();
    include 'app/views/layout/header.php';
    $header = ob_get_clean();
    echo "Header length: " . strlen($header) . " bytes\n";

    if (strlen($header) < 100) {
        echo "ERROR: Header is too small!\n";
        echo "Content: " . htmlspecialchars($header) . "\n";
    }

    echo "Loading forgot view...\n";
    ob_start();
    include 'app/views/forgot/index.php';
    $view = ob_get_clean();
    echo "View length: " . strlen($view) . " bytes\n";

    if (strlen($view) < 50) {
        echo "ERROR: View is too small!\n";
        echo "Content: " . htmlspecialchars($view) . "\n";
    }

    echo "Loading footer...\n";
    ob_start();
    include 'app/views/layout/footer.php';
    $footer = ob_get_clean();
    echo "Footer length: " . strlen($footer) . " bytes\n";

    if (strlen($footer) < 100) {
        echo "ERROR: Footer is too small!\n";
        echo "Content: " . htmlspecialchars($footer) . "\n";
    }

    $total = strlen($header) + strlen($view) + strlen($footer);
    echo "\nTotal page length: $total bytes\n";
    echo "Status: OK\n";

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

?>

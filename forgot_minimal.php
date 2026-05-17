<?php
// Minimal version to test forgot page flow
require_once 'config/database.php';
require_once 'app/core/helpers.php';
require_once 'app/core/mailer.php';
require_once 'app/services/RateLimiter.php';

$submitted = false;
$resetLink = null;
$error_message = null;

echo "REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD'] . "\n";
echo "submitted: " . ($submitted ? 'true' : 'false') . "\n";

if (!$submitted) {
    echo "Should show form\n";
} else {
    echo "Should show success message\n";
}
?>

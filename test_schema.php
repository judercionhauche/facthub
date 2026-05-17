<?php
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/app/core/schema_updates.php';

    echo json_encode([
        'status' => 'ok',
        'message' => 'Database connection successful',
        'applying_schema' => 'in progress'
    ]);

    apply_security_schema_updates($conn);

    echo json_encode([
        'status' => 'ok',
        'message' => 'Schema updates applied successfully'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
?>

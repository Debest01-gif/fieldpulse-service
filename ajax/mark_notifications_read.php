<?php
// AJAX: Mark all notifications as read
require_once __DIR__ . '/../config/db.php';
require_auth();

header('Content-Type: application/json');

try {
    $db = get_db();
    $db->exec("UPDATE notifications SET is_read = 1 WHERE is_read = 0");
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

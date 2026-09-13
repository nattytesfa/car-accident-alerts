<?php
/*
 * mark_notifications.php
 * Marks notifications as delivered once the bridge has sent them.
 *
 * POST params: ids (comma-separated integers, e.g. "1,2,3")
 */

require 'db.php';

header('Content-Type: application/json');

$ids = preg_replace('/[^0-9,]/', '', isset($_POST['ids']) ? $_POST['ids'] : '');

if ($ids === '') {
    echo json_encode(['status' => 'error', 'message' => 'No ids']);
    exit;
}

$conn->query("UPDATE hospital_notifications SET delivered = 1 WHERE id IN ($ids)");
echo json_encode(['status' => 'ok', 'message' => 'Marked delivered']);
?>
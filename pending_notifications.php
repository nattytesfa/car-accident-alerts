<?php
/*
 * pending_notifications.php
 * Returns unsent hospital approval/rejection notifications.
 * Consumed by telegram_bridge.py.
 *
 * GET → [{"id":..,"hospital":"..","chat_id":"..","type":"approved|rejected"}, ...]
 */

require 'db.php';

header('Content-Type: application/json');

$result = $conn->query("SELECT id, hospital, chat_id, type FROM hospital_notifications
                        WHERE delivered = 0 ORDER BY id ASC");
$notifications = [];

while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}

echo json_encode($notifications);
?>
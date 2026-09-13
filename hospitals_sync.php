<?php
/*
 * hospitals_sync.php
 * Returns ONLY the admin-approved hospital list as JSON.
 * Used by telegram_bridge.py to push approved hospitals to the Arduino.
 *
 * GET  → [{"name":"...","lat":..,"lng":..,"chat_id":".."}, ...]
 */

require 'db.php';

header('Content-Type: application/json');

$result = $conn->query("SELECT name, lat, lng, chat_id FROM hospitals WHERE status = 'approved' ORDER BY name ASC");
$hospitals = [];

while ($row = $result->fetch_assoc()) {
    $hospitals[] = [
        'name' => $row['name'],
        'lat' => (float)$row['lat'],
        'lng' => (float)$row['lng'],
        'chat_id' => $row['chat_id'] ?? '',
    ];
}

echo json_encode($hospitals);
?>
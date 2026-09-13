<?php
/*
 * register_hospital.php
 * Stores a hospital (name, lat, lng, chat_id) in the `hospitals` table.
 * Called by telegram_bridge.py when a hospital registers via the Telegram bot.
 *
 * New / re-registered hospitals are saved as 'pending' and must be
 * approved by an admin on the dashboard before the Arduino receives them.
 *
 * POST params: name, lat, lng, chat_id (optional)
 * Upserts on unique `name`.
 */

require 'db.php';

header('Content-Type: application/json');

function respond($ok, $message, $code = 200) {
    http_response_code($code);
    echo json_encode(['status' => $ok ? 'ok' : 'error', 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Use POST for this endpoint', 405);
}

$name = isset($_POST['name']) ? trim($_POST['name']) : '';
$lat = isset($_POST['lat']) ? trim($_POST['lat']) : '';
$lng = isset($_POST['lng']) ? trim($_POST['lng']) : '';
$chat_id = isset($_POST['chat_id']) ? trim($_POST['chat_id']) : '';

if ($name === '' || $lat === '' || $lng === '') {
    respond(false, 'Missing required fields: name, lat, lng', 400);
}

if (!is_numeric($lat) || !is_numeric($lng)) {
    respond(false, 'lat and lng must be numeric', 400);
}

if ((float)$lat < -90 || (float)$lat > 90 || (float)$lng < -180 || (float)$lng > 180) {
    respond(false, 'lat/lng out of range', 400);
}

$name = substr($name, 0, 100);
$chat_id = substr($chat_id, 0, 50);

$stmt = $conn->prepare("INSERT INTO hospitals (name, lat, lng, chat_id, status)
                        VALUES (?, ?, ?, ?, 'pending')
                        ON DUPLICATE KEY UPDATE lat = VALUES(lat), lng = VALUES(lng),
                                                chat_id = VALUES(chat_id), status = 'pending'");
$stmt->bind_param("sdds", $name, $lat, $lng, $chat_id);

if ($stmt->execute()) {
    respond(true, 'Hospital saved and sent for admin approval', 200);
} else {
    respond(false, 'Database error', 500);
}
?>
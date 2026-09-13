<?php
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "accident_alerts";

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Username allowed to approve hospitals via the dashboard.
define('ADMIN_USERNAME', 'admin');

function current_username($conn) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    $stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $stmt->bind_result($username);
    $stmt->fetch();
    $stmt->close();
    return $username;
}

function is_admin($conn) {
    return current_username($conn) === ADMIN_USERNAME;
}
?>
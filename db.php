<?php
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "accident_alerts";

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
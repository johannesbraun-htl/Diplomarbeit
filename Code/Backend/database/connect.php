<?php
$servername = "91.151.18.23";
$username   = "h109556_admin_v2";
$password   = "!PresentAI";
$dbname     = "h109556_presentai_v2";
$port       = 3307;

$conn = new mysqli($servername, $username, $password, $dbname, $port);

if ($conn->connect_error) {
    die("Verbindung fehlgeschlagen: " . $conn->connect_error);
}
?>

<?php
// Code/Backend/database/connect.php
// Enthält KEINE echten Passwörter, lädt nur lokale Konfiguration.

$configLocal = __DIR__ . '/connect.local.php';

if (!file_exists($configLocal)) {
    die('DB connect.local.php missing – please create Code/Backend/database/connect.local.php');
}

require_once $configLocal;

// Erwartet Variablen aus connect.local.php:
// $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $PORT

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $PORT);

if ($conn->connect_error) {
    die("Verbindung fehlgeschlagen: " . $conn->connect_error);
}

$conn->set_charset('utf8mb4');

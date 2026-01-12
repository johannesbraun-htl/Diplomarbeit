<?php
// Code/Backend/database/connect.php

$configLocal = __DIR__ . '/connect.local.php';

if (!file_exists($configLocal)) {
    die('DB connect.local.php missing - please create Code/Backend/database/connect.local.php');
}

require_once $configLocal;

// Erwartet Variablen aus connect.local.php:
// $DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $PORT

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $PORT);

if ($mysqli->connect_error) {
    die('Database connection failed: ' . $mysqli->connect_error);
}

$mysqli->set_charset('utf8mb4');

// ✅ Kompatibilität: dein Projekt nutzt überall $conn
$conn = $mysqli;

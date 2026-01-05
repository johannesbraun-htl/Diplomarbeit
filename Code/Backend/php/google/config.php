<?php
// Code/Backend/php/google/config.php
// Lädt die lokalen, nicht getrackten Secrets

$configLocal = __DIR__ . '/config.local.php';

if (!file_exists($configLocal)) {
    die('config.local.php missing – please create it on this server.');
}

require_once $configLocal;

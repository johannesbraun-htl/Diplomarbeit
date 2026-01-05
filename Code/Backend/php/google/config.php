<?php
// Code/Backend/php/google/config.php
// Diese Datei enthält KEINE echten Secrets.
// Sie lädt nur die lokale, ignorierte Konfigurationsdatei.

$configLocal = __DIR__ . '/config.local.php';

if (!file_exists($configLocal)) {
    die('Google config.local.php missing – please create Code/Backend/php/google/config.local.php');
}

require_once $configLocal;

<?php
// Frontend/api/analyse.php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$path = __DIR__ . '/../../daten_von_ki/analyse.json'; // <-- eine Ebene über Frontend/
if (!is_readable($path)) {
  http_response_code(404);
  echo json_encode(['error' => 'analyse.json not found']);
  exit;
}
readfile($path);

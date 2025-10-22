<?php
// Frontend/api/db.php
// Feste Verbindungsdaten (deine Angaben)
$db_host = getenv('DB_HOST') ?: '91.151.18.23';
$db_name = getenv('DB_NAME') ?: 'h109556_presentai';
$db_user = getenv('DB_USER') ?: 'h109556_admin';
$db_pass = getenv('DB_PASS') ?: '!PresentAI';
$db_port = getenv('DB_PORT') ?: '3307';

$dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";

try {
  $pdo = new PDO($dsn, $db_user, $db_pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_TIMEOUT            => 5, // Sekunden
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'error'   => 'DB connection failed',
    'details' => $e->getMessage(),
    'host'    => $db_host, 'port' => $db_port, 'db' => $db_name, 'user' => $db_user
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

/** "HH:MM:SS" -> Sekunden (int) */
function time_to_seconds(string $hhmmss): int {
  if (!preg_match('/^(\d{1,2}):(\d{2}):(\d{2})$/', $hhmmss, $m)) return 0;
  return (int)$m[1]*3600 + (int)$m[2]*60 + (int)$m[3];
}

/** 123.4 -> "MM:SS" */
function mmss(int|float $seconds): string {
  $s = max(0, (int)round($seconds));
  $m = intdiv($s, 60);
  $r = $s % 60;
  return sprintf('%02d:%02d', $m, $r);
}

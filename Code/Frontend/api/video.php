<?php
// video.php — streamt den Video-BLOB aus der Datenbank mit Range-Unterstützung
declare(strict_types=1);

// === DB-Verbindung ===
$dsn  = 'mysql:host=91.151.18.23;port=3307;dbname=h109556_presentai;charset=utf8mb4';
$user = 'h109556_admin';
$pass = '!PresentAI';

// === Header vorbereiten ===
header('Cache-Control: no-store');
header('Accept-Ranges: bytes');

$pid  = isset($_GET['pid'])  ? (int)$_GET['pid']  : 0;
$hash = isset($_GET['hash']) ? trim((string)$_GET['hash']) : '';

if ($pid <= 0 && $hash === '') {
  http_response_code(400);
  echo 'Missing pid or hash';
  exit;
}

try {
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);

  // === Video aus DB laden ===
  if ($pid > 0) {
    $stmt = $pdo->prepare("SELECT video FROM presentations WHERE presentations_id = :pid LIMIT 1");
    $stmt->execute([':pid' => $pid]);
  } else {
    $stmt = $pdo->prepare("SELECT video FROM presentations WHERE video_hash = :h LIMIT 1");
    $stmt->execute([':h' => $hash]);
  }

  $row = $stmt->fetch();
  if (!$row || $row['video'] === null) {
    http_response_code(404);
    echo 'Video not found';
    exit;
  }

  $blob = $row['video'];
  $size = strlen($blob);
  if ($size === 0) {
    http_response_code(404);
    echo 'Empty video';
    exit;
  }

  // === MIME-Typ erkennen ===
  $head = substr($blob, 0, 32);
  $mime = 'video/mp4';
  if (strpos($head, 'webm') !== false) $mime = 'video/webm';
  elseif (strpos($head, 'OggS') !== false) $mime = 'video/ogg';

  // === Range-Header verarbeiten ===
  $start = 0;
  $end   = $size - 1;
  $code  = 200;

  if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/i', $_SERVER['HTTP_RANGE'], $m)) {
    if ($m[1] !== '') $start = (int)$m[1];
    if ($m[2] !== '') $end   = (int)$m[2];
    if ($start > $end || $start >= $size) {
      header("Content-Range: bytes */$size");
      http_response_code(416);
      exit;
    }
    $code = 206;
  }

  $length = $end - $start + 1;
  if ($code === 206) header("Content-Range: bytes $start-$end/$size");

  header("Content-Type: $mime");
  header("Content-Length: $length");
  http_response_code($code);

  // === Ausgabe in Chunks (8 KB) ===
  $chunk = 8192;
  $pos = $start;
  while ($pos <= $end) {
    $take = min($chunk, $end - $pos + 1);
    echo substr($blob, $pos, $take);
    $pos += $take;
  }

} catch (Throwable $e) {
  http_response_code(500);
  echo 'Server error: ' . $e->getMessage();
}

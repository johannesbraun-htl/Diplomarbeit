<?php
// Code/Backend/php/home/stream_video.php

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

require "../../database/connect.php";
require "../google/config.php";

// Tabellen (wie in upload_presentation.php)
$TBL_PRESENTATIONS = "`h109556_presentai_v2`.`presentations`";
$TBL_VIDEOS        = "`h109556_presentai_v2`.`videos`";
$TBL_TOKENS        = "`h109556_presentai_v2`.`google_tokens`";

function fail($httpCode, $msg) {
  http_response_code($httpCode);
  header("Content-Type: text/plain; charset=utf-8");
  echo $msg;
  exit();
}

function extractDriveId($url) {
  if (!$url) return null;

  if (preg_match('~drive\.google\.com/file/d/([^/]+)~', $url, $m)) {
    return $m[1];
  }

  $parts = parse_url($url);
  if (!empty($parts['query'])) {
    parse_str($parts['query'], $q);
    if (!empty($q['id'])) return $q['id'];
  }
  return null;
}

/* ===================== Token-Handling (wie Upload) ===================== */

function get_token_row($conn, $TBL_TOKENS) {
  $res = $conn->query("SELECT * FROM $TBL_TOKENS LIMIT 1");
  if ($res && $res->num_rows === 1) return $res->fetch_assoc();
  return null;
}

function refresh_access_token_db($conn, $TBL_TOKENS, $refreshToken) {
  $post = http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'refresh_token' => $refreshToken,
    'grant_type'    => 'refresh_token'
  ]);

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => 'https://oauth2.googleapis.com/token',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $post,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
  ]);

  $resp = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($resp === false || $http < 200 || $http >= 300) return null;

  $j = json_decode($resp, true);
  if (empty($j['access_token'])) return null;

  $accessToken = $j['access_token'];
  $expiresAt   = time() + (int)($j['expires_in'] ?? 3600);

  $stmt = $conn->prepare("UPDATE $TBL_TOKENS SET access_token=?, expires_at=?");
  $stmt->bind_param("si", $accessToken, $expiresAt);
  $stmt->execute();
  $stmt->close();

  return $accessToken;
}

function ensure_google_access_token($conn, $TBL_TOKENS) {
  $row = get_token_row($conn, $TBL_TOKENS);
  if (!$row) return null;

  $expiresAt = (int)($row['expires_at'] ?? 0);
  $access    = $row['access_token'] ?? '';
  $refresh   = $row['refresh_token'] ?? '';

  if ($access && $expiresAt && time() < ($expiresAt - 60)) {
    return $access;
  }

  if (!$refresh) return null;

  return refresh_access_token_db($conn, $TBL_TOKENS, $refresh);
}

/* ===================== Input + Permission ===================== */

if (empty($_SESSION['user_id'])) {
  fail(401, "Keine Session.");
}

if (empty($_GET['id']) || !ctype_digit($_GET['id'])) {
  fail(400, "Fehlende oder ungültige Präsentations-ID.");
}

$presentationId = (int)$_GET['id'];
$userId = (int)$_SESSION['user_id'];

// Prüfen: gehört dem User?
$stmt = $conn->prepare("SELECT presentations_id FROM $TBL_PRESENTATIONS WHERE presentations_id=? AND fk_user_id=? LIMIT 1");
$stmt->bind_param("ii", $presentationId, $userId);
$stmt->execute();
$r = $stmt->get_result();
$ok = ($r && $r->num_rows === 1);
$stmt->close();

if (!$ok) {
  fail(403, "Keine Berechtigung oder Präsentation existiert nicht.");
}

// Video-Info holen
$stmt = $conn->prepare("SELECT drive_file_id, drive_web_view_link FROM $TBL_VIDEOS WHERE fk_presentations_id=? LIMIT 1");
$stmt->bind_param("i", $presentationId);
$stmt->execute();
$r = $stmt->get_result();
$vid = $r ? $r->fetch_assoc() : null;
$stmt->close();

if (!$vid) {
  fail(404, "Kein Video zur Präsentation gefunden.");
}

$driveFileId = $vid['drive_file_id'] ?? null;
$webViewLink = $vid['drive_web_view_link'] ?? null;

if (!$driveFileId && $webViewLink) {
  $driveFileId = extractDriveId($webViewLink);
}

if (!$driveFileId) {
  fail(404, "Drive File ID fehlt.");
}

$accessToken = ensure_google_access_token($conn, $TBL_TOKENS);
if (!$accessToken) {
  fail(500, "Google Drive ist nicht verbunden (Token fehlt/ungültig).");
}

/* ===================== Metadata (mimeType + size) ===================== */

$metaUrl = "https://www.googleapis.com/drive/v3/files/" . rawurlencode($driveFileId) . "?fields=mimeType,size,name";
$ch = curl_init();
curl_setopt_array($ch, [
  CURLOPT_URL => $metaUrl,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => [
    "Authorization: Bearer $accessToken",
    "Accept: application/json"
  ]
]);
$metaResp = curl_exec($ch);
$metaHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($metaResp === false || $metaHttp < 200 || $metaHttp >= 300) {
  fail(502, "Drive Metadata Fehler (HTTP $metaHttp).");
}

$meta = json_decode($metaResp, true);
$mimeType = $meta['mimeType'] ?? "video/mp4";
$size = isset($meta['size']) ? (int)$meta['size'] : 0;

if ($size <= 0) {
  fail(502, "Ungültige Dateigröße von Drive.");
}

/* ===================== Range Parsing ===================== */

$rangeHeader = $_SERVER['HTTP_RANGE'] ?? null;
$start = 0;
$end = $size - 1;
$statusCode = 200;

if ($rangeHeader && preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $m)) {
  $statusCode = 206;

  if ($m[1] !== '') $start = (int)$m[1];
  if ($m[2] !== '') $end = (int)$m[2];

  if ($start < 0) $start = 0;
  if ($end > $size - 1) $end = $size - 1;

  if ($start > $end) {
    header("Content-Range: bytes */$size");
    fail(416, "Ungültiger Range.");
  }
}

$length = ($end - $start) + 1;

/* ===================== Output Headers ===================== */

http_response_code($statusCode);
header("Content-Type: $mimeType");
header("Accept-Ranges: bytes");
header("Content-Length: $length");
header("Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate");
header("Pragma: no-cache");

if ($statusCode === 206) {
  header("Content-Range: bytes $start-$end/$size");
}

/* ===================== Stream from Drive (alt=media) ===================== */

$mediaUrl = "https://www.googleapis.com/drive/v3/files/" . rawurlencode($driveFileId) . "?alt=media";

$ch = curl_init();
curl_setopt_array($ch, [
  CURLOPT_URL => $mediaUrl,
  CURLOPT_RETURNTRANSFER => false,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTPHEADER => array_filter([
    "Authorization: Bearer $accessToken",
    "Range: bytes=$start-$end"
  ]),
  CURLOPT_WRITEFUNCTION => function($ch, $data) {
    echo $data;
    flush();
    return strlen($data);
  }
]);

$ok = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($ok === false) {
  // Hier können wir nix mehr sauber als JSON ausgeben, weil schon gestreamt wurde.
  // Trotzdem: im Log wäre der Error sichtbar.
  // (Optional) error_log($err);
  exit();
}

// Drive liefert bei Range normalerweise 206.
// Wenn Drive 200 liefert, ist es auch ok, da wir trotzdem Content-Length etc. gesetzt haben.
exit();

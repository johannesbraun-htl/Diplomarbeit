<?php
header("Content-Type: application/json; charset=utf-8");

function fail($code, $msg) {
  http_response_code($code);
  echo json_encode(["error" => $msg], JSON_UNESCAPED_UNICODE);
  exit();
}

$videoPath = isset($_GET["video_path"]) ? $_GET["video_path"] : "";
if ($videoPath === "") fail(400, "Missing video_path");

// uploads folder auto-create
$uploadsDir = __DIR__ . "/../uploads";
if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0777, true);

$allowedBase = realpath($uploadsDir);
$realVideo   = realpath($videoPath);

if ($allowedBase === false) fail(500, "uploads folder not found: " . $uploadsDir);
if ($realVideo === false) fail(404, "Video not found");
if (strpos($realVideo, $allowedBase) !== 0) fail(403, "Video path not allowed (must be inside Tests/uploads)");

// return a job (no cache)
$baseUrl = rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/\\") . "/";
$startUrl = $baseUrl . "start_job.php?video_path=" . urlencode($realVideo);

// start job by internal include style (call start_job logic directly would duplicate code)
// simplest: tell client to call start_job.php
echo json_encode([
  "mode" => "job",
  "start_url" => $startUrl
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

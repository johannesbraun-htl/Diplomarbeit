<?php
header("Content-Type: application/json; charset=utf-8");

function fail($code, $msg) {
  http_response_code($code);
  echo json_encode(["error" => $msg], JSON_UNESCAPED_UNICODE);
  exit();
}

$videoPath = isset($_GET["video_path"]) ? $_GET["video_path"] : "";
if ($videoPath === "") fail(400, "Missing video_path");

$uploadsDir = __DIR__ . "/../uploads";
if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0777, true);

$allowedBase = realpath($uploadsDir);
$realVideo   = realpath($videoPath);

if ($allowedBase === false) fail(500, "uploads folder not found: " . $uploadsDir);
if ($realVideo === false) fail(404, "Video not found");
if (strpos($realVideo, $allowedBase) !== 0) fail(403, "Video path not allowed (must be inside Tests/uploads)");

$configFile = __DIR__ . "/config_openai.php";
if (!file_exists($configFile)) fail(500, "Missing config_openai.php");
$config = require $configFile;

$openaiKey = isset($config["OPENAI_API_KEY"]) ? trim($config["OPENAI_API_KEY"]) : "";
$model     = isset($config["OPENAI_MODEL"]) ? trim($config["OPENAI_MODEL"]) : "gpt-4o-mini";
if ($openaiKey === "" || $openaiKey === "PASTE_DEINEN_KEY_HIER") fail(500, "OPENAI_API_KEY missing in config_openai.php");

$pyExe = "C:\\Windows\\py.exe";
if (!file_exists($pyExe)) fail(500, "py.exe not found: " . $pyExe);

$script = realpath(__DIR__ . "/../ai/analyze_and_feedback.py");
if ($script === false) fail(500, "Python script not found: " . (__DIR__ . "/../ai/analyze_and_feedback.py"));

$jobsDir = __DIR__ . "/../jobs";
if (!is_dir($jobsDir)) @mkdir($jobsDir, 0777, true);
$jobsDirReal = realpath($jobsDir);
if ($jobsDirReal === false) fail(500, "jobs dir missing: " . $jobsDir);

$jobId = bin2hex(random_bytes(8));
$jobPath = $jobsDirReal . "\\" . $jobId;
@mkdir($jobPath, 0777, true);

$statusFile = $jobPath . "\\status.json";
$outFile    = $jobPath . "\\result.json";
$errFile    = $jobPath . "\\error.txt";

file_put_contents($statusFile, json_encode([
  "state" => "queued",
  "percent" => 0,
  "message" => "Warteschlange...",
  "ts" => time()
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

$userProfile = "C:\\Users\\johan";
$mplConfigDir = realpath(__DIR__ . "/../") . "\\.mplconfig";
$tmpDir = realpath(__DIR__ . "/../") . "\\.tmp";
@mkdir($mplConfigDir, 0777, true);
@mkdir($tmpDir, 0777, true);

putenv("OPENAI_API_KEY=".$openaiKey);
putenv("OPENAI_MODEL=".$model);
putenv("USERPROFILE=".$userProfile);
putenv("HOME=".$userProfile);
putenv("MPLCONFIGDIR=".$mplConfigDir);
putenv("TEMP=".$tmpDir);
putenv("TMP=".$tmpDir);

// allgemeine Defaults (funktionieren fuer Full-Body und Upper-Body)
$sample = 6;
$maxSeconds = 35;
$cacheMinutes = 0;

$cmdInner =
  "\"" . $pyExe . "\" -3.12 " .
  "\"" . $script . "\" " .
  "--video " . "\"" . $realVideo . "\" " .
  "--sample " . $sample . " " .
  "--max_seconds " . $maxSeconds . " " .
  "--cache_minutes " . $cacheMinutes . " " .
  "--out " . "\"" . $outFile . "\" " .
  "--status " . "\"" . $statusFile . "\" " .
  "--error " . "\"" . $errFile . "\"";

$cmd = "cmd /c start \"\" /B " . $cmdInner;
@pclose(@popen($cmd, "r"));

echo json_encode(["job_id" => $jobId], JSON_UNESCAPED_UNICODE);

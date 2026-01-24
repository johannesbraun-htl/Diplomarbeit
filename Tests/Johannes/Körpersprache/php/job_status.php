<?php
header("Content-Type: application/json; charset=utf-8");

function fail($code, $msg) {
  http_response_code($code);
  echo json_encode(["error" => $msg], JSON_UNESCAPED_UNICODE);
  exit();
}

$jobId = isset($_GET["job_id"]) ? preg_replace("/[^a-f0-9]/", "", $_GET["job_id"]) : "";
if ($jobId === "" || strlen($jobId) < 8) fail(400, "Missing/invalid job_id");

$jobPath = realpath(__DIR__ . "/../jobs/" . $jobId);
if ($jobPath === false) fail(404, "Job not found");

$statusFile = $jobPath . "\\status.json";
$outFile    = $jobPath . "\\result.json";
$errFile    = $jobPath . "\\error.txt";

$status = ["state" => "unknown", "percent" => 0, "message" => "No status"];
if (file_exists($statusFile)) {
  $txt = file_get_contents($statusFile);
  $decoded = json_decode($txt, true);
  if (is_array($decoded)) $status = $decoded;
}

$response = ["status" => $status];

if (($status["state"] ?? "") === "done" && file_exists($outFile)) {
  $outTxt = file_get_contents($outFile);
  $out = json_decode($outTxt, true);
  if (is_array($out)) $response["result"] = $out;
}

if (($status["state"] ?? "") === "error") {
  if (file_exists($errFile)) {
    $response["stderr"] = substr(file_get_contents($errFile), -4000);
  }
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

<?php
session_start();
require "../../database/connect.php";
require "../google/config.php";

$FILE_FIELD = 'presentationFile';

$TBL_PRESENTATIONS = "`h109556_presentai_v2`.`presentations`";
$TBL_VIDEOS        = "`h109556_presentai_v2`.`videos`";
$TBL_TOKENS        = "`h109556_presentai_v2`.`google_tokens`";

function is_ajax() {
    return (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (!empty($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
}

function json_out($httpCode, array $payload) {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit();
}

function fail($msg, $httpCode = 400) {
    if (is_ajax()) {
        json_out($httpCode, ["ok" => false, "message" => $msg]);
    }
    $_SESSION['error'] = $msg;
    header("Location: ../../../Frontend/main.php");
    exit();
}

/**
 * WICHTIG: KEIN Login/Redirect mehr!
 * Wenn Token fehlt: einfach Fehler.
 */
function google_not_connected() {
    fail("Google Drive ist nicht verbunden (Admin-Token fehlt). Bitte im Admin-Setup erneut verbinden.", 500);
}

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

function drive_json_request($method, $url, $accessToken, $jsonBody = null) {
    $ch = curl_init();

    $headers = [
        "Authorization: Bearer $accessToken",
        "Accept: application/json"
    ];

    if ($jsonBody !== null) {
        $headers[] = "Content-Type: application/json; charset=UTF-8";
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers
    ]);

    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$http, $resp];
}

function drive_get_or_create_folder($accessToken, $folderName, $parentId = null) {
    $safeName = str_replace("'", "\\'", $folderName);

    $q = "mimeType='application/vnd.google-apps.folder' and name='{$safeName}' and trashed=false";
    if ($parentId) {
        $q .= " and '{$parentId}' in parents";
    }

    $url = "https://www.googleapis.com/drive/v3/files?q=" . urlencode($q) . "&fields=files(id,name)&spaces=drive";
    [$http, $resp] = drive_json_request("GET", $url, $accessToken);

    if ($http >= 200 && $http < 300) {
        $j = json_decode($resp, true);
        if (!empty($j["files"][0]["id"])) return $j["files"][0]["id"];
    }

    $meta = [
        "name" => $folderName,
        "mimeType" => "application/vnd.google-apps.folder"
    ];
    if ($parentId) $meta["parents"] = [$parentId];

    [$http2, $resp2] = drive_json_request(
        "POST",
        "https://www.googleapis.com/drive/v3/files?fields=id",
        $accessToken,
        json_encode($meta)
    );

    $j2 = json_decode($resp2, true);
    if ($http2 < 200 || $http2 >= 300 || empty($j2["id"])) {
        throw new Exception("Drive Ordner Fehler | HTTP $http2 | Body: " . substr($resp2, 0, 600));
    }

    return $j2["id"];
}

function drive_start_resumable($accessToken, $fileName, $mimeType, $totalBytes, $parentFolderId) {
    $meta = json_encode([
        "name" => $fileName,
        "parents" => [$parentFolderId]
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id,webViewLink",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $meta,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $accessToken",
            "Content-Type: application/json; charset=UTF-8",
            "X-Upload-Content-Type: $mimeType",
            "X-Upload-Content-Length: $totalBytes"
        ]
    ]);

    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception("Resumable INIT cURL Fehler: $err");
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($resp, 0, $hdrSize);
    $body    = substr($resp, $hdrSize);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        throw new Exception("Resumable INIT Fehler | HTTP $code | Body: " . substr($body, 0, 700));
    }

    $location = "";
    foreach (explode("\n", $headers) as $line) {
        $line = trim($line);
        if (stripos($line, "Location:") === 0) {
            $location = trim(substr($line, 9));
            break;
        }
    }
    if (!$location) throw new Exception("Resumable INIT: Location Header fehlt.");

    return $location;
}

function drive_put_chunk($accessToken, $sessionUrl, $bytes, $start, $end, $total, $mimeType) {
    $contentRange = "bytes $start-$end/$total";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $sessionUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => "PUT",
        CURLOPT_POSTFIELDS => $bytes,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $accessToken",
            "Content-Type: $mimeType",
            "Content-Range: $contentRange"
        ]
    ]);

    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception("Chunk PUT cURL Fehler: $err");
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body    = substr($resp, $hdrSize);
    curl_close($ch);

    return [$code, $body];
}

/* ===================== AUTH / SESSION ===================== */

if (empty($_SESSION['user_id'])) {
    fail("Keine Session.", 401);
}

// Admin-Token aus DB holen/refreshen (kein Login-Flow mehr)
$accessToken = ensure_google_access_token($conn, $TBL_TOKENS);
if (!$accessToken) {
    google_not_connected();
}

/* ===================== INPUT CHECKS ===================== */

$title = trim($_POST['title'] ?? '');
if ($title === '') fail("Titel fehlt.", 400);

if (!isset($_FILES[$FILE_FIELD]) || $_FILES[$FILE_FIELD]['error'] !== UPLOAD_ERR_OK) {
    fail("Kein Video hochgeladen.", 400);
}

$tmpPath  = $_FILES[$FILE_FIELD]['tmp_name'];
$origName = $_FILES[$FILE_FIELD]['name'];

if (strtolower(pathinfo($origName, PATHINFO_EXTENSION)) !== 'mp4') {
    fail("Nur MP4 erlaubt.", 400);
}

$fileSize = filesize($tmpPath);
if ($fileSize <= 0) fail("Ungültige Dateigröße.", 400);

/* ===================== DB + UPLOAD ===================== */

$conn->begin_transaction();

try {
    // Präsentation in DB anlegen
    $stmt = $conn->prepare("INSERT INTO $TBL_PRESENTATIONS (fk_user_id, titel) VALUES (?, ?)");
    $stmt->bind_param("is", $_SESSION['user_id'], $title);
    $stmt->execute();
    $presentationId = $stmt->insert_id;
    $stmt->close();

    // Drive Ordnerstruktur:
    // "PresentAI Uploads" / "<presentationId>" / "original.mp4"
    $rootFolderId = drive_get_or_create_folder($accessToken, DRIVE_ROOT_FOLDER_NAME);
    $pidFolderId  = drive_get_or_create_folder($accessToken, (string)$presentationId, $rootFolderId);

    // Resumable Session direkt im Zielordner
    $sessionUrl = drive_start_resumable($accessToken, "original.mp4", "video/mp4", $fileSize, $pidFolderId);

    // Chunk Upload
    $chunkSize = 5 * 1024 * 1024; // 5MB
    $fh = fopen($tmpPath, "rb");
    $offset = 0;

    while (!feof($fh)) {
        $chunk = fread($fh, $chunkSize);
        if ($chunk === '' || $chunk === false) break;

        $len = strlen($chunk);
        $start = $offset;
        $end   = $offset + $len - 1;

        [$code, $body] = drive_put_chunk($accessToken, $sessionUrl, $chunk, $start, $end, $fileSize, "video/mp4");

        if ($code === 308) {
            $offset += $len;
            continue;
        }

        if ($code === 200 || $code === 201) {
            $out = json_decode($body, true);
            if (!is_array($out) || empty($out["id"])) {
                throw new Exception("Drive COMPLETE: ungültige JSON | HTTP $code | Body: " . substr($body, 0, 700));
            }

            $fileId  = $out["id"];
            $webView = $out["webViewLink"] ?? "";

            // Video speichern
            $stmt = $conn->prepare(
                "INSERT INTO $TBL_VIDEOS
                 (fk_presentations_id, original_filename, drive_file_id, drive_web_view_link)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->bind_param("isss", $presentationId, $origName, $fileId, $webView);
            $stmt->execute();
            $stmt->close();

            fclose($fh);
            $conn->commit();

            if (is_ajax()) {
                json_out(200, ["ok" => true, "presentationId" => $presentationId]);
            }

            header("Location: ../../../Frontend/main.php?upload=ok");
            exit();
        }

        throw new Exception("Drive PUT Fehler | HTTP $code | Body: " . substr($body, 0, 700));
    }

    fclose($fh);
    throw new Exception("Upload unvollständig.");

} catch (Exception $e) {
    $conn->rollback();
    fail($e->getMessage(), 500);
}

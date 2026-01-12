<?php
// Code/Backend/php/admin_drive_setup.php
session_start();

require_once __DIR__ . "/../database/connect.php";
require_once __DIR__ . "/google/config.php";

// Falls irgendwo $conn nicht existiert:
if (!isset($conn) && isset($mysqli)) $conn = $mysqli;

/* =========================
   ✅ HIER EINSTELLEN
   ========================= */
const SETUP_LINK_KEY = '4749bda5cd8b60c8646dbcee645dfe2b07a382b6f10cc4e2'; // Zufälliger Key, z.B. mit `bin2hex(random_bytes(20))` generieren
const SETUP_PASSWORD_HASH = '$2y$12$1pVd/Q./TS5GtHRRoPacpuEj.sBG/5.NsaDhoRYe7eBQEOuCVLq.y'; // Passwort-Hash 

/* =========================
   Helper
   ========================= */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function base_url_this_file_no_query(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path   = strtok($_SERVER['REQUEST_URI'] ?? '', '?'); // ohne Query
    return $scheme . '://' . $host . $path;
}

function setup_url(): string {
    // zurück zur Seite MIT Link-Key
    $path = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
    return $path . '?k=' . urlencode(SETUP_LINK_KEY);
}

function flash_set($type, $msg) {
    $_SESSION['drive_setup_flash'][$type] = $msg;
}

function flash_get() {
    $f = $_SESSION['drive_setup_flash'] ?? [];
    unset($_SESSION['drive_setup_flash']);
    return $f;
}

function is_authed(): bool {
    $ok = $_SESSION['drive_setup_ok'] ?? false;
    $exp = (int)($_SESSION['drive_setup_ok_until'] ?? 0);
    return $ok && $exp > time();
}

function require_authed_or_die() {
    if (!is_authed()) {
        http_response_code(403);
        die("Nicht eingeloggt oder Session abgelaufen. Bitte Seite neu öffnen und Passwort eingeben.");
    }
}

function token_table(mysqli $conn): string {
    $db = '';
    $res = $conn->query("SELECT DATABASE() AS db");
    if ($res) {
        $row = $res->fetch_assoc();
        $db = $row['db'] ?? '';
    }
    if ($db) {
        $db = str_replace("`","``",$db);
        return "`$db`.`google_tokens`";
    }
    return "`google_tokens`";
}

function table_has_column(mysqli $conn, string $tbl, string $col): bool {
    $colEsc = str_replace("`","``",$col);
    $q = "SHOW COLUMNS FROM $tbl LIKE '$colEsc'";
    $res = $conn->query($q);
    return ($res && $res->num_rows > 0);
}

function ensure_tokens_schema(mysqli $conn): array {
    $tbl = token_table($conn);

    // Tabelle anlegen (id optional, aber wir legen sie so an, dass id=1 existiert)
    $conn->query("
        CREATE TABLE IF NOT EXISTS $tbl (
            id TINYINT NOT NULL PRIMARY KEY,
            refresh_token TEXT NOT NULL,
            access_token TEXT NULL,
            expires_at INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Wenn Tabelle bereits anders existiert (ohne id), kann CREATE TABLE IF NOT EXISTS nichts ändern.
    // Deshalb prüfen wir, ob "id" wirklich existiert:
    $hasId = table_has_column($conn, $tbl, 'id');

    if ($hasId) {
        // sicherstellen, dass id=1 Zeile existiert
        $conn->query("
            INSERT INTO $tbl (id, refresh_token, access_token, expires_at)
            SELECT 1, '', NULL, NULL
            WHERE NOT EXISTS (SELECT 1 FROM $tbl WHERE id=1)
        ");
    } else {
        // fallback: wenn keine Zeile existiert, eine anlegen
        $res = $conn->query("SELECT COUNT(*) AS c FROM $tbl");
        $c = 0;
        if ($res) { $row = $res->fetch_assoc(); $c = (int)($row['c'] ?? 0); }
        if ($c === 0) {
            $conn->query("INSERT INTO $tbl (refresh_token, access_token, expires_at) VALUES ('', NULL, NULL)");
        }
    }

    return [$tbl, $hasId];
}

function get_token_row(mysqli $conn): ?array {
    [$tbl, $hasId] = ensure_tokens_schema($conn);
    $sql = $hasId ? "SELECT * FROM $tbl WHERE id=1 LIMIT 1" : "SELECT * FROM $tbl LIMIT 1";
    $res = $conn->query($sql);
    if ($res && $res->num_rows === 1) return $res->fetch_assoc();
    return null;
}

function save_tokens(mysqli $conn, string $refresh, ?string $access, ?int $expires): void {
    [$tbl, $hasId] = ensure_tokens_schema($conn);

    if ($hasId) {
        $stmt = $conn->prepare("UPDATE $tbl SET refresh_token=?, access_token=?, expires_at=? WHERE id=1");
        $stmt->bind_param("ssi", $refresh, $access, $expires);
        $stmt->execute();
        $stmt->close();
    } else {
        // ohne id: update alle (sollte 1 Zeile sein)
        $stmt = $conn->prepare("UPDATE $tbl SET refresh_token=?, access_token=?, expires_at=?");
        $stmt->bind_param("ssi", $refresh, $access, $expires);
        $stmt->execute();
        $stmt->close();
    }
}

function disconnect_tokens(mysqli $conn): void {
    [$tbl, $hasId] = ensure_tokens_schema($conn);
    if ($hasId) $conn->query("UPDATE $tbl SET refresh_token='', access_token=NULL, expires_at=NULL WHERE id=1");
    else $conn->query("UPDATE $tbl SET refresh_token='', access_token=NULL, expires_at=NULL");
}

function refresh_test_access_token(string $refreshToken): array {
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

    $j = json_decode($resp ?: '', true);
    return [$http, $j];
}

/* =========================
   Link-Key Schutz
   - Bei OAuth Callback (code=...) erlauben wir ohne ?k=..., aber nur wenn Session authed
   ========================= */
$isOauthCallback = isset($_GET['code']) || isset($_GET['error']);
if (!$isOauthCallback) {
    $k = $_GET['k'] ?? '';
    if (!hash_equals(SETUP_LINK_KEY, $k)) {
        http_response_code(404);
        exit("Not Found");
    }
}

/* =========================
   Actions
   ========================= */
if (isset($_POST['action']) && $_POST['action'] === 'logout') {
    unset($_SESSION['drive_setup_ok'], $_SESSION['drive_setup_ok_until'], $_SESSION['google_oauth_state']);
    flash_set('success', 'Ausgeloggt.');
    header("Location: " . setup_url());
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $pw = $_POST['password'] ?? '';
    if (SETUP_PASSWORD_HASH === 'CHANGE_ME_TO_PASSWORD_HASH') {
        flash_set('error', 'SETUP_PASSWORD_HASH ist noch nicht gesetzt.');
    } elseif (password_verify($pw, SETUP_PASSWORD_HASH)) {
        $_SESSION['drive_setup_ok'] = true;
        $_SESSION['drive_setup_ok_until'] = time() + 30*60; // 30 Minuten
        flash_set('success', 'Login erfolgreich.');
    } else {
        flash_set('error', 'Falsches Passwort.');
    }
    header("Location: " . setup_url());
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'save_manual') {
    require_authed_or_die();
    $refresh = trim($_POST['refresh_token'] ?? '');
    if (!$refresh || strlen($refresh) < 20) {
        flash_set('error', 'Refresh-Token wirkt ungültig (zu kurz).');
        header("Location: " . setup_url());
        exit;
    }

    // Test: hol Access-Token
    [$http, $j] = refresh_test_access_token($refresh);
    if ($http < 200 || $http >= 300 || empty($j['access_token'])) {
        flash_set('error', 'Token-Test fehlgeschlagen (HTTP ' . $http . ').');
        header("Location: " . setup_url());
        exit;
    }

    $access  = $j['access_token'];
    $expires = time() + (int)($j['expires_in'] ?? 3600);

    save_tokens($conn, $refresh, $access, $expires);
    flash_set('success', 'Refresh-Token gespeichert & getestet ✅');
    header("Location: " . setup_url());
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'disconnect') {
    require_authed_or_die();
    disconnect_tokens($conn);
    flash_set('success', 'Google Drive getrennt (Token gelöscht).');
    header("Location: " . setup_url());
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'start_oauth') {
    require_authed_or_die();

    $state = bin2hex(random_bytes(16));
    $_SESSION['google_oauth_state'] = $state;

    $redirectUri = base_url_this_file_no_query(); // callback auf dieselbe Datei (ohne Query)
    $scope = "https://www.googleapis.com/auth/drive";

    $params = [
        "response_type" => "code",
        "client_id" => GOOGLE_CLIENT_ID,
        "redirect_uri" => $redirectUri,
        "scope" => $scope,
        "access_type" => "offline",
        "prompt" => "consent",
        "include_granted_scopes" => "true",
        "state" => $state,
    ];

    $authUrl = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query($params);
    header("Location: $authUrl");
    exit;
}

// OAuth Callback Verarbeitung
if (isset($_GET['error'])) {
    require_authed_or_die();
    flash_set('error', 'Google OAuth abgebrochen: ' . ($_GET['error'] ?? 'unknown'));
    header("Location: " . setup_url());
    exit;
}

if (isset($_GET['code'])) {
    require_authed_or_die();

    $state = $_GET['state'] ?? '';
    $sessState = $_SESSION['google_oauth_state'] ?? '';
    unset($_SESSION['google_oauth_state']);

    if (!$state || !$sessState || !hash_equals($sessState, $state)) {
        flash_set('error', 'OAuth State ungültig. Bitte erneut verbinden.');
        header("Location: " . setup_url());
        exit;
    }

    $code = $_GET['code'] ?? '';
    $redirectUri = base_url_this_file_no_query();

    $post = http_build_query([
        'code' => $code,
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code'
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

    $j = json_decode($resp ?: '', true);
    $refresh = $j['refresh_token'] ?? '';
    $access  = $j['access_token'] ?? '';
    $expires = time() + (int)($j['expires_in'] ?? 3600);

    if ($http < 200 || $http >= 300 || !$refresh) {
        flash_set('error', 'OAuth Token holen fehlgeschlagen (HTTP ' . $http . '). Kein refresh_token erhalten.');
        header("Location: " . setup_url());
        exit;
    }

    save_tokens($conn, $refresh, $access, $expires);
    flash_set('success', 'Google Drive verbunden ✅ Refresh-Token gespeichert.');
    header("Location: " . setup_url());
    exit;
}

/* =========================
   Render
   ========================= */
$flash = flash_get();
$row = get_token_row($conn);
$hasRefresh = !empty($row['refresh_token']);
$expiresAt = (int)($row['expires_at'] ?? 0);

?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Drive Admin Setup</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f6f7fb;margin:0;padding:24px;}
    .card{max-width:820px;margin:0 auto;background:#fff;border:1px solid #e7e7ee;border-radius:14px;padding:18px;}
    .row{display:flex;gap:12px;flex-wrap:wrap;align-items:center;}
    input{padding:10px;border:1px solid #e7e7ee;border-radius:10px;width:min(620px,100%);}
    button{padding:10px 12px;border:1px solid #e7e7ee;border-radius:10px;background:#fff;cursor:pointer;}
    .primary{background:#5a3ea6;color:#fff;border-color:#5a3ea6;}
    .muted{color:#6d6d78;}
    .ok{color:#166534;}
    .bad{color:#991b1b;}
    .alert{padding:10px 12px;border-radius:10px;margin:0 0 12px;}
    .alert-ok{background:#dcfce7;color:#166534;}
    .alert-bad{background:#fee2e2;color:#991b1b;}
    code{background:#f3f4f6;padding:2px 6px;border-radius:6px;}
  </style>
</head>
<body>
  <div class="card">
    <h2 style="margin:0 0 8px;">Google Drive Admin Setup</h2>
    <p class="muted" style="margin:0 0 14px;">
      Zugriff nur via Link-Key + Passwort. Token wird in <code>google_tokens</code> gespeichert.
    </p>

    <?php if (!empty($flash['success'])): ?>
      <div class="alert alert-ok"><?= h($flash['success']) ?></div>
    <?php endif; ?>
    <?php if (!empty($flash['error'])): ?>
      <div class="alert alert-bad"><?= h($flash['error']) ?></div>
    <?php endif; ?>

    <p style="margin:0 0 10px;">
      Status:
      <?php if ($hasRefresh): ?>
        <strong class="ok">Verbunden</strong>
      <?php else: ?>
        <strong class="bad">Nicht verbunden</strong>
      <?php endif; ?>
      <?php if ($expiresAt): ?>
        <span class="muted">(Access-Token bis <?= date('d.m.Y H:i', $expiresAt) ?>)</span>
      <?php endif; ?>
    </p>

    <?php if (!is_authed()): ?>
      <form method="post" class="row" style="margin-top:10px;">
        <input type="hidden" name="action" value="login">
        <input type="password" name="password" placeholder="Passwort" required>
        <button class="primary" type="submit">Öffnen</button>
      </form>

      <p class="muted" style="margin-top:12px;">
        Hinweis: Setze oben im File <code>SETUP_LINK_KEY</code> und <code>SETUP_PASSWORD_HASH</code>.
      </p>

    <?php else: ?>

      <form method="post" style="margin:10px 0 14px;">
        <input type="hidden" name="action" value="logout">
        <button type="submit">Logout</button>
      </form>

      <h3 style="margin:0 0 8px;">Option A: Automatisch verbinden (OAuth)</h3>
      <form method="post" class="row" style="margin-bottom:16px;">
        <input type="hidden" name="action" value="start_oauth">
        <button class="primary" type="submit">Mit Google verbinden</button>
        <span class="muted">→ speichert Refresh-Token automatisch</span>
      </form>

      <p class="muted" style="margin:-6px 0 16px;">
        Falls Google meckert: In der Google Cloud Console muss als Redirect URI exakt diese URL drinstehen:
        <code><?= h(base_url_this_file_no_query()) ?></code>
      </p>

      <h3 style="margin:0 0 8px;">Option B: Refresh-Token manuell eintragen</h3>
      <form method="post" style="margin-bottom:12px;">
        <input type="hidden" name="action" value="save_manual">
        <input type="text" name="refresh_token" placeholder="Refresh-Token (1//0g...)" required>
        <div class="row" style="margin-top:10px;">
          <button type="submit">Speichern & testen</button>
          <button type="submit" name="action" value="disconnect">Trennen</button>
        </div>
      </form>

      <form method="post">
        <input type="hidden" name="action" value="disconnect">
        <button type="submit">Trennen (Token löschen)</button>
      </form>

    <?php endif; ?>
  </div>
</body>
</html>

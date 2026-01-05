<?php
// Code/Frontend/analyse.php

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

require_once "../Backend/database/connect.php";

function h($v) {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* Flash */
if (!empty($_SESSION['error'])) {
  echo '<div class="alert alert-error">' . h($_SESSION['error']) . '</div>';
  unset($_SESSION['error']);
}
if (!empty($_SESSION['success'])) {
  echo '<div class="alert alert-success">' . h($_SESSION['success']) . '</div>';
  unset($_SESSION['success']);
}

/* Login */
if (empty($_SESSION['user_id'])) {
  ?>
  <div class="analyse-wrap">
    <div class="analyse-header">
      <div>
        <h2 class="analyse-title">Analyse</h2>
        <p class="analyse-subtitle">Bitte melde dich zuerst an.</p>
      </div>
      <div class="analyse-header-links">
        <a href="main.php?tab=home" class="link-quiet">Zurück zur Übersicht</a>
      </div>
    </div>

    <div class="analyse-dashboard">
      <section class="card video-card"><h3 class="card-title">Video-Vorschau</h3><p class="empty-state">Bitte einloggen.</p></section>
      <section class="card status-card"><h3 class="card-title">Status</h3><p class="empty-state">Bitte einloggen.</p></section>
      <section class="card wpm-card"><h3 class="card-title">Geschwindigkeit (WPM) – 30-Sekunden-Segmente</h3><p class="empty-state">Bitte einloggen.</p></section>
      <section class="card filler-card"><h3 class="card-title">Füllwörter</h3><p class="empty-state">Bitte einloggen.</p></section>
      <section class="card focus-card"><h3 class="card-title">Publikumsfokus</h3><p class="empty-state">Bitte einloggen.</p></section>
      <section class="card gesture-card"><h3 class="card-title">Gestik – KI-Fazit</h3><p class="empty-state">Bitte einloggen.</p></section>
    </div>
  </div>
  <?php
  return;
}

$currentUserId = (int)$_SESSION['user_id'];

/* id */
$presentationId = null;
if (isset($_GET['id']) && ctype_digit($_GET['id'])) {
  $presentationId = (int)$_GET['id'];
}

if (!$presentationId) {
  ?>
  <div class="analyse-wrap">
    <div class="analyse-header">
      <div>
        <h2 class="analyse-title">Analyse</h2>
        <p class="analyse-subtitle">Keine Präsentation ausgewählt.</p>
      </div>
      <div class="analyse-header-links">
        <a href="main.php?tab=home" class="link-quiet">Zurück zur Übersicht</a>
      </div>
    </div>

    <div class="analyse-dashboard">
      <section class="card video-card"><h3 class="card-title">Video-Vorschau</h3><p class="empty-state">Keine Präsentation ausgewählt.</p></section>
      <section class="card status-card"><h3 class="card-title">Status</h3><p class="empty-state">Keine Präsentation ausgewählt.</p></section>
      <section class="card wpm-card"><h3 class="card-title">Geschwindigkeit (WPM) – 30-Sekunden-Segmente</h3><p class="empty-state">Keine Präsentation ausgewählt.</p></section>
      <section class="card filler-card"><h3 class="card-title">Füllwörter</h3><p class="empty-state">Keine Präsentation ausgewählt.</p></section>
      <section class="card focus-card"><h3 class="card-title">Publikumsfokus</h3><p class="empty-state">Keine Präsentation ausgewählt.</p></section>
      <section class="card gesture-card"><h3 class="card-title">Gestik – KI-Fazit</h3><p class="empty-state">Keine Präsentation ausgewählt.</p></section>
    </div>
  </div>
  <?php
  return;
}

/* Base */
$sqlBase = "
  SELECT
    p.presentations_id,
    p.titel,
    p.created,
    k.avarage_wpm,
    k.filling_words_count,
    k.score,
    v.original_filename
  FROM presentations AS p
  LEFT JOIN kpi AS k
    ON p.presentations_id = k.fk_presentations_id
  LEFT JOIN videos AS v
    ON v.fk_presentations_id = p.presentations_id
  WHERE p.presentations_id = ?
    AND p.fk_user_id = ?
  ORDER BY v.videos_id DESC
  LIMIT 1
";
$stmt = $conn->prepare($sqlBase);
$stmt->bind_param("ii", $presentationId, $currentUserId);
$stmt->execute();
$res = $stmt->get_result();
$pres = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$pres) {
  ?>
  <div class="analyse-wrap">
    <div class="analyse-header">
      <div>
        <h2 class="analyse-title">Analyse</h2>
        <p class="analyse-subtitle">Nicht gefunden oder keine Berechtigung.</p>
      </div>
      <div class="analyse-header-links">
        <a href="main.php?tab=home" class="link-quiet">Zurück zur Übersicht</a>
      </div>
    </div>

    <div class="analyse-dashboard">
      <section class="card video-card"><h3 class="card-title">Video-Vorschau</h3><p class="empty-state">Nicht gefunden.</p></section>
      <section class="card status-card"><h3 class="card-title">Status</h3><p class="empty-state">Nicht gefunden.</p></section>
      <section class="card wpm-card"><h3 class="card-title">Geschwindigkeit (WPM) – 30-Sekunden-Segmente</h3><p class="empty-state">Nicht gefunden.</p></section>
      <section class="card filler-card"><h3 class="card-title">Füllwörter</h3><p class="empty-state">Nicht gefunden.</p></section>
      <section class="card focus-card"><h3 class="card-title">Publikumsfokus</h3><p class="empty-state">Nicht gefunden.</p></section>
      <section class="card gesture-card"><h3 class="card-title">Gestik – KI-Fazit</h3><p class="empty-state">Nicht gefunden.</p></section>
    </div>
  </div>
  <?php
  return;
}

$titel   = h($pres['titel'] ?? '');
$created = h($pres['created'] ?? '');

$wpm       = ($pres['avarage_wpm'] !== null) ? (float)$pres['avarage_wpm'] : null;
$fillWords = ($pres['filling_words_count'] !== null) ? (int)$pres['filling_words_count'] : null;
$score     = ($pres['score'] !== null) ? (float)$pres['score'] : null;

$wpmDisplay       = ($wpm !== null) ? h($wpm) : '-';
$fillWordsDisplay = ($fillWords !== null) ? h($fillWords) : '-';
$scoreDisplay     = ($score !== null) ? h($score) : '-';
$scorePercent     = ($score !== null) ? max(0, min(100, $score)) : 0;

$filename = !empty($pres['original_filename']) ? h($pres['original_filename']) : null;

/* Stream URL */
$videoStreamUrl = "../Backend/php/home/stream_video.php?id=" . $presentationId;
$videoStreamEsc = h($videoStreamUrl);

/* Publikum */
$focusPercent = null;
$stmt = $conn->prepare("SELECT viewer_percent FROM line_of_sight WHERE fk_presentations_id = ? LIMIT 1");
$stmt->bind_param("i", $presentationId);
$stmt->execute();
$r = $stmt->get_result();
if ($row = $r ? $r->fetch_assoc() : null) {
  if ($row['viewer_percent'] !== null) $focusPercent = (float)$row['viewer_percent'];
}
$stmt->close();

$focusPercent = ($focusPercent !== null) ? max(0, min(100, $focusPercent)) : null;
$focusPercentDisplay = ($focusPercent !== null) ? h((string)round($focusPercent)) : '-';

/* Gestik */
$gesturePoints   = null;
$gestureTitleDb  = null;
$gestureSubDb    = null;
$gesturesId      = null;
$ratings         = [];

$stmt = $conn->prepare("SELECT gestures_id, points, title, subtitle FROM gestures WHERE fk_presentations_id = ? LIMIT 1");
$stmt->bind_param("i", $presentationId);
$stmt->execute();
$r = $stmt->get_result();
if ($g = $r ? $r->fetch_assoc() : null) {
  $gesturesId     = (int)$g['gestures_id'];
  $gesturePoints  = ($g['points'] !== null) ? (float)$g['points'] : null;
  $gestureTitleDb = ($g['title'] !== null) ? $g['title'] : null;
  $gestureSubDb   = ($g['subtitle'] !== null) ? $g['subtitle'] : null;
}
$stmt->close();

if ($gesturesId !== null) {
  $stmt = $conn->prepare("SELECT content, rating_positive FROM ratings WHERE fk_gestures_id = ? ORDER BY ratings_id ASC");
  $stmt->bind_param("i", $gesturesId);
  $stmt->execute();
  $rr = $stmt->get_result();
  while ($row = $rr->fetch_assoc()) {
    $ratings[] = [
      'content'  => $row['content'],
      'positive' => ((int)$row['rating_positive'] === 1)
    ];
  }
  $stmt->close();
}

$gesturePointsDisplay = ($gesturePoints !== null) ? h((string)round($gesturePoints)) : null;
$gestureMainTitle = ($gestureTitleDb !== null) ? h($gestureTitleDb) : "Noch keine Bewertung";
$gestureText      = ($gestureSubDb   !== null) ? h($gestureSubDb)   : "Für diese Präsentation liegt noch keine Gestik-Auswertung vor.";

/* Charts */
$chartsId = null;
$stmt = $conn->prepare("SELECT charts_id FROM charts WHERE fk_presentations_id = ? ORDER BY charts_id DESC LIMIT 1");
$stmt->bind_param("i", $presentationId);
$stmt->execute();
$r = $stmt->get_result();
if ($c = $r ? $r->fetch_assoc() : null) $chartsId = (int)$c['charts_id'];
$stmt->close();

$wpmSegments  = [];
$fillerPerMin = [];
$fillerTags   = [];

if ($chartsId !== null) {
  $stmt = $conn->prepare("SELECT secs, `values` FROM wpm WHERE fk_charts_id = ? ORDER BY secs ASC");
  $stmt->bind_param("i", $chartsId);
  $stmt->execute();
  $rw = $stmt->get_result();
  while ($row = $rw->fetch_assoc()) {
    $wpmSegments[] = [
      'secs'  => (int)$row['secs'],
      'value' => (int)$row['values'],
    ];
  }
  $stmt->close();

  $stmt = $conn->prepare("SELECT `timestamp`, content FROM filling_timestamps WHERE fk_charts_id = ? ORDER BY `timestamp` ASC");
  $stmt->bind_param("i", $chartsId);
  $stmt->execute();
  $rf = $stmt->get_result();
  while ($row = $rf->fetch_assoc()) {
    $ts = $row['timestamp'];
    $content = $row['content'];

    $minute = 0;
    if (is_string($ts) && strlen($ts) >= 5) $minute = (int)substr($ts, 3, 2);

    if (!isset($fillerPerMin[$minute])) $fillerPerMin[$minute] = 0;
    $fillerPerMin[$minute]++;

    if (count($fillerTags) < 10) $fillerTags[] = substr($ts, 0, 5) . " - " . $content;
  }
  $stmt->close();
}

$hasWpmChart   = !empty($wpmSegments);
$hasFillerData = !empty($fillerPerMin);

$maxWpmValue = 1;
foreach ($wpmSegments as $seg) if ($seg['value'] > $maxWpmValue) $maxWpmValue = $seg['value'];

$maxFillCount = 1;
foreach ($fillerPerMin as $cnt) if ($cnt > $maxFillCount) $maxFillCount = $cnt;

ksort($fillerPerMin);
?>

<div class="analyse-wrap">

  <div class="analyse-header">
    <div>
      <h2 class="analyse-title"><?php echo $titel; ?></h2>
      <p class="analyse-subtitle">Erstellt am <?php echo $created; ?></p>
    </div>
    <div class="analyse-header-links">
      <a href="main.php?tab=home" class="link-quiet">Zurück zur Übersicht</a>
    </div>
  </div>

  <div class="analyse-dashboard">

    <!-- Video -->
    <section class="card video-card">
      <h3 class="card-title">Video-Vorschau</h3>

      <div class="video-frame-wrapper">
        <video
          id="analysisVideo"
          class="video-frame"
          src="<?php echo $videoStreamEsc; ?>"
          controls
          preload="metadata"
          playsinline
        ></video>

        <!-- ✅ Nur: Video nicht verfügbar + Neu laden -->
        <div id="videoErrorOverlay" class="video-error-overlay is-hidden">
          <p class="video-error-title">Video nicht verfügbar</p>
          <button type="button" class="video-retry-btn" id="videoRetryBtn">Neu laden</button>
        </div>
      </div>

      <div class="video-meta">
        <p class="video-filename">Datei: <?php echo $filename ? $filename : '-'; ?></p>
      </div>
    </section>

    <!-- Status -->
    <section class="card status-card">
      <h3 class="card-title">Status</h3>

      <div class="status-grid">
        <div class="status-item">
          <span class="status-label">Gesamt</span>
          <div class="status-number"><?php echo $scoreDisplay; ?></div>
          <div class="status-progress">
            <div class="status-progress-bar" style="width: <?php echo (float)$scorePercent; ?>%;"></div>
          </div>
        </div>

        <div class="status-item">
          <span class="status-label">Ø WPM</span>
          <div class="status-number"><?php echo $wpmDisplay !== '-' ? ($wpmDisplay . ' wpm') : '-'; ?></div>
        </div>

        <div class="status-item">
          <span class="status-label">Füllwörter</span>
          <div class="status-number"><?php echo $fillWordsDisplay; ?></div>
        </div>
      </div>

      <?php if ($score === null && $wpm === null && $fillWords === null): ?>
        <p class="empty-state">Noch keine KPI-Daten vorhanden.</p>
      <?php endif; ?>
    </section>

    <!-- WPM -->
    <section class="card wpm-card">
      <h3 class="card-title">Geschwindigkeit (WPM) – 30-Sekunden-Segmente</h3>
      <p class="card-caption">Grünes Band = Zielbereich 120–160 WPM</p>

      <?php if ($hasWpmChart): ?>
        <div class="wpm-chart">
          <div class="wpm-chart-y-label">Wörter/Minute</div>
          <div class="wpm-chart-inner">
            <div class="wpm-target-band"></div>
            <div class="wpm-bars">
              <?php
              $index = 1;
              foreach ($wpmSegments as $seg):
                $value = (int)$seg['value'];
                $heightPercent = min(100, ($value / $maxWpmValue) * 100);
                $label = "S" . $index;
                $index++;
              ?>
                <div class="wpm-bar-group">
                  <div class="wpm-bar" style="height: <?php echo $heightPercent; ?>%;"></div>
                  <span class="wpm-bar-label"><?php echo h($label); ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="wpm-x-label">30s-Segmente</div>
        </div>
      <?php else: ?>
        <div class="chart-empty"><p>Noch keine WPM-Daten vorhanden.</p></div>
      <?php endif; ?>
    </section>

    <!-- Füllwörter -->
    <section class="card filler-card">
      <h3 class="card-title">Füllwörter</h3>

      <?php if ($hasFillerData): ?>
        <div class="filler-charts">
          <div class="filler-bar-section">
            <div class="filler-bar-header"><span>Füllwörter/Minute</span></div>

            <div class="filler-bar-chart">
              <?php foreach ($fillerPerMin as $minute => $count):
                $height = min(100, ($count / $maxFillCount) * 100);
              ?>
                <div class="filler-bar-group">
                  <div class="filler-bar" style="height: <?php echo $height; ?>%;"></div>
                  <span class="filler-bar-label"><?php echo (int)$minute; ?></span>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="filler-bar-x-label">Minute</div>
          </div>

          <div class="filler-scatter-section">
            <div class="filler-scatter-header"><span>Vorkommen</span></div>

            <div class="filler-scatter">
              <?php
              $dotCount = array_sum($fillerPerMin);
              $dotCount = min(12, max(0, $dotCount));
              for ($i = 0; $i < $dotCount; $i++):
                $left = 5 + ($i * (90 / max(1, $dotCount - 1)));
              ?>
                <div class="filler-dot" style="left: <?php echo $left; ?>%;"></div>
              <?php endfor; ?>
            </div>

            <div class="filler-scatter-x-label">Zeit (Minuten)</div>

            <?php if (!empty($fillerTags)): ?>
              <div class="filler-tags">
                <?php foreach ($fillerTags as $tag): ?>
                  <span class="filler-tag"><?php echo h($tag); ?></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php else: ?>
        <div class="chart-empty"><p>Noch keine Füllwort-Daten vorhanden.</p></div>
      <?php endif; ?>
    </section>

    <!-- Publikumsfokus -->
    <section class="card focus-card">
      <h3 class="card-title">Publikumsfokus</h3>

      <p class="focus-label-row">
        <span>Blick → Publikum</span>
        <span><?php echo $focusPercentDisplay !== '-' ? ($focusPercentDisplay . '%') : '-'; ?></span>
      </p>

      <div class="focus-bar">
        <div class="focus-bar-fill" style="width: <?php echo ($focusPercent !== null) ? $focusPercent : 0; ?>%;"></div>
      </div>

      <p class="focus-subtext">Rest: Boden / Decke</p>

      <?php if ($focusPercent === null): ?>
        <p class="empty-state">Noch keine Blick-Daten vorhanden.</p>
      <?php endif; ?>
    </section>

    <!-- Gestik -->
    <section class="card gesture-card">
      <div class="gesture-header">
        <h3 class="card-title">
          Gestik – KI-Fazit
          <?php if ($gesturePointsDisplay !== null): ?>
            (<?php echo $gesturePointsDisplay; ?>/100)
          <?php endif; ?>
        </h3>

        <button class="icon-button" type="button" aria-label="Gestik-Bewertung" onclick="toggleGestureRatings()">ⓘ</button>
      </div>

      <p class="gesture-text">
        <strong><?php echo $gestureMainTitle; ?></strong><br>
        <?php echo $gestureText; ?>
      </p>

      <?php if (!empty($ratings)): ?>
        <div id="gestureRatings" class="gesture-ratings gesture-ratings-hidden">
          <ul class="gesture-ratings-list">
            <?php foreach ($ratings as $rating):
              $isPos   = (bool)$rating['positive'];
              $content = h($rating['content']);
            ?>
              <li class="gesture-rating-item <?php echo $isPos ? 'pos' : 'neg'; ?>">
                <span class="gesture-rating-icon"><?php echo $isPos ? '＋' : '−'; ?></span>
                <span class="gesture-rating-text"><?php echo $content; ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if ($gesturesId === null): ?>
        <p class="empty-state">Noch keine Gestik-Daten vorhanden.</p>
      <?php endif; ?>
    </section>

  </div>
</div>

<script>
function toggleGestureRatings() {
  var el = document.getElementById('gestureRatings');
  if (!el) return;
  el.classList.toggle('gesture-ratings-hidden');
}

(function () {
  var video = document.getElementById('analysisVideo');
  var overlay = document.getElementById('videoErrorOverlay');
  var retryBtn = document.getElementById('videoRetryBtn');
  if (!video || !overlay) return;

  function showOverlay() {
    overlay.classList.remove('is-hidden');
    video.classList.add('is-hidden');
  }

  function hideOverlay() {
    overlay.classList.add('is-hidden');
    video.classList.remove('is-hidden');
  }

  // Wenn Video ladbar → Overlay weg
  video.addEventListener('loadeddata', hideOverlay);

  // Wenn Fehler (404, Token, etc.) → Overlay zeigen
  video.addEventListener('error', showOverlay);
  video.addEventListener('stalled', showOverlay);
  video.addEventListener('abort', showOverlay);

  if (retryBtn) {
    retryBtn.addEventListener('click', function () {
      hideOverlay();
      var src = video.getAttribute('src');
      video.pause();
      video.removeAttribute('src');
      video.load();
      video.setAttribute('src', src);
      video.load();
    });
  }
})();
</script>

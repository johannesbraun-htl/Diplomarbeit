<?php
// analyse.php
header('Content-Type: application/json; charset=utf-8');

// === DB-Verbindung ===
$dsn  = 'mysql:host=91.151.18.23;port=3307;dbname=h109556_presentai;charset=utf8mb4';
$user = 'h109556_admin';
$pass = '!PresentAI';

// Präsentation-ID (per GET)
$pid = isset($_GET['pid']) ? (int)$_GET['pid'] : 1;

try {
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
  ]);

  // --- Video URL (optional) ---
  $stmt = $pdo->prepare("SELECT video_hash FROM presentations WHERE presentations_id = :pid");
  $stmt->execute([':pid' => $pid]);
  $pres = $stmt->fetch();
  $video_url = $pres ? "" /* oder "/videos/{$pres['video_hash']}.mp4" */ : "";

  // --- Chart (ID) ---
  $stmt = $pdo->prepare("SELECT charts_id FROM charts WHERE fk_presentations_id = :pid LIMIT 1");
  $stmt->execute([':pid' => $pid]);
  $chart = $stmt->fetch();
  $chartId = $chart ? (int)$chart['charts_id'] : null;

  // --- KPI ---
  $stmt = $pdo->prepare("SELECT avarage_wpm, filling_words_count, score FROM kpi WHERE fk_presentations_id = :pid LIMIT 1");
  $stmt->execute([':pid' => $pid]);
  $kpi = $stmt->fetch() ?: ['avarage_wpm'=>0,'filling_words_count'=>0,'score'=>0];

  // --- Blick / Line of sight ---
  $stmt = $pdo->prepare("SELECT viewer_percent FROM line_of_sight WHERE fk_presentations_id = :pid LIMIT 1");
  $stmt->execute([':pid' => $pid]);
  $los = $stmt->fetch() ?: ['viewer_percent'=>0];

  // --- WPM Segmente ---
  $wpmLabels = $wpmValues = $wpmSecs = [];
  $totalSecs = 0;
  if ($chartId) {
    $stmt = $pdo->prepare("SELECT secs, `values` FROM wpm WHERE fk_charts_id = :cid ORDER BY wpm_id ASC");
    $stmt->execute([':cid' => $chartId]);
    $rows = $stmt->fetchAll();
    $i = 1;
    foreach ($rows as $r) {
      $s = (int)$r['secs'];
      $v = (int)$r['values'];
      $wpmLabels[] = "S{$i} ({$s}s)";
      $wpmValues[] = $v;
      $wpmSecs[]   = $s;
      $totalSecs  += $s;
      $i++;
    }
  }

  // --- Füllwörter Zeitpunkte ---
  $fuellZeitpunkte = [];
  $fuellAlle = [];
  $perMinute = [];
  $minutesCount = max(1, (int)ceil($totalSecs / 60.0));

  if ($chartId) {
    $stmt = $pdo->prepare("SELECT `timestamp`, `content` FROM filling_timestamps WHERE fk_charts_id = :cid ORDER BY filling_timestamps_id ASC");
    $stmt->execute([':cid' => $chartId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as $r) {
      $ts = $r['timestamp']; // HH:MM:SS
      [$hh,$mm,$ss] = array_map('intval', explode(':', $ts));
      $totalS = $hh*3600 + $mm*60 + $ss;

      $x = round($totalS / 60.0, 2);
      $label = sprintf('%02d:%02d – %s', floor($totalS/60), $totalS%60, $r['content']);

      $fuellZeitpunkte[] = [ 'x' => $x, 'y' => 1, 'label' => $label ];
      $fuellAlle[] = sprintf('%02d:%02d · %s', floor($totalS/60), $totalS%60, $r['content']);

      $bucket = (int)floor($totalS / 60) + 1;
      $perMinute[$bucket] = ($perMinute[$bucket] ?? 0) + 1;
    }
  }

  // --- Füllwörter pro Minute ---
  $fuellMinuteLabels = [];
  $fuellMinuteValues = [];
  for ($m = 1; $m <= $minutesCount; $m++) {
    $fuellMinuteLabels[] = $m;
    $fuellMinuteValues[] = (int)($perMinute[$m] ?? 0);
  }

  // --- Gestik-Fazit ---
  $stmt = $pdo->prepare("SELECT gestures_id, points, title, subtitle FROM gestures WHERE fk_presentations_id = :pid LIMIT 1");
  $stmt->execute([':pid' => $pid]);
  $gest = $stmt->fetch();
  $gestik = [
    'punktzahl' => 0,
    'urteil' => '',
    'untertitel' => '',
    'punkte' => []
  ];
  if ($gest) {
    $gestik['punktzahl'] = (int)$gest['points'];
    $gestik['urteil'] = $gest['title'];
    $gestik['untertitel'] = $gest['subtitle'];

    $stmt = $pdo->prepare("SELECT content, rating_positive FROM ratings WHERE fk_gestures_id = :gid ORDER BY ratings_id ASC");
    $stmt->execute([':gid' => $gest['gestures_id']]);
    foreach ($stmt->fetchAll() as $r) {
      $gestik['punkte'][] = [
        'text' => $r['content'],
        'positiv' => (bool)$r['rating_positive']
      ];
    }
  }

  // --- Ausgabe exakt wie analyse.json ---
  $out = [
    'metadaten' => [
      'video_url' => $video_url
    ],
    'kpi' => [
      'durchschnitt_wpm' => (float)$kpi['avarage_wpm'],
      'fuellwoerter_anzahl' => (int)$kpi['filling_words_count'],
      'gesamt_score' => (float)$kpi['score']
    ],
    'blick' => [
      'publikum_prozent' => (float)$los['viewer_percent']
    ],
    'charts' => [
      'wpm' => [
        'labels' => $wpmLabels,
        'values' => $wpmValues,
        'secs'   => $wpmSecs
      ],
      'fuell_minute' => [
        'labels' => $fuellMinuteLabels,
        'values' => $fuellMinuteValues
      ],
      'fuell_zeitpunkte' => $fuellZeitpunkte
    ],
    'gestik_fazit' => $gestik,
    'fuellwoerter_alle' => $fuellAlle
  ];

  echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

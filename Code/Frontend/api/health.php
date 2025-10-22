<?php
// Frontend/api/health.php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/db.php';

try {
  $ok = [];

  // DB ping
  $pdo->query('SELECT 1');
  $ok['db'] = 'ok';

  // Minimal-Checks
  $present = $pdo->query("SELECT COUNT(*) AS c FROM presentations")->fetch();
  $ok['presentations'] = (int)$present['c'];

  // neueste PID
  $pidRow = $pdo->query("SELECT presentations_id FROM presentations ORDER BY presentations_id DESC LIMIT 1")->fetch();
  $pid = $pidRow ? (int)$pidRow['presentations_id'] : null;
  $ok['latest_presentation_id'] = $pid;

  if ($pid) {
    $charts = $pdo->prepare("SELECT charts_id FROM charts WHERE fk_presentations_id = ? ORDER BY charts_id DESC LIMIT 1");
    $charts->execute([$pid]);
    $c = $charts->fetch();
    $ok['charts_id_for_latest'] = $c ? (int)$c['charts_id'] : null;

    $kpi = $pdo->prepare("SELECT COUNT(*) AS c FROM kpi WHERE fk_presentations_id = ?");
    $kpi->execute([$pid]);
    $ok['kpi_rows_for_latest'] = (int)$kpi->fetch()['c'];

    $los = $pdo->prepare("SELECT COUNT(*) AS c FROM line_of_sight WHERE fk_presentations_id = ?");
    $los->execute([$pid]);
    $ok['line_of_sight_rows_for_latest'] = (int)$los->fetch()['c'];
  }

  echo json_encode(['status' => 'ok', 'checks' => $ok], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['status' => 'fail', 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

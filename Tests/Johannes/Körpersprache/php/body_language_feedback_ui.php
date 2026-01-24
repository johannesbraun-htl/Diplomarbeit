<?php
$defaultVideo = "C:\\xampp\\htdocs\\Diplomarbeit\\Tests\\uploads\\test.mp4";
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Body-Language Analyse (Tests)</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;margin:24px;max-width:980px}
    .row{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
    input{padding:10px;border:1px solid #ccc;border-radius:10px;flex:1;min-width:340px}
    button{padding:10px 14px;border:0;border-radius:10px;background:#111;color:#fff;cursor:pointer}
    button:disabled{opacity:.6;cursor:not-allowed}
    .card{border:1px solid #ddd;border-radius:14px;padding:14px;margin-top:14px}
    .muted{opacity:.7}
    .bar{height:10px;background:#eee;border-radius:999px;overflow:hidden}
    .bar > div{height:100%;width:0%;background:#111;transition:width .2s}
    pre{background:#0b0b0b;color:#e9e9e9;padding:12px;border-radius:12px;overflow:auto;max-height:340px}
    ul{margin:6px 0 0 18px}
    .pill{display:inline-block;padding:3px 10px;border:1px solid #ccc;border-radius:999px;font-size:12px}
    .err{border-color:#ffb4b4;background:#fff2f2}
  </style>
</head>
<body>
  <h2>Body-Language Analyse (Tests)</h2>
  <div class="muted">Job-Mode + Progress. (Cache aus, Fazit variiert.)</div>

  <div class="card">
    <div class="row">
      <input id="videoPath" value="<?= htmlspecialchars($defaultVideo) ?>" />
      <button id="startBtn">Analyse starten</button>
    </div>

    <div style="margin-top:12px" class="bar"><div id="barFill"></div></div>
    <div id="statusText" class="muted" style="margin-top:8px">Bereit.</div>
  </div>

  <div id="result" class="card" style="display:none">
    <div class="row" style="justify-content:space-between">
      <div><b>Gesamtscore</b> <span id="score" class="pill">-</span></div>
      <div class="pill" id="modePill">-</div>
    </div>

    <div style="margin-top:10px"><b>Fazit:</b> <span id="verdict"></span></div>

    <div style="margin-top:10px"><b>Tipps (3)</b>
      <ul id="tips"></ul>
    </div>

    <div style="margin-top:10px"><b>Pluspunkte</b>
      <ul id="plus"></ul>
    </div>

    <div style="margin-top:10px"><b>Minuspunkte</b>
      <ul id="minus"></ul>
    </div>

    <div style="margin-top:10px"><b>Raw JSON</b>
      <pre id="raw"></pre>
    </div>
  </div>

<script>
const startBtn = document.getElementById("startBtn");
const videoPath = document.getElementById("videoPath");
const barFill = document.getElementById("barFill");
const statusText = document.getElementById("statusText");

const result = document.getElementById("result");
const scoreEl = document.getElementById("score");
const modePill = document.getElementById("modePill");
const verdictEl = document.getElementById("verdict");
const tipsEl = document.getElementById("tips");
const plusEl = document.getElementById("plus");
const minusEl = document.getElementById("minus");
const rawEl = document.getElementById("raw");

function setProgress(p, msg){
  barFill.style.width = Math.max(0, Math.min(100, p)) + "%";
  statusText.textContent = msg || "";
}

function li(text){
  const el = document.createElement("li");
  el.textContent = text;
  return el;
}

// ✅ Unterstützt:
// - tip als string
// - tip als object {text:"...", title:"..."} etc.
function renderTips(tips){
  tipsEl.innerHTML = "";
  if(!Array.isArray(tips) || tips.length === 0){
    tipsEl.appendChild(li("—"));
    return;
  }
  for(const t of tips){
    if(typeof t === "string"){
      tipsEl.appendChild(li(t));
    } else if(t && typeof t === "object"){
      const text = t.text || t.tip || t.label || t.title || JSON.stringify(t);
      tipsEl.appendChild(li(text));
    } else {
      tipsEl.appendChild(li(String(t)));
    }
  }
}

// ✅ Unterstützt:
// - points als objects [{label,score}, ...]
// - points als strings ["...", "..."]
function renderPoints(listEl, points){
  listEl.innerHTML = "";
  if(!Array.isArray(points) || points.length === 0){
    listEl.appendChild(li("—"));
    return;
  }
  for(const p of points){
    if(typeof p === "string"){
      listEl.appendChild(li(p));
    } else if(p && typeof p === "object"){
      const label = p.label || p.metric || "Punkt";
      const sc = (typeof p.score === "number") ? p.score : null;
      listEl.appendChild(li(sc !== null ? `${label} (${sc})` : label));
    } else {
      listEl.appendChild(li(String(p)));
    }
  }
}

async function start(){
  startBtn.disabled = true;
  result.style.display = "none";
  result.classList.remove("err");
  setProgress(5, "Job wird gestartet...");

  const url = "/Diplomarbeit/Tests/php/start_job.php?video_path=" + encodeURIComponent(videoPath.value);
  const r = await fetch(url, {cache:"no-store"});
  const job = await r.json();

  if(!job.job_id){
    setProgress(100, "Fehler beim Start.");
    startBtn.disabled = false;
    alert(job.error || "Start fehlgeschlagen");
    return;
  }

  setProgress(10, "Läuft...");
  poll(job.job_id);
}

async function poll(jobId){
  const statusUrl = "/Diplomarbeit/Tests/php/job_status.php?job_id=" + encodeURIComponent(jobId);
  while(true){
    const r = await fetch(statusUrl, {cache:"no-store"});
    const data = await r.json();

    const st = data.status || {};
    setProgress(st.percent ?? 0, st.message ?? "...");

    if(st.state === "done" || st.state === "error"){
      showResult(data.result, data);
      startBtn.disabled = false;
      return;
    }
    await new Promise(res => setTimeout(res, 350));
  }
}

function showResult(res, full){
  result.style.display = "block";
  rawEl.textContent = JSON.stringify(res || full, null, 2);

  if(!res || !res.pose_score){
    result.classList.add("err");
    scoreEl.textContent = "—";
    modePill.textContent = "—";
    verdictEl.textContent = "Fehler: Kein Ergebnis erhalten.";
    renderTips([]);
    renderPoints(plusEl, []);
    renderPoints(minusEl, []);
    return;
  }

  const ps = res.pose_score;
  const fb = res.feedback || {};

  modePill.textContent = ps.mode || "—";

  if(ps.error && ps.error.code === "NO_PERSON_DETECTED"){
    result.classList.add("err");
    scoreEl.textContent = "0";
    verdictEl.textContent = ps.verdict || "Keine Person erkannt.";
    renderTips(fb.tips || []);
    renderPoints(plusEl, []);
    renderPoints(minusEl, []);
    return;
  }

  scoreEl.textContent = String(ps.total_score_0_100 ?? fb.total_score_0_100 ?? fb.score_0_100 ?? "—");
  verdictEl.textContent = fb.verdict || ps.verdict || "—";

  // ✅ Wichtig: tips könnten bei dir auch in feedback.points drin sein -> wir nehmen fb.tips primär
  renderTips(fb.tips || []);
  renderPoints(plusEl, fb.plus_points || ps.plus_points || []);
  renderPoints(minusEl, fb.minus_points || ps.minus_points || []);
}

startBtn.addEventListener("click", start);
</script>
</body>
</html>

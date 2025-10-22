document.addEventListener('DOMContentLoaded', () => {
  if (window.lucide) lucide.createIcons();
  const jahrEl = document.getElementById('jahr');
  if (jahrEl) jahrEl.textContent = new Date().getFullYear();
  init();
});

let WPM_CHART, FUELL_STREU_CHART;

async function init(){
  try {
    // 🔹 Präsentation-ID aus URL lesen (Standard = 1)
    const pid = new URLSearchParams(location.search).get('pid') || '1';

    // 🔹 API-Aufruf mit PID
    const res = await fetch(`api/analyse.php?pid=${encodeURIComponent(pid)}`, { cache: 'no-store' });
    if (!res.ok) throw new Error(`analyse.json HTTP ${res.status}`);
    const DATA = await res.json();

    // 🔹 Inhalte rendern
    renderVideo(DATA);
    renderKPIs(DATA);
    renderFokus(DATA);
    renderGestik(DATA);
    renderFuellwoerter(DATA);
    renderCharts(DATA);

    // 🔹 Gestik-Popup
    const infoBtn = document.getElementById('gestikInfoBtn');
    const pop = document.getElementById('gestikInfo');
    if (infoBtn && pop) {
      infoBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        pop.classList.toggle('hidden');
      });
      document.addEventListener('click', (e) => {
        if (!pop.classList.contains('hidden')) {
          const within = pop.contains(e.target) || infoBtn.contains(e.target);
          if (!within) pop.classList.add('hidden');
        }
      });
    }

  } catch (e) {
    console.error('Initialisierung fehlgeschlagen:', e);
  }
}

/* === Video === */
function renderVideo(D){
  const url = D?.metadaten?.video_url;
  const c = document.getElementById('videoContainer');
  if (c && url) c.innerHTML = `<video src="${url}" controls class="w-full h-full rounded-lg"></video>`;
}

/* === KPIs === */
function renderKPIs(D){
  setText('kpiWpm', `${D.kpi.durchschnitt_wpm} wpm`);
  setText('kpiFuell', D.kpi.fuellwoerter_anzahl);
  setText('kpiGesamt', D.kpi.gesamt_score);
  setWidth('scoreBalken', D.kpi.gesamt_score + '%');
}

/* === Fokus / Blickkontakt === */
function renderFokus(D){
  const p = D?.blick?.publikum_prozent ?? 0;
  setText('blickPublikumProzentBadge', p + '%');
  setWidth('blickBalken', p + '%');
}

/* === Gestik-Fazit === */
function renderGestik(D){
  const G = D.gestik_fazit;
  if (!G) return;
  setText('gestikUrteil', `${G.urteil} (${G.punktzahl}/100)`);
  setText('gestikUntertitel', G.untertitel || '');
  const list = document.getElementById('gestikWarum');
  if (list) {
    list.innerHTML = (G.punkte || []).map(p =>
      `<li class="point ${p.positiv?'plus':'minus'}">
         <span class="sym">${p.positiv?'+':'–'}</span>${p.text}
       </li>`
    ).join('');
  }
}

/* === Füllwörter === */
function renderFuellwoerter(D){
  const chips = document.getElementById('chips');
  if (!chips) return;

  // Falls neue JSON-Struktur → erstelle aus fuell_zeitpunkte oder fallback
  let alle = [];
  if (Array.isArray(D.fuellwoerter_alle)) {
    alle = D.fuellwoerter_alle;
  } else if (Array.isArray(D.charts?.fuell_zeitpunkte)) {
    alle = D.charts.fuell_zeitpunkte.map(p => p.label);
  } else if (Array.isArray(D.fuellwoerter_letzte10)) {
    alle = D.fuellwoerter_letzte10;
  }

  chips.innerHTML = alle.map(t => `<span class="chip">${t}</span>`).join('');
}

/* === Diagramme === */
function renderCharts(D){
  // === WPM Chart ===
  if (WPM_CHART) WPM_CHART.destroy();
  const wpmLabels = D.charts.wpm.labels;
  const wpmValues = D.charts.wpm.values;
  const wpmSecs   = D.charts.wpm.secs;

  WPM_CHART = new Chart(document.getElementById('wpmDiagrammCanvas'), {
    type: 'bar',
    data: { labels: wpmLabels, datasets: [{ label: 'WPM', data: wpmValues }] },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'top' },
        zielbereichOverlay: { min: 120, max: 160 },
        tooltip: {
          callbacks: {
            title: (items) => {
              const i = items?.[0]?.dataIndex ?? 0;
              return `${wpmLabels[i]}`;
            },
            label: (item) => {
              const i = item.dataIndex;
              const sec = Array.isArray(wpmSecs) ? wpmSecs[i] : undefined;
              const val = item.formattedValue;
              return sec != null ? ` ${sec}s • ${val} WPM` : ` ${val} WPM`;
            }
          }
        }
      },
      scales: {
        x: { title: { display: true, text: '30s-Segmente (Dauer)' } },
        y: { title: { display: true, text: 'Wörter/Minute' }, beginAtZero: true }
      }
    },
    plugins: [{
      id: 'zielbereichOverlay',
      beforeDraw(chart, _args, opt){
        if(!opt) return;
        const {ctx, chartArea:{left,right}, scales:{y}} = chart;
        const y1 = y.getPixelForValue(opt.min), y2 = y.getPixelForValue(opt.max);
        ctx.save();
        ctx.fillStyle = 'rgba(16,185,129,.12)';
        ctx.fillRect(left, y2, right-left, y1-y2);
        ctx.restore();
      }
    }]
  });

  // === Füllwörter Zeitpunkte (Scatter) ===
  if (FUELL_STREU_CHART) FUELL_STREU_CHART.destroy();
  FUELL_STREU_CHART = new Chart(document.getElementById('fuellStreuCanvas'), {
    type: 'scatter',
    data: {
      datasets: [{
        label: 'Zeitpunkte',
        data: D.charts.fuell_zeitpunkte,
        pointRadius: 6,
        pointHoverRadius: 8,
        backgroundColor: 'rgba(59,130,246,0.6)',
        borderColor: 'rgba(37,99,235,0.8)',
        borderWidth: 1.5
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: (c) => c.raw.label } }
      },
      scales: {
        x: { title: { display: true, text: 'Zeit (Minuten)' } },
        y: { display: false, min: 0, max: 2 }
      }
    }
  });
}

/* === kleine Helfer === */
function setText(id, v){ const el = document.getElementById(id); if (el) el.textContent = v; }
function setWidth(id, v){ const el = document.getElementById(id); if (el) el.style.width = v; }

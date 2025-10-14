<?php
  $seitentitel = "PresentAI – Analyse Dashboard";
?>
<!doctype html>
<html lang="de">
  <?php include __DIR__ . '/partials/head.php'; ?>
  <body>
    <?php include __DIR__ . '/partials/header.php'; ?>

    <main>
      <!-- Zeile 1: Video -->
      <section class="card p-4 col-span-2">
        <h3 class="title mb-3">Video-Vorschau</h3>
        <div id="videoContainer" class="bg-black aspect-video rounded-lg flex items-center justify-center text-gray-300 text-sm">
          Demo Video
        </div>
      </section>

      <!-- Zeile 2: Status -->
      <section class="card p-4 col-span-2">
        <h3 class="title mb-3 flex items-center gap-2">
          <i data-lucide="bar-chart-2" class="w-5 h-5"></i>Status
        </h3>
        <div class="grid grid-cols-3 gap-3">
          <div class="kpi">
            <p class="kpi-label">Gesamt</p>
            <p id="kpiGesamt" class="kpi-value">–</p>
            <div class="meter"><span id="scoreBalken"></span></div>
          </div>
          <div class="kpi">
            <p class="kpi-label">Ø WPM</p>
            <p id="kpiWpm" class="kpi-value">–</p>
          </div>
          <div class="kpi">
            <p class="kpi-label">Füllwörter</p>
            <p id="kpiFuell" class="kpi-value">–</p>
          </div>
        </div>
      </section>

      <!-- Zeile 3: Geschwindigkeit -->
      <section class="card p-4 col-span-2">
        <h3 class="title mb-2">Geschwindigkeit (WPM) – 30-Sekunden-Segmente</h3>
        <div class="chart chart-lg"><canvas id="wpmDiagrammCanvas"></canvas></div>
        <p class="text-xs text-gray-500 mt-2">Grünes Band = Zielbereich 120–160 WPM</p>
      </section>

      <!-- Zeile 4: Füllwörter -->
      <section class="card p-4 col-span-2">
        <h3 class="title mb-2">Füllwörter</h3>
        <div class="chart chart-xl"><canvas id="fuellStreuCanvas"></canvas></div>
        <div class="mt-3 flex flex-wrap gap-2" id="chips"></div>
      </section>

      <!-- Zeile 5: Fokus + Gestik -->
      <section class="card p-4">
        <h3 class="title mb-2">Publikumsfokus</h3>
        <div>
          <div class="flex items-center justify-between text-sm mb-1">
            <span class="font-medium">Blick → Publikum</span><span id="blickPublikumProzentBadge" class="badge-soft">–%</span>
          </div>
          <div class="statbar"><span id="blickBalken" style="width:0%;background:#0ea5e9"></span></div>
          <p class="text-xs text-gray-500 mt-1">Rest: Boden/Decke</p>
        </div>
      </section>

      <section class="card p-4">
        <div class="flex items-center justify-between mb-1">
          <h3 class="title">Gestik – KI-Fazit</h3>
          <button id="gestikInfoBtn" class="info-btn" aria-label="Details">
            <i data-lucide="wand-2" class="w-4 h-4"></i>
          </button>
        </div>
        <p id="gestikUrteil" class="text-xl font-extrabold">—</p>
        <p id="gestikUntertitel" class="text-sm text-gray-600">Automatische Einschätzung deiner Körpersprache.</p>

        <div id="gestikInfo" class="popover hidden">
          <div class="popover-arrow"></div>
          <p class="font-medium mb-2">Warum dieses Fazit?</p>
          <ul id="gestikWarum" class="space-y-2 list-none"></ul>
        </div>
      </section>
    </main>

    <?php include __DIR__ . '/partials/footer.php'; ?>
    <script src="/assets/app.js"></script>
  </body>
</html>

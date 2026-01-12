<?php
require "../Backend/database/connect.php";

$error = $_SESSION['error'] ?? null;
unset($_SESSION['error']);
?>

<link rel="stylesheet" href="css/style_home.css">

<div class="home-head">
  <div class="home-head__left">
    <h1 class="home-title">Willkommen, <?= htmlspecialchars($_SESSION['username']); ?>!</h1>
    <p class="home-sub">Verwalte deine Präsentationen und starte Analysen.</p>
  </div>

  <div class="home-head__right">
    <button class="open-button" type="button" onclick="openForm()">
      <span class="open-button__icon">＋</span>
      Neue Präsentation
    </button>
  </div>
</div>

<?php if ($error): ?>
  <script>
    const errorMessage = <?php echo json_encode($error); ?>;
    window.addEventListener('DOMContentLoaded', function () {
      alert(errorMessage);
    });
  </script>
<?php endif; ?>

<!-- MODAL (nur sichtbar wenn openForm() -> display:flex setzt) -->
<div class="form-popup" id="myForm" aria-hidden="true">
  <div class="modal-overlay" onclick="closeForm()"></div>

  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="createModalTitle">
    <div class="modal-head">
      <div>
        <h2 class="modal-title" id="createModalTitle">Neue Präsentation erstellen</h2>
        <p class="modal-sub">Titel vergeben und eine MP4-Datei hochladen.</p>
      </div>

      <button type="button" class="modal-close" aria-label="Schließen" onclick="closeForm()">✕</button>
    </div>

    <form id="uploadForm"
          action="../Backend/php/home/upload_presentation.php"
          method="post"
          enctype="multipart/form-data"
          class="form-container">

      <div class="field">
        <label for="title">Titel</label>
        <input type="text" id="title" name="title" placeholder="z.B. Projekt Präsentation" required>
      </div>

      <div class="field">
        <label for="presentationFile">Video (MP4)</label>

        <div class="filebox">
          <input type="file" id="presentationFile" name="presentationFile" accept=".mp4,video/mp4" required>
          <div class="filebox-ui">
            <div class="filebox-icon">🎬</div>
            <div class="filebox-text">
              <div class="filebox-title">MP4 auswählen</div>
              <div class="filebox-sub">Klicke hier oder ziehe eine Datei hinein</div>
            </div>
            <div class="filebox-btn">Datei wählen</div>
          </div>
          <div class="filebox-filename" id="fileNameText">Keine Datei ausgewählt</div>
        </div>
      </div>

      <!-- Progress -->
      <div id="uploadProgressWrap" class="upload-progress" style="display:none;">
        <div class="upload-progress-meta">
          <span id="uploadProgressText">0%</span>
          <span id="uploadEtaText">ETA: --:--</span>
        </div>

        <div class="upload-progress-bar">
          <div id="uploadProgressBar" class="upload-progress-bar__fill"></div>
        </div>

        <div id="uploadPhaseText" class="upload-progress-phase">Warte auf Upload…</div>
      </div>

      <div class="modal-actions">
        <button id="uploadSubmitBtn" class="btn-primary" type="submit">Erstellen</button>
        <button type="button" class="btn-secondary" onclick="closeForm()">Abbrechen</button>
      </div>
    </form>
  </div>
</div>

<div class="home-section">
  <div class="home-section-head">
    <h2 class="home-section-title">Vorhandene Präsentationen</h2>
    <p class="home-section-sub">Ansehen, bearbeiten oder löschen.</p>
  </div>

  <div id="presentationsWrap">
    <?php require "../Backend/php/home/load_presentations.php"; ?>
    <table class="presentations-table">
      <?php loadPresentations($conn); ?>
    </table>
  </div>
</div>

<script src="../Backend/js/script_home.js"></script>

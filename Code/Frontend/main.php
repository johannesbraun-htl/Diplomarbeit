<?php
session_start();

if (empty($_SESSION['user_id'])) {
    $_SESSION['error'] = "Bitte melde dich zuerst an.";
    header("Location: ../index.php");
    exit;
}

$open = isset($_GET['open']) ? strtolower($_GET['open']) : 'home';
$allowedOpen = ['home', 'analyse', 'einstellungen', 'bearbeiten'];
if (!in_array($open, $allowedOpen, true)) $open = 'home';

$openId = (isset($_GET['id']) && ctype_digit($_GET['id'])) ? (int)$_GET['id'] : 0;

/**
 * WICHTIG: Wenn Bearbeiten/Analyse über Link geöffnet wird,
 * speichern wir die ID in der Session, damit die URL danach clean sein kann.
 */
if ($open === 'bearbeiten' && $openId > 0) {
    $_SESSION['active_edit_id'] = $openId;
}
if ($open === 'analyse' && $openId > 0) {
    $_SESSION['active_analyse_id'] = $openId;
}

// Flash Messages
$flashError = $_SESSION['error'] ?? '';
$flashSuccess = $_SESSION['success'] ?? '';
unset($_SESSION['error'], $_SESSION['success']);

// Bearbeiten-Button nur anzeigen, wenn Bearbeiten gerade geöffnet wurde
$showBearbeitenBtn = ($open === 'bearbeiten');
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <title>PresentAI</title>

  <link rel="stylesheet" href="css/style_main.css">
  <link rel="stylesheet" href="css/style_analyse.css">
  <link rel="stylesheet" href="css/style_edit.css">

  <script>
    window.__OPEN_TAB__ = <?= json_encode($open) ?>;

    function hideAllTabContents() {
      const contents = document.getElementsByClassName("tabcontent");
      for (let i = 0; i < contents.length; i++) contents[i].style.display = "none";

      const links = document.getElementsByClassName("tablinks");
      for (let i = 0; i < links.length; i++) links[i].classList.remove("active");
    }

    function showBearbeitenTabButton(show) {
      const btn = document.getElementById("tabBtnBearbeiten");
      if (!btn) return;
      btn.style.display = show ? "inline-flex" : "none";
    }

    // Session löschen, wenn Bearbeiten "verlassen" wird
    function clearEditSession() {
      try {
        fetch("../Backend/php/home/clear_active_edit.php", { method: "POST" });
      } catch(e) {}
    }

    function tab(evt, tabName) {
      hideAllTabContents();

      // Wenn man Bearbeiten verlässt -> ausblenden + Session löschen
      if (tabName !== "bearbeiten") {
        showBearbeitenTabButton(false);
        clearEditSession();
      } else {
        showBearbeitenTabButton(true);
      }

      const el = document.getElementById(tabName);
      if (el) el.style.display = "block";

      if (evt && evt.currentTarget) evt.currentTarget.classList.add("active");
    }

    function openTabByName(tabName) {
      hideAllTabContents();

      if (tabName !== "bearbeiten") {
        showBearbeitenTabButton(false);
      } else {
        showBearbeitenTabButton(true);
      }

      const el = document.getElementById(tabName);
      if (el) el.style.display = "block";

      const btnIdMap = {
        home: "tabBtnHome",
        analyse: "tabBtnAnalyse",
        einstellungen: "tabBtnEinstellungen",
        bearbeiten: "tabBtnBearbeiten",
      };
      const btn = document.getElementById(btnIdMap[tabName] || "tabBtnHome");
      if (btn) btn.classList.add("active");
    }

    document.addEventListener("DOMContentLoaded", () => {
      // initial öffnen
      openTabByName(window.__OPEN_TAB__ || "home");

      // URL NUR EINMAL cleanen (damit reload immer main.php ist)
      if (window.location.search && window.location.search.length > 0) {
        try { history.replaceState({}, "", "main.php"); } catch(e) {}
      }
    });
  </script>
</head>
<body>

<h1 class="header-title">PresentAI</h1>

<?php if (!empty($flashError)): ?>
  <div class="ev-alert ev-alert--error" style="max-width: 1120px; margin: 16px auto 0; padding: 12px 16px;">
    <?= htmlspecialchars($flashError) ?>
  </div>
<?php endif; ?>

<?php if (!empty($flashSuccess)): ?>
  <div class="ev-alert ev-alert--success" style="max-width: 1120px; margin: 16px auto 0; padding: 12px 16px;">
    <?= htmlspecialchars($flashSuccess) ?>
  </div>
<?php endif; ?>

<div class="tab">
  <button id="tabBtnHome" class="tablinks" onclick="tab(event, 'home')">Home</button>
  <button id="tabBtnAnalyse" class="tablinks" onclick="tab(event, 'analyse')">Analyse</button>
  <button id="tabBtnEinstellungen" class="tablinks" onclick="tab(event, 'einstellungen')">Einstellungen</button>

  <button id="tabBtnBearbeiten"
          class="tablinks"
          style="display: <?= $showBearbeitenBtn ? 'inline-flex' : 'none' ?>;"
          onclick="tab(event, 'bearbeiten')">
    Bearbeiten
  </button>

  <form class="logout-form" action="../Backend/php/login-system/logout.php" method="post">
    <button type="submit" class="logout-btn">Abmelden</button>
  </form>
</div>

<div id="home" class="tabcontent" style="display:none;">
  <?php include "home.php"; ?>
</div>

<div id="analyse" class="tabcontent" style="display:none;">
  <?php include "analyse.php"; ?>
</div>

<div id="einstellungen" class="tabcontent" style="display:none;">
  <?php include "einstellungen.php"; ?>
</div>

<div id="bearbeiten" class="tabcontent" style="display:none;">
  <?php include "bearbeiten.php"; ?>
</div>

</body>
</html>

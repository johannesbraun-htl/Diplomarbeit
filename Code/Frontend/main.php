<?php
// Frontend/main.php
session_start();

if (empty($_SESSION['user_id'])) {
    $_SESSION['error'] = "Bitte melde dich zuerst an.";
    header("Location: ../index.php");
    exit;
}

/*
 * Aktiven Tab aus der URL bestimmen:
 * - ohne ?tab=...        -> home
 * - ?tab=analyse         -> Analyse
 * - ?tab=einstellungen   -> Einstellungen
 */
$activeTab = 'home';
if (isset($_GET['tab'])) {
    $tab = strtolower($_GET['tab']);
    if (in_array($tab, ['home', 'analyse', 'einstellungen'], true)) {
        $activeTab = $tab;
    }
}

// Initiale Anzeige für die Tab-Inhalte
$showHome          = $activeTab === 'home'         ? 'block' : 'none';
$showAnalyse       = $activeTab === 'analyse'      ? 'block' : 'none';
$showEinstellungen = $activeTab === 'einstellungen'? 'block' : 'none';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>PresentAI</title>

    <!-- Globales Layout + Header/Tabs -->
    <link rel="stylesheet" href="css/style_main.css">
    <!-- Analyse-Dashboard (Cards, Charts usw.) -->
    <link rel="stylesheet" href="css/style_analyse.css">
</head>
<body>

    <!-- Fixer Header oben -->
    <h1 class="header-title">
        PresentAI
    </h1>

    <!-- Fixe Tab-Leiste mit Logout ganz rechts -->
    <div class="tab">

        <!-- Tab-Buttons links -->
        <button
            class="tablinks <?php echo $activeTab === 'home' ? 'active' : ''; ?>"
            onclick="tab(event, 'home')">
            Home
        </button>

        <button
            class="tablinks <?php echo $activeTab === 'analyse' ? 'active' : ''; ?>"
            onclick="tab(event, 'analyse')">
            Analyse
        </button>

        <button
            class="tablinks <?php echo $activeTab === 'einstellungen' ? 'active' : ''; ?>"
            onclick="tab(event, 'einstellungen')">
            Einstellungen
        </button>

        <!-- Logout-Formular – per CSS an den rechten Rand geschoben -->
        <form class="logout-form" action="../Backend/php/login-system/logout.php" method="post">
            <button type="submit" class="logout-btn">Abmelden</button>
        </form>
    </div>

    <!-- Inhalt: Home -->
    <div id="home" class="tabcontent" style="display: <?php echo $showHome; ?>;">
        <?php include "home.php"; ?>
    </div>

    <!-- Inhalt: Analyse (nutzt ?id=... weiter wie bisher) -->
    <div id="analyse" class="tabcontent" style="display: <?php echo $showAnalyse; ?>;">
        <?php include "analyse.php"; ?>
    </div>

    <!-- Inhalt: Einstellungen -->
    <div id="einstellungen" class="tabcontent" style="display: <?php echo $showEinstellungen; ?>;">
        <?php include "einstellungen.php"; ?>
    </div>

    <script>
        // einfache Tab-Funktion, damit onclick="tab(...)" funktioniert
        function tab(evt, tabName) {
            // alle Inhalte verstecken
            var i, tabcontent, tablinks;
            tabcontent = document.getElementsByClassName("tabcontent");
            for (i = 0; i < tabcontent.length; i++) {
                tabcontent[i].style.display = "none";
            }

            // active-Klasse von allen Buttons entfernen
            tablinks = document.getElementsByClassName("tablinks");
            for (i = 0; i < tablinks.length; i++) {
                tablinks[i].classList.remove("active");
            }

            // gewünschten Tab einblenden
            var target = document.getElementById(tabName);
            if (target) {
                target.style.display = "block";
            }

            // angeklickten Button markieren
            if (evt && evt.currentTarget) {
                evt.currentTarget.classList.add("active");
            }
        }
    </script>
</body>
</html>

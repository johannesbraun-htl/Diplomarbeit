<?php
require "../Backend/database/connect.php";

$error = $_SESSION['error'] ?? null;
unset($_SESSION['error']);
?>

<link rel="stylesheet" href="css/style_home.css">

<h1>Willkommen, <?php echo htmlspecialchars($_SESSION['username']); ?>!</h1>

<?php if ($error): ?>
    <script>
        const errorMessage = <?php echo json_encode($error); ?>;
        window.addEventListener('DOMContentLoaded', function () {
            alert(errorMessage);
        });
    </script>
<?php endif; ?>

<button class="open-button" onclick="openForm()">Neue Präsentation erstellen</button>

<div class="form-popup" id="myForm">
    <form id="uploadForm"
          action="../Backend/php/home/upload_presentation.php"
          method="post"
          enctype="multipart/form-data"
          class="form-container">

        <label for="title">Titel der Präsentation:</label>
        <input type="text" id="title" name="title" required>

        <label for="presentationFile" style="margin-top:10px;">Präsentationsdatei hochladen:</label>
        <input type="file" id="presentationFile" name="presentationFile" accept=".mp4" required>

        <div id="uploadProgressWrap" style="display:none; margin-top:12px;">
            <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:6px;">
                <span id="uploadProgressText">0%</span>
                <span id="uploadEtaText">ETA: --:--</span>
            </div>

            <div style="width:100%; height:10px; background:#eee; border-radius:999px; overflow:hidden;">
                <div id="uploadProgressBar" style="width:0%; height:100%; background:#4CAF50;"></div>
            </div>

            <div id="uploadPhaseText" style="font-size:12px; margin-top:6px;">Warte auf Upload…</div>
        </div>

        <input id="uploadSubmitBtn" type="submit" value="Erstellen">
        <button type="button" class="btn-cancel" onclick="closeForm()">Schließen</button>
    </form>
</div>

<h2>Vorhandene Präsentationen</h2>

<div id="presentationsWrap">
    <?php require "../Backend/php/home/load_presentations.php"; ?>
    <table class="presentations-table">
        <?php loadPresentations($conn); ?>
    </table>
</div>

<script src="../Backend/js/script_home.js"></script>

<?php
require "../Backend/database/connect.php";

$error = $_SESSION['error'] ?? null;
unset($_SESSION['error']);
?>

<!-- Home Seite nach dem Login -->
<!-- Erstellt von Johannes Braun -->

<!-- Einbindung StyleSheet -->
<link rel="stylesheet" href="css/style_home.css">

<h1>Willkommen, <?php echo htmlspecialchars($_SESSION['username']); ?>!</h1>

<!-- Fehlermeldung anzeigen, falls vorhanden -->
<?php if ($error): ?>
    <script>
        const errorMessage = <?php echo json_encode($error); ?>;
        window.addEventListener('DOMContentLoaded', function () {
            alert(errorMessage);
        });
    </script>
<?php endif; ?>

<!-- Button zum Erstellen einer neuen Präsentation -->
<button class="open-button" onclick="openForm()">Neue Präsentation erstellen</button>

<div class="form-popup" id="myForm">
    <form action="../Backend/php/home/upload_presentation.php" method="post" enctype="multipart/form-data" class="form-container">
        <label for="title">Titel der Präsentation:</label>
        <input type="text" id="title" name="title" required>
        <br>
        <label for="presentationFile">Präsentationsdatei hochladen:</label>
        <input type="file" id="presentationFile" name="presentationFile" accept=".mp4">

        <input type="submit" value="Erstellen">
        <button type="button" class="btn-cancel" onclick="closeForm()">Schließen</button>
    </form>
</div>

<h2>Vorhandene Präsentationen</h2>

<!-- Einbindung des JavaScript-Codes -->
<script src="../Backend/js/script_home.js"></script>
<!-- Laden und Anzeigen der Präsentationen -->
<?php require "../Backend/php/home/load_presentations.php"; ?>
<table class="presentations-table">
    <?php loadPresentations($conn); ?>
</table>

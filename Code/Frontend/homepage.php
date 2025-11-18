<?php
session_start();
require "../Backend/database/connect.php";

// Zugriffsschutz
if (empty($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Bitte melde dich zuerst an.';
    $_SESSION['form']  = 'signIn';
    header("Location: ../index.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PresentAI - Hauptseite</title>
    <link rel="stylesheet" href="css/style_homepage.css">
</head>
<body>

    <div class="header-title">PresentAI</div>

    <div class="tab">
        <button class="tablinks" onclick="tab(event, 'home')" id="defaultOpen">Home</button>
        <button class="tablinks" onclick="tab(event, 'analyse')">Analyse</button>
        <button class="tablinks" onclick="tab(event, 'einstellungen')">Einstellungen</button>
        <button class="tablinks logout-btn" onclick="window.location.href='../Backend/php/login-system/logout.php'">Ausloggen</button>
    </div>

    <div id="home" class="tabcontent">
        <?php include "home.php"; ?>
    </div>

    <div id="analyse" class="tabcontent">
        <?php include "analyse.php"; ?>
    </div>

    <div id="einstellungen" class="tabcontent">
        <?php include "einstellungen.php"; ?>
    </div>

    <script src="../Backend/js/tabs.js"></script>
</body>
</html>

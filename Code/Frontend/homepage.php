<?php
session_start();
require_once "../Backend/database/connect.php";

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
        <button class="tablinks logout-btn" onclick="window.location.href='login-system/logout.php'">Ausloggen</button>
    </div>

    <div id="home" class="tabcontent">
        <h3>Home</h3>
        <p>Willkommen, <?php echo htmlspecialchars($_SESSION['username']); ?>!</p>
    </div>

    <div id="analyse" class="tabcontent">
        <h3>Analyse</h3>
        <p>Analyse Seite!</p> 
    </div>

    <div id="einstellungen" class="tabcontent">
        <h3>Einstellungen</h3>
        <p>Einstellungen Seite!</p>
    </div>

    <script src="../Backend/js/tabs.js"></script>
</body>
</html>


<!-- Für Username-Anzeige -->
<!--
    <?php
        if(isset($_SESSION["username"])){
            $username=$_SESSION["username"];
            $query=mysqli_query($conn, "SELECT user.* From `user` WHERE user.username='$username'");
            while($row=mysqli_fetch_array($query)){
                echo $row["username"];
            }
        }
    ?>
-->
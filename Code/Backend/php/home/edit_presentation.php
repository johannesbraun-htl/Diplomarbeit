<?php
// Backend/php/home/edit_presentation.php

session_start();
require "../../database/connect.php";

if (empty($_SESSION['user_id'])) {
    $_SESSION['error'] = "Bitte melde dich zuerst an.";
    header("Location: ../../../Frontend/main.php");
    exit;
}

$currentUserId = (int)$_SESSION['user_id'];

if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
    $_SESSION['error'] = "Ungültige Präsentations-ID.";
    header("Location: ../../../Frontend/main.php");
    exit;
}

$presentationId = (int)$_GET['id'];

// Aktuell nur als Platzhalter – später kannst du hier Edit-Formular einbauen
$sql = "
    SELECT titel, created
    FROM presentations
    WHERE presentations_id = ?
      AND fk_user_id = ?
";

if (!$stmt = $conn->prepare($sql)) {
    $_SESSION['error'] = "Fehler beim Laden der Präsentation.";
    header("Location: ../../../Frontend/main.php");
    exit;
}

$stmt->bind_param("ii", $presentationId, $currentUserId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error'] = "Präsentation wurde nicht gefunden.";
    $stmt->close();
    header("Location: ../../../Frontend/main.php");
    exit;
}

$pres = $result->fetch_assoc();
$stmt->close();

$titel   = htmlspecialchars($pres['titel'] ?? '', ENT_QUOTES, 'UTF-8');
$created = htmlspecialchars($pres['created'] ?? '', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Präsentation bearbeiten</title>
</head>
<body>
    <h1>Präsentation bearbeiten</h1>
    <p><strong>Titel (aktuell):</strong> <?php echo $titel; ?></p>
    <p><strong>Erstellt am:</strong> <?php echo $created; ?></p>

    <p>(Hier kannst du später ein Formular einbauen, um z.B. den Titel zu ändern.)</p>

    <p><a href="../../../Frontend/main.php">Zurück</a></p>
</body>
</html>

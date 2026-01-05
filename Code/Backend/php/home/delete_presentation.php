<?php
// Backend/php/home/delete_presentation.php

session_start();
require "../../database/connect.php";

// Nur eingeloggte Nutzer dürfen löschen
if (empty($_SESSION['user_id'])) {
    $_SESSION['error'] = "Bitte melde dich zuerst an.";
    header("Location: ../../../Frontend/main.php");
    exit;
}

$currentUserId = (int)$_SESSION['user_id'];

// Prüfen, ob eine gültige ID übergeben wurde
if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
    $_SESSION['error'] = "Ungültige Präsentations-ID.";
    header("Location: ../../../Frontend/main.php");
    exit;
}

$presentationId = (int)$_GET['id'];

// Präsentation löschen, aber nur wenn sie dem aktuellen User gehört
$sql = "
    DELETE FROM presentations
    WHERE presentations_id = ?
      AND fk_user_id = ?
";

if (!$stmt = $conn->prepare($sql)) {
    $_SESSION['error'] = "Fehler beim Löschen der Präsentation.";
    header("Location: ../../../Frontend/main.php");
    exit;
}

$stmt->bind_param("ii", $presentationId, $currentUserId);
$stmt->execute();

if ($stmt->affected_rows === 0) {
    // Entweder existiert die ID nicht oder gehört nicht zu diesem User
    $_SESSION['error'] = "Präsentation konnte nicht gelöscht werden.";
} else {
    $_SESSION['success'] = "Präsentation wurde erfolgreich gelöscht.";
}

$stmt->close();

// Zurück auf die Hauptseite (Home-Tab lädt automatisch die aktualisierte Liste)
header("Location: ../../../Frontend/main.php");
exit;

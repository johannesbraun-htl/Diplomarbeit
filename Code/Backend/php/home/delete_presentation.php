<?php
require "../../database/connect.php";

$titel = $_GET['titel'] ?? null;

if ($titel === null) {
    echo "Kein Titel übergeben!";
    exit;
}

$sql = "DELETE FROM `h109556_presentai_v2`.`presentations` WHERE `titel` = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo "Fehler beim Vorbereiten der Löschanfrage: " . htmlspecialchars($conn->error);
    exit;
}
$stmt->bind_param("s", $titel);
if ($stmt->execute()) {
    header("Location: ../../../Frontend/main.php");
    exit();
    
} else {
    echo "Fehler beim Löschen der Präsentation: " . htmlspecialchars($stmt->error);
}
$stmt->close();
?>
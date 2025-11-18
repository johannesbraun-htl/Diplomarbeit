<?php
session_start();
require "../database/connect.php";

$title = $_POST['title'] ?? '';
$fk_user_id = $_SESSION['user_id'] ?? null;

$TBL_PRESENTATIONS = "`h109556_presentai_v2`.`presentations`";

function redirect_with_error($message) {
    $_SESSION['error'] = $message;
    header("Location: ../../Frontend/homepage.php");
    exit();
}

if ($fk_user_id === null) {
    redirect_with_error("Fehler: Keine gültige Sitzung. Bitte erneut einloggen.");
}

if ($title === '') {
    redirect_with_error("Fehler: Titel darf nicht leer sein.");
}

// Existenzcheck
$sql = "SELECT 1 FROM $TBL_PRESENTATIONS WHERE titel = ? AND fk_user_id = ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    redirect_with_error("DB-Fehler (Prepare Check): " . $conn->error);
}

$stmt->bind_param("si", $title, $fk_user_id);

if (!$stmt->execute()) {
    $err = $stmt->error;
    $stmt->close();
    redirect_with_error("DB-Fehler (Execute Check): " . $err);
}

$stmt->store_result();
if ($stmt->num_rows > 0) {
    $stmt->close();
    redirect_with_error("Fehler: Der Titel ist bereits vergeben.");
}
$stmt->close();

// INSERT
$sql = "INSERT INTO $TBL_PRESENTATIONS (`fk_user_id`, `titel`) VALUES (?, ?)";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    redirect_with_error("DB-Fehler (Prepare Insert): " . $conn->error);
}

$stmt->bind_param("is", $fk_user_id, $title);

if (!$stmt->execute()) {
    $err = $stmt->error;
    $stmt->close();
    redirect_with_error("DB-Fehler (Execute Insert): " . $err);
}

$stmt->close();

header("Location: ../../Frontend/homepage.php");
exit();
?>

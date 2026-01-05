<?php
session_start();
require "../../database/connect.php";

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}
if (isset($conn) && $conn instanceof mysqli) {
    @$conn->set_charset('utf8mb4');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../../../index.php");
    exit();
}

$username         = trim($_POST['username'] ?? '');
$password         = $_POST['password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';
$doSignUp         = isset($_POST['signUp']);
$doSignIn         = isset($_POST['signIn']);

// Schema + Tabelle
$TBL_USER = "`h109556_presentai_v2`.`user`";

function flash_redirect(string $type, string $message, string $form = 'signIn'): void {
    $_SESSION[$type] = $message;
    $_SESSION['form'] = $form;
    header("Location: ../../../index.php");
    exit();
}

if ($doSignUp) {
    $_SESSION['form'] = 'signUp';

    if ($username === '' || $password === '' || $confirm_password === '') {
        flash_redirect('error', 'Bitte alle Felder ausfüllen.', 'signUp');
    }
    if ($password !== $confirm_password) {
        flash_redirect('error', 'Passwörter stimmen nicht überein.', 'signUp');
    }

    // Existenzcheck
    $sql = "SELECT 1 FROM $TBL_USER WHERE username = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        flash_redirect('error', 'SQL-Fehler (C01): ' . $conn->error, 'signUp');
    }
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->close();
        flash_redirect('error', 'Benutzername bereits vergeben.', 'signUp');
    }
    $stmt->close();

    // Insert
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $sql = "INSERT INTO $TBL_USER (username, password) VALUES (?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        flash_redirect('error', 'SQL-Fehler (C02): ' . $conn->error, 'signUp');
    }
    $stmt->bind_param("ss", $username, $hash);
    if (!$stmt->execute()) {
        $stmt->close();
        flash_redirect('error', 'Registrierung fehlgeschlagen: ' . $conn->error, 'signUp');
    }
    $stmt->close();

    flash_redirect('success', 'Registrierung erfolgreich. Bitte einloggen.', 'signIn');
}

if ($doSignIn) {
    $_SESSION['form'] = 'signIn';

    if ($username === '' || $password === '') {
        flash_redirect('error', 'Bitte Benutzername und Passwort angeben.', 'signIn');
    }

    // WICHTIG: user_id statt id
    $sql = "SELECT user_id, username, password FROM $TBL_USER WHERE username = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        flash_redirect('error', 'SQL-Fehler (L01): ' . $conn->error, 'signIn');
    }
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row || !password_verify($password, $row['password'])) {
        flash_redirect('error', 'Falscher Benutzername oder Passwort.', 'signIn');
    }

    session_regenerate_id(true);
    $_SESSION['user_id']  = (int)$row['user_id']; // <-- hier auf user_id
    $_SESSION['username'] = $row['username'];

    header("Location: ../../../Frontend/main.php");
    exit();
}

// Fallback
header("Location: ../../../index.php");
exit();
?>
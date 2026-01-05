<?php
session_start();
session_unset();
session_destroy();
session_start();

$_SESSION['success'] = 'Du wurdest erfolgreich abgemeldet.';
$_SESSION['form']    = 'signIn';

header("Location: ../../../index.php");
exit();
?>

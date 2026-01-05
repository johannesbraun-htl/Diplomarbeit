<?php
session_start();
require "../../database/connect.php";
require "load_presentations.php";

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    echo json_encode(["ok" => false, "message" => "Keine Session. Bitte neu einloggen."]);
    exit();
}

ob_start();
?>
<table class="presentations-table">
    <?php loadPresentations($conn); ?>
</table>
<?php
$html = ob_get_clean();

echo json_encode(["ok" => true, "html" => $html]);
exit();

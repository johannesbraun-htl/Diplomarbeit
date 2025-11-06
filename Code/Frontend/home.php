<h1>Willkommen, <?php echo htmlspecialchars($_SESSION['username']); ?>!</h1>
<h2>Vorhandene Präsentationen:</h2>
<style>
    table {
        border-collapse: collapse;
        width: 100%;
    }

    th, td {
        padding: 12px 15px;
        border: 1px solid #ddd;
    }

    th {
        background-color: #f4f4f4;
    }

    tr:hover {
        background-color: #f1f1f1;
    }

</style>

<table>
    <?php loadPresentations($conn); ?>
</table>


<!-- Funktion zum laden der Präsentationen aus der Datenbank -->
<?php
function loadPresentations($conn) {
    $username=$_SESSION["username"];
    $sql = "SELECT 
                p.titel,
                p.created,
                k.avarage_wpm,
                k.filling_words_count,
                k.score
            FROM `h109556_presentai_v2`.`kpi` AS k
            JOIN `h109556_presentai_v2`.`presentations` AS p
                ON p.`presentations_id` = k.`fk_presentations_id`
            JOIN `h109556_presentai_v2`.`user` AS u
                ON u.`user_id` = p.`fk_user_id`
            WHERE u.`username` = '$username'";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo "<tr><td>Fehler beim Laden der Präsentationen: " . htmlspecialchars($conn->error) . "</td></tr>";
        return;
    }
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo "<tr><td colspan='4'>Keine Präsentationen gefunden.</td></tr>";
    } else {
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<th>Titel</th>";
            echo "<th>Erstellt am</th>";
            echo "<th>WPM</th>";
            echo "<th>Füllwörter</th>";
            echo "<th>Score</th>";
            echo "<th>Aktionen</th>";
            echo "</tr>";
            echo "<td>" . htmlspecialchars($row['titel']) . "</td>";
            echo "<td>" . htmlspecialchars($row['created']) . "</td>";
            echo "<td>" . htmlspecialchars($row['avarage_wpm']) . "</td>";
            echo "<td>" . htmlspecialchars($row['filling_words_count']) . "</td>";
            echo "<td>" . htmlspecialchars($row['score']) . "</td>";
            echo "<td>
                    <a href='#'>Ansehen</a> 
                    <a href='#'>Bearbeiten</a> 
                    <a href='#'>Löschen</a>
                </td>";
            echo "</tr>";
        }
    }
    $stmt->close();
}
?>
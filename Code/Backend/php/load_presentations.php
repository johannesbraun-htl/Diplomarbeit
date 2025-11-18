<?php
/* Funktion zum Laden der Präsentationen aus der Datenbank */
function loadPresentations($conn) {
    if (!isset($_SESSION["username"])) {
        echo "<tr><td>Fehler: Kein Benutzer angemeldet.</td></tr>";
        return;
    }

    $username = $_SESSION["username"];

    $sql = "SELECT 
                p.titel,
                p.created,
                k.avarage_wpm,
                k.filling_words_count,
                k.score
            FROM `h109556_presentai_v2`.`presentations` AS p
            JOIN `h109556_presentai_v2`.`user` AS u
                ON u.`user_id` = p.`fk_user_id`
            LEFT JOIN `h109556_presentai_v2`.`kpi` AS k
                ON p.`presentations_id` = k.`fk_presentations_id`
            WHERE u.`username` = ?";


    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo "<tr><td>Fehler beim Laden der Präsentationen: " . htmlspecialchars($conn->error) . "</td></tr>";
        return;
    }

    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo "<tr><td colspan='6'>Keine Präsentationen gefunden.</td></tr>";
    } else {
        // Tabellenkopf nur einmal
        echo "<tr>";
        echo "<th>Titel</th>";
        echo "<th>Erstellt am</th>";
        echo "<th>WPM</th>";
        echo "<th>Füllwörter</th>";
        echo "<th>Score</th>";
        echo "<th>Aktionen</th>";
        echo "</tr>";

        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['titel']) . "</td>";
            echo "<td>" . htmlspecialchars($row['created']) . "</td>";
            echo "<td>" . htmlspecialchars($row['avarage_wpm']) . "</td>";
            echo "<td>" . htmlspecialchars($row['filling_words_count']) . "</td>";
            echo "<td>" . htmlspecialchars($row['score']) . "</td>";
            echo "<td class='actions-cell'>
                    <a href='#'>Ansehen</a> 
                    <a href='#'>Bearbeiten</a> 
                    <a href='#' class='danger'>Löschen</a>
                  </td>";
            echo "</tr>";
        }
    }

    $stmt->close();
}
?>

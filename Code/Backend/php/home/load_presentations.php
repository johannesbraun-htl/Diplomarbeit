<?php
// Backend/php/home/load_presentations.php

function loadPresentations(mysqli $conn): void
{
    // Session muss vom Aufrufer bereits gestartet sein
    if (empty($_SESSION['username'])) {
        echo "<p>Fehler: Kein Benutzer angemeldet.</p>";
        return;
    }

    $username = $_SESSION['username'];

    // Alle Präsentationen des aktuellen Users inkl. KPI laden
    $sql = "
        SELECT 
            p.presentations_id,
            p.titel,
            p.created,
            k.avarage_wpm,
            k.filling_words_count,
            k.score
        FROM presentations AS p
        JOIN user AS u 
            ON u.user_id = p.fk_user_id
        LEFT JOIN kpi AS k 
            ON p.presentations_id = k.fk_presentations_id
        WHERE u.username = ?
        ORDER BY p.created DESC
    ";

    if (!$stmt = $conn->prepare($sql)) {
        echo "<p>Fehler beim Vorbereiten der Anfrage.</p>";
        return;
    }

    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    // Keine Präsentationen gefunden
    if ($result->num_rows === 0) {
        echo '<table class="presentations-table">';
        echo '<tr><td>Keine Präsentationen gefunden.</td></tr>';
        echo '</table>';
        $stmt->close();
        return;
    }

    // Tabelle mit Kopfzeile ausgeben
    echo '<table class="presentations-table">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Titel</th>';
    echo '<th>Erstellt am</th>';
    echo '<th>WPM</th>';
    echo '<th>Füllwörter</th>';
    echo '<th>Score</th>';
    echo '<th>Aktionen</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    while ($row = $result->fetch_assoc()) {
        $presentationId = (int)$row['presentations_id'];
        $titel          = htmlspecialchars($row['titel'] ?? '', ENT_QUOTES, 'UTF-8');
        $created        = htmlspecialchars($row['created'] ?? '', ENT_QUOTES, 'UTF-8');

        $wpm            = $row['avarage_wpm'] !== null
            ? htmlspecialchars($row['avarage_wpm'], ENT_QUOTES, 'UTF-8')
            : '-';

        $fillWords      = $row['filling_words_count'] !== null
            ? htmlspecialchars($row['filling_words_count'], ENT_QUOTES, 'UTF-8')
            : '-';

        $score          = $row['score'] !== null
            ? htmlspecialchars($row['score'], ENT_QUOTES, 'UTF-8')
            : '-';

        // WICHTIG: Ansehen führt jetzt in den Analyse-Tab von main.php
        // main.php liegt im Ordner Frontend/, aktuelle Seite ist auch Frontend/main.php
        // → Link relativ dazu: ./main.php?tab=analyse&id=...
        $viewUrl   = "main.php?tab=analyse&id=" . urlencode($presentationId);

        // Bearbeiten und Löschen bleiben wie vorher (Edit/Platzhalter + sichere Delete-Logik)
        $editUrl   = "../Backend/php/home/edit_presentation.php?id=" . urlencode($presentationId);
        $deleteUrl = "../Backend/php/home/delete_presentation.php?id=" . urlencode($presentationId);

        echo '<tr>';
        echo '<td>' . $titel . '</td>';
        echo '<td>' . $created . '</td>';
        echo '<td>' . $wpm . '</td>';
        echo '<td>' . $fillWords . '</td>';
        echo '<td>' . $score . '</td>';
        echo '<td class="actions">';
        echo '  <a href="' . $viewUrl . '">Ansehen</a> ';
        echo '  <a href="' . $editUrl . '">Bearbeiten</a> ';
        echo '  <a href="' . $deleteUrl . '" class="danger">Löschen</a>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';

    $stmt->close();
}

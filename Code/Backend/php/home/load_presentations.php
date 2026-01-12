<?php
// Backend/php/home/load_presentations.php

function loadPresentations(mysqli $conn): void
{
    if (empty($_SESSION['username'])) {
        echo "<p>Fehler: Kein Benutzer angemeldet.</p>";
        return;
    }

    $username = $_SESSION['username'];

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

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo "<p>Fehler beim Vorbereiten der Anfrage.</p>";
        return;
    }

    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo '<table class="presentations-table">';
        echo '<tr><td>Keine Präsentationen gefunden.</td></tr>';
        echo '</table>';
        $stmt->close();
        return;
    }

    echo '<table class="presentations-table">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Titel</th>';
    echo '<th>Erstellt am</th>';
    echo '<th>WPM</th>';
    echo '<th>Füllwörter</th>';
    echo '<th>Score</th>';
    echo '<th class="col-actions">Aktionen</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    while ($row = $result->fetch_assoc()) {
        $presentationId = (int)$row['presentations_id'];
        $titel          = htmlspecialchars($row['titel'] ?? '', ENT_QUOTES, 'UTF-8');
        $created        = htmlspecialchars($row['created'] ?? '', ENT_QUOTES, 'UTF-8');

        $wpm       = $row['avarage_wpm'] !== null ? htmlspecialchars($row['avarage_wpm'], ENT_QUOTES, 'UTF-8') : '-';
        $fillWords = $row['filling_words_count'] !== null ? htmlspecialchars($row['filling_words_count'], ENT_QUOTES, 'UTF-8') : '-';
        $score     = $row['score'] !== null ? htmlspecialchars($row['score'], ENT_QUOTES, 'UTF-8') : '-';

        // Tabs öffnen (URL wird danach gecleant in main.php)
        $viewUrl   = "main.php?open=analyse&id=" . urlencode((string)$presentationId);
        $editUrl   = "main.php?open=bearbeiten&id=" . urlencode((string)$presentationId);
        $deleteUrl = "../Backend/php/home/delete_presentation.php?id=" . urlencode((string)$presentationId);

        echo '<tr>';
        echo '<td class="cell-title">' . $titel . '</td>';
        echo '<td>' . $created . '</td>';
        echo '<td>' . $wpm . '</td>';
        echo '<td>' . $fillWords . '</td>';
        echo '<td>' . $score . '</td>';

        echo '<td class="actions">';
        echo '  <div class="action-group">';
        echo '    <a class="act-btn act-view" href="' . $viewUrl . '" title="Ansehen">👁️ <span>Ansehen</span></a>';
        echo '    <a class="act-btn act-edit" href="' . $editUrl . '" title="Bearbeiten">✏️ <span>Bearbeiten</span></a>';
        echo '    <a class="act-btn act-del" href="' . $deleteUrl . '" title="Löschen" onclick="return confirm(\'Wirklich löschen?\')">🗑️ <span>Löschen</span></a>';
        echo '  </div>';
        echo '</td>';

        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';

    $stmt->close();
}

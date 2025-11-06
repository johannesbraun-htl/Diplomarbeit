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
    <thead>
        <tr>
            <th>Titel</th>
            <th>Beschreibung</th>
            <th>Erstellt am</th>
            <th>Aktionen</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td></td>
            <td></td>
            <td></td>
            <td>
                <a>Ansehen</a> 
                <a>Bearbeiten</a> 
                <a>Löschen</a>
            </td>
        </tr>
    </tbody>
</table>

<!-- Funktion zum laden der Präsentationen aus der Datenbank -->
<?php
function loadPresentations($conn, $user_id) {
    $sql = "SELECT avarage_wpm, filling_words_count, score FROM kpi WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo "<tr><td colspan='4'>Fehler beim Laden der Präsentationen: " . htmlspecialchars($conn->error) . "</td></tr>";
        return;
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo "<tr><td colspan='4'>Keine Präsentationen gefunden.</td></tr>";
    } else {
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['title']) . "</td>";
            echo "<td>" . htmlspecialchars($row['description']) . "</td>";
            echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
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

<!-- Für Username-Anzeige -->
<!--
    <?php
        if(isset($_SESSION["username"])){
            $username=$_SESSION["username"];
            $query=mysqli_query($conn, "SELECT user.* From `user` WHERE user.username='$username'");
            while($row=mysqli_fetch_array($query)){
                echo $row["username"];
            }
        }
    ?>
-->
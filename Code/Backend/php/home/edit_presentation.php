<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../../database/connect.php";

/**
 * Sicherer Redirect im Tab-Include-Szenario
 */
function smartRedirect(string $url): void
{
    if (!headers_sent()) {
        header("Location: " . $url);
        exit;
    }

    echo '<script>window.location.href=' . json_encode($url) . ';</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url='
        . htmlspecialchars($url, ENT_QUOTES, "UTF-8") . '"></noscript>';
    exit;
}

function goHome(string $msg = "", bool $success = false): void
{
    if ($msg !== "") {
        if ($success) {
            $_SESSION['success'] = $msg;
        } else {
            $_SESSION['error'] = $msg;
        }
    }

    // Wichtig: relativ zu Frontend/main.php
    smartRedirect("main.php");
}

/* ---------------- Auth ---------------- */

if (empty($_SESSION['user_id'])) {
    goHome("Bitte melde dich zuerst an.");
}

$userId = (int)$_SESSION['user_id'];

/* ---------------- Präsentations-ID ----------------
   - zuerst aus GET (beim Öffnen)
   - danach aus Session (URL wurde gecleant)
--------------------------------------------------- */

$presentationId = 0;

if (isset($_GET['id']) && ctype_digit($_GET['id'])) {
    $presentationId = (int)$_GET['id'];
    $_SESSION['active_edit_id'] = $presentationId;
} elseif (!empty($_SESSION['active_edit_id']) && is_numeric($_SESSION['active_edit_id'])) {
    $presentationId = (int)$_SESSION['active_edit_id'];
}

/* ---------------- Laden ---------------- */

$errors = [];
$p = [
    'titel'   => '',
    'created' => ''
];

if ($presentationId <= 0) {
    $errors[] = "Keine Präsentation ausgewählt. Bitte über die Home-Tabelle bearbeiten.";
} else {
    $stmt = $conn->prepare(
        "SELECT titel, created
         FROM presentations
         WHERE presentations_id = ?
           AND fk_user_id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        $errors[] = "Datenbankfehler beim Laden.";
    } else {
        $stmt->bind_param("ii", $presentationId, $userId);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 0) {
            unset($_SESSION['active_edit_id']);
            $errors[] = "Präsentation nicht gefunden.";
        } else {
            $p = $res->fetch_assoc();
        }

        $stmt->close();
    }
}

/* ---------------- POST: Speichern ----------------
   -> NUR doppelten Titel prüfen (+ leer verhindern)
-------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors) && $presentationId > 0) {

    $title = trim($_POST['title'] ?? '');

    if ($title === '') {
        $errors[] = "Titel darf nicht leer sein.";
    } else {
        $dup = $conn->prepare(
            "SELECT presentations_id
             FROM presentations
             WHERE fk_user_id = ?
               AND titel = ?
               AND presentations_id <> ?
             LIMIT 1"
        );

        if (!$dup) {
            $errors[] = "Datenbankfehler bei der Titelprüfung.";
        } else {
            $dup->bind_param("isi", $userId, $title, $presentationId);
            $dup->execute();
            $dupRes = $dup->get_result();

            if ($dupRes->num_rows > 0) {
                $errors[] = "Diesen Titel gibt es bei dir bereits. Bitte wähle einen anderen.";
            }

            $dup->close();
        }
    }

    if (empty($errors)) {
        $upd = $conn->prepare(
            "UPDATE presentations
             SET titel = ?
             WHERE presentations_id = ?
               AND fk_user_id = ?
             LIMIT 1"
        );

        if (!$upd) {
            $errors[] = "Datenbankfehler beim Speichern.";
        } else {
            $upd->bind_param("sii", $title, $presentationId, $userId);
            $upd->execute();
            $upd->close();

            // Bearbeiten schließen
            unset($_SESSION['active_edit_id']);

            goHome("Titel gespeichert.", true);
        }
    }
}

/* ---------------- View-Daten ---------------- */

$prefillTitle = htmlspecialchars(
    ($_SERVER['REQUEST_METHOD'] === 'POST'
        ? ($_POST['title'] ?? '')
        : ($p['titel'] ?? '')
    ),
    ENT_QUOTES,
    "UTF-8"
);

$safeCreated = htmlspecialchars($p['created'] ?? '', ENT_QUOTES, "UTF-8");
?>

<!-- ===================== HTML ===================== -->

<div class="edit-wrap">

  <div class="edit-card">
    <h2>Präsentation bearbeiten</h2>

    <?php if (!empty($errors)): ?>
      <div class="ev-alert ev-alert--error">
        <strong>Hinweis:</strong>
        <ul>
          <?php foreach ($errors as $err): ?>
            <li><?= htmlspecialchars($err, ENT_QUOTES, "UTF-8") ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form
      method="post"
      class="edit-form"
      action="main.php?open=bearbeiten"
    >
      <label>Titel</label>
      <input
        type="text"
        name="title"
        value="<?= $prefillTitle ?>"
        required
        <?= ($presentationId <= 0 ? 'disabled' : '') ?>
      >

      <div class="edit-actions">
        <button
          class="btn-primary"
          type="submit"
          <?= ($presentationId <= 0 ? 'disabled' : '') ?>
        >
          Speichern
        </button>

        <a class="btn-secondary" href="main.php">Abbrechen</a>
      </div>
    </form>
  </div>

  <div class="edit-card meta">
    <h3>Informationen</h3>
    <p>
      <strong>Erstellt am:</strong><br>
      <?= $safeCreated ?>
    </p>
  </div>

</div>

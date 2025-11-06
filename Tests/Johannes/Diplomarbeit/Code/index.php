<?php
session_start();
$error   = $_SESSION['error']   ?? null;
$success = $_SESSION['success'] ?? null;
$form    = $_SESSION['form']    ?? 'signIn';
unset($_SESSION['error'], $_SESSION['success'], $_SESSION['form']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login & Registrierung</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="Frontend/css/style.css">
</head>
<body data-form="<?php echo htmlspecialchars($form, ENT_QUOTES, 'UTF-8'); ?>">

<div class="stack">

    <!-- Registrierung -->
    <div class="container" id="signUp" style="display:none;">
        <h1 class="form-title">Registrieren</h1>
        <form method="POST" action="Frontend/login-system/register_and_login.php">
            <div class="input-group">
                <i class="fas fa-user"></i>
                <input type="text" name="username" id="su_username" placeholder="Username" required>
                <label for="su_username">Username</label>
            </div>
            <div class="input-group">
                <i class="fas fa-lock"></i>
                <input type="password" name="password" id="su_password" placeholder="Passwort" required>
                <label for="su_password">Passwort</label>
            </div>
            <div class="input-group">
                <i class="fas fa-lock"></i>
                <input type="password" name="confirm_password" id="su_confirm" placeholder="Passwort bestätigen" required>
                <label for="su_confirm">Passwort bestätigen</label>
            </div>
            <input type="submit" class="btn" value="Registrieren" name="signUp">
        </form>
        <div class="links">
            <p>Bereits registriert?</p>
            <button id="signInButton" type="button">Login</button>
        </div>
    </div>

    <!-- Login -->
    <div class="container" id="signIn">
        <h1 class="form-title">Login</h1>
        <form method="POST" action="Frontend/login-system/register_and_login.php">
            <div class="input-group">
                <i class="fas fa-user"></i>
                <input type="text" name="username" id="li_username" placeholder="Username" required>
                <label for="li_username">Username</label>
            </div>
            <div class="input-group">
                <i class="fas fa-lock"></i>
                <input type="password" name="password" id="li_password" placeholder="Passwort" required>
                <label for="li_password">Passwort</label>
            </div>
            <input type="submit" class="btn" value="Login" name="signIn">
        </form>
        <div class="links">
            <p>Noch keinen Account?</p>
            <button id="signUpButton" type="button">Registrieren</button>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

</div>

<script src="Backend/js/script.js"></script>
</body>
</html>

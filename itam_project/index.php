<?php
session_start(); // Session starten

require_once "inc/system.php"; // system.php einbinden

$content = ""; // Initial leerer Inhalt

// Prüfen, ob eine Aktion existiert
if (isset($_GET['act'])) {
    $action = "act_" . $_GET['act'];

    if (function_exists($action)) {
        ob_start(); // Startet den Output-Buffer
        $action();  // Führt die Funktion aus (z.B. act_list_customer oder act_login)
        $content = ob_get_clean(); // Holt den generierten HTML-Code
    } else {
        $content = "<p class='error-msg'>❌ Fehler: Die Aktion '$action' existiert nicht!</p>";
    }
} else {
    // Falls keine Aktion gesetzt ist, prüfe, ob der Benutzer eingeloggt ist
    if (isset($_SESSION['user_id'])) {
        header("Location: index.php?act=list_customer"); // Weiterleitung zur Kundenliste
        exit();
    } else {
        header("Location: index.php?act=login"); // Weiterleitung zur Login-Seite
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IT-Asset Management</title>
    <!-- Bootstrap einbinden -->
    <link rel="stylesheet" href="bootstrap/css/bootstrap.min.css">
    <script src="bootstrap/js/bootstrap.bundle.min.js"></script>
    <!-- Main CSS -->
    <link rel="stylesheet" href="css/main.css">
</head>
<body>
<!-- Navigation -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="index.php">IT-Asset Management</a>
    </div>
</nav>
<!-- Hauptinhalt -->
<div class="container mt-4">
    <?php echo $content; ?>
</div>
</body>
</html>

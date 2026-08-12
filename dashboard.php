<?php

session_start();

// =========================================
// CHECK LOGIN
// =========================================

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true
) {
    header('Location: login.php');
    exit;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>MCO Portal Dashboard</title>
</head>

<body>

    <h1>Welcome, <?= htmlspecialchars($_SESSION['user_name']) ?>!</h1>

    <p>
        You are successfully logged in.
    </p>

    <p>
        Email:
        <?= htmlspecialchars($_SESSION['user_email']) ?>
    </p>

    <a href="logout.php">
        Logout
    </a>

</body>

</html>
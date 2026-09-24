<?php
session_start();
require_once '../includes/functions.php';

if (empty($_SESSION['admin'])) {
    header('Location: login.php');
    exit;
}

// Logout (POST only, so a link on another site can't log the admin out)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: login.php');
    exit;
}

$username = $_SESSION['admin_username'] ?? 'admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Admin Dashboard - PixelCraft</title>
    <link rel="stylesheet" href="<?= asset('../', 'assets/css/style.css') ?>">
</head>
<body>

<header class="site-header">
    <nav class="nav">
        <a href="index.php" class="logo">Pixel<span>Craft</span> <small class="admin-tag">Admin</small></a>
        <div class="admin-user">
            <span>👤 <?= e($username) ?></span>
            <form method="POST">
                <button type="submit" name="logout" value="1" class="btn btn-outline btn-sm">Logout</button>
            </form>
        </div>
    </nav>
</header>

<main class="section">
    <div class="admin-welcome">
        <h1>Welcome to <span class="red">PixelCraft</span> Admin Panel</h1>
        <p>You're logged in as <strong><?= e($username) ?></strong>. Product management and price sync are coming next.</p>
    </div>

    <div class="admin-grid">
        <div class="admin-tile is-soon">
            <span class="admin-tile-icon">📦</span>
            <h3>Products</h3>
            <p>Add, edit and remove products.</p>
            <span class="soon-tag">Coming soon</span>
        </div>
        <div class="admin-tile is-soon">
            <span class="admin-tile-icon">🔄</span>
            <h3>Price Sync</h3>
            <p>Sync prices from Google Sheets and view sync logs.</p>
            <span class="soon-tag">Coming soon</span>
        </div>
        <a href="../index.php" class="admin-tile" target="_blank" rel="noopener">
            <span class="admin-tile-icon">🌐</span>
            <h3>View Store</h3>
            <p>Open the public website in a new tab.</p>
        </a>
    </div>
</main>

</body>
</html>

<?php
session_start();
require_once '../includes/functions.php';

if (($_SESSION['admin_logged_in'] ?? false) !== true) {
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
        <a href="index.php" class="logo"><?= logo_img('../') ?> <small class="admin-tag">Admin</small></a>
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
        <p>You're logged in as <strong><?= e($username) ?></strong>. Manage your products and sync prices below.</p>
    </div>

    <div class="admin-grid">
        <a href="products.php" class="admin-tile">
            <span class="admin-tile-icon">📦</span>
            <h3>Products</h3>
            <p>Edit, hide and remove products, and manage PC variants.</p>
        </a>
        <a href="sync.php" class="admin-tile">
            <span class="admin-tile-icon">🔄</span>
            <h3>Price Sync</h3>
            <p>Upload the Price Sync Excel file to update all PC variant prices.</p>
        </a>
        <a href="../index.php" class="admin-tile" target="_blank" rel="noopener">
            <span class="admin-tile-icon">🌐</span>
            <h3>View Store</h3>
            <p>Open the public website in a new tab.</p>
        </a>
    </div>
</main>

</body>
</html>

<?php
// Set $base ('' for root pages, '../' for pages/), $page_title and optionally $lang before including.
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/functions.php';

$base       = $base ?? '';
$page_title = $page_title ?? 'PixelCraft - Custom PC Store';
$lang       = $lang ?? 'en';
$cart_count = isset($_SESSION['cart']) ? max(0, array_sum($_SESSION['cart'])) : 0;
$current    = basename($_SERVER['PHP_SELF']);
// Pages can set $nav_active ('pcs' or 'shop') when the file name alone isn't enough (products.php serves both)
$nav_active = $nav_active ?? ($current === 'pc-categories.php' ? 'pcs' : '');
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Michroma&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset($base, 'assets/css/style.css') ?>">
</head>
<body>

<header class="site-header">
    <nav class="nav">
        <a href="<?= $base ?>index.php" class="logo"><?= logo_img($base) ?></a>

        <button class="nav-toggle" aria-label="Open menu" aria-expanded="false">☰</button>

        <ul class="nav-links">
            <li><a href="<?= $base ?>index.php" class="<?= $current === 'index.php' ? 'active' : '' ?>">Home</a></li>
            <li><a href="<?= $base ?>pages/pc-categories.php" class="<?= $nav_active === 'pcs' ? 'active' : '' ?>">Custom PCs</a></li>
            <li><a href="<?= $base ?>pages/products.php" class="<?= $nav_active === 'shop' ? 'active' : '' ?>">Accessories</a></li>
            <li><a href="<?= $base ?>pages/services.php" class="<?= $current === 'services.php' ? 'active' : '' ?>">Services</a></li>
            <li><a href="<?= $base ?>pages/ansuran.php" class="<?= $current === 'ansuran.php' ? 'active' : '' ?>">Ansuran</a></li>
            <li><a href="<?= $base ?>pages/location.php" class="<?= $current === 'location.php' ? 'active' : '' ?>">Location</a></li>
        </ul>

        <div class="nav-icons">
            <a href="<?= $base ?>pages/cart.php" title="Cart" class="cart-link">
                🛒
                <?php if ($cart_count > 0): ?>
                <span class="cart-count"><?= $cart_count ?></span>
                <?php endif; ?>
            </a>
        </div>
    </nav>
</header>

<main>

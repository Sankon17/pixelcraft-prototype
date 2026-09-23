<?php
// Set $base ('' for root pages, '../' for pages/) and $page_title before including.
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/functions.php';

$base       = $base ?? '';
$page_title = $page_title ?? 'PixelCraft - Custom PC Store';
$cart_count = isset($_SESSION['cart']) ? max(0, array_sum($_SESSION['cart'])) : 0;
$current    = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?></title>
    <link rel="stylesheet" href="<?= $base ?>assets/css/style.css">
</head>
<body>

<header class="site-header">
    <nav class="nav">
        <a href="<?= $base ?>index.php" class="logo">Pixel<span>Craft</span></a>

        <button class="nav-toggle" aria-label="Open menu" aria-expanded="false">☰</button>

        <ul class="nav-links">
            <li><a href="<?= $base ?>index.php" class="<?= $current === 'index.php' ? 'active' : '' ?>">Home</a></li>
            <li><a href="<?= $base ?>pages/pc-categories.php" class="<?= $current === 'pc-categories.php' ? 'active' : '' ?>">Custom PCs</a></li>
            <li><a href="<?= $base ?>pages/products.php" class="<?= in_array($current, ['products.php', 'product.php'], true) ? 'active' : '' ?>">Shop All</a></li>
            <li><a href="<?= $base ?>index.php#partners">Partners</a></li>
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

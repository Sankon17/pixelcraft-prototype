<?php
// Set $base ('' for root pages, '../' for pages/), $page_title and optionally $lang before including.
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/cart.php';

$base       = $base ?? '';
$page_title = $page_title ?? 'PixelCraft - Custom PC Store';
$lang       = $lang ?? 'en';
$cart_count = cart_count();
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
    <script>
        // Intro splash: plays on the first page of a visit only, and never for people who turn off motion.
        // Decided here, before the page draws, so the splash doesn't flash in late
        try {
            if (!sessionStorage.getItem('pc-intro') && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
                document.documentElement.classList.add('has-intro');
                sessionStorage.setItem('pc-intro', '1');
            }
        } catch (e) {}
    </script>
</head>
<body class="site">

<div class="intro-screen" aria-hidden="true">
    <div class="intro-inner">
        <img src="<?= e(asset($base, 'assets/images/logo/pixelcraft-mark.png')) ?>" alt="" class="intro-logo">
        <span class="intro-bar"><span></span></span>
        <p class="intro-tag">Custom PC Builder</p>
    </div>
</div>

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
            <a href="<?= $base ?>pages/cart.php" class="cart-link<?= in_array($current, ['cart.php', 'checkout.php'], true) ? ' active' : '' ?>"
               aria-label="Cart, <?= $cart_count ?> item<?= $cart_count === 1 ? '' : 's' ?>">
                <span aria-hidden="true">🛒</span><span class="cart-label">Cart</span>
                <?php if ($cart_count > 0): ?>
                <span class="cart-count" aria-hidden="true"><?= $cart_count ?></span>
                <?php endif; ?>
            </a>
        </div>
    </nav>
</header>

<?php
// One-off message after an action, e.g. "You just added … to the cart." (main.js shows the same box without reloading)
$toast = $_SESSION['toast'] ?? null;
unset($_SESSION['toast']);
?>
<div class="toast-area" aria-live="polite" data-cart-url="<?= $base ?>pages/cart.php">
    <?php if ($toast): ?>
    <div class="toast is-<?= e($toast[0]) ?>" role="status">
        <span class="toast-icon" aria-hidden="true"><?= $toast[0] === 'success' ? '✓' : '!' ?></span>
        <p><?= e($toast[1]) ?></p>
        <?php if ($toast[0] === 'success'): ?><a href="<?= $base ?>pages/cart.php" class="btn btn-sm">View Cart</a><?php endif; ?>
        <button type="button" class="toast-close" aria-label="Close">×</button>
    </div>
    <?php endif; ?>
</div>

<main>

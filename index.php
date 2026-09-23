<?php
require_once 'includes/db.php';
require_once 'includes/functions.php';

$latest = $pdo->query("SELECT p.*, c.name AS category_name, c.slug AS category_slug
    FROM products p JOIN categories c ON p.category_id = c.id
    ORDER BY p.created_at DESC, p.id DESC LIMIT 8")->fetchAll();

$categories = $pdo->query("SELECT c.*, COUNT(p.id) AS product_count, MIN(p.price) AS min_price
    FROM categories c LEFT JOIN products p ON p.category_id = c.id
    GROUP BY c.id ORDER BY c.id")->fetchAll();

$tiers = [];
$accessories = [];
foreach ($categories as $cat) {
    if (isset(pc_tiers()[$cat['slug']])) {
        $tiers[$cat['slug']] = $cat;
    } else {
        $accessories[] = $cat;
    }
}

$base = '';
$page_title = 'PixelCraft - Custom PC Store';
require 'includes/header.php';
?>

<!-- HERO: PARTNERSHIP -->
<section class="hero">
    <div class="hero-inner">
        <div class="hero-content">
            <div class="hero-badge">Official Partner</div>
            <h1>Powered by <span class="red">Armageddon</span> &amp; <span class="red">TT Racing</span></h1>
            <p>Malaysia's premier custom PC builder, featuring exclusive peripherals and gaming chairs from our brand partners.</p>
            <div class="hero-btns">
                <a href="pages/pc-categories.php" class="btn">Shop Custom PCs</a>
                <a href="#categories" class="btn btn-outline">Browse Accessories</a>
            </div>
        </div>
        <div class="hero-partners">
            <a href="pages/products.php?category=keyboard" class="partner-card">
                <img src="assets/images/partners/armageddon.png" alt="Armageddon" class="partner-logo-img">
                <span class="partner-desc">Keyboards · Mice · Headsets · Monitors</span>
            </a>
            <a href="pages/products.php?category=gaming-chairs" class="partner-card">
                <img src="assets/images/partners/tt-racing.png" alt="TT Racing" class="partner-logo-img">
                <span class="partner-desc">Pro-grade gaming chairs</span>
            </a>
        </div>
    </div>
</section>

<!-- LATEST PRODUCTS / NEWS -->
<section class="section">
    <div class="section-head">
        <h2 class="section-title">What's New</h2>
        <a href="pages/products.php" class="section-link">View all →</a>
    </div>

    <?php if ($latest): ?>
    <div class="product-grid">
        <?php foreach ($latest as $product): ?>
        <a href="pages/product.php?id=<?= (int)$product['id'] ?>" class="product-card">
            <span class="product-tag">New</span>
            <?= product_image($product, $base) ?>
            <div class="product-brand"><?= e($product['category_name']) ?></div>
            <h3><?= e($product['name']) ?></h3>
            <p class="price">RM <?= number_format($product['price'], 2) ?></p>
        </a>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty-state">New arrivals are on the way. Check back soon.</div>
    <?php endif; ?>
</section>

<!-- SHOP BY CATEGORY -->
<section class="section" id="categories">
    <div class="section-head">
        <h2 class="section-title">Shop by Category</h2>
        <div class="scroll-arrows">
            <button type="button" class="scroll-btn" data-scroll="-1" aria-label="Previous categories">‹</button>
            <button type="button" class="scroll-btn" data-scroll="1" aria-label="Next categories">›</button>
        </div>
    </div>
    <div class="category-scroller">
        <a href="pages/pc-categories.php" class="cat-tile cat-tile-feature">
            <?php $img = category_image($base, 'custom-pcs'); ?>
            <div class="cat-media"<?= $img ? ' style="background-image:url(\'' . e($img) . '\')"' : '' ?>>
                <?php if (!$img): ?><span class="cat-icon">🖥️</span><?php endif; ?>
            </div>
            <div class="cat-info">
                <span class="cat-label">Custom PCs</span>
                <span class="cat-sub">Office · Editing · Gaming</span>
                <span class="cat-cta">Shop now →</span>
            </div>
        </a>
        <?php foreach ($accessories as $cat): ?>
        <?php $img = category_image($base, $cat['slug']); ?>
        <a href="pages/products.php?category=<?= urlencode($cat['slug']) ?>" class="cat-tile">
            <div class="cat-media"<?= $img ? ' style="background-image:url(\'' . e($img) . '\')"' : '' ?>>
                <?php if (!$img): ?><span class="cat-icon"><?= category_icon($cat['slug']) ?></span><?php endif; ?>
            </div>
            <div class="cat-info">
                <span class="cat-label"><?= e($cat['name']) ?></span>
                <span class="cat-cta">Shop now →</span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</section>

<!-- CUSTOM PCs: OFFICE / EDITING / GAMING -->
<section class="section-band">
    <div class="section">
        <div class="section-head">
            <h2 class="section-title">Custom PCs, Built for You</h2>
            <a href="pages/pc-categories.php" class="section-link">Compare builds →</a>
        </div>
        <div class="pc-grid">
            <?php foreach (pc_tiers() as $slug => $tier): ?>
            <?php $cat = $tiers[$slug] ?? null; ?>
            <a href="pages/products.php?category=<?= urlencode($slug) ?>" class="pc-tile pc-<?= e($slug) ?>">
                <span class="pc-icon"><?= category_icon($slug) ?></span>
                <h3><?= e($tier['title']) ?> PCs</h3>
                <p class="pc-tagline"><?= e($tier['tagline']) ?></p>
                <ul>
                    <?php foreach ($tier['uses'] as $use): ?>
                    <li><?= e($use) ?></li>
                    <?php endforeach; ?>
                </ul>
                <span class="pc-price">
                    <?= $cat && $cat['min_price'] !== null ? 'From RM ' . number_format($cat['min_price'], 2) : 'Builds coming soon' ?>
                </span>
                <span class="btn">View <?= e($tier['title']) ?> PCs</span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- PARTNERSHIP BANNER -->
<section class="partner-banner" id="partners">
    <div class="partner-banner-inner">
        <h2>Official Brand Partners</h2>
        <p>PixelCraft is proud to be an authorised reseller of Malaysia's top gaming brands</p>
        <div class="partner-feature">
            <div class="partner-feature-card">
                <img src="assets/images/partners/armageddon.png" alt="Armageddon" class="partner-logo-img">
                <p>Malaysian gaming peripherals: keyboards, mice, headsets, monitors and more.</p>
            </div>
            <div class="partner-feature-card">
                <img src="assets/images/partners/tt-racing.png" alt="TT Racing" class="partner-logo-img">
                <p>Ergonomic gaming chairs built for long sessions at the desk.</p>
            </div>
        </div>
    </div>
</section>

<?php require 'includes/footer.php'; ?>

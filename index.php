<?php
require_once 'includes/db.php';
require_once 'includes/functions.php';

// What's New spotlight: the product marked is_featured, otherwise the newest Gaming PC
$featured = $pdo->query("SELECT p.*, c.name AS category_name, c.slug AS category_slug,
        s.cpu, s.gpu, s.ram, s.storage
    FROM products p
    JOIN categories c ON p.category_id = c.id
    LEFT JOIN product_specs s ON s.product_id = p.id
    WHERE p.is_active = 1 AND (p.is_featured = 1 OR c.slug = 'gaming-pcs')
    ORDER BY p.is_featured DESC, p.created_at DESC, p.id DESC
    LIMIT 1")->fetch();

$categories = $pdo->query("SELECT c.*, COUNT(p.id) AS product_count, MIN(p.price) AS min_price
    FROM categories c LEFT JOIN products p ON p.category_id = c.id AND p.is_active = 1
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

<!-- HERO: store photo -->
<section class="hero">
    <div class="hero-inner">
        <div class="hero-content">
            <p class="hero-label">Official Partner of Armageddon &amp; TT Racing</p>
            <h1>Malaysia's Premier Custom PC Builder</h1>
            <p class="hero-sub">Seremban's trusted choice for custom gaming and office PCs</p>
            <div class="hero-btns">
                <a href="pages/pc-categories.php" class="btn">Shop Custom PCs</a>
                <a href="pages/products.php" class="btn btn-light-outline">Browse Accessories</a>
            </div>
        </div>
    </div>
</section>

<!-- LATEST PRODUCTS / NEWS -->
<section class="section">
    <div class="section-head">
        <h2 class="section-title">What's New</h2>
    </div>

    <?php if ($featured): ?>
    <?php
    $spot_specs = array_filter([
        'CPU'     => $featured['cpu'],
        'GPU'     => $featured['gpu'],
        'RAM'     => $featured['ram'],
        'Storage' => $featured['storage'],
    ]);
    ?>
    <div class="spotlight">
        <?= product_image($featured, $base, 'spotlight-img') ?>
        <div class="spotlight-info">
            <span class="spotlight-tag"><?= $featured['category_slug'] === 'gaming-pcs' ? '🆕 Latest Gaming Set' : '🔥 Featured' ?></span>
            <p class="product-brand"><?= e($featured['category_name']) ?></p>
            <h3><?= e($featured['name']) ?></h3>
            <p class="spotlight-price">RM <?= number_format($featured['price'], 2) ?></p>

            <?php if ($spot_specs): ?>
            <ul class="spotlight-specs">
                <?php foreach ($spot_specs as $label => $value): ?>
                <li><span><?= $label ?></span> <?= e($value) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <p class="spotlight-note">Full specs coming soon.</p>
            <?php endif; ?>

            <div class="spotlight-btns">
                <a href="pages/product.php?id=<?= (int)$featured['id'] ?>" class="btn">View Details</a>
                <a href="pages/products.php?type=<?= urlencode($featured['category_slug']) ?>" class="btn btn-outline">See All <?= e($featured['category_name']) ?></a>
            </div>
        </div>
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
                <span class="cat-sub">Laptops · Office · Editing · Gaming</span>
                <span class="cat-cta">Shop now →</span>
            </div>
        </a>
        <?php foreach ($accessories as $cat): ?>
        <?php $img = category_image($base, $cat['slug']); ?>
        <a href="pages/products.php?cat=<?= urlencode($cat['slug']) ?>" class="cat-tile">
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
            <a href="pages/products.php?type=<?= urlencode($slug) ?>" class="pc-tile pc-<?= e($slug) ?>">
                <span class="pc-icon"><?= category_icon($slug) ?></span>
                <h3><?= e($tier['name']) ?></h3>
                <p class="pc-tagline"><?= e($tier['tagline']) ?></p>
                <ul>
                    <?php foreach ($tier['uses'] as $use): ?>
                    <li><?= e($use) ?></li>
                    <?php endforeach; ?>
                </ul>
                <span class="pc-price">
                    <?= $cat && $cat['min_price'] !== null ? 'From RM ' . number_format($cat['min_price'], 2) : 'Coming soon' ?>
                </span>
                <span class="btn">View <?= e($tier['name']) ?></span>
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

        <?php
        // Smaller brands we carry; logo files live in assets/images/partners/ (name shown as text if the file is missing)
        $sellers = [
            ['name' => 'Alcatroz',  'img' => 'alcatroz.png',  'parent' => 'Armageddon', 'desc' => 'Affordable keyboards, mice and PC accessories'],
            ['name' => 'SonicGear', 'img' => 'sonicgear.png', 'parent' => 'Armageddon', 'desc' => 'Speakers, headsets and audio peripherals'],
            ['name' => 'Colorful',  'img' => 'colorful.png',  'parent' => null,         'desc' => 'GPUs, SSDs and PC components'],
        ];
        ?>
        <div class="seller-block">
            <h3>Authorised Sellers</h3>
            <p class="seller-sub">PixelCraft also carries products from these authorised brands</p>
            <div class="seller-grid">
                <?php foreach ($sellers as $s): ?>
                <div class="seller-card">
                    <span class="seller-badge">Authorised Seller</span>
                    <?php $logo = asset_image('', 'partners', $s['img']); ?>
                    <div class="seller-logo">
                        <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="<?= e($s['name']) ?>"><?php else: ?><?= e($s['name']) ?><?php endif; ?>
                    </div>
                    <?php if ($s['parent']): ?>
                    <p class="seller-parent">Sub-brand of <?= e($s['parent']) ?></p>
                    <?php endif; ?>
                    <p><?= e($s['desc']) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<?php require 'includes/footer.php'; ?>

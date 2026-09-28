<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';

// Product listings. Two separate sections share this page:
//   ?type=gaming-pcs   one PC category (reached from Custom PCs); chips switch between PC categories
//   ?cat=mouse         one accessory category
//   (no parameter)     All Accessories — PCs are never mixed in
//   ?category=...      older links, same as ?type= / ?cat=
$filter = trim($_GET['type'] ?? $_GET['cat'] ?? $_GET['category'] ?? '');

$tiers      = pc_tiers();
$categories = $pdo->query("SELECT * FROM categories ORDER BY id")->fetchAll();
$by_slug    = array_column($categories, null, 'slug');

$pc_mode = isset($tiers[$filter]);

if ($pc_mode) {
    // PC categories, in the same order as the Custom PCs page
    $chips = [];
    foreach ($tiers as $slug => $tier) {
        if (isset($by_slug[$slug])) $chips[] = $by_slug[$slug];
    }
} else {
    // Accessories only
    $chips = array_values(array_filter($categories, fn($c) => !isset($tiers[$c['slug']])));
}

$page_url = 'products.php';
$chip_link = function (array $cat) use ($page_url, $tiers): string {
    $param = isset($tiers[$cat['slug']]) ? 'type' : 'cat';
    return $page_url . '?' . $param . '=' . urlencode($cat['slug']);
};

// Which category slugs to show products from (unknown/retired categories fall back to the full list)
$chip_slugs = array_column($chips, 'slug');
if (!in_array($filter, $chip_slugs, true)) {
    $filter = '';
}
$slugs = $filter !== '' ? [$filter] : $chip_slugs;

$products = [];
if ($slugs) {
    $placeholders = implode(',', array_fill(0, count($slugs), '?'));
    // Cheapest first on every listing
    $stmt = $pdo->prepare("SELECT p.*, c.name AS category_name, c.slug AS category_slug
        FROM products p JOIN categories c ON p.category_id = c.id
        WHERE c.slug IN ($placeholders) AND p.is_active = 1 ORDER BY p.price ASC, p.id ASC");
    $stmt->execute($slugs);
    $products = $stmt->fetchAll();
}

if ($filter !== '' && isset($by_slug[$filter]) && in_array($filter, $chip_slugs, true)) {
    $title = $by_slug[$filter]['name'];
} else {
    $title = 'All Accessories';
}
$nav_active = $pc_mode ? 'pcs' : 'shop';
$subtitle = $pc_mode
    ? $tiers[$filter]['tagline']
    : 'Gaming gear and PC accessories from Armageddon, TT Racing and our other partner brands.';

// Hero title: last word in red, like the other page headers
$split = strrpos($title, ' ');
$title_html = $split === false
    ? '<span class="red">' . e($title) . '</span>'
    : e(substr($title, 0, $split)) . ' <span class="red">' . e(substr($title, $split + 1)) . '</span>';

$base = '../';
$page_title = $title . ' - PixelCraft';
require '../includes/header.php';
?>

<section class="page-hero">
    <div class="hero-badge"><?= $pc_mode ? 'Custom PCs' : 'Accessories' ?></div>
    <h1><?= $title_html ?></h1>
    <p><?= e($subtitle) ?></p>
</section>

<section class="section-band">
    <div class="section">
        <div class="listing-bar">
            <?php if ($pc_mode): ?>
            <a href="pc-categories.php" class="back-link">← All PC categories</a>
            <?php endif; ?>
            <span class="listing-count"><?= count($products) ?> product<?= count($products) === 1 ? '' : 's' ?></span>
        </div>

        <div class="category-filter">
            <?php if (!$pc_mode): ?>
            <a href="<?= $page_url ?>" class="chip <?= $filter === '' ? 'active' : '' ?>">All</a>
            <?php endif; ?>
            <?php foreach ($chips as $cat): ?>
            <a href="<?= e($chip_link($cat)) ?>" class="chip <?= $filter === $cat['slug'] ? 'active' : '' ?>">
                <?= e($cat['name']) ?>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if ($products): ?>
        <div class="product-grid">
            <?php foreach ($products as $product): ?>
            <a href="product.php?id=<?= (int)$product['id'] ?>" class="product-card">
                <?= product_image($product, $base) ?>
                <div class="product-brand"><?= e($product['category_name']) ?></div>
                <h3><?= e($product['name']) ?></h3>
                <p class="price">RM <?= number_format($product['price'], 2) ?></p>
                <span class="btn btn-outline btn-block">View Details</span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <strong>Coming soon</strong>
            <p>We're preparing products for this category. Check back soon, or WhatsApp us for a custom build.</p>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php require '../includes/footer.php'; ?>

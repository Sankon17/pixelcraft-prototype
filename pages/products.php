<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';

$category_filter = $_GET['category'] ?? '';

$sql = "SELECT p.*, c.name AS category_name, c.slug AS category_slug
    FROM products p JOIN categories c ON p.category_id = c.id";
if ($category_filter !== '') {
    $stmt = $pdo->prepare("$sql WHERE c.slug = ? ORDER BY p.created_at DESC, p.id DESC");
    $stmt->execute([$category_filter]);
} else {
    $stmt = $pdo->query("$sql ORDER BY p.created_at DESC, p.id DESC");
}
$products = $stmt->fetchAll();

$categories = $pdo->query("SELECT * FROM categories ORDER BY id")->fetchAll();

$current_name = 'All Products';
foreach ($categories as $cat) {
    if ($cat['slug'] === $category_filter) $current_name = $cat['name'];
}

$base = '../';
$page_title = $current_name . ' - PixelCraft';
require '../includes/header.php';
?>

<section class="section">
    <div class="section-head">
        <h2 class="section-title"><?= e($current_name) ?></h2>
    </div>

    <div class="category-filter">
        <a href="products.php" class="chip <?= $category_filter === '' ? 'active' : '' ?>">All</a>
        <?php foreach ($categories as $cat): ?>
        <a href="products.php?category=<?= urlencode($cat['slug']) ?>" class="chip <?= $category_filter === $cat['slug'] ? 'active' : '' ?>">
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
        </a>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty-state">No products in this category yet.</div>
    <?php endif; ?>
</section>

<?php require '../includes/footer.php'; ?>

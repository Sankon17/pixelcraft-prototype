<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';

$stmt = $pdo->prepare("SELECT c.slug, COUNT(p.id) AS product_count, MIN(p.price) AS min_price
    FROM categories c LEFT JOIN products p ON p.category_id = c.id
    WHERE c.slug IN (?, ?, ?) GROUP BY c.id");
$stmt->execute(array_keys(pc_tiers()));
$stats = array_column($stmt->fetchAll(), null, 'slug');

$base = '../';
$page_title = 'Custom PCs - PixelCraft';
require '../includes/header.php';
?>

<section class="page-hero">
    <h1>Custom <span class="red">PCs</span></h1>
    <p>Pick the build that matches how you work and play. Every PC is assembled and tested by PixelCraft.</p>
</section>

<section class="section">
    <div class="pc-grid">
        <?php foreach (pc_tiers() as $slug => $tier): ?>
        <?php $s = $stats[$slug] ?? ['product_count' => 0, 'min_price' => null]; ?>
        <a href="products.php?category=<?= urlencode($slug) ?>" class="pc-tile pc-<?= e($slug) ?>">
            <span class="pc-icon"><?= category_icon($slug) ?></span>
            <h3><?= e($tier['title']) ?> PCs</h3>
            <p class="pc-tagline"><?= e($tier['tagline']) ?></p>
            <ul>
                <?php foreach ($tier['uses'] as $use): ?>
                <li><?= e($use) ?></li>
                <?php endforeach; ?>
            </ul>
            <span class="pc-price">
                <?= $s['min_price'] !== null ? 'From RM ' . number_format($s['min_price'], 2) : 'Builds coming soon' ?>
            </span>
            <span class="pc-count"><?= (int)$s['product_count'] ?> build<?= $s['product_count'] == 1 ? '' : 's' ?> available</span>
            <span class="btn">View <?= e($tier['title']) ?> PCs</span>
        </a>
        <?php endforeach; ?>
    </div>
</section>

<?php require '../includes/footer.php'; ?>

<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';

$slugs = array_keys(pc_tiers());
$placeholders = implode(',', array_fill(0, count($slugs), '?'));
$stmt = $pdo->prepare("SELECT c.slug, COUNT(p.id) AS product_count, MIN(p.price) AS min_price
    FROM categories c LEFT JOIN products p ON p.category_id = c.id AND p.is_active = 1
    WHERE c.slug IN ($placeholders) GROUP BY c.id");
$stmt->execute($slugs);
$stats = array_column($stmt->fetchAll(), null, 'slug');

$base = '../';
$page_title = 'Custom PCs - PixelCraft';
require '../includes/header.php';
?>

<section class="page-hero">
    <div class="hero-badge">Custom Builds</div>
    <h1>Custom <span class="red">PCs</span></h1>
    <p>Pick the build that matches how you work and play. Every PC is assembled and tested by PixelCraft.</p>
</section>

<section class="section-band">
    <div class="section">
        <div class="pc-grid">
            <?php foreach (pc_tiers() as $slug => $tier): ?>
            <?php
            $s = $stats[$slug] ?? ['product_count' => 0, 'min_price' => null];
            $unit = $slug === 'laptops' ? 'model' : 'build';
            ?>
            <a href="products.php?type=<?= urlencode($slug) ?>" class="pc-tile pc-<?= e($slug) ?>">
                <span class="pc-icon"><?= category_icon($slug) ?></span>
                <h3><?= e($tier['name']) ?></h3>
                <p class="pc-tagline"><?= e($tier['tagline']) ?></p>
                <ul>
                    <?php foreach ($tier['uses'] as $use): ?>
                    <li><?= e($use) ?></li>
                    <?php endforeach; ?>
                </ul>
                <span class="pc-price">
                    <?= $s['min_price'] !== null ? 'From RM ' . number_format($s['min_price'], 2) : 'Coming soon' ?>
                </span>
                <span class="pc-count"><?= (int)$s['product_count'] ?> <?= $unit ?><?= $s['product_count'] == 1 ? '' : 's' ?> available</span>
                <span class="btn">View <?= e($tier['name']) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php require '../includes/footer.php'; ?>

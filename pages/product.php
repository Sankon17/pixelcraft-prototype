<?php
session_start();
require_once '../includes/db.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare("SELECT p.*, c.name AS category_name, c.slug AS category_slug
    FROM products p JOIN categories c ON p.category_id = c.id WHERE p.id = ?");
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    header('Location: products.php');
    exit;
}

$stock = (int)$product['stock'];

// Handle Add to Cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $stock > 0) {
    $qty = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
    $in_cart = $_SESSION['cart'][$id] ?? 0;
    $qty = max(1, min($qty, $stock - $in_cart));

    if ($qty > 0 && $in_cart < $stock) {
        $_SESSION['cart'][$id] = $in_cart + $qty;
    }
    header('Location: cart.php');
    exit;
}

$base = '../';
$page_title = $product['name'] . ' - PixelCraft';
require '../includes/header.php';
?>

<section class="section">
    <a href="products.php?category=<?= urlencode($product['category_slug']) ?>" class="back-link">← Back to <?= e($product['category_name']) ?></a>
    <div class="product-detail">
        <?= product_image($product, $base, 'product-detail-img') ?>
        <div class="product-detail-info">
            <p class="product-brand"><?= e($product['category_name']) ?></p>
            <h1><?= e($product['name']) ?></h1>
            <p class="price">RM <?= number_format($product['price'], 2) ?></p>
            <p class="description"><?= nl2br(e($product['description'])) ?></p>

            <?php if ($stock > 0): ?>
            <p class="stock">In stock: <?= $stock ?> unit<?= $stock === 1 ? '' : 's' ?></p>
            <form method="POST" class="add-to-cart">
                <input type="number" name="quantity" value="1" min="1" max="<?= $stock ?>" class="qty-input">
                <button type="submit" class="btn">Add to Cart</button>
            </form>
            <?php else: ?>
            <p class="stock out">Out of stock</p>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require '../includes/footer.php'; ?>

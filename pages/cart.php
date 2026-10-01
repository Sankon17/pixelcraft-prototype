<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/cart.php';

// Quantity changes and removals (POST, then back here so a refresh doesn't repeat them)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $key  = (string)($_POST['key'] ?? '');
    $item = cart_items()[$key] ?? null;
    if ($item) {
        match ($_POST['action'] ?? '') {
            'inc'    => cart_set_qty($key, $item['qty'] + 1),
            'dec'    => cart_set_qty($key, $item['qty'] - 1),
            'set'    => cart_set_qty($key, (int)($_POST['qty'] ?? $item['qty'])),
            'remove' => cart_remove($key),
            default  => null,
        };
    }
    header('Location: cart.php' . (cart_items() ? '#cart' : ''));
    exit;
}

// Current prices and stock; anything that changed since it was added is explained above the list
$notes = $_SESSION['cart_notes'] ?? [];
unset($_SESSION['cart_notes']);
$lines = cart_lines($pdo, $notes);
$total = cart_total($lines);
$items = array_sum(array_column($lines, 'qty'));
$continue = continue_shopping_url();

$base = '../';
$page_title = 'Your Cart - PixelCraft';
require '../includes/header.php';
?>

<section class="page-hero">
    <h1>Your <span class="red">Cart</span></h1>
    <p><?= $lines ? "$items item" . ($items === 1 ? '' : 's') . ' ready for checkout' : 'Nothing here yet' ?></p>
</section>

<section class="section shop-section" id="cart">
    <?php foreach ($notes as $note): ?>
    <div class="shop-note" role="status"><?= e($note) ?></div>
    <?php endforeach; ?>

    <?php if (!$lines): ?>
    <div class="cart-empty">
        <span class="cart-empty-icon" aria-hidden="true">🛒</span>
        <h2>Your cart is empty</h2>
        <p>Find a PC or an accessory you like and add it to your cart.</p>
        <a href="<?= e($continue) ?>" class="btn">Continue Shopping</a>
    </div>
    <?php else: ?>
    <div class="shop-layout">
        <ul class="cart-list">
            <?php foreach ($lines as $line): ?>
            <?php $img = asset_image('../', 'products', $line['image']); ?>
            <li class="cart-item">
                <a href="product.php?id=<?= $line['product_id'] ?>" class="cart-thumb">
                    <?php if ($img): ?><img src="<?= e($img) ?>" alt="" loading="lazy"><?php else: ?><span><?= category_icon($line['category_slug'] ?? '') ?></span><?php endif; ?>
                </a>

                <div class="cart-info">
                    <a href="product.php?id=<?= $line['product_id'] ?>" class="cart-name"><?= e($line['name']) ?></a>
                    <?php if ($line['detail']): ?><span class="cart-detail"><?= e($line['detail']) ?></span><?php endif; ?>
                    <span class="cart-unit"><?= rm_price($line['unit_price']) ?> each</span>
                </div>

                <form method="POST" class="cart-qty" aria-label="Quantity of <?= e($line['name']) ?>">
                    <input type="hidden" name="key" value="<?= e($line['key']) ?>">
                    <button type="submit" name="action" value="dec" class="qty-btn" aria-label="One fewer"<?= $line['qty'] <= 1 ? ' disabled' : '' ?>>−</button>
                    <input type="number" name="qty" value="<?= $line['qty'] ?>" min="1" max="<?= $line['max'] ?>" class="qty-input" aria-label="Quantity" data-autosubmit>
                    <button type="submit" name="action" value="inc" class="qty-btn" aria-label="One more"<?= $line['qty'] >= $line['max'] ? ' disabled' : '' ?>>+</button>
                    <noscript><button type="submit" name="action" value="set" class="btn btn-sm btn-outline">Update</button></noscript>
                </form>

                <strong class="cart-line-total"><?= rm_price($line['line_total']) ?></strong>

                <form method="POST" class="cart-remove">
                    <input type="hidden" name="key" value="<?= e($line['key']) ?>">
                    <button type="submit" name="action" value="remove" title="Remove from cart" aria-label="Remove <?= e($line['name']) ?>">×</button>
                </form>
            </li>
            <?php endforeach; ?>
        </ul>

        <aside class="shop-summary">
            <h2>Order Summary</h2>
            <dl>
                <div><dt>Items</dt><dd><?= $items ?></dd></div>
                <div class="shop-summary-total"><dt>Subtotal</dt><dd><?= rm_price($total) ?></dd></div>
            </dl>
            <p class="shop-summary-note">Delivery / pickup arrangement will be confirmed after order.</p>
            <a href="checkout.php" class="btn shop-summary-btn">Proceed to Checkout</a>
            <a href="<?= e($continue) ?>" class="shop-continue">← Continue shopping</a>
        </aside>
    </div>
    <?php endif; ?>
</section>

<script>
// Typing a new quantity updates the cart straight away
document.querySelectorAll('[data-autosubmit]').forEach(input => {
    input.addEventListener('change', () => {
        const form = input.form;
        const set = document.createElement('input');
        set.type = 'hidden';
        set.name = 'action';
        set.value = 'set';
        form.appendChild(set);
        form.submit();
    });
});
</script>

<?php require '../includes/footer.php'; ?>

<?php
// Atome orders stop here before the receipt: the customer opens the Atome app, then comes back.
// Prototype: there is no Atome merchant account yet, so "I've paid" simply continues (payment is simulated).
// With Atome's merchant API this page would redirect to the payment link Atome returns for the order.
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/cart.php';

$id = (int)($_GET['order_id'] ?? 0);
$order = null;
if ($id && can_view_order($id)) {
    $stmt = $pdo->prepare('SELECT id, total_amount, payment_method FROM orders WHERE id = ?');
    $stmt->execute([$id]);
    $order = $stmt->fetch() ?: null;
}
if (!$order || $order['payment_method'] !== payment_methods()['atome']) {
    header('Location: order_success.php?order_id=' . $id);
    exit;
}
$total = (float)$order['total_amount'];
$logo  = asset_image('../', 'payments', 'atome.png');

$base = '../';
$page_title = 'Pay with Atome - PixelCraft';
require '../includes/header.php';
?>

<section class="section shop-section">
    <div class="atome-pay">
        <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="Atome" class="atome-pay-logo"><?php endif; ?>
        <h1>Pay with Atome</h1>
        <p>Order <?= order_number((int)$order['id']) ?> · <strong><?= rm_price($total) ?></strong></p>

        <ol class="atome-split" aria-label="Payment plan">
            <?php foreach (['Today', 'In 1 month', 'In 2 months'] as $when): ?>
            <li><span><?= $when ?></span><strong><?= rm_price(round($total / 3, 2)) ?></strong></li>
            <?php endforeach; ?>
        </ol>

        <ol class="atome-steps">
            <li>Open the Atome app and approve the payment.</li>
            <li>Come back here and tap <strong>I’ve paid</strong> to see your receipt.</li>
        </ol>

        <div class="atome-pay-actions">
            <a href="<?= e(ATOME_URL) ?>" class="btn atome-btn" target="_blank" rel="noopener">Open Atome app ↗</a>
            <a href="order_success.php?order_id=<?= (int)$order['id'] ?>" class="btn btn-outline">I’ve paid, show my receipt →</a>
        </div>
    </div>
</section>

<?php require '../includes/footer.php'; ?>

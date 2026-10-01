<?php
// "Payment Successful!" and a receipt the customer can print or save as PDF.
// Prototype: payment is simulated (no gateway), so every placed order lands here as paid.
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/cart.php';

$id = (int)($_GET['order_id'] ?? 0);
$order = null;
$items = [];
if ($id && can_view_order($id)) {
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$id]);
    $order = $stmt->fetch() ?: null;
    $stmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();
}
$sales = store_contacts()['whatsapp']['sales'];
$pickup = $order && $order['delivery_method'] === 'Pick Up';

$base = '../';
$page_title = ($order ? 'Receipt ' . order_number($id) : 'Order') . ' - PixelCraft';
require '../includes/header.php';
?>

<section class="section shop-section">
    <?php if (!$order): ?>
    <div class="cart-empty">
        <span class="cart-empty-icon" aria-hidden="true">🔎</span>
        <h2>We couldn’t find that order</h2>
        <p>A receipt can only be opened from the browser the order was placed in. For help with an order, WhatsApp us.</p>
        <a href="<?= e($sales['url']) ?>" class="btn btn-whatsapp" target="_blank" rel="noopener">💬 WhatsApp <?= e($sales['display']) ?></a>
    </div>
    <?php else: ?>

    <div class="success-head no-print">
        <span class="confirm-tick" aria-hidden="true">✓</span>
        <h1>Payment Successful!</h1>
        <p>Thank you, <?= e($order['customer_name']) ?>! Your order has been received.</p>
        <p class="success-sub">Our team will contact you at <strong><?= e($order['phone']) ?></strong> to confirm <?= $pickup ? 'your pickup' : 'delivery' ?>.</p>
    </div>

    <article class="receipt" id="receipt">
        <header class="receipt-head">
            <span class="receipt-logo"><?= logo_img('../') ?></span>
            <div>
                <h2>RECEIPT</h2>
                <p>Order <?= order_number((int)$order['id']) ?></p>
            </div>
        </header>

        <dl class="receipt-meta">
            <div><dt>Order ID</dt><dd><?= order_number((int)$order['id']) ?></dd></div>
            <div><dt>Date</dt><dd><?= e(date('j M Y, g:i a', strtotime($order['created_at']))) ?></dd></div>
            <div><dt>Customer</dt><dd><?= e($order['customer_name']) ?><br><?= e($order['phone']) ?><br><?= e($order['email']) ?></dd></div>
            <div><dt><?= $pickup ? 'Pick Up' : 'Address' ?></dt><dd><?= $pickup ? 'Collect at 282, Jalan Bandar Senawang 1, 70450 Seremban' : e($order['address']) ?></dd></div>
            <div><dt>Payment Method</dt><dd><?= e($order['payment_method']) ?> <span class="receipt-paid">PAID</span></dd></div>
            <div><dt>Delivery</dt><dd><?= e($order['delivery_method']) ?></dd></div>
        </dl>

        <table class="receipt-items">
            <thead>
                <tr><th>Product Name</th><th>Variant</th><th class="num">Qty</th><th class="num">Unit Price</th><th class="num">Subtotal</th></tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td data-label="Product"><?= e($item['product_name']) ?></td>
                    <td data-label="Variant"><?= e($item['variant_detail'] ?: '—') ?></td>
                    <td data-label="Qty" class="num"><?= (int)$item['qty'] ?></td>
                    <td data-label="Unit Price" class="num"><?= rm_price((float)$item['unit_price']) ?></td>
                    <td data-label="Subtotal" class="num"><?= rm_price($item['qty'] * $item['unit_price']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><th colspan="4">TOTAL</th><td class="num"><?= rm_price((float)$order['total_amount']) ?></td></tr>
            </tfoot>
        </table>

        <footer class="receipt-foot">PixelCraft Sdn Bhd | 282, Jalan Bandar Senawang 1, 70450 Seremban</footer>
    </article>

    <div class="success-actions no-print">
        <button type="button" class="btn" onclick="window.print()">⬇ Download Receipt</button>
        <a href="../index.php" class="btn btn-outline">Back to Home</a>
    </div>
    <p class="success-tip no-print">Tip: in the print window, choose “Save as PDF” to keep a copy.</p>
    <?php endif; ?>
</section>

<?php require '../includes/footer.php'; ?>

<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/cart.php';
require_once '_layout.php';

if (($_SESSION['admin_logged_in'] ?? false) !== true) {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'That order no longer exists.'];
    header('Location: orders.php');
    exit;
}
$stmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
$stmt->execute([$id]);
$items = $stmt->fetchAll();

$statuses = order_statuses();
$wa = 'https://wa.me/' . preg_replace('/^0/', '60', preg_replace('/\D/', '', $order['phone'])); // 012-345 6789 → 60123456789

admin_open('Order ' . order_number($id), 'orders');
?>
        <a href="orders.php" class="order-back">← All orders</a>
        <header class="admin-page-head">
            <div>
                <h1>Order <?= order_number($id) ?> <span class="order-badge is-<?= e($order['status']) ?>"><?= e($statuses[$order['status']] ?? $order['status']) ?></span></h1>
                <p class="admin-page-sub">Placed <?= e(date('l, j F Y, g:i a', strtotime($order['created_at']))) ?></p>
            </div>
            <div class="admin-page-actions">
                <a href="../pages/order_success.php?order_id=<?= $id ?>" class="btn btn-sm btn-outline" target="_blank" rel="noopener">View receipt ↗</a>
            </div>
        </header>

        <div class="order-grid">
            <section class="dash-card is-blue">
                <header class="dash-card-head">
                    <span class="dash-card-icon is-blue"><?= admin_icon('eye') ?></span>
                    <div><h2>Customer</h2><p>Contact them to confirm the order</p></div>
                </header>
                <dl class="acct-facts">
                    <div><dt>Name</dt><dd><?= e($order['customer_name']) ?></dd></div>
                    <div><dt>Phone</dt><dd><?= e($order['phone']) ?> · <a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="ap-link">WhatsApp</a></dd></div>
                    <div><dt>Email</dt><dd><a href="mailto:<?= e($order['email']) ?>" class="ap-link"><?= e($order['email']) ?></a></dd></div>
                    <div><dt><?= e($order['delivery_method']) ?></dt><dd><?= e($order['address']) ?></dd></div>
                    <div><dt>Payment</dt><dd><?= e($order['payment_method']) ?></dd></div>
                </dl>
            </section>

            <section class="dash-card is-amber">
                <header class="dash-card-head">
                    <span class="dash-card-icon is-amber"><?= admin_icon('orders') ?></span>
                    <div><h2>Status</h2><p>Where this order is up to</p></div>
                </header>
                <form method="POST" action="order_update.php" class="order-status-form">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <input type="hidden" name="order_id" value="<?= $id ?>">
                    <label for="status">Order status</label>
                    <select name="status" id="status">
                        <?php foreach (order_statuses_for($order['delivery_method']) as $key => $label): ?>
                        <option value="<?= $key ?>"<?= $order['status'] === $key ? ' selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn">Update Status</button>
                    <p class="order-status-note"><?= $order['status'] === 'cancelled'
                        ? "This order's items are back in stock. Choosing another status takes them from stock again."
                        : "Cancelling puts this order's items back in stock." ?></p>
                </form>
            </section>
        </div>

        <section class="dash-card is-red order-items-card">
            <header class="dash-card-head">
                <span class="dash-card-icon is-red"><?= admin_icon('box') ?></span>
                <div><h2>Items ordered</h2><p><?= array_sum(array_column($items, 'qty')) ?> item<?= array_sum(array_column($items, 'qty')) === 1 ? '' : 's' ?></p></div>
            </header>
            <div class="ap-table-wrap">
                <table class="ap-table">
                    <thead><tr><th>Product</th><th>Variant</th><th class="ap-num">Qty</th><th class="ap-num">Unit Price</th><th class="ap-num">Subtotal</th></tr></thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td class="ap-name"><strong><?= e($item['product_name']) ?></strong>
                                <?php if ($item['product_id']): ?><small><a href="products.php?edit=<?= (int)$item['product_id'] ?>#edit" class="ap-link">Open product</a></small><?php endif; ?></td>
                            <td><?= e($item['variant_detail'] ?: '—') ?></td>
                            <td class="ap-num"><?= (int)$item['qty'] ?></td>
                            <td class="ap-num"><?= rm_price((float)$item['unit_price']) ?></td>
                            <td class="ap-num"><?= rm_price($item['qty'] * $item['unit_price']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr><th colspan="4" class="ap-num">Order total</th><td class="ap-num order-total"><?= rm_price((float)$order['total_amount']) ?></td></tr></tfoot>
                </table>
            </div>
        </section>
<?php admin_close(); ?>

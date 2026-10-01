<?php
// "Create order" on admin/orders.php: phone and walk-in orders entered by the team.
// Prices come from the database, and stock is checked and taken off exactly like a website order (save_order()).
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

// Everything that can be ordered: products, and their variants (RAM/SSD, set, GPU) with price and stock
$catalogue = [];
foreach ($pdo->query('SELECT p.id, p.name, p.price, p.stock, p.is_active, c.name AS category FROM products p
    LEFT JOIN categories c ON c.id = p.category_id ORDER BY c.id, p.name') as $p) {
    $catalogue[(int)$p['id']] = $p + ['variants' => []];
}
foreach ($pdo->query('SELECT id, product_id, ram, storage, set_type, gpu_variant, color, price, stock FROM product_variants ORDER BY product_id, price, id') as $v) {
    if (isset($catalogue[(int)$v['product_id']])) {
        $catalogue[(int)$v['product_id']]['variants'][(int)$v['id']] = $v + ['label' => variant_detail($v)];
    }
}

$payments   = payment_methods();
$deliveries = delivery_methods();
$errors = [];
$in = ['name' => '', 'phone' => '', 'email' => '', 'delivery' => 'pickup', 'payment' => 'card', 'address' => '', 'status' => 'pending'];
$rows = [['product' => 0, 'variant' => 0, 'qty' => 1]];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($in as $field => $_) {
        $in[$field] = trim((string)($_POST[$field] ?? ''));
    }
    $rows = [];
    foreach ((array)($_POST['items'] ?? []) as $row) {
        $rows[] = ['product' => (int)($row['product'] ?? 0), 'variant' => (int)($row['variant'] ?? 0), 'qty' => (int)($row['qty'] ?? 1)];
    }

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors[] = 'Session expired. Please try again.';
    }
    if ($in['name'] === '' || mb_strlen($in['name']) > 100) {
        $errors[] = 'Enter the customer’s name.';
    }
    $digits = preg_replace('/\D/', '', $in['phone']);
    if (!preg_match('/^\+?[\d\s()-]+$/', $in['phone']) || strlen($digits) < 9 || strlen($digits) > 15) {
        $errors[] = 'Enter a phone number, e.g. 012-345 6789.';
    }
    // Walk-in customers may not give an email
    if ($in['email'] !== '' && (!filter_var($in['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($in['email']) > 255)) {
        $errors[] = 'That email address doesn’t look right (or leave it empty).';
    }
    if (!isset($deliveries[$in['delivery']])) {
        $errors[] = 'Choose Pick Up or Postage.';
    } elseif ($in['delivery'] === 'postage' && ($in['address'] === '' || mb_strlen($in['address']) > 500)) {
        $errors[] = 'Enter the delivery address for Postage.';
    }
    if (!isset($payments[$in['payment']])) {
        $errors[] = 'Choose a payment method.';
    }
    $allowed = isset($deliveries[$in['delivery']]) ? order_statuses_for($deliveries[$in['delivery']]) : order_statuses();
    if (!isset($allowed[$in['status']])) {
        $errors[] = 'Choose a status that fits the delivery method.';
    }

    // Items, priced from the database
    $lines = [];
    foreach ($rows as $n => $row) {
        $p = $catalogue[$row['product']] ?? null;
        if (!$p) {
            continue; // an empty row
        }
        $v = $p['variants'][$row['variant']] ?? null;
        if ($p['variants'] && !$v) {
            $errors[] = 'Choose the option (RAM / SSD / set) for ' . $p['name'] . '.';
            continue;
        }
        if ($row['qty'] < 1 || $row['qty'] > 99) {
            $errors[] = 'Quantity for ' . $p['name'] . ' must be 1 to 99.';
            continue;
        }
        $price = (float)($v['price'] ?? $p['price']);
        $lines[] = [
            'product_id' => (int)$p['id'], 'variant_id' => $v ? (int)$v['id'] : null,
            'name' => $p['name'], 'detail' => $v['label'] ?? null,
            'qty' => $row['qty'], 'unit_price' => $price, 'line_total' => $price * $row['qty'],
        ];
    }
    if (!$lines) {
        $errors[] = 'Add at least one item.';
    }

    if (!$errors) {
        try {
            $saved = save_order($pdo, [
                'customer_name'   => $in['name'],
                'phone'           => $in['phone'],
                'email'           => $in['email'],
                'address'         => $in['delivery'] === 'pickup' ? 'Pick up at store' : $in['address'],
                'delivery_method' => $deliveries[$in['delivery']],
                'payment_method'  => $payments[$in['payment']],
                'status'          => $in['status'],
            ], $lines);
            if (isset($saved['short'])) {
                $errors = $saved['short'];
            } else {
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Order ' . order_number($saved['id']) . ' was created and the stock taken off.'];
                header('Location: order_detail.php?id=' . $saved['id']);
                exit;
            }
        } catch (Throwable $e) {
            error_log('Admin order failed: ' . $e->getMessage());
            $errors[] = 'Something went wrong and the order was not saved. Please try again.';
        }
    }
    if (!$rows) {
        $rows = [['product' => 0, 'variant' => 0, 'qty' => 1]];
    }
}

// For the page script: each product's price, stock and options
$js_catalogue = [];
foreach ($catalogue as $id => $p) {
    $js_catalogue[$id] = [
        'price' => (float)$p['price'], 'stock' => (int)$p['stock'],
        'variants' => array_values(array_map(fn($v) => ['id' => (int)$v['id'], 'label' => $v['label'], 'price' => (float)$v['price'], 'stock' => (int)$v['stock']], $p['variants'])),
    ];
}

admin_open('Create order', 'orders');
?>
        <a href="orders.php" class="order-back">← All orders</a>
        <header class="admin-page-head">
            <div>
                <h1>Create order</h1>
                <p class="admin-page-sub">For phone and walk-in orders. Prices come from the catalogue and the stock is taken off.</p>
            </div>
        </header>

        <?php if ($errors): ?>
        <ul class="admin-flash is-error ap-errors" role="alert">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <form method="POST" class="order-create" id="orderCreate">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">

            <section class="dash-card is-red">
                <header class="dash-card-head">
                    <span class="dash-card-icon is-red"><?= admin_icon('box') ?></span>
                    <div><h2>Items</h2><p>Product, option and quantity</p></div>
                </header>
                <div class="ap-table-wrap">
                    <table class="ap-table oc-items">
                        <thead><tr><th>Product</th><th>Option</th><th class="ap-num">Qty</th><th class="ap-num">Price</th><th></th></tr></thead>
                        <tbody data-rows>
                            <?php foreach ($rows as $i => $row): ?>
                            <tr>
                                <td>
                                    <select name="items[<?= $i ?>][product]" data-product>
                                        <option value="">Choose a product…</option>
                                        <?php $group = null; foreach ($catalogue as $id => $p): ?>
                                        <?php if ($p['category'] !== $group): ?><?= $group !== null ? '</optgroup>' : '' ?><optgroup label="<?= e($p['category'] ?? 'Other') ?>"><?php $group = $p['category']; endif; ?>
                                        <option value="<?= $id ?>"<?= $row['product'] === $id ? ' selected' : '' ?>><?= e($p['name']) ?><?= $p['is_active'] ? '' : ' (hidden)' ?></option>
                                        <?php endforeach; ?><?= $group !== null ? '</optgroup>' : '' ?>
                                    </select>
                                </td>
                                <td>
                                    <select name="items[<?= $i ?>][variant]" data-variant>
                                        <?php $variants = $catalogue[$row['product']]['variants'] ?? []; ?>
                                        <?php if (!$variants): ?><option value="">—</option><?php else: ?>
                                        <option value="">Choose…</option>
                                        <?php foreach ($variants as $vid => $v): ?><option value="<?= $vid ?>"<?= $row['variant'] === $vid ? ' selected' : '' ?>><?= e($v['label']) ?> · <?= rm_price((float)$v['price']) ?></option><?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </td>
                                <td class="ap-num"><input type="number" name="items[<?= $i ?>][qty]" value="<?= max(1, $row['qty']) ?>" min="1" max="99" data-qty></td>
                                <td class="ap-num oc-price" data-price>—</td>
                                <td><button type="button" class="oc-remove" data-remove title="Remove item" aria-label="Remove item">×</button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr><th colspan="3" class="ap-num">Total</th><td class="ap-num order-total" data-total>RM 0.00</td><td></td></tr></tfoot>
                    </table>
                </div>
                <button type="button" class="btn btn-sm btn-outline oc-add" data-add>＋ Add item</button>
            </section>

            <div class="order-grid">
                <section class="dash-card is-blue">
                    <header class="dash-card-head">
                        <span class="dash-card-icon is-blue"><?= admin_icon('eye') ?></span>
                        <div><h2>Customer</h2><p>Email is optional for walk-ins</p></div>
                    </header>
                    <div class="oc-fields">
                        <label class="is-wide"><span>Name</span><input type="text" name="name" value="<?= e($in['name']) ?>" maxlength="100" required></label>
                        <label><span>Phone</span><input type="tel" name="phone" value="<?= e($in['phone']) ?>" maxlength="30" placeholder="012-345 6789" required></label>
                        <label><span>Email <em>(optional)</em></span><input type="email" name="email" value="<?= e($in['email']) ?>" maxlength="255"></label>
                        <label class="is-wide" data-address<?= $in['delivery'] === 'postage' ? '' : ' hidden' ?>><span>Delivery address</span>
                            <textarea name="address" rows="2" maxlength="500"><?= e($in['address']) ?></textarea></label>
                    </div>
                </section>

                <section class="dash-card is-amber">
                    <header class="dash-card-head">
                        <span class="dash-card-icon is-amber"><?= admin_icon('orders') ?></span>
                        <div><h2>Delivery, payment & status</h2><p>Walk-in sale paid in store? Choose Pick Up and Completed</p></div>
                    </header>
                    <div class="oc-fields">
                        <label><span>Delivery</span>
                            <select name="delivery" data-delivery>
                                <?php foreach ($deliveries as $key => $label): ?><option value="<?= $key ?>"<?= $in['delivery'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                            </select></label>
                        <label><span>Payment</span>
                            <select name="payment">
                                <?php foreach ($payments as $key => $label): ?><option value="<?= $key ?>"<?= $in['payment'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                            </select></label>
                        <label class="is-wide"><span>Status</span>
                            <select name="status" data-status>
                                <?php foreach (order_statuses() as $key => $label): ?><option value="<?= $key ?>"<?= $in['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                            </select></label>
                    </div>
                </section>
            </div>

            <div class="oc-actions">
                <button type="submit" class="btn">Create order</button>
                <a href="orders.php" class="btn btn-outline">Cancel</a>
            </div>
        </form>

        <script>
        (() => {
            const CATALOGUE = <?= json_encode($js_catalogue, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
            const form = document.getElementById('orderCreate');
            const rows = form.querySelector('[data-rows]');
            const money = n => 'RM ' + n.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            // Each row's price, and the total, as items are picked
            function refresh() {
                let total = 0;
                rows.querySelectorAll('tr').forEach(tr => {
                    const p = CATALOGUE[tr.querySelector('[data-product]').value];
                    const vid = Number(tr.querySelector('[data-variant]').value);
                    const qty = Math.max(1, Number(tr.querySelector('[data-qty]').value) || 1);
                    const v = p && p.variants.find(x => x.id === vid);
                    const unit = p ? (p.variants.length ? (v ? v.price : null) : p.price) : null;
                    tr.querySelector('[data-price]').textContent = unit === null ? '—' : money(unit * qty);
                    if (unit !== null) total += unit * qty;
                });
                form.querySelector('[data-total]').textContent = money(total);
            }
            // Picking a product lists its options (RAM / SSD / set / GPU)
            function fillVariants(tr) {
                const p = CATALOGUE[tr.querySelector('[data-product]').value];
                const select = tr.querySelector('[data-variant]');
                select.replaceChildren();
                if (!p || !p.variants.length) {
                    select.add(new Option('—', ''));
                } else {
                    select.add(new Option('Choose…', ''));
                    p.variants.forEach(v => select.add(new Option(v.label + ' · ' + money(v.price) + (v.stock < 1 ? ' (out of stock)' : ''), v.id)));
                    if (p.variants.length === 1) select.value = p.variants[0].id;
                }
            }
            rows.addEventListener('change', e => {
                if (e.target.matches('[data-product]')) fillVariants(e.target.closest('tr'));
                refresh();
            });
            rows.addEventListener('input', refresh);
            rows.addEventListener('click', e => {
                if (!e.target.matches('[data-remove]')) return;
                if (rows.children.length > 1) e.target.closest('tr').remove();
                else { const tr = e.target.closest('tr'); tr.querySelector('[data-product]').value = ''; fillVariants(tr); tr.querySelector('[data-qty]').value = 1; }
                refresh();
            });
            form.querySelector('[data-add]').addEventListener('click', () => {
                const first = rows.querySelector('tr');
                const row = first.cloneNode(true);
                const next = rows.children.length;
                row.querySelectorAll('[name]').forEach(el => { el.name = el.name.replace(/items\[\d+\]/, `items[${next}]`); });
                row.querySelector('[data-product]').value = '';
                row.querySelector('[data-qty]').value = 1;
                fillVariants(row);
                rows.appendChild(row);
                refresh();
                row.querySelector('[data-product]').focus();
            });

            // Postage needs an address; Pick Up orders can't be "Shipped", Postage ones can't be "Ready for pickup"
            const delivery = form.querySelector('[data-delivery]');
            const status = form.querySelector('[data-status]');
            function deliveryChanged() {
                const pickup = delivery.value === 'pickup';
                const address = form.querySelector('[data-address]');
                address.hidden = pickup;
                address.querySelector('textarea').required = !pickup;
                status.querySelector('[value=shipped]').hidden = status.querySelector('[value=shipped]').disabled = pickup;
                status.querySelector('[value=ready_pickup]').hidden = status.querySelector('[value=ready_pickup]').disabled = !pickup;
                if (status.selectedOptions[0].disabled) status.value = 'pending';
            }
            delivery.addEventListener('change', deliveryChanged);
            deliveryChanged();
            refresh();
        })();
        </script>
<?php admin_close(); ?>

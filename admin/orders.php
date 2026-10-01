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
$admin_id = (int)($_SESSION['admin_id'] ?? 0);

// ── The filters, all in the URL (so they can be bookmarked, exported and saved) ──
$statuses   = order_statuses();
$payments   = payment_methods();
$deliveries = delivery_methods();
$f = orders_filter($_GET);

// ── Save filters (per admin) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Session expired. Please try again.'];
    } elseif (($_POST['action'] ?? '') === 'save_filter') {
        $name  = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 60);
        $saved = orders_filter($_POST);
        if ($name === '' || !$saved) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => $saved ? 'Give the filter a name.' : 'Choose a tab, search or filter first, then save it.'];
        } else {
            $pdo->prepare('INSERT INTO order_saved_filters (admin_id, name, query) VALUES (?, ?, ?)')->execute([$admin_id, $name, http_build_query($saved)]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => "Saved “{$name}”. It's in your saved filters above the list."];
        }
    } elseif (($_POST['action'] ?? '') === 'delete_filter') {
        $pdo->prepare('DELETE FROM order_saved_filters WHERE id = ? AND admin_id = ?')->execute([(int)($_POST['id'] ?? 0), $admin_id]);
    }
    header('Location: ' . orders_url(orders_filter($_POST)));
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Tab counts (with the other filters applied)
$args  = [];
$where = orders_where($f, $args);
$stmt  = $pdo->prepare("SELECT o.status, COUNT(*) AS n FROM orders o$where GROUP BY o.status");
$stmt->execute($args);
$counts = array_fill_keys(array_keys($statuses), 0);
foreach ($stmt as $row) {
    $counts[$row['status']] = (int)$row['n'];
}

// The list: filters + the chosen tab, newest first
if (isset($f['status'])) {
    $where .= ($where ? ' AND' : ' WHERE') . ' o.status = ?';
    $args[] = $f['status'];
}
$stmt = $pdo->prepare("SELECT o.*, (SELECT SUM(qty) FROM order_items i WHERE i.order_id = o.id) AS items
    FROM orders o$where ORDER BY o.created_at DESC, o.id DESC");
$stmt->execute($args);
$orders = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT id, name, query FROM order_saved_filters WHERE admin_id = ? ORDER BY name');
$stmt->execute([$admin_id]);
$saved_filters = $stmt->fetchAll();

// The filter chips: "Fulfillment status: Awaiting processing ×" and so on
$chips = [];
if (isset($f['status']))   $chips['status']   = 'Fulfillment status: ' . $statuses[$f['status']];
if (isset($f['q']))        $chips['q']        = 'Search: “' . $f['q'] . '”';
if (isset($f['payment']))  $chips['payment']  = 'Payment: ' . $payments[$f['payment']];
if (isset($f['delivery'])) $chips['delivery'] = 'Delivery: ' . $deliveries[$f['delivery']];
$fmt_day = fn($d) => date('j M Y', strtotime($d));
if (isset($f['from']) || isset($f['to'])) {
    $chips['dates'] = 'Date: ' . (isset($f['from']) ? $fmt_day($f['from']) : 'any') . ' – ' . (isset($f['to']) ? $fmt_day($f['to']) : 'today');
}
$filter_open = isset($f['payment']) || isset($f['delivery']) || isset($f['from']) || isset($f['to']);
$total_all = array_sum($counts);

admin_open('Orders', 'orders');
?>
        <header class="admin-page-head">
            <div>
                <h1>Orders</h1>
                <p class="admin-page-sub"><?= $total_all ?> order<?= $total_all === 1 ? '' : 's' ?><?= $chips ? ' match' . ($total_all === 1 ? 'es' : '') . ' your filters' : '' ?> · <?= $counts['pending'] ?> awaiting processing</p>
            </div>
            <div class="admin-page-actions">
                <a href="<?= e(orders_url($f, [], 'orders_export.php')) ?>" class="btn btn-sm btn-outline admin-btn-icon" title="Download the orders in this list as a CSV spreadsheet">⬇ Export</a>
                <a href="order_create.php" class="btn btn-sm admin-btn-icon"><?= admin_icon('plus') ?> Create order</a>
            </div>
        </header>

        <?php if ($flash): ?>
        <div class="admin-flash is-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <nav class="order-tabs" aria-label="Fulfillment status">
            <a href="<?= e(orders_url($f, ['status' => ''])) ?>" class="order-tab<?= isset($f['status']) ? '' : ' is-active' ?>"<?= isset($f['status']) ? '' : ' aria-current="page"' ?>>All <span><?= $total_all ?></span></a>
            <?php foreach ($statuses as $key => $label): ?>
            <a href="<?= e(orders_url($f, ['status' => $key])) ?>" class="order-tab<?= ($f['status'] ?? '') === $key ? ' is-active' : '' ?>"<?= ($f['status'] ?? '') === $key ? ' aria-current="page"' : '' ?>>
                <?= $label ?><?php if ($counts[$key]): ?> <span><?= $counts[$key] ?></span><?php endif; ?>
            </a>
            <?php endforeach; ?>
        </nav>

        <div class="order-panel">
            <!-- Search + Filter + Save filters -->
            <form method="GET" action="orders.php" class="order-toolbar" role="search">
                <?php if (isset($f['status'])): ?><input type="hidden" name="status" value="<?= e($f['status']) ?>"><?php endif; ?>
                <label class="order-search">
                    <span aria-hidden="true">🔍</span>
                    <input type="search" name="q" value="<?= e($f['q'] ?? '') ?>" placeholder="Search order no., name, phone or email" aria-label="Search orders">
                </label>
                <button type="button" class="btn btn-sm btn-outline order-tool" data-toggle="orderFilters" aria-expanded="<?= $filter_open ? 'true' : 'false' ?>">☰ Filter</button>
                <button type="button" class="btn btn-sm btn-outline order-tool" data-toggle="orderSave" aria-expanded="false"<?= $chips ? '' : ' disabled title="Choose a tab, search or filter first"' ?>>☆ Save filters</button>

                <div class="order-filters" id="orderFilters"<?= $filter_open ? '' : ' hidden' ?>>
                    <label>
                        <span>Payment</span>
                        <select name="payment">
                            <option value="">Any</option>
                            <?php foreach ($payments as $key => $label): ?><option value="<?= $key ?>"<?= ($f['payment'] ?? '') === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span>Delivery</span>
                        <select name="delivery">
                            <option value="">Any</option>
                            <?php foreach ($deliveries as $key => $label): ?><option value="<?= $key ?>"<?= ($f['delivery'] ?? '') === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label><span>From</span><input type="date" name="from" value="<?= e($f['from'] ?? '') ?>"></label>
                    <label><span>To</span><input type="date" name="to" value="<?= e($f['to'] ?? '') ?>"></label>
                    <button type="submit" class="btn btn-sm">Apply</button>
                </div>
            </form>

            <form method="POST" action="orders.php" class="order-save" id="orderSave" hidden>
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="save_filter">
                <?php foreach ($f as $key => $value): ?><input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>"><?php endforeach; ?>
                <input type="text" name="name" maxlength="60" placeholder="Name this filter, e.g. Postage to pack" aria-label="Filter name" required>
                <button type="submit" class="btn btn-sm">Save</button>
            </form>

            <?php if ($chips): ?>
            <div class="order-chips">
                <?php foreach ($chips as $key => $label): ?>
                <a href="<?= e(orders_url($f, $key === 'dates' ? ['from' => '', 'to' => ''] : [$key => ''])) ?>" class="order-chip" title="Remove this filter"><?= e($label) ?> <span aria-hidden="true">×</span></a>
                <?php endforeach; ?>
                <a href="orders.php" class="order-chip-clear">Clear all</a>
            </div>
            <?php endif; ?>

            <?php if ($saved_filters): ?>
            <div class="order-saved">
                <span>Saved filters:</span>
                <?php foreach ($saved_filters as $sf): ?>
                <span class="order-saved-item">
                    <a href="orders.php?<?= e($sf['query']) ?>">★ <?= e($sf['name']) ?></a>
                    <form method="POST" action="orders.php">
                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="delete_filter">
                        <input type="hidden" name="id" value="<?= (int)$sf['id'] ?>">
                        <?php foreach ($f as $key => $value): ?><input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>"><?php endforeach; ?>
                        <button type="submit" aria-label="Delete saved filter <?= e($sf['name']) ?>" title="Delete">×</button>
                    </form>
                </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!$orders): ?>
            <div class="order-empty">
                <strong><?= $chips ? 'No orders match these filters' : 'No orders yet' ?></strong>
                <span><?= $chips ? 'Try another tab, or clear the filters.' : 'They appear here as soon as a customer checks out, or when you create one.' ?></span>
            </div>
            <?php else: ?>
            <div class="ap-table-wrap">
                <table class="ap-table orders-table">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Customer Name</th>
                            <th>Phone</th>
                            <th class="ap-num">Total</th>
                            <th>Payment Method</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $o): ?>
                        <tr>
                            <td><a href="order_detail.php?id=<?= (int)$o['id'] ?>" class="order-id"><?= order_number((int)$o['id']) ?></a></td>
                            <td class="ap-name"><strong><?= e($o['customer_name']) ?></strong><small><?= (int)$o['items'] ?> item<?= (int)$o['items'] === 1 ? '' : 's' ?> · <?= e($o['delivery_method']) ?></small></td>
                            <td class="order-phone"><?= e($o['phone']) ?></td>
                            <td class="ap-num"><?= rm_price((float)$o['total_amount']) ?></td>
                            <td><?= e($o['payment_method']) ?></td>
                            <td><span class="order-badge is-<?= e($o['status']) ?>"><?= e($statuses[$o['status']] ?? $o['status']) ?></span></td>
                            <td class="order-date"><?= e(date('j M Y', strtotime($o['created_at']))) ?><small><?= e(date('g:i a', strtotime($o['created_at']))) ?></small></td>
                            <td><a href="order_detail.php?id=<?= (int)$o['id'] ?>" class="btn btn-sm ap-btn-edit">View</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <script>
        // Filter and Save filters open their panels
        document.querySelectorAll('[data-toggle]').forEach(btn => btn.addEventListener('click', () => {
            const panel = document.getElementById(btn.dataset.toggle);
            panel.hidden = !panel.hidden;
            btn.setAttribute('aria-expanded', String(!panel.hidden));
            if (!panel.hidden) panel.querySelector('input:not([type=hidden]), select')?.focus();
        }));
        </script>
<?php admin_close(); ?>

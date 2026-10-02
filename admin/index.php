<?php
session_start();
require_once '../includes/functions.php';
require_once '_layout.php';

if (($_SESSION['admin_logged_in'] ?? false) !== true) {
    header('Location: login.php');
    exit;
}

// Logout (POST only, so a link on another site can't log the admin out)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: login.php');
    exit;
}

$username = $_SESSION['admin_username'] ?? 'admin';

require_once '../includes/db.php';
require_once '../includes/cart.php'; // order statuses, rm_price(), order_number()

// ── Catalogue check: every product, with what the store would show for it ──
$rows = $pdo->query('SELECT p.id, p.name, p.price, p.stock, p.image, p.is_active,
        c.name AS category_name, c.slug AS category_slug,
        s.cpu, s.gpu, s.motherboard, s.psu, s.cooler, s.casing, s.ram, s.storage,
        (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id) AS variant_count,
        (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.stock <= 0) AS variants_out
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN product_specs s ON s.product_id = p.id
    ORDER BY p.name')->fetchAll();

$counts =['live' => 0, 'hidden' => 0, 'pc' => 0, 'laptop' => 0, 'accessory' => 0, 'variants' => 0, 'variants_out' => 0];
$attention = ['live' => [], 'hidden' => []];

foreach ($rows as $p) {
    $group = product_group($p['category_slug']);
    $live  = (bool)$p['is_active'];
    $variants = (int)$p['variant_count'];
    $out      = (int)$p['variants_out'];
    $counts[$live ? 'live' : 'hidden']++;
    $counts[$group]++;
    $counts['variants'] += $variants;
    $counts['variants_out'] += $out;

    // [severity, what's wrong, edit-page section], most serious first (includes/functions.php)
    $issues = array_values(product_issues($p));
    if ($issues) {
        $attention[$live ? 'live' : 'hidden'][] = $p + ['issues' => $issues, 'level' => $issues[0][0]];
    }
}
// Most serious first, then by name
foreach ($attention as &$list) {
    usort($list, fn($a, $b) => [$b['level'], count($b['issues'])] <=> [$a['level'], count($a['issues'])] ?: strcasecmp($a['name'], $b['name']));
}
unset($list);
$total = count($rows);

// ── Low stock: variants, and products' own stock, under this many ──
const LOW_STOCK = 5;
$stmt = $pdo->prepare('SELECT p.id, p.name, p.is_active, v.option_name, v.ram, v.storage, v.set_type, v.gpu_variant, v.stock, 1 AS is_variant
        FROM product_variants v JOIN products p ON p.id = v.product_id
        WHERE v.stock < ?
    UNION ALL
    SELECT p.id, p.name, p.is_active, NULL, NULL, NULL, NULL, NULL, p.stock, 0
        FROM products p
        WHERE p.stock < ?
    ORDER BY stock, name');
$stmt->execute([LOW_STOCK, LOW_STOCK]);
$low_stock = $stmt->fetchAll();
$set_labels = ['cpu_only' => 'CPU Only', 'full_set' => 'Full Set'];

// ── Price Sync history (database/price_sync_log.sql); starts empty and fills with each sync ──
try {
    $syncs = $pdo->query('SELECT * FROM price_sync_log ORDER BY synced_at DESC, id DESC LIMIT 5')->fetchAll();
    $sync_log_ready = true;
} catch (PDOException $e) {
    $syncs = [];
    $sync_log_ready = false; // table not created yet
}
// ── Orders (database/orders.sql): this month's sales, and what's waiting to be packed or collected ──
const OPEN_STATUSES = ['pending', 'processing', 'packed', 'ready_pickup'];
$month_start = date('Y-m-01 00:00:00');
$sales = ['this' => ['n' => 0, 'total' => 0], 'last' => ['n' => 0, 'total' => 0]];
$by_status = [];
$open_orders = [];
try {
    // Cancelled orders don't count as sales
    $sum = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(total_amount), 0) AS total FROM orders
        WHERE status <> 'cancelled' AND created_at >= ? AND created_at < ?");
    $sum->execute([$month_start, date('Y-m-d H:i:s', strtotime('first day of next month midnight'))]);
    $sales['this'] = $sum->fetch();
    $sum->execute([date('Y-m-d H:i:s', strtotime('first day of last month midnight')), $month_start]);
    $sales['last'] = $sum->fetch();

    $by_status = $pdo->query('SELECT status, COUNT(*) FROM orders GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);

    // Oldest first: the order that has waited longest is at the top
    $open_orders = $pdo->query("SELECT o.id, o.customer_name, o.delivery_method, o.payment_method, o.total_amount, o.status, o.created_at,
            (SELECT COALESCE(SUM(i.qty), 0) FROM order_items i WHERE i.order_id = o.id) AS items
        FROM orders o WHERE o.status IN ('" . implode("','", OPEN_STATUSES) . "')
        ORDER BY o.created_at, o.id LIMIT 6")->fetchAll();
    $orders_ready = true;
} catch (PDOException $e) {
    $orders_ready = false; // orders table not created yet
}
$open_total = array_sum(array_map(fn($s) => (int)($by_status[$s] ?? 0), OPEN_STATUSES));
$pending    = (int)($by_status['pending'] ?? 0);
$statuses   = order_statuses();

// "3 h ago", "2 days ago"
function ago(string $ts): string
{
    $mins = max(0, intdiv(time() - strtotime($ts), 60));
    if ($mins < 1) return 'just now';
    if ($mins < 60) return $mins . ' min ago';
    if ($mins < 1440) return intdiv($mins, 60) . ' h ago';
    $days = intdiv($mins, 1440);
    return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
}

$fmt_date = fn(string $ts) => date('j M Y, g:i a', strtotime($ts));
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$needs = count($attention['live']);

admin_open('Dashboard', 'dashboard');
?>
        <header class="admin-page-head">
            <div>
                <p class="admin-eyebrow"><?= date('l, j F Y') ?></p>
                <h1><?= $greeting ?>, <?= e($username) ?></h1>
                <p class="admin-page-sub">Here's how the store looks right now.</p>
            </div>
            <div class="admin-page-actions">
                <a href="order_create.php" class="btn btn-sm admin-btn-icon"><?= admin_icon('plus') ?> Create order</a>
                <a href="products.php?add=1#add" class="btn btn-sm btn-outline admin-btn-icon"><?= admin_icon('box') ?> Add product</a>
                <a href="sync.php" class="btn btn-sm btn-outline admin-btn-icon"><?= admin_icon('sync') ?> Run price sync</a>
            </div>
        </header>

        <div class="dash-stats">
            <a href="orders.php?from=<?= date('Y-m-01') ?>" class="dash-stat is-green" title="Orders placed this month, not counting cancelled ones">
                <span class="dash-stat-icon"><?= admin_icon('stock') ?></span>
                <span class="dash-stat-label">Sales in <?= date('F') ?></span>
                <strong><small>RM </small><?= number_format((float)$sales['this']['total']) ?></strong>
                <span class="dash-stat-note"><?= (int)$sales['this']['n'] ?> order<?= (int)$sales['this']['n'] === 1 ? '' : 's' ?> · RM <?= number_format((float)$sales['last']['total']) ?> last month</span>
            </a>
            <a href="orders.php?status=pending" class="dash-stat is-amber">
                <span class="dash-stat-icon"><?= admin_icon('orders') ?></span>
                <span class="dash-stat-label">Orders to fulfil</span>
                <strong><?= $pending ?><small> awaiting</small></strong>
                <span class="dash-stat-note<?= $pending ? ' is-warn' : '' ?>"><?= $open_total - $pending ?> more in progress · <?= (int)($by_status['shipped'] ?? 0) ?> shipped</span>
            </a>
            <a href="products.php" class="dash-stat is-blue">
                <span class="dash-stat-icon"><?= admin_icon('eye') ?></span>
                <span class="dash-stat-label">Products live</span>
                <strong><?= $counts['live'] ?><small> / <?= $total ?></small></strong>
                <span class="dash-stat-note"><?= $counts['pc'] ?> PCs · <?= $counts['laptop'] ?> laptops · <?= $counts['accessory'] ?> accessories<?= $counts['hidden'] ? ' · ' . $counts['hidden'] . ' hidden' : '' ?></span>
            </a>
            <a href="#syncs" class="dash-stat is-cyan">
                <span class="dash-stat-icon"><?= admin_icon('clock') ?></span>
                <span class="dash-stat-label">Last price sync</span>
                <?php if ($syncs): ?>
                <strong class="is-text"><?= date('j M', strtotime($syncs[0]['synced_at'])) ?></strong>
                <span class="dash-stat-note"><?= date('g:i a', strtotime($syncs[0]['synced_at'])) ?> · <?= (int)$syncs[0]['prices_changed'] ?> price<?= (int)$syncs[0]['prices_changed'] === 1 ? '' : 's' ?> changed</span>
                <?php else: ?>
                <strong class="is-text">None yet</strong>
                <span class="dash-stat-note">Recorded from the next sync</span>
                <?php endif; ?>
            </a>
        </div>

        <div class="dash-grid">
            <!-- Left: orders waiting, then what to fix -->
            <div class="dash-side">
            <section class="dash-card is-amber" id="orders">
                <header class="dash-card-head">
                    <span class="dash-card-icon is-amber"><?= admin_icon('orders') ?></span>
                    <div>
                        <h2>Orders to fulfil <?php if ($open_total): ?><span class="dash-count is-amber"><?= $open_total ?></span><?php endif; ?></h2>
                        <p>Awaiting processing, processing, packed or ready for pickup · longest waiting first</p>
                    </div>
                </header>
                <?php if (!$orders_ready): ?>
                <p class="dash-empty"><span>Orders aren't switched on yet: run <code>database/orders.sql</code> in phpMyAdmin.</span></p>
                <?php elseif (!$open_orders): ?>
                <p class="dash-empty is-good"><?= admin_icon('check') ?> <span>All caught up: no orders waiting.</span></p>
                <?php else: ?>
                <ul class="dash-list dash-orders">
                    <?php foreach ($open_orders as $o): ?>
                    <li>
                        <a href="order_detail.php?id=<?= (int)$o['id'] ?>" class="dash-list-main">
                            <strong><span class="order-id"><?= order_number((int)$o['id']) ?></span> <?= e($o['customer_name']) ?></strong>
                            <small><?= (int)$o['items'] ?> item<?= (int)$o['items'] === 1 ? '' : 's' ?> · <?= e($o['delivery_method']) ?> · <?= e($o['payment_method']) ?> · <?= ago($o['created_at']) ?></small>
                        </a>
                        <div class="dash-order-end">
                            <span class="order-badge is-<?= e($o['status']) ?>"><?= e($statuses[$o['status']] ?? $o['status']) ?></span>
                            <b><?= rm_price((float)$o['total_amount']) ?></b>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <?php if ($orders_ready): ?>
                <a href="orders.php" class="dash-card-more">View all orders<?= $open_total > count($open_orders) ? ' (' . ($open_total - count($open_orders)) . ' more waiting)' : '' ?> →</a>
                <?php endif; ?>
            </section>

            <section class="dash-card <?= $needs ? 'is-red' : 'is-green' ?>" id="attention">
                <header class="dash-card-head">
                    <span class="dash-card-icon is-<?= $needs ? 'red' : 'green' ?>"><?= admin_icon($needs ? 'alert' : 'check') ?></span>
                    <div>
                        <h2>Needs attention <?php if ($needs): ?><span class="dash-count is-red"><?= $needs ?></span><?php endif; ?></h2>
                        <p>Problems customers can see on the store, most serious first</p>
                    </div>
                </header>

                <?php if (!$needs): ?>
                <p class="dash-empty is-good"><?= admin_icon('check') ?> <span>Every live product has a price, stock, a photo and its specs.</span></p>
                <?php endif; ?>

                <?php foreach (['live' => null, 'hidden' => 'Hidden products · fix these before switching them Live'] as $which => $heading): ?>
                <?php if (!$attention[$which]) continue; ?>
                <?php if ($heading): ?><h3 class="dash-subhead"><?= $heading ?></h3><?php endif; ?>
                <ul class="dash-issues<?= $which === 'hidden' ? ' is-hidden-list' : '' ?>">
                    <?php foreach ($attention[$which] as $p): ?>
                    <li class="dash-issue is-level-<?= $p['level'] ?>">
                        <div class="dash-issue-main">
                            <a href="products.php?edit=<?= (int)$p['id'] ?>#edit" class="dash-issue-name"><?= e($p['name']) ?></a>
                            <span class="dash-issue-cat"><?= e($p['category_name'] ?? 'No category') ?></span>
                            <div class="dash-chips">
                                <?php foreach ($p['issues'] as [$level, $label, $anchor]): ?>
                                <a href="products.php?edit=<?= (int)$p['id'] ?>#<?= $anchor ?>" class="dash-chip is-level-<?= $level ?>"><?= e($label) ?></a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <a href="products.php?edit=<?= (int)$p['id'] ?>#<?= $p['issues'][0][2] ?>" class="btn btn-sm ap-btn-edit dash-fix">Fix</a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endforeach; ?>
            </section>
            </div>

            <!-- Right: stock and sync history -->
            <div class="dash-side">
                <section class="dash-card is-amber" id="low-stock">
                    <header class="dash-card-head">
                        <span class="dash-card-icon is-amber"><?= admin_icon('stock') ?></span>
                        <div>
                            <h2>Low stock <?php if ($low_stock): ?><span class="dash-count is-amber"><?= count($low_stock) ?></span><?php endif; ?></h2>
                            <p>Fewer than <?= LOW_STOCK ?> left</p>
                        </div>
                    </header>
                    <?php if (!$low_stock): ?>
                    <p class="dash-empty is-good"><?= admin_icon('check') ?> <span>Everything has at least <?= LOW_STOCK ?> in stock.</span></p>
                    <?php else: ?>
                    <ul class="dash-list">
                        <?php foreach ($low_stock as $row): ?>
                        <?php
                        $variant = $row['is_variant']
                            ? ((string)$row['option_name'] !== '' ? $row['option_name'] : implode(' · ', array_filter([$row['ram'] . ' / ' . $row['storage'], $set_labels[$row['set_type']] ?? '', $row['gpu_variant']])))
                            : 'Whole product';
                        $out = (int)$row['stock'] <= 0;
                        ?>
                        <li>
                            <a href="products.php?edit=<?= (int)$row['id'] ?>#<?= $row['is_variant'] ? ((string)$row['option_name'] !== '' ? 'options' : 'variants') : 'edit' ?>" class="dash-list-main" title="Restock">
                                <strong><?= e($row['name']) ?><?= $row['is_active'] ? '' : ' <em>Hidden</em>' ?></strong>
                                <small><?= e($variant) ?></small>
                            </a>
                            <span class="dash-pill <?= $out ? 'is-red' : 'is-amber' ?>"><?= $out ? 'Out of stock' : (int)$row['stock'] . ' left' ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </section>

                <section class="dash-card is-cyan" id="syncs">
                    <header class="dash-card-head">
                        <span class="dash-card-icon is-cyan"><?= admin_icon('sync') ?></span>
                        <div>
                            <h2>Recent price syncs</h2>
                            <p>The last 5 uploads</p>
                        </div>
                    </header>
                    <?php if (!$sync_log_ready): ?>
                    <p class="dash-empty"><span>Sync history isn't switched on yet: run <code>database/price_sync_log.sql</code> in phpMyAdmin.</span></p>
                    <?php elseif (!$syncs): ?>
                    <p class="dash-empty"><span>No syncs recorded yet. Your next <a href="sync.php">Price Sync</a> upload will show up here.</span></p>
                    <?php else: ?>
                    <ul class="dash-list">
                        <?php foreach ($syncs as $s): ?>
                        <?php $problems = (int)$s['skipped'] + (int)$s['not_found']; ?>
                        <li>
                            <div class="dash-list-main">
                                <strong><?= e($fmt_date($s['synced_at'])) ?></strong>
                                <small title="<?= e($s['filename']) ?>"><?= e($s['filename']) ?> · <?= (int)$s['rows_updated'] ?> rows · <?= (int)$s['products'] ?> PCs<?= $s['admin'] ? ' · ' . e($s['admin']) : '' ?></small>
                            </div>
                            <span class="dash-pill <?= $problems ? 'is-amber' : 'is-cyan' ?>" title="<?= (int)$s['skipped'] ?> skipped, <?= (int)$s['not_found'] ?> not found">
                                <?= (int)$s['prices_changed'] ?> changed<?= $problems ? " · $problems ⚠" : '' ?>
                            </span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </section>
            </div>
        </div>
<?php admin_close(); ?>

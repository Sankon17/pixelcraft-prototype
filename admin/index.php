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
$stmt = $pdo->prepare('SELECT p.id, p.name, p.is_active, v.ram, v.storage, v.set_type, v.gpu_variant, v.stock, 1 AS is_variant
        FROM product_variants v JOIN products p ON p.id = v.product_id
        WHERE v.stock < ?
    UNION ALL
    SELECT p.id, p.name, p.is_active, NULL, NULL, NULL, NULL, p.stock, 0
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
                <a href="products.php?add=1#add" class="btn btn-sm admin-btn-icon"><?= admin_icon('plus') ?> Add product</a>
                <a href="sync.php" class="btn btn-sm btn-outline admin-btn-icon"><?= admin_icon('sync') ?> Run price sync</a>
            </div>
        </header>

        <div class="dash-stats">
            <div class="dash-stat is-green">
                <span class="dash-stat-icon"><?= admin_icon('eye') ?></span>
                <span class="dash-stat-label">Live on the store</span>
                <strong><?= $counts['live'] ?><small> / <?= $total ?></small></strong>
                <span class="dash-stat-note"><?= $counts['hidden'] ? $counts['hidden'] . ' hidden' : 'None hidden' ?></span>
            </div>
            <div class="dash-stat is-blue">
                <span class="dash-stat-icon"><?= admin_icon('box') ?></span>
                <span class="dash-stat-label">Catalogue</span>
                <strong><?= $counts['pc'] ?><small> PCs</small></strong>
                <span class="dash-stat-note"><?= $counts['laptop'] ?> laptops · <?= $counts['accessory'] ?> accessories</span>
            </div>
            <div class="dash-stat is-purple">
                <span class="dash-stat-icon"><?= admin_icon('layers') ?></span>
                <span class="dash-stat-label">PC variants</span>
                <strong><?= $counts['variants'] ?></strong>
                <span class="dash-stat-note<?= $counts['variants_out'] ? ' is-warn' : '' ?>"><?= $counts['variants_out'] ? $counts['variants_out'] . ' out of stock' : 'All in stock' ?></span>
            </div>
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
            <!-- Left: what to fix -->
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
                            ? implode(' · ', array_filter([$row['ram'] . ' / ' . $row['storage'], $set_labels[$row['set_type']] ?? '', $row['gpu_variant']]))
                            : 'Whole product';
                        $out = (int)$row['stock'] <= 0;
                        ?>
                        <li>
                            <a href="products.php?edit=<?= (int)$row['id'] ?>#<?= $row['is_variant'] ? 'variants' : 'edit' ?>" class="dash-list-main" title="Restock">
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

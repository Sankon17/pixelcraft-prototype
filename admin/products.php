<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (($_SESSION['admin_logged_in'] ?? false) !== true) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$username = $_SESSION['admin_username'] ?? 'admin';

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

// List tabs and sort order. They ride along in the URL (and in every form) so they survive edits, hides and deletes
const LIST_TYPES = ['all' => 'All', 'pc' => 'PCs', 'laptop' => 'Laptops', 'accessory' => 'Accessories'];
const LIST_SORTS = [
    'price_asc'  => 'Price: low to high',
    'price_desc' => 'Price: high to low',
    'name'       => 'Name: A to Z',
    'newest'     => 'Newest first',
];

function list_state(array $src): array
{
    $type = $src['type'] ?? 'all';
    $sort = $src['sort'] ?? 'price_asc';
    return [
        'type' => is_string($type) && isset(LIST_TYPES[$type]) ? $type : 'all',
        'sort' => is_string($sort) && isset(LIST_SORTS[$sort]) ? $sort : 'price_asc',
    ];
}

// products.php?type=pc&sort=price_desc (defaults are left out of the URL)
function list_url(array $state, array $extra = []): string
{
    $query = array_filter($state + $extra, fn($v, $k) => !($k === 'type' && $v === 'all') && !($k === 'sort' && $v === 'price_asc'), ARRAY_FILTER_USE_BOTH);
    return 'products.php' . ($query ? '?' . http_build_query($query) : '');
}

function back_to_list(int $id = 0): never
{
    global $state;
    header('Location: ' . list_url($state) . ($id ? '#p-' . $id : ''));
    exit;
}

// PCs (office / editing / gaming), laptops, or everything else
function product_group(?string $slug): string
{
    if ($slug === 'laptops') {
        return 'laptop';
    }
    return isset(pc_tiers()[$slug ?? '']) ? 'pc' : 'accessory';
}

function variant_count(PDO $pdo, int $id): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM product_variants WHERE product_id = ?');
    $stmt->execute([$id]);
    return (int)$stmt->fetchColumn();
}

// A YouTube watch / youtu.be / shorts / embed link, or empty
function valid_youtube(string $url): bool
{
    return $url === '' || (bool)preg_match('#^https?://(www\.|m\.)?(youtube\.com/(watch\?v=|shorts/|embed/)|youtu\.be/)[\w-]{6,}#i', $url);
}

$state = list_state($_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET);
$state_fields = '<input type="hidden" name="type" value="' . e($state['type']) . '">'
    . '<input type="hidden" name="sort" value="' . e($state['sort']) . '">';

$errors = [];
$form = null;

// ── Actions (POST → redirect back to the list) ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        flash('error', 'Session expired. Please try again.');
        back_to_list();
    }

    $stmt = $pdo->prepare('SELECT id, name FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) {
        flash('error', 'That product no longer exists.');
        back_to_list();
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'toggle') {
        $pdo->prepare('UPDATE products SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        $stmt = $pdo->prepare('SELECT is_active FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $active = (int)$stmt->fetchColumn();
        flash('success', $target['name'] . ($active ? ' is now live on the store.' : ' is now hidden from the store.'));
        back_to_list($id);
    }

    if ($action === 'delete') {
        // Specs, images and variants go with the product (ON DELETE CASCADE); the cart table blocks it, so clear that first
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM cart WHERE product_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        $pdo->commit();
        flash('success', $target['name'] . ' was deleted.');
        back_to_list();
    }

    if ($action === 'save') {
        $has_variants = variant_count($pdo, $id) > 0;
        $form = [
            'id'          => $id,
            'name'        => trim($_POST['name'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'price'       => trim($_POST['price'] ?? ''),
            'stock'       => trim($_POST['stock'] ?? ''),
            'image'       => trim($_POST['image'] ?? ''),
            'video_url'   => trim($_POST['video_url'] ?? ''),
        ];

        if ($form['name'] === '') {
            $errors[] = 'Name is required.';
        } elseif (mb_strlen($form['name']) > 255) {
            $errors[] = 'Name must be 255 characters or fewer.';
        }
        if (!$has_variants && (!is_numeric($form['price']) || $form['price'] < 0)) {
            $errors[] = 'Price must be a number of 0 or more.';
        }
        if (!ctype_digit($form['stock'])) {
            $errors[] = 'Stock must be a whole number of 0 or more.';
        }
        if ($form['image'] !== '' && !preg_match('/^[\w.-]+\.(jpe?g|png|webp|gif)$/i', $form['image'])) {
            $errors[] = 'Image must be a file name only (e.g. pixel-lite-1.jpg), with no folders.';
        }
        if (!valid_youtube($form['video_url'])) {
            $errors[] = 'YouTube link must be a youtube.com or youtu.be video link.';
        }

        if (!$errors) {
            $pdo->beginTransaction();
            // PCs with variants take their price from the cheapest variant (Price Sync), so price is left alone here
            if ($has_variants) {
                $pdo->prepare('UPDATE products SET name = ?, description = ?, stock = ?, image = ? WHERE id = ?')
                    ->execute([$form['name'], $form['description'] ?: null, (int)$form['stock'], $form['image'] ?: null, $id]);
            } else {
                $pdo->prepare('UPDATE products SET name = ?, description = ?, price = ?, stock = ?, image = ? WHERE id = ?')
                    ->execute([$form['name'], $form['description'] ?: null, round((float)$form['price'], 2), (int)$form['stock'], $form['image'] ?: null, $id]);
            }
            // The YouTube link lives on the spec row; only create one when there is a link to store
            $pdo->prepare('UPDATE product_specs SET video_url = ? WHERE product_id = ?')->execute([$form['video_url'] ?: null, $id]);
            if ($form['video_url'] !== '') {
                $pdo->prepare('INSERT IGNORE INTO product_specs (product_id, video_url) VALUES (?, ?)')->execute([$id, $form['video_url']]);
            }
            $pdo->commit();

            flash('success', $form['name'] . ' was saved.');
            back_to_list($id);
        }
        // Errors: fall through and show the form again with what was typed
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ── Edit form (GET ?edit=ID, or a POST that failed validation) ──────
$edit_id = $form['id'] ?? (int)($_GET['edit'] ?? 0);
$editing = null;
if ($edit_id) {
    $stmt = $pdo->prepare('SELECT p.*, c.name AS category_name, s.video_url
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN product_specs s ON s.product_id = p.id
        WHERE p.id = ?');
    $stmt->execute([$edit_id]);
    $editing = $stmt->fetch() ?: null;
    if ($editing) {
        $editing['variant_count'] = variant_count($pdo, $edit_id);
        $form ??= [
            'id'          => $editing['id'],
            'name'        => $editing['name'],
            'description' => (string)$editing['description'],
            'price'       => $editing['price'],
            'stock'       => (string)$editing['stock'],
            'image'       => (string)$editing['image'],
            'video_url'   => (string)$editing['video_url'],
        ];
    }
}

// ── Product list ────────────────────────────────────────────────────
$products = $pdo->query('SELECT p.*, c.name AS category_name, c.slug AS category_slug,
        s.cpu, s.gpu, s.ram, s.storage, s.video_url,
        (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id) AS variant_count
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN product_specs s ON s.product_id = p.id')->fetchAll();

$live = count(array_filter($products, fn($p) => $p['is_active']));

$counts = ['all' => count($products), 'pc' => 0, 'laptop' => 0, 'accessory' => 0];
foreach ($products as &$p) {
    $p['group'] = product_group($p['category_slug']);
    $counts[$p['group']]++;
}
unset($p);

$shown = $state['type'] === 'all' ? $products : array_filter($products, fn($p) => $p['group'] === $state['type']);
usort($shown, fn($a, $b) => (match ($state['sort']) {
    'price_desc' => (float)$b['price'] <=> (float)$a['price'],
    'name'       => strcasecmp($a['name'], $b['name']),
    'newest'     => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']],
    default      => (float)$a['price'] <=> (float)$b['price'],
}) ?: $a['id'] <=> $b['id']);

// Table sections, in this order; the sort applies inside each one
$sections = ['pc' => ['🖥️', 'PCs'], 'laptop' => ['💻', 'Laptops'], 'accessory' => ['🎧', 'Accessories']];
$grouped = array_fill_keys(array_keys($sections), []);
foreach ($shown as $p) {
    $grouped[$p['group']][] = $p;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Products - PixelCraft Admin</title>
    <link rel="stylesheet" href="<?= asset('../', 'assets/css/style.css') ?>">
</head>
<body>

<header class="site-header">
    <nav class="nav">
        <a href="index.php" class="logo"><?= logo_img('../') ?> <small class="admin-tag">Admin</small></a>
        <div class="admin-user">
            <span>👤 <?= e($username) ?></span>
            <form method="POST" action="index.php">
                <button type="submit" name="logout" value="1" class="btn btn-outline btn-sm">Logout</button>
            </form>
        </div>
    </nav>
</header>

<main class="section">
    <a href="index.php" class="back-link">← Back to dashboard</a>

    <div class="admin-welcome sync-head">
        <h1><span class="red">Products</span></h1>
        <p><?= count($products) ?> products · <?= $live ?> live on the store · <?= count($products) - $live ?> hidden</p>
    </div>

    <?php if ($flash): ?>
    <div class="admin-flash is-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($editing && $form): ?>
    <?php $preview = $form['image'] !== '' ? asset_image('../', 'products', $form['image']) : null; ?>
    <section class="ap-edit" id="edit">
        <div class="ap-edit-head">
            <h2>Edit product</h2>
            <span><?= e($editing['category_name'] ?? 'No category') ?> · ID <?= (int)$editing['id'] ?></span>
        </div>

        <?php if ($errors): ?>
        <ul class="admin-flash is-error ap-errors" role="alert">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <form method="POST" action="products.php" class="ap-form">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="save">
            <?= $state_fields ?>
            <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">

            <label class="ap-wide">
                <span>Name</span>
                <input type="text" name="name" value="<?= e($form['name']) ?>" maxlength="255" required>
            </label>

            <label>
                <span>Price (RM)</span>
                <?php if ($editing['variant_count']): ?>
                <input type="text" value="<?= number_format((float)$editing['price'], 2) ?>" disabled>
                <small>Set automatically to the cheapest of its <?= (int)$editing['variant_count'] ?> variants. Change prices in <a href="variants.php?id=<?= (int)$editing['id'] ?>">Variants</a> or <a href="sync.php">Price Sync</a>.</small>
                <?php else: ?>
                <input type="number" name="price" value="<?= e($form['price']) ?>" min="0" step="0.01" required>
                <?php endif; ?>
            </label>

            <label>
                <span>Stock</span>
                <input type="number" name="stock" value="<?= e($form['stock']) ?>" min="0" step="1" required>
            </label>

            <label>
                <span>Image filename</span>
                <input type="text" name="image" value="<?= e($form['image']) ?>" placeholder="pixel-lite-1.jpg">
                <small>
                    File in <code>assets/images/products/</code>.
                    <?php if ($form['image'] !== '' && !$preview): ?><strong class="ap-warn">File not found.</strong><?php endif; ?>
                </small>
            </label>

            <div class="ap-preview">
                <?php if ($preview): ?>
                <img src="<?= e($preview) ?>" alt="">
                <?php else: ?>
                <span>No image</span>
                <?php endif; ?>
            </div>

            <label class="ap-wide">
                <span>YouTube link</span>
                <input type="url" name="video_url" value="<?= e($form['video_url']) ?>" placeholder="https://youtu.be/...">
                <small>Performance video shown on the product page. Leave empty for none.</small>
            </label>

            <label class="ap-wide">
                <span>Description</span>
                <textarea name="description" rows="4"><?= e($form['description']) ?></textarea>
            </label>

            <div class="ap-form-actions ap-wide">
                <button type="submit" class="btn">Save changes</button>
                <a href="<?= e(list_url($state)) ?>#p-<?= (int)$editing['id'] ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </section>
    <?php elseif ($edit_id): ?>
    <div class="admin-flash is-error" role="alert">That product no longer exists.</div>
    <?php endif; ?>

    <form method="GET" action="products.php" class="ap-toolbar">
        <nav class="category-filter ap-tabs" aria-label="Product type">
            <?php foreach (LIST_TYPES as $key => $label): ?>
            <?php if ($key === 'accessory' && !$counts['accessory']) continue; ?>
            <a href="<?= e(list_url(['type' => $key, 'sort' => $state['sort']])) ?>" class="chip<?= $state['type'] === $key ? ' active' : '' ?>"<?= $state['type'] === $key ? ' aria-current="page"' : '' ?>>
                <?= $label ?> <span class="ap-tab-count"><?= $counts[$key] ?></span>
            </a>
            <?php endforeach; ?>
        </nav>
        <?php if ($state['type'] !== 'all'): ?><input type="hidden" name="type" value="<?= e($state['type']) ?>"><?php endif; ?>
        <label class="ap-sort">
            <span>Sort</span>
            <select name="sort" onchange="this.form.submit()">
                <?php foreach (LIST_SORTS as $key => $label): ?>
                <option value="<?= $key ?>"<?= $state['sort'] === $key ? ' selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <noscript><button type="submit" class="btn btn-sm">Apply</button></noscript>
    </form>

    <div class="ap-table-wrap">
        <table class="ap-table">
            <thead>
                <tr>
                    <th>Active</th>
                    <th>Image</th>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Spec</th>
                    <th>Price</th>
                    <th>Stock</th>
                    <th>YouTube</th>
                    <th></th>
                </tr>
            </thead>
            <?php if (!$shown): ?>
            <tbody><tr><td colspan="9" class="ap-empty">No products here yet.</td></tr></tbody>
            <?php endif; ?>
            <?php foreach ($sections as $group => [$icon, $label]): ?>
            <?php if (!$grouped[$group]) continue; ?>
            <tbody>
                <?php if ($state['type'] === 'all'): ?>
                <tr class="ap-section"><th colspan="9"><?= $icon ?> <?= $label ?> <span><?= count($grouped[$group]) ?></span></th></tr>
                <?php endif; ?>
                <?php foreach ($grouped[$group] as $p): ?>
                <?php
                $thumb = asset_image('../', 'products', $p['image']);
                // CPU and GPU only: RAM / SSD are per variant and make the column too long
                $spec  = array_filter([$p['cpu'], $p['gpu']]);
                ?>
                <tr id="p-<?= (int)$p['id'] ?>" class="<?= $p['is_active'] ? '' : 'is-hidden' ?><?= $editing && $editing['id'] == $p['id'] ? ' is-editing' : '' ?>">
                    <td>
                        <form method="POST" action="products.php">
                            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                            <input type="hidden" name="action" value="toggle">
                            <?= $state_fields ?>
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <button type="submit" class="ap-switch<?= $p['is_active'] ? ' is-on' : '' ?>"
                                    title="<?= $p['is_active'] ? 'Live: click to hide from the store' : 'Hidden: click to show on the store' ?>">
                                <span class="ap-switch-knob"></span>
                                <span class="ap-switch-label"><?= $p['is_active'] ? 'Live' : 'Hidden' ?></span>
                            </button>
                        </form>
                    </td>
                    <td>
                        <div class="ap-thumb">
                            <?php if ($thumb): ?>
                            <img src="<?= e($thumb) ?>" alt="" loading="lazy">
                            <?php else: ?>
                            <span title="<?= e($p['image'] ?: 'No image') ?>"><?= category_icon($p['category_slug'] ?? '') ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="ap-name">
                        <strong><?= e($p['name']) ?></strong>
                        <small><?= $p['image'] ? e($p['image']) : 'No image' ?><?= $p['image'] && !$thumb ? ' · missing' : '' ?></small>
                    </td>
                    <td><span class="ap-cat"><?= e($p['category_name'] ?? '—') ?></span></td>
                    <td class="ap-spec"><?= $spec ? e(implode(' · ', $spec)) : '<span class="ap-muted">—</span>' ?></td>
                    <td class="ap-num">
                        RM <?= number_format((float)$p['price'], 2) ?>
                        <?php if ($p['variant_count']): ?><small>from · <?= (int)$p['variant_count'] ?> variants</small><?php endif; ?>
                    </td>
                    <td class="ap-num<?= (int)$p['stock'] === 0 ? ' ap-warn' : '' ?>"><?= (int)$p['stock'] ?></td>
                    <td>
                        <?php if ($p['video_url']): ?>
                        <a href="<?= e($p['video_url']) ?>" class="ap-link" target="_blank" rel="noopener" title="<?= e($p['video_url']) ?>">▶ Watch</a>
                        <?php else: ?>
                        <span class="ap-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="ap-actions">
                            <a href="<?= e(list_url($state, ['edit' => (int)$p['id']])) ?>#edit" class="btn btn-sm">Edit</a>
                            <?php if ($p['variant_count']): ?>
                            <a href="variants.php?id=<?= (int)$p['id'] ?>" class="btn btn-sm">Variants</a>
                            <?php endif; ?>
                            <a href="../pages/product.php?id=<?= (int)$p['id'] ?>" class="btn btn-sm ap-btn-view" target="_blank" rel="noopener" title="View on the store">View</a>
                            <form method="POST" action="products.php"
                                  onsubmit="return confirm(<?= e(json_encode('Delete ' . $p['name'] . '? Its specs, images and variants are deleted too. This cannot be undone.')) ?>)">
                                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                <input type="hidden" name="action" value="delete">
                                <?= $state_fields ?>
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <button type="submit" class="btn btn-sm ap-delete">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <?php endforeach; ?>
        </table>
    </div>
</main>

</body>
</html>

<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '_layout.php';

if (($_SESSION['admin_logged_in'] ?? false) !== true) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// $where: 'top' shows above the edit form, 'photos' inside the Photos panel
function flash(string $type, string $message, string $where = 'top'): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message, 'where' => $where];
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

// Categories for the dropdown, grouped like the list tabs: ['pc' => [id => row], 'laptop' => ..., 'accessory' => ...]
function category_options(PDO $pdo): array
{
    $groups = ['pc' => [], 'laptop' => [], 'accessory' => []];
    foreach ($pdo->query('SELECT id, name, slug FROM categories ORDER BY name') as $c) {
        $groups[product_group($c['slug'])][(int)$c['id']] = $c;
    }
    // PC tiers in the order the store shows them (Office, Editing, Gaming)
    $order = array_flip(array_keys(pc_tiers()));
    uasort($groups['pc'], fn($a, $b) => $order[$a['slug']] <=> $order[$b['slug']]);
    return $groups;
}

function find_category(array $groups, int $id): ?array
{
    foreach ($groups as $group) {
        if (isset($group[$id])) return $group[$id];
    }
    return null;
}

// The add and edit forms share these fields; price is skipped when it comes from variants
function read_product_form(array $src, array $categories, bool $price_from_variants): array
{
    $form = [
        'name'        => trim($src['name'] ?? ''),
        'category_id' => (int)($src['category_id'] ?? 0),
        'price'       => trim($src['price'] ?? ''),
        'stock'       => trim($src['stock'] ?? ''),
        'video_url'   => trim($src['video_url'] ?? ''),
    ];
    $errors = [];
    if ($form['name'] === '') {
        $errors[] = 'Name is required.';
    } elseif (mb_strlen($form['name']) > 255) {
        $errors[] = 'Name must be 255 characters or fewer.';
    }
    if (!find_category($categories, $form['category_id'])) {
        $errors[] = 'Choose a category.';
    }
    if (!$price_from_variants && (!is_numeric($form['price']) || $form['price'] < 0)) {
        $errors[] = 'Price must be a number of 0 or more.';
    }
    if (!ctype_digit($form['stock'])) {
        $errors[] = 'Stock must be a whole number of 0 or more.';
    }
    if (!valid_youtube($form['video_url'])) {
        $errors[] = 'YouTube link must be a youtube.com or youtu.be video link.';
    }
    return [$form, $errors];
}

// The "What can this PC handle?" type follows the category's default when a product moves,
// unless it was set on purpose to something else (Pixel Neo is an Office PC shown as a student PC)
function move_category(PDO $pdo, int $id, ?array $from, array $to): void
{
    $old = pc_tiers()[$from['slug'] ?? '']['pc_type'] ?? null;
    $new = pc_tiers()[$to['slug']]['pc_type'] ?? null;
    $pdo->prepare('UPDATE products SET category_id = ?, pc_type = ? WHERE id = ?')->execute([$to['id'], $new, $id]);
    if ($old !== null) {
        $pdo->prepare('UPDATE product_specs SET pc_type = ? WHERE product_id = ? AND pc_type = ?')->execute([$new, $id, $old]);
    }
}

// ── Specs and variants (edited on the same page as the product) ─────
// product_specs column => [label, max length, placeholder]. Empty ones show as "TBA" on the product page
const SPEC_FIELDS = [
    'cpu'         => ['Processor', 255, 'Ryzen 5 5500'],
    'gpu'         => ['Graphics card', 255, 'RTX 3050 / RTX 3060'],
    'motherboard' => ['Motherboard', 255, 'A520M'],
    'psu'         => ['Power supply', 100, '550W'],
    'cooler'      => ['Cooler', 100, 'Stock Cooler'],
    'casing'      => ['Casing', 255, 'Aquaron Nemo'],
    'ram'         => ['RAM', 100, '8GB / 16GB DDR4'],
    'storage'     => ['Storage', 100, '256GB / 512GB / 1TB NVMe'],
];
const SET_TYPES = ['cpu_only' => 'CPU Only', 'full_set' => 'Full Set'];
// The only RAM / SSD sizes a variant can have (32GB is for high-end builds like Pixel Raptor)
const RAM_OPTIONS = ['8GB', '16GB', '32GB'];
const STORAGE_OPTIONS = ['256GB', '512GB', '1TB'];

// ['8GB', '16GB', '32GB'] → "8GB, 16GB or 32GB"
function or_list(array $items): string
{
    $last = array_pop($items);
    return $items ? implode(', ', $items) . ' or ' . $last : (string)$last;
}
const JCL_MONTHS = [1 => 12, 2 => 24, 3 => 36];

// JCL instalment: flat 1.25% a month on the full price, split over the months (RM1,759 → RM70.85 × 36)
function jcl_monthly(float $price, int $months): float
{
    return round($price * (1 + 0.0125 * $months) / $months, 2);
}

// PCs and laptops show the spec table and RAM/SSD options on the store; accessories don't
function has_specs(?array $category): bool
{
    return in_array(product_group($category['slug'] ?? null), ['pc', 'laptop'], true);
}

function variants_of(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT id, ram, storage, set_type, gpu_variant AS gpu, price, stock FROM product_variants WHERE product_id = ? ORDER BY price, id');
    $stmt->execute([$id]);
    return array_map(fn($v) => ['remove' => false] + $v, $stmt->fetchAll());
}

function read_specs(array $src): array
{
    $specs = [];
    foreach (SPEC_FIELDS as $key => $_) {
        $specs[$key] = trim((string)($src[$key] ?? ''));
    }
    return $specs;
}

function spec_errors(array $specs): array
{
    $errors = [];
    foreach (SPEC_FIELDS as $key => [$label, $max]) {
        if (mb_strlen($specs[$key]) > $max) $errors[] = "$label must be $max characters or fewer.";
    }
    return $errors;
}

// Variant rows as typed. Rows left completely empty (a spare "add" row) are dropped
function read_variants(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $v = [
            'id'       => (int)($row['id'] ?? 0),
            'ram'      => trim((string)($row['ram'] ?? '')),
            'storage'  => trim((string)($row['storage'] ?? '')),
            'set_type' => (string)($row['set_type'] ?? 'cpu_only'),
            'gpu'      => trim((string)($row['gpu'] ?? '')),
            'price'    => trim((string)($row['price'] ?? '')),
            'stock'    => trim((string)($row['stock'] ?? '')),
            'remove'   => !empty($row['remove']),
        ];
        if (!$v['id'] && ($v['remove'] || $v['ram'] . $v['storage'] . $v['gpu'] . $v['price'] === '')) continue;
        $out[] = $v;
    }
    return $out;
}

function variant_errors(array $variants): array
{
    $errors = [];
    $seen = [];
    foreach (array_values(array_filter($variants, fn($v) => !$v['remove'])) as $n => $v) {
        $row = 'Variant ' . ($n + 1);
        if ($v['ram'] === '' || $v['storage'] === '') {
            $errors[] = "$row needs its RAM and storage.";
        } else {
            if (!in_array($v['ram'], RAM_OPTIONS, true)) $errors[] = "$row: RAM must be " . or_list(RAM_OPTIONS) . '.';
            if (!in_array($v['storage'], STORAGE_OPTIONS, true)) $errors[] = "$row: storage must be " . or_list(STORAGE_OPTIONS) . '.';
        }
        if (mb_strlen($v['gpu']) > 100) $errors[] = "$row: graphics card must be 100 characters or fewer.";
        if (!isset(SET_TYPES[$v['set_type']])) $errors[] = "$row: choose CPU Only or Full Set.";
        if (!is_numeric($v['price']) || $v['price'] <= 0) $errors[] = "$row needs a price above 0.";
        if (!ctype_digit($v['stock'])) $errors[] = "$row: stock must be a whole number of 0 or more.";
        // The store picks a variant by these four; two identical ones could never both be chosen
        $key = mb_strtolower($v['ram'] . '|' . $v['storage'] . '|' . $v['set_type'] . '|' . $v['gpu']);
        if (isset($seen[$key])) $errors[] = "$row is the same as variant {$seen[$key]} (RAM, storage, set and graphics card).";
        $seen[$key] ??= $n + 1;
    }
    return $errors;
}

// The whole add/edit form: details, plus specs and variants for PCs and laptops.
// $had_variants: the product already has variants (its price comes from them if the Variants section isn't on the form)
function read_full_form(array $post, array $categories, bool $had_variants = false): array
{
    $with_specs = isset($post['specs']) && has_specs(find_category($categories, (int)($post['category_id'] ?? 0)));
    $variants   = $with_specs ? read_variants((array)($post['variants'] ?? [])) : [];
    $kept       = array_filter($variants, fn($v) => !$v['remove']);
    // With variants the price is the cheapest one's, so the typed price isn't checked (or used)
    $price_from_variants = $with_specs ? (bool)$kept : $had_variants;
    [$form, $errors] = read_product_form($post, $categories, $price_from_variants);
    $form['specs']    = $with_specs ? read_specs((array)$post['specs']) : read_specs([]);
    $form['variants'] = $variants;
    if ($with_specs) {
        $errors = [...$errors, ...spec_errors($form['specs']), ...variant_errors($variants)];
    }
    return [$form, $errors, $with_specs, $price_from_variants];
}

function save_specs(PDO $pdo, int $id, array $specs): void
{
    $cols = array_keys(SPEC_FIELDS);
    $pdo->prepare('INSERT INTO product_specs (product_id, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')
        ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($c) => "$c = VALUES($c)", $cols)))
        ->execute([$id, ...array_map(fn($s) => $s === '' ? null : $s, array_values($specs))]);
}

// The YouTube link lives on the spec row; only create one when there is a link to store
function save_video(PDO $pdo, int $id, string $url): void
{
    $pdo->prepare('UPDATE product_specs SET video_url = ? WHERE product_id = ?')->execute([$url ?: null, $id]);
    if ($url !== '') {
        $pdo->prepare('INSERT IGNORE INTO product_specs (product_id, video_url) VALUES (?, ?)')->execute([$id, $url]);
    }
}

// "2 added, 1 removed" from save_variants()' counts
function variant_note(array $changes): string
{
    return implode(', ', array_filter([
        $changes[0] ? "$changes[0] added" : '',
        $changes[1] ? "$changes[1] changed" : '',
        $changes[2] ? "$changes[2] removed" : '',
    ]));
}

// Applies the typed variants. JCL is worked out again only for new rows and changed prices. Returns [added, changed, removed]
function save_variants(PDO $pdo, int $product_id, array $variants): array
{
    $current = [];
    foreach (variants_of($pdo, $product_id) as $v) {
        $current[(int)$v['id']] = $v;
    }
    $jcl = fn(float $price) => array_map(fn($m) => jcl_monthly($price, $m), array_values(JCL_MONTHS));
    $counts = [0, 0, 0];

    foreach ($variants as $v) {
        $price = round((float)$v['price'], 2);
        $gpu   = $v['gpu'] ?: null;
        $old   = $current[$v['id']] ?? null;

        if ($v['id'] && !$old) continue; // not this product's (or already gone)
        if ($old && $v['remove']) {
            $pdo->prepare('DELETE FROM product_variants WHERE id = ?')->execute([$v['id']]);
            $counts[2]++;
        } elseif ($old) {
            $same = [$old['ram'], $old['storage'], $old['set_type'], (string)$old['gpu'], (float)$old['price'], (int)$old['stock']]
                === [$v['ram'], $v['storage'], $v['set_type'], $v['gpu'], $price, (int)$v['stock']];
            if ($same) continue;
            $pdo->prepare('UPDATE product_variants SET ram = ?, storage = ?, set_type = ?, gpu_variant = ?, price = ?, stock = ? WHERE id = ?')
                ->execute([$v['ram'], $v['storage'], $v['set_type'], $gpu, $price, (int)$v['stock'], $v['id']]);
            if ($price !== (float)$old['price']) {
                $pdo->prepare('UPDATE product_variants SET jcl_1yr = ?, jcl_2yr = ?, jcl_3yr = ? WHERE id = ?')->execute([...$jcl($price), $v['id']]);
            }
            $counts[1]++;
        } elseif (!$v['remove']) {
            $pdo->prepare('INSERT INTO product_variants (product_id, ram, storage, set_type, gpu_variant, price, jcl_1yr, jcl_2yr, jcl_3yr, stock) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$product_id, $v['ram'], $v['storage'], $v['set_type'], $gpu, $price, ...$jcl($price), (int)$v['stock']]);
            $counts[0]++;
        }
    }

    // The store shows "From RM…": the cheapest variant
    $pdo->prepare('UPDATE products p JOIN (SELECT MIN(price) AS min_price FROM product_variants WHERE product_id = ?) v SET p.price = v.min_price WHERE p.id = ? AND v.min_price IS NOT NULL')
        ->execute([$product_id, $product_id]);
    return $counts;
}

// ── Photo gallery (product_images; files in assets/images/products/) ─
const PHOTO_MAX_BYTES = 20 * 1024 * 1024;
const PHOTO_TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
// Uploads bigger than this (in pixels or bytes) are shrunk to a web-size JPG, like the existing 1080px pixel-*.jpg files
const PHOTO_MAX_SIDE = 1600;
const PHOTO_KEEP_BYTES = 1024 * 1024;

// '40M' → bytes
function ini_bytes(string $key): int
{
    $value = trim((string)ini_get($key));
    $unit = strtoupper(substr($value, -1));
    return (int)$value * match ($unit) { 'G' => 1 << 30, 'M' => 1 << 20, 'K' => 1 << 10, default => 1 };
}

// Saves one upload into assets/images/products/ and returns its new filename, or null if it could not be saved
function store_photo(string $tmp, array $info, string $product_name): ?string
{
    [$width, $height, $type] = $info;
    $open = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'][$type] ?? null;
    $big  = max($width, $height) > PHOTO_MAX_SIDE || filesize($tmp) > PHOTO_KEEP_BYTES;

    // GIFs (maybe animated) and small files are kept exactly as uploaded
    if (!$big || !$open || !function_exists($open) || !($src = @$open($tmp))) {
        $file = photo_filename($product_name, PHOTO_TYPES[$type]);
        return move_uploaded_file($tmp, photo_dir() . $file) ? $file : null;
    }

    // Phone photos store their rotation separately; apply it before resizing
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $angle = [3 => 180, 6 => -90, 8 => 90][@exif_read_data($tmp)['Orientation'] ?? 1] ?? 0;
        if ($angle) {
            $src = imagerotate($src, $angle, 0);
            [$width, $height] = [imagesx($src), imagesy($src)];
        }
    }

    $scale = min(1, PHOTO_MAX_SIDE / max($width, $height));
    $w = max(1, (int)round($width * $scale));
    $h = max(1, (int)round($height * $scale));
    $out = imagecreatetruecolor($w, $h);
    imagefill($out, 0, 0, imagecolorallocate($out, 0, 0, 0)); // transparent PNG areas become black, like the site background
    imagecopyresampled($out, $src, 0, 0, 0, 0, $w, $h, $width, $height);

    $file = photo_filename($product_name, 'jpg');
    return imagejpeg($out, photo_dir() . $file, 85) ? $file : null;
}

function photo_dir(): string
{
    return __DIR__ . '/../assets/images/products/';
}

// Gallery rows in the order the product page shows them
function gallery(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT id, filename FROM product_images WHERE product_id = ? ORDER BY sort_order, id');
    $stmt->execute([$id]);
    return $stmt->fetchAll();
}

// Renumbers the gallery 1..n in this order. The first photo is the cover on listing cards (products.image)
function save_gallery(PDO $pdo, int $id, array $rows): void
{
    $rows = array_values($rows);
    $stmt = $pdo->prepare('UPDATE product_images SET sort_order = ? WHERE id = ?');
    foreach ($rows as $i => $row) {
        $stmt->execute([$i + 1, $row['id']]);
    }
    $pdo->prepare('UPDATE products SET image = ? WHERE id = ?')->execute([$rows[0]['filename'] ?? null, $id]);
}

// pixel-lite-4.jpg: the product name plus the first number no file uses yet (in any extension)
function photo_filename(string $name, string $ext): string
{
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'product';
    for ($n = 1; ; $n++) {
        foreach (PHOTO_TYPES as $taken) {
            if (is_file(photo_dir() . "$slug-$n.$taken")) continue 2;
        }
        return "$slug-$n.$ext";
    }
}

function back_to_photos(int $id): never
{
    global $state;
    header('Location: ' . list_url($state, ['edit' => $id]) . '#photos');
    exit;
}

// An upload bigger than post_max_size arrives with $_POST empty, so it would fail the CSRF check as "session expired"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $state = list_state($_GET);
    flash('error', 'Those photos add up to more than ' . ini_get('post_max_size') . 'B, the most one upload can take. Add fewer at a time.', 'photos');
    back_to_photos((int)($_GET['edit'] ?? 0));
}

$state = list_state($_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET);
$state_fields = '<input type="hidden" name="type" value="' . e($state['type']) . '">'
    . '<input type="hidden" name="sort" value="' . e($state['sort']) . '">';

$errors = [];
$form = null;
$adding = false;
$categories = category_options($pdo);

// ── Actions (POST → redirect back to the list) ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        flash('error', 'Session expired. Please try again.');
        back_to_list();
    }

    if ($action === 'add') {
        [$form, $errors, $with_specs] = read_full_form($_POST, $categories);
        $form['id'] = 0;
        $form['is_active'] = isset($_POST['is_active']);
        if (!$errors) {
            $category = find_category($categories, $form['category_id']);
            $pdo->beginTransaction();
            // With variants, save_variants() sets the price to the cheapest one
            $pdo->prepare('INSERT INTO products (category_id, name, price, stock, is_active, pc_type) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$category['id'], $form['name'], round((float)$form['price'], 2), (int)$form['stock'], (int)$form['is_active'], pc_tiers()[$category['slug']]['pc_type'] ?? null]);
            $new_id = (int)$pdo->lastInsertId();
            save_video($pdo, $new_id, $form['video_url']);
            $added = 0;
            if ($with_specs) {
                save_specs($pdo, $new_id, $form['specs']);
                $added = save_variants($pdo, $new_id, $form['variants'])[0];
            }
            $pdo->commit();

            flash('success', $form['name'] . ' was added to ' . $category['name']
                . ($added ? " with $added variant" . ($added === 1 ? '' : 's') : '')
                . ($form['is_active'] ? ' and is live on the store.' : ', hidden from the store for now.')
                . ' Add its photos here.', 'photos');
            // Everything else was on the add form; photos need the product to exist first
            back_to_photos($new_id);
        }
        // Errors: fall through and show the add form again with what was typed
        $adding = true;
    }

    $stmt = $pdo->prepare('SELECT id, name, image, category_id FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target && !$adding) {
        flash('error', 'That product no longer exists.');
        back_to_list();
    }

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

    if ($action === 'photo_upload') {
        $files = $_FILES['photos'] ?? [];
        $rows  = gallery($pdo, $id);
        // First upload: keep the current single image as photo 1 so it stays on the store
        if (!$rows && asset_image('../', 'products', $target['image'])) {
            $pdo->prepare('INSERT INTO product_images (product_id, filename, sort_order) VALUES (?, ?, 1)')->execute([$id, basename($target['image'])]);
            $rows = gallery($pdo, $id);
        }

        $added = [];
        $skipped = [];
        foreach ((array)($files['name'] ?? []) as $i => $original) {
            $error = $files['error'][$i];
            if ($error === UPLOAD_ERR_NO_FILE) continue;
            if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $files['size'][$i] > PHOTO_MAX_BYTES) {
                $skipped[] = "$original is over 20 MB";
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                $skipped[] = "$original did not upload";
                continue;
            }
            // Trust the file's contents, not its name, for the type
            $info = @getimagesize($files['tmp_name'][$i]);
            if (!isset(PHOTO_TYPES[$info[2] ?? 0])) {
                $skipped[] = "$original is not a JPG, PNG, WebP or GIF";
                continue;
            }
            $file = store_photo($files['tmp_name'][$i], $info, $target['name']);
            if (!$file) {
                $skipped[] = "$original could not be saved to assets/images/products/";
                continue;
            }
            $pdo->prepare('INSERT INTO product_images (product_id, filename, sort_order) VALUES (?, ?, 0)')->execute([$id, $file]);
            $rows[] = ['id' => (int)$pdo->lastInsertId(), 'filename' => $file];
            $added[] = $file;
        }
        save_gallery($pdo, $id, $rows);

        $message = $added ? 'Added ' . count($added) . ' photo' . (count($added) === 1 ? '' : 's') . ' to ' . $target['name'] . '.' : 'No photos were added.';
        if ($skipped) {
            $message .= ' Skipped: ' . implode('; ', $skipped) . '.';
        }
        flash($skipped ? 'error' : 'success', $message, 'photos');
        back_to_photos($id);
    }

    if (in_array($action, ['photo_left', 'photo_right', 'photo_cover', 'photo_remove'], true)) {
        $rows = gallery($pdo, $id);
        $at = array_search((int)($_POST['photo'] ?? 0), array_map('intval', array_column($rows, 'id')), true);
        if ($at === false) {
            flash('error', 'That photo is no longer in the gallery.', 'photos');
            back_to_photos($id);
        }
        $photo = $rows[$at];

        if ($action === 'photo_remove') {
            array_splice($rows, $at, 1);
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM product_images WHERE id = ?')->execute([$photo['id']]);
            save_gallery($pdo, $id, $rows);
            $pdo->commit();
            // Delete the file too, unless another product (or this one) still uses it
            $stmt = $pdo->prepare('SELECT (SELECT COUNT(*) FROM product_images WHERE filename = ?) + (SELECT COUNT(*) FROM products WHERE image = ?)');
            $stmt->execute([$photo['filename'], $photo['filename']]);
            if (!(int)$stmt->fetchColumn() && is_file(photo_dir() . basename($photo['filename']))) {
                unlink(photo_dir() . basename($photo['filename']));
            }
            flash('success', 'Removed ' . $photo['filename'] . '.', 'photos');
            back_to_photos($id);
        }

        $to = match ($action) {
            'photo_left'  => max(0, $at - 1),
            'photo_right' => min(count($rows) - 1, $at + 1),
            'photo_cover' => 0,
        };
        array_splice($rows, $at, 1);
        array_splice($rows, $to, 0, [$photo]);
        save_gallery($pdo, $id, $rows);
        if ($action === 'photo_cover') {
            flash('success', $photo['filename'] . ' is now the cover photo.', 'photos');
        }
        back_to_photos($id);
    }

    if ($action === 'save') {
        [$form, $errors, $with_specs, $price_from_variants] = read_full_form($_POST, $categories, variant_count($pdo, $id) > 0);
        $form['id'] = $id;

        if (!$errors) {
            $from = find_category($categories, (int)$target['category_id']);
            $to   = find_category($categories, $form['category_id']);
            $pdo->beginTransaction();
            $set = ['name = ?' => $form['name'], 'stock = ?' => (int)$form['stock']];
            if (!$price_from_variants) {
                $set['price = ?'] = round((float)$form['price'], 2);
            }
            $pdo->prepare('UPDATE products SET ' . implode(', ', array_keys($set)) . ' WHERE id = ?')
                ->execute([...array_values($set), $id]);
            save_video($pdo, $id, $form['video_url']);
            $changes = [0, 0, 0];
            if ($with_specs) {
                save_specs($pdo, $id, $form['specs']);
                $changes = save_variants($pdo, $id, $form['variants']);
            }
            $moved = (int)$to['id'] !== (int)($from['id'] ?? 0);
            if ($moved) {
                move_category($pdo, $id, $from, $to);
            }
            $pdo->commit();

            // "Pixel Nova was saved (variants: 2 added, 1 removed; moved to Gaming PCs)."
            $note = variant_note($changes);
            $done = array_filter([$note ? "variants: $note" : '', $moved ? 'moved to ' . $to['name'] : '']);
            flash('success', $form['name'] . ' was saved' . ($done ? ' (' . implode('; ', $done) . ')' : '') . '.');
            back_to_list($id);
        }
        // Errors: fall through and show the form again with what was typed
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ── Add form (GET ?add=1, or an add that failed validation) ─────────
if (!$form && isset($_GET['add'])) {
    $adding = true;
    $form = ['id' => 0, 'name' => '', 'category_id' => 0, 'price' => '', 'stock' => '10', 'video_url' => '', 'is_active' => false, 'specs' => read_specs([]), 'variants' => []];
}

// ── Edit form (GET ?edit=ID, or a POST that failed validation) ──────
$edit_id = $adding ? 0 : ($form['id'] ?? (int)($_GET['edit'] ?? 0));
$editing = null;
$photos = [];
if ($edit_id) {
    $stmt = $pdo->prepare('SELECT p.*, c.name AS category_name, s.video_url,
            s.cpu, s.gpu, s.motherboard, s.psu, s.cooler, s.casing, s.ram AS spec_ram, s.storage AS spec_storage
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN product_specs s ON s.product_id = p.id
        WHERE p.id = ?');
    $stmt->execute([$edit_id]);
    $editing = $stmt->fetch() ?: null;
    if ($editing) {
        $editing['variant_count'] = variant_count($pdo, $edit_id);
        $photos = gallery($pdo, $edit_id);
        $form ??= [
            'id'          => $editing['id'],
            'name'        => $editing['name'],
            'category_id' => (int)$editing['category_id'],
            'price'       => $editing['price'],
            'stock'       => (string)$editing['stock'],
            'video_url'   => (string)$editing['video_url'],
            'specs'       => read_specs(['ram' => $editing['spec_ram'], 'storage' => $editing['spec_storage']] + $editing),
            'variants'    => variants_of($pdo, $edit_id),
        ];
        // A failed save of an accessory posts no specs/variants, but the sections appear if the category is switched to a PC
        $form['specs']    ??= read_specs([]);
        $form['variants'] ??= [];
    }
}

// ── Product list ────────────────────────────────────────────────────
$products = $pdo->query('SELECT p.*, c.name AS category_name, c.slug AS category_slug,
        s.cpu, s.gpu, s.motherboard, s.psu, s.cooler, s.casing, s.ram, s.storage, s.video_url,
        (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id) AS variant_count,
        (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.stock <= 0) AS variants_out,
        (SELECT COUNT(*) FROM product_images i WHERE i.product_id = p.id) AS photo_count
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
admin_open('Products', 'products');
?>
<div class="ap-page">
    <header class="admin-page-head">
        <div>
            <h1>Products</h1>
            <p class="admin-page-sub"><?= count($products) ?> products · <?= $live ?> live on the store · <?= count($products) - $live ?> hidden</p>
        </div>
        <?php if (!$adding): ?>
        <div class="admin-page-actions">
            <a href="<?= e(list_url($state, ['add' => 1])) ?>#add" class="btn btn-sm admin-btn-icon"><?= admin_icon('plus') ?> Add product</a>
        </div>
        <?php endif; ?>
    </header>

    <?php if ($flash && (($flash['where'] ?? 'top') === 'top' || !$editing)): ?>
    <div class="admin-flash is-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <?php if (($editing || $adding) && $form): ?>
    <section class="ap-edit" id="<?= $adding ? 'add' : 'edit' ?>">
        <div class="ap-edit-head">
            <h2><?= $adding ? 'Add product' : 'Edit product' ?></h2>
            <span><?= $adding ? 'Details, specs and variants in one go · photos right after' : e($editing['category_name'] ?? 'No category') . ' · ID ' . (int)$editing['id'] ?></span>
        </div>

        <?php if ($errors): ?>
        <ul class="admin-flash is-error ap-errors" role="alert">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <?php
        // Specs and variants are for PCs and laptops (shown too while a new product has no category yet)
        $show_specs = !$form['category_id'] || has_specs(find_category($categories, $form['category_id']));
        // One spare empty row, so a variant can be added even without JavaScript
        $variant_rows = [...$form['variants'], ['id' => 0, 'ram' => '', 'storage' => '', 'set_type' => 'cpu_only', 'gpu' => '', 'price' => '', 'stock' => '10', 'remove' => false]];
        // With variants the price is the cheapest one's: shown in the price box, which is then locked
        $variant_prices = array_map(fn($v) => (float)$v['price'], array_filter($form['variants'], fn($v) => !$v['remove'] && is_numeric($v['price']) && $v['price'] > 0));
        $keep_locked = !$adding && !$show_specs && $editing['variant_count']; // an accessory with variants
        $price_locked = $variant_prices || $keep_locked;
        ?>
        <form method="POST" action="products.php" class="ap-form" data-product-form>
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="<?= $adding ? 'add' : 'save' ?>">
            <?= $state_fields ?>
            <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">

            <label class="ap-wide">
                <span>Name</span>
                <input type="text" name="name" value="<?= e($form['name']) ?>" maxlength="255" placeholder="Pixel Nova" required<?= $adding ? ' autofocus' : '' ?>>
            </label>

            <label class="ap-cat-field">
                <span>Category</span>
                <select name="category_id" required>
                    <?php if (!$form['category_id']): ?><option value="" selected disabled>Choose a category…</option><?php endif; ?>
                    <?php foreach ($categories as $group => $options): ?>
                    <?php if (!$options) continue; ?>
                    <optgroup label="<?= e(LIST_TYPES[$group]) ?>">
                        <?php foreach ($options as $cid => $c): ?>
                        <option value="<?= $cid ?>" data-specs="<?= has_specs($c) ? 1 : 0 ?>"<?= $cid === $form['category_id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>
                <small><?= $adding ? 'Where it appears on the store.' : 'Change it to move the product to another section of the store.' ?></small>
            </label>

            <label>
                <span>Price (RM)</span>
                <input type="number" name="price" value="<?= e($variant_prices ? number_format(min($variant_prices), 2, '.', '') : $form['price']) ?>" min="0" step="0.01" placeholder="0.00"
                       data-price<?= $keep_locked ? ' data-keep-locked' : '' ?><?= $price_locked ? ' readonly' : ' required' ?>>
                <small data-price-hint><?= $price_locked ? 'The cheapest variant. Change prices in Variants below.' : 'Once you add variants below, the price follows the cheapest one.' ?></small>
            </label>

            <label>
                <span>Stock</span>
                <input type="number" name="stock" value="<?= e($form['stock']) ?>" min="0" step="1" required>
            </label>

            <label class="ap-wide">
                <span>YouTube link</span>
                <input type="url" name="video_url" value="<?= e($form['video_url']) ?>" placeholder="https://youtu.be/...">
                <small>Performance video shown on the product page. Leave empty for none.</small>
            </label>

            <?php // Disabled fieldsets aren't submitted, so an accessory's save leaves specs and variants alone ?>
            <fieldset class="ap-section ap-wide" id="specs" data-needs-specs<?= $show_specs ? '' : ' hidden disabled' ?>>
                <legend>Specs</legend>
                <p class="ap-section-note">The spec table on the product page. Empty ones show as TBA.</p>
                <div class="ap-spec-grid">
                    <?php foreach (SPEC_FIELDS as $key => [$label, $max, $example]): ?>
                    <label>
                        <span><?= $label ?></span>
                        <input type="text" name="specs[<?= $key ?>]" value="<?= e($form['specs'][$key]) ?>" maxlength="<?= $max ?>" placeholder="e.g. <?= e($example) ?>">
                    </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <fieldset class="ap-section ap-wide" id="variants" data-needs-specs<?= $show_specs ? '' : ' hidden disabled' ?>>
                <legend>Variants</legend>
                <p class="ap-section-note">
                    The RAM/SSD, set and graphics card options customers pick from. The cheapest one is the "From" price.
                    JCL instalments are worked out from each price (flat 1.25% a month).
                </p>
                <div class="ap-table-wrap ap-variants-wrap">
                    <table class="ap-variants">
                        <thead>
                            <tr>
                                <th>RAM</th>
                                <th>Storage</th>
                                <th>Set</th>
                                <th>Graphics card <small>if it varies</small></th>
                                <th>Price (RM)</th>
                                <th>JCL 3 yrs</th>
                                <th>Stock</th>
                                <th>Remove</th>
                            </tr>
                        </thead>
                        <tbody data-variant-rows>
                            <?php foreach ($variant_rows as $i => $v): ?>
                            <tr<?= $v['id'] ? '' : ' class="is-new"' ?>>
                                <td>
                                    <input type="hidden" name="variants[<?= $i ?>][id]" value="<?= (int)$v['id'] ?>">
                                    <select name="variants[<?= $i ?>][ram]" aria-label="RAM">
                                        <option value="">—</option>
                                        <?php foreach (RAM_OPTIONS as $size): ?>
                                        <option value="<?= $size ?>"<?= $v['ram'] === $size ? ' selected' : '' ?>><?= $size ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <select name="variants[<?= $i ?>][storage]" aria-label="Storage">
                                        <option value="">—</option>
                                        <?php foreach (STORAGE_OPTIONS as $size): ?>
                                        <option value="<?= $size ?>"<?= $v['storage'] === $size ? ' selected' : '' ?>><?= $size ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <select name="variants[<?= $i ?>][set_type]" aria-label="Set">
                                        <?php foreach (SET_TYPES as $key => $label): ?>
                                        <option value="<?= $key ?>"<?= $v['set_type'] === $key ? ' selected' : '' ?>><?= $label ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="text" name="variants[<?= $i ?>][gpu]" value="<?= e($v['gpu']) ?>" maxlength="100" placeholder="optional" aria-label="Graphics card"></td>
                                <td><input type="number" name="variants[<?= $i ?>][price]" value="<?= e($v['price']) ?>" min="0" step="0.01" placeholder="0.00" aria-label="Price" data-variant-price></td>
                                <td class="ap-jcl" data-jcl><?= is_numeric($v['price']) && $v['price'] > 0 ? 'RM ' . number_format(jcl_monthly((float)$v['price'], 36), 2) : '—' ?></td>
                                <td><input type="number" name="variants[<?= $i ?>][stock]" value="<?= e($v['stock']) ?>" min="0" step="1" aria-label="Stock"></td>
                                <td class="ap-remove">
                                    <?php if ($v['id']): ?>
                                    <label title="Remove this variant when you save"><input type="checkbox" name="variants[<?= $i ?>][remove]" value="1"<?= $v['remove'] ? ' checked' : '' ?>> <span>Remove</span></label>
                                    <?php else: ?>
                                    <button type="button" class="ap-row-x" data-remove-row title="Remove this row" aria-label="Remove this row">✕</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-sm btn-outline ap-add-variant" data-add-variant hidden>＋ Add variant</button>
            </fieldset>

            <?php if ($adding): ?>
            <label class="ap-wide ap-check">
                <input type="checkbox" name="is_active" value="1"<?= $form['is_active'] ? ' checked' : '' ?>>
                <span>Show on the store straight away</span>
                <small>Leave unticked to add photos first, then switch it Live in the list.</small>
            </label>
            <?php endif; ?>

            <div class="ap-form-actions ap-wide">
                <button type="submit" class="btn"><?= $adding ? 'Add product' : 'Save changes' ?></button>
                <a href="<?= e(list_url($state)) ?><?= $adding ? '' : '#p-' . (int)$editing['id'] ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </section>

    <script>
    (() => {
        const form = document.querySelector('[data-product-form]');
        const rows = form.querySelector('[data-variant-rows]');
        const variants = form.querySelector('#variants');
        const price = form.querySelector('[data-price]');
        const hint = form.querySelector('[data-price-hint]');
        const money = n => 'RM ' + n.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        // Same flat formula as jcl_monthly(): 1.25% a month over 36 months
        const jcl = price => Math.round(price * (1 + 0.0125 * 36) / 36 * 100) / 100;

        const refresh = () => {
            const prices = [];
            rows.querySelectorAll('tr').forEach(tr => {
                const price = parseFloat(tr.querySelector('[data-variant-price]').value);
                const removed = tr.querySelector('.ap-remove input:checked');
                tr.querySelector('[data-jcl]').textContent = price > 0 ? money(jcl(price)) : '—';
                if (price > 0 && !removed) prices.push(price);
            });
            // The price box follows the cheapest variant, and is locked while there are any
            if (price.hasAttribute('data-keep-locked')) return;
            const locked = !variants.disabled && prices.length > 0;
            if (locked) price.value = Math.min(...prices).toFixed(2);
            price.readOnly = locked;
            price.required = !locked;
            hint.textContent = locked ? 'The cheapest variant. Change prices in Variants below.' : 'Once you add variants below, the price follows the cheapest one.';
        };
        rows.addEventListener('input', refresh);
        rows.addEventListener('change', refresh);

        // ✕ on a new row: drop it (the last spare row is only emptied, so there's always one to copy)
        rows.addEventListener('click', e => {
            const x = e.target.closest('[data-remove-row]');
            if (!x) return;
            const row = x.closest('tr');
            if (rows.querySelectorAll('tr.is-new').length > 1) {
                row.remove();
            } else {
                row.querySelectorAll('input:not([type=hidden])').forEach(el => { el.value = el.name.endsWith('[stock]') ? '10' : ''; });
                row.querySelectorAll('select').forEach(el => { el.selectedIndex = 0; });
            }
            refresh();
        });

        // New row: a copy of the empty spare row with the next index (variants[7][ram] …)
        const add = form.querySelector('[data-add-variant]');
        add.hidden = false;
        add.addEventListener('click', () => {
            const spare = rows.querySelector('tr.is-new:last-child');
            const row = spare.cloneNode(true);
            const next = rows.children.length;
            row.querySelectorAll('[name]').forEach(el => {
                el.name = el.name.replace(/^variants\[\d+\]/, `variants[${next}]`);
                if (el.tagName === 'SELECT') el.selectedIndex = 0;
                else if (el.type !== 'hidden') el.value = el.name.endsWith('[stock]') ? '10' : '';
            });
            rows.appendChild(row);
            refresh();
            row.querySelector('select').focus();
        });

        // Specs and variants only apply to PCs and laptops; disabled fieldsets aren't sent
        const category = form.querySelector('[name=category_id]');
        category.addEventListener('change', () => {
            const on = category.selectedOptions[0]?.dataset.specs === '1';
            form.querySelectorAll('[data-needs-specs]').forEach(fs => { fs.hidden = !on; fs.disabled = !on; });
            refresh();
        });
    })();
    </script>
    <?php endif; ?>

    <?php if ($editing && $form): ?>
    <?php
    // A product with no gallery rows may still have a single image from before the Photos panel
    $old_image = $photos ? null : asset_image('../', 'products', $editing['image']);
    ?>

    <section class="ap-edit" id="photos">
        <div class="ap-edit-head">
            <h2>Photos</h2>
            <span><?= count($photos) ?> photo<?= count($photos) === 1 ? '' : 's' ?> · shown on the product page in this order · changes save straight away</span>
        </div>

        <?php if ($flash && ($flash['where'] ?? 'top') === 'photos'): ?>
        <div class="admin-flash is-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <?php if ($photos): ?>
        <ol class="ap-gallery">
            <?php foreach ($photos as $i => $photo): ?>
            <?php $src = asset_image('../', 'products', $photo['filename']); ?>
            <li class="ap-photo<?= $i === 0 ? ' is-cover' : '' ?>">
                <div class="ap-photo-img">
                    <?php if ($src): ?>
                    <a href="<?= e($src) ?>" target="_blank" rel="noopener" title="Open full size"><img src="<?= e($src) ?>" alt="" loading="lazy"></a>
                    <?php else: ?>
                    <span class="ap-warn">File not found</span>
                    <?php endif; ?>
                    <span class="ap-photo-badge<?= $i === 0 ? '' : ' is-num' ?>"><?= $i === 0 ? 'Cover' : $i + 1 ?></span>
                </div>
                <div class="ap-photo-name" title="<?= e($photo['filename']) ?>"><?= e($photo['filename']) ?></div>
                <form method="POST" action="products.php" class="ap-photo-actions">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <?= $state_fields ?>
                    <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                    <input type="hidden" name="photo" value="<?= (int)$photo['id'] ?>">
                    <button type="submit" name="action" value="photo_left" class="btn btn-sm btn-outline" title="Move earlier" aria-label="Move earlier"<?= $i === 0 ? ' disabled' : '' ?>>←</button>
                    <button type="submit" name="action" value="photo_right" class="btn btn-sm btn-outline" title="Move later" aria-label="Move later"<?= $i === count($photos) - 1 ? ' disabled' : '' ?>>→</button>
                    <button type="submit" name="action" value="photo_cover" class="btn btn-sm btn-outline"<?= $i === 0 ? ' disabled' : '' ?>><?= $i === 0 ? 'Cover' : 'Make cover' ?></button>
                    <button type="submit" name="action" value="photo_remove" class="btn btn-sm ap-delete"
                            onclick="return confirm(<?= e(json_encode('Remove ' . $photo['filename'] . ' from ' . $editing['name'] . '?')) ?>)">Remove</button>
                </form>
            </li>
            <?php endforeach; ?>
        </ol>
        <?php else: ?>
        <p class="ap-photos-empty">No photos yet<?= $old_image ? '. The store shows ' . e(basename($editing['image'])) . ' until you upload, and your first upload keeps it as photo 1' : '' ?>.</p>
        <?php endif; ?>

        <form method="POST" action="<?= e(list_url($state, ['edit' => (int)$editing['id']])) ?>" enctype="multipart/form-data" class="ap-upload">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="photo_upload">
            <?= $state_fields ?>
            <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
            <label class="ap-drop" data-max-total="<?= ini_bytes('post_max_size') ?>" data-max-file="<?= min(PHOTO_MAX_BYTES, ini_bytes('upload_max_filesize')) ?>">
                <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple required>
                <span class="ap-drop-icon" aria-hidden="true">📷</span>
                <strong>Drop photos here, or click to choose</strong>
                <small>JPG, PNG, WebP or GIF · up to 20 MB each · big photos are shrunk to <?= PHOTO_MAX_SIDE ?>px JPGs · added to the end as <?= e(photo_filename($editing['name'], 'jpg')) ?>, …</small>
                <span class="ap-drop-files" aria-live="polite"></span>
            </label>
            <button type="submit" class="btn">Upload photos</button>
        </form>
    </section>

    <script>
    (() => {
        const drop = document.querySelector('.ap-drop');
        const input = drop.querySelector('input');
        const list = drop.querySelector('.ap-drop-files');
        const mb = bytes => (bytes / 1048576).toFixed(1) + ' MB';
        // The server rejects the whole upload past post_max_size, so catch that here instead
        const problem = () => {
            const files = [...input.files];
            const total = files.reduce((sum, f) => sum + f.size, 0);
            const big = files.filter(f => f.size > drop.dataset.maxFile).map(f => f.name);
            if (big.length) return big.join(', ') + (big.length === 1 ? ' is' : ' are') + ' over ' + mb(drop.dataset.maxFile) + '.';
            if (total > drop.dataset.maxTotal) return 'These add up to ' + mb(total) + '; one upload can take ' + mb(drop.dataset.maxTotal) + '. Choose fewer and upload the rest after.';
            return '';
        };
        const show = () => {
            const names = [...input.files].map(f => f.name);
            const error = problem();
            list.textContent = error || (names.length ? names.length + ' chosen: ' + names.join(', ') : '');
            list.classList.toggle('is-error', !!error);
        };
        input.addEventListener('change', show);
        ['dragenter', 'dragover'].forEach(t => drop.addEventListener(t, () => drop.classList.add('is-over')));
        ['dragleave', 'drop'].forEach(t => drop.addEventListener(t, () => drop.classList.remove('is-over')));
        drop.closest('form').addEventListener('submit', e => {
            if (problem()) {
                e.preventDefault();
                show();
                return;
            }
            const btn = e.target.querySelector('button[type=submit]');
            btn.disabled = true;
            btn.textContent = 'Uploading…';
        });
    })();
    </script>
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
                // Missing or incomplete information shows in red (same checks as the dashboard's Needs attention)
                $issues  = product_issues($p);
                $missing = fn(string $field) => isset($issues[$field]) ? ' is-missing' : '';
                ?>
                <tr id="p-<?= (int)$p['id'] ?>" class="<?= $p['is_active'] ? '' : 'is-hidden' ?><?= $editing && $editing['id'] == $p['id'] ? ' is-editing' : '' ?><?= $issues ? ' has-issues' : '' ?>"
                    <?= $issues ? 'title="' . e('Missing: ' . implode('; ', array_column($issues, 1))) . '"' : '' ?>>
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
                        <div class="ap-thumb<?= $missing('photo') ?>">
                            <?php if ($thumb): ?>
                            <img src="<?= e($thumb) ?>" alt="" loading="lazy">
                            <?php else: ?>
                            <span title="<?= e($p['image'] ?: 'No image') ?>"><?= category_icon($p['category_slug'] ?? '') ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="ap-name">
                        <strong><?= e($p['name']) ?></strong>
                        <?php if (isset($issues['photo'])): ?>
                        <small class="ap-missing-text">No photo<?= $p['image'] ? ' (' . e($p['image']) . ' not found)' : '' ?></small>
                        <?php else: ?>
                        <small><?= e($p['image']) ?><?= $p['photo_count'] > 1 ? ' · ' . (int)$p['photo_count'] . ' photos' : '' ?></small>
                        <?php endif; ?>
                    </td>
                    <td><span class="ap-cat"><?= e($p['category_name'] ?? '—') ?></span></td>
                    <td class="ap-spec">
                        <?php if ($spec): ?><?= e(implode(' · ', $spec)) ?><?php elseif (!isset($issues['specs'])): ?><span class="ap-muted">—</span><?php endif; ?>
                        <?php if (isset($issues['specs'])): ?>
                        <small class="ap-missing-text"><?= $spec ? e(strstr($issues['specs'][1], ' —', true)) : 'Specs missing' ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="ap-num<?= $missing('price') ?>">
                        RM <?= number_format((float)$p['price'], 2) ?>
                        <?php if (isset($issues['variants'])): ?>
                        <small class="ap-missing-text"><?= e($issues['variants'][1]) ?></small>
                        <?php elseif ($p['variant_count']): ?>
                        <small>from · <?= (int)$p['variant_count'] ?> variants</small>
                        <?php endif; ?>
                    </td>
                    <td class="ap-num<?= $missing('stock') ?>"><?= (int)$p['stock'] ?></td>
                    <td>
                        <?php if ($p['video_url']): ?>
                        <a href="<?= e($p['video_url']) ?>" class="ap-link" target="_blank" rel="noopener" title="<?= e($p['video_url']) ?>">▶ Watch</a>
                        <?php else: ?>
                        <span class="ap-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="ap-actions">
                            <a href="<?= e(list_url($state, ['edit' => (int)$p['id']])) ?>#edit" class="btn btn-sm ap-btn-edit">Edit</a>
                            <?php if ($p['variant_count'] || $p['group'] !== 'accessory'): ?>
                            <a href="<?= e(list_url($state, ['edit' => (int)$p['id']])) ?>#variants" class="btn btn-sm ap-btn-variants">Variants</a>
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
</div>
<?php admin_close(); ?>

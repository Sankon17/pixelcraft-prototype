<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/fps_data.php';
require_once '../includes/cart.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// LEFT JOIN so the page works before a product has a product_specs row
$stmt = $pdo->prepare("SELECT p.*, c.name AS category_name, c.slug AS category_slug,
        ps.cpu, ps.gpu, ps.motherboard, ps.psu, ps.cooler, ps.casing,
        ps.ram, ps.storage, ps.config_options, ps.color, ps.video_url, ps.pc_type
    FROM products p
    JOIN categories c ON p.category_id = c.id
    LEFT JOIN product_specs ps ON ps.product_id = p.id
    WHERE p.id = ?");
$stmt->execute([$id]);
$product = $stmt->fetch();

// Hidden products (admin/products.php) are only visible to a logged-in admin, for previewing
if (!$product || (!$product['is_active'] && ($_SESSION['admin_logged_in'] ?? false) !== true)) {
    header('Location: pc-categories.php');
    exit;
}

$tiers   = pc_tiers();
$is_pc   = isset($tiers[$product['category_slug']]);
$stock   = (int)$product['stock'];
$max_qty = min(10, $stock);

// Spec row wins; otherwise fall back to the category's default type (accessories have none)
$pc_type = $product['pc_type'] ?: ($tiers[$product['category_slug']]['pc_type'] ?? null);
// Laptops: gaming laptops (with FPS figures in includes/fps_data.php) show the games they can play; the others show
// what they're good for (office work, classes, streaming), with no game performance
if (product_group($product['category_slug']) === 'laptop' && !$product['pc_type']) {
    $pc_type = isset(fps_data()[pc_key($product['name'])]) ? 'gaming' : 'office';
}

// Priced configurations (RAM/SSD + set + GPU + colour), cheapest first
$stmt = $pdo->prepare("SELECT id, option_name, option_values, ram, storage, set_type, gpu_variant, color, price, jcl_3yr, stock
    FROM product_variants WHERE product_id = ? ORDER BY price, id");
$stmt->execute([$id]);
$variants = $stmt->fetchAll();

// No variants yet: RAM/SSD sets from product_specs, e.g. "8GB DDR4 / 256GB NVMe" (no price change)
$configs = $variants ? [] : array_values(array_filter(array_map('trim', explode('|', (string)$product['config_options']))));

$set_labels = ['cpu_only' => 'CPU Only', 'full_set' => 'Full Set'];

// The value each chip group takes for a variant. An accessory's variant has one option per variation type
// (opt0 = Colour "Red", opt1 = Fabric "PU Leather"); one from before variation types has just its name
function variant_keys(array $v): array
{
    $values = json_decode((string)$v['option_values'], true);
    if (is_array($values) && $values) {
        $keys = [];
        foreach (array_values($values) as $i => $value) {
            $keys["opt$i"] = (string)$value;
        }
        return $keys;
    }
    if (trim((string)$v['option_name']) !== '') {
        return ['option' => $v['option_name']];
    }
    return [
        'ramssd' => $v['ram'] . ' + ' . str_replace(' NVMe', '', $v['storage']),
        'set'    => $v['set_type'],
        'gpu'    => (string)$v['gpu_variant'],
        'color'  => (string)$v['color'],
    ];
}


// Chip groups: value => label, in order of first appearance (so the first chip of each is the cheapest variant)
$options      = ['option' => [], 'ramssd' => [], 'set' => [], 'gpu' => [], 'color' => []];
$variant_json = [];
foreach ($variants as $v) {
    $keys = variant_keys($v);
    foreach ($keys as $group => $value) {
        $options[$group][$value] = $group === 'set' ? $set_labels[$value] : $value;
    }
    $variant_json[] = $keys + [
        'id'    => (int)$v['id'],
        'price' => (float)$v['price'],
        'jcl'   => $v['jcl_3yr'] !== null ? (float)$v['jcl_3yr'] : null,
        'stock' => (int)$v['stock'],
    ];
}

$chip_groups = [];
if (isset($options['opt0'])) {
    // One row of chips per variation type, named and ordered the way the admin set them up
    $opt_types = json_decode((string)$product['option_groups'], true) ?: [];
    for ($i = 0; isset($options["opt$i"]); $i++) {
        $order = array_flip(array_map('strval', $opt_types[$i]['values'] ?? []));
        uksort($options["opt$i"], fn($a, $b) => ($order[(string)$a] ?? PHP_INT_MAX) <=> ($order[(string)$b] ?? PHP_INT_MAX));
        $chip_groups["opt$i"] = [$opt_types[$i]['name'] ?? 'Pilihan', $options["opt$i"]];
    }
} elseif ($options['option']) {
    $chip_groups['option'] = ['Pilihan', $options['option']];
} elseif ($variants) {
    $chip_groups['ramssd'] = ['RAM dan SSD', $options['ramssd']];
    $chip_groups['set']    = ['Set', $options['set']];
    if (count($options['gpu']) > 1)   $chip_groups['gpu']   = ['Graphics Card', $options['gpu']];
    if (count($options['color']) > 1) $chip_groups['color'] = ['Color', $options['color']];
} elseif ($configs) {
    $chip_groups['config'] = ['RAM dan SSD', array_combine($configs, $configs)];
}

// Pre-selected: the cheapest variant that can be bought (the cheapest overall if every one is sold out)
$default   = current(array_filter($variants, fn($v) => (int)$v['stock'] > 0)) ?: ($variants[0] ?? null);
$picked    = $default ? variant_keys($default) : [];
$has_jcl   = $variants && array_filter(array_column($variants, 'jcl_3yr'), fn($j) => $j !== null);
$show_from = $variants ? count($variants) > 1 : count($configs) > 1;

// Tambah ke Troli / Beli Sekarang
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $stock > 0) {
    $limit      = $stock;
    $variant_id = null;
    $config     = null;

    if ($variants) {
        // Only variants of this product; anything else falls back to the cheapest
        $chosen = $default;
        foreach ($variants as $v) {
            if ((int)$v['id'] === (int)($_POST['selected_variant_id'] ?? 0)) $chosen = $v;
        }
        $limit      = min($limit, (int)$chosen['stock']);
        $variant_id = (int)$chosen['id'];
    } elseif ($configs) {
        // The chosen RAM/SSD set (only values we actually offer)
        $config = in_array($_POST['config'] ?? '', $configs, true) ? $_POST['config'] : $configs[0];
    }

    // Each product + option is its own cart line (includes/cart.php)
    $qty   = max(1, min((int)($_POST['quantity'] ?? 1), $max_qty));
    $added = $limit > 0 ? cart_add($id, $variant_id, $config, $qty, $limit) : 0;

    if (($_POST['action'] ?? '') === 'buy_now') {
        header('Location: checkout.php');
        exit;
    }

    // Tambah ke Troli keeps the customer here: "You just added Pixel Radiant, 8GB / 256GB · CPU Only to the cart."
    $what = $product['name'] . (($detail = $variant_id ? variant_detail($chosen) : $config) ? ", $detail" : '');
    $toast = match (true) {
        $limit <= 0 => ['error', "Sorry, $what is out of stock."],
        $added === 0 => ['error', "Your cart already has all we can sell of $what."],
        default => ['success', 'You just added ' . ($added > 1 ? "$added × " : '') . "$what to the cart."],
    };
    // The page sends the button with fetch() and shows the message without reloading (assets/js/main.js)
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $toast[0] === 'success', 'message' => $toast[1], 'count' => cart_count()]);
        exit;
    }
    $_SESSION['toast'] = $toast;
    header('Location: product.php?id=' . $id);
    exit;
}

// Turn a YouTube watch/share link into an embed URL
function youtube_embed(?string $url): ?string
{
    if (!$url) return null;
    if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/))([\w-]{11})~', $url, $m)) {
        return 'https://www.youtube.com/embed/' . $m[1];
    }
    return null;
}
$video = youtube_embed($product['video_url']);

// A laptop's description: an intro sentence, then one "•" line per detail. The main parts become rows of a spec
// table like the PCs' ("Intel N150 Processor" → Processor: Intel N150); everything else is listed as a feature
function laptop_details(string $text): array
{
    $rows = ['Processor' => null, 'Graphics Card' => null, 'Display' => null, 'RAM' => null, 'Storage' => null];
    $intro = [];
    $features = [];
    foreach (preg_split('/\R/u', trim($text)) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (!preg_match('/^[•\-*]\s*(.+)$/u', $line, $m)) {
            $intro[] = $line;
            continue;
        }
        $item = trim($m[1]);
        $label = match (true) {
            (bool)preg_match('/\bdisplay\b|\d+(\.\d+)?\s*("|”|-?inch)/iu', $item)           => 'Display',
            (bool)preg_match('/\bgpu\b|graphics|geforce|\brtx\b|radeon/iu', $item)         => 'Graphics Card',
            (bool)preg_match('/processor|\bcore\s+(i\d|ultra)|ryzen|\bintel\s+n\d+/iu', $item) => 'Processor',
            (bool)preg_match('/\bram\b/iu', $item)                                         => 'RAM',
            (bool)preg_match('/\bssd\b|storage|\bnvme\b|\bhdd\b/iu', $item)                => 'Storage',
            default                                                                        => null,
        };
        if ($label && $rows[$label] === null) {
            // The row's label already says it: "16GB DDR5 RAM" → "16GB DDR5"
            $rows[$label] = preg_replace('/\s+(processor|display|ram|storage)(?=\s*(\(|$))/iu', '', $item);
        } else {
            $features[] = $item;
        }
    }
    return [implode(' ', $intro), array_filter($rows), $features];
}

// Prices are whole ringgit for PCs; show cents only when there are any
function rm(float $amount): string
{
    return 'RM ' . number_format($amount, fmod($amount, 1) ? 2 : 0);
}

// CPU badge next to the name, picked from the processor: "Ryzen 5 5500GT" → assets/images/cpu/ryzen-5.png,
// "Intel i5 12400" → intel-i5.png, "Intel U7 270K" → intel-ultra-7.png. Nothing shows until the file exists.
$cpu_badge = null;
$cpu       = (string)$product['cpu'];
if (preg_match('/Ryzen (\d)/', $cpu, $m))   $cpu_badge = "ryzen-$m[1].png";
elseif (preg_match('/\bi(\d)\b/', $cpu, $m)) $cpu_badge = "intel-i$m[1].png";
elseif (preg_match('/\bU(\d)\b/', $cpu, $m)) $cpu_badge = "intel-ultra-$m[1].png";
$cpu_badge = asset_image('../', 'cpu', $cpu_badge);

$specs = [
    'Processor'     => $product['cpu'],
    'Graphics Card' => $product['gpu'],
    'Motherboard'   => $product['motherboard'],
    'Power Supply'  => $product['psu'],
    'Cooler'        => $product['cooler'],
    'Casing'        => $product['casing'],
    'RAM'           => $product['ram'],
    'Storage'       => $product['storage'],
];

// Checkout / warranty perks under the buy buttons (outline icons, drawn with currentColor)
$perk_icons = [
    'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    'box'      => '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
    'award'    => '<circle cx="12" cy="8" r="6"/><path d="M15.5 12.9 17 22l-5-3-5 3 1.5-9.1"/>',
    'check'    => '<path d="M21.8 10A10 10 0 1 1 17 3.3"/><path d="m9 11 3 3L22 4"/>',
    'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
];
$perks = [
    ['shield',   '256-bit SSL secure checkout'],
    ['box',      'Pre-Order : 3-7 days'],
    ['award',    '100% satisfaction guarantee'],
    ['check',    'Easy and secure payment'],
    ['calendar', '15 days easy return policy'],
];
// Shown under the video on desktop, and under the buy buttons on phones (where the video sits above the price)
function perk_list(array $perks, array $icons, string $class): void
{ ?>
    <ul class="pd-perks <?= $class ?>">
        <?php foreach ($perks as [$icon, $text]): ?>
        <li>
            <svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons[$icon] ?></svg>
            <?= e($text) ?>
        </li>
        <?php endforeach; ?>
    </ul>
<?php }

$services = [
    ['Free PC Consultation',          'get our help to match the best spec to your budget'],
    ['Free Setup + Testing',          'fully tested and ready, no loose parts'],
    ['Free Lifetime Cleaning Service', 'walk into our store anytime for internal cleaning'],
    ['Installment Plan Available',    'no need to wait for payday to build your dream PC'],
];

// "What Can This PC Do?" — estimates until real benchmarks are published.
// Each item: [logo, name, detail, status (ok / mid / no), badge]
// logo: an image in assets/images/apps/, an emoji, or [short text, tile colour, text colour] for a drawn tile
$capabilities = [
    'gaming' => [
        'title' => '🎮 What Can It Play?',
        'note'  => '* Anggaran FPS pada 1080p berdasarkan ujian penanda aras awam (Tom\'s Hardware, TechSpot, Notebookcheck). Prestasi sebenar bergantung kepada tetapan, versi permainan dan pemacu.',
        // Details are filled per PC from includes/fps_data.php (see below); these are the fallbacks
        'items' => [
            ['gta5.png',     'GTA V',    '1080p · Lancar dimainkan', 'ok', 'Lancar'],
            ['valorant.png', 'Valorant', '1080p · Lancar dimainkan', 'ok', 'Lancar'],
            ['cs2.png',      'CS2',      '1080p · Lancar dimainkan', 'ok', 'Lancar'],
            ['fortnite.png', 'Fortnite', '1080p · Lancar dimainkan', 'ok', 'Lancar'],
            ['roblox.png',   'Roblox',   '1080p · Lancar dimainkan', 'ok', 'Lancar'],
        ],
    ],
    'office' => [
        'title' => '💼 What Is It Good For?',
        'note'  => null,
        'items' => [
            ['word.png',              'Microsoft Word / Excel / PowerPoint', 'Dokumen pejabat harian tanpa lag',             'ok', '✓ Sesuai'],
            ['teams.png',             'Web Browsing & Video Call',           'Zoom, Teams, Google Meet — 1080p smooth',      'ok', '✓ Sesuai'],
            ['youtube.png',           'Streaming & YouTube',                 'Tonton video 1080p / 4K tanpa buffer',         'ok', '✓ Sesuai'],
            ['🗂️',                    'Multitasking',                        'Buka 10+ tab Chrome + Teams + Excel serentak', 'ok', '✓ Sesuai'],
            ['pdf.png',               'PDF & Document Processing',           'Adobe Reader, WPS, Foxit PDF — lancar',        'ok', '✓ Sesuai'],
            ['davinci.png',             'Heavy Video Editing',              'Premiere Pro, DaVinci Resolve — tidak sesuai', 'no', '✗ Tidak'],
            [['Game',  '#333', '#888'], 'Gaming Berat',                      'GTA V, Cyberpunk 2077 — tidak sesuai',         'no', '✗ Tidak'],
        ],
    ],
    'editing' => [
        'title' => '🎬 Supported Software',
        'note'  => null,
        'items' => [
            ['premiere-pro.png',              'Adobe Premiere Pro', 'Edit video 1080p smooth / 4K basic', 'ok',  '✓ Disokong'],
            ['davinci.png',                   'DaVinci Resolve',    '1080p edit / 4K dengan proxy',       'ok',  '✓ Disokong'],
            ['blender.png',                   'Blender',            '3D modelling & basic render',        'mid', '~ Sederhana'],
            ['photoshop.png',                 'Adobe Photoshop',    'Photo editing penuh — lancar',       'ok',  '✓ Disokong'],
            ['lightroom.png',                 'Adobe Lightroom',    'Edit & export gambar — lancar',      'ok',  '✓ Disokong'],
            ['after-effects.png',             'After Effects',      'Motion graphics & composition HD',   'ok',  '✓ Disokong'],
        ],
    ],
    'student' => [
        'title' => '📚 Great for Students',
        'note'  => '* FPS adalah anggaran sahaja. Untuk kerja berat seperti video editing, pertimbangkan PC Editing.',
        'items' => [
            ['word.png',                  'Microsoft Office',       'Siapkan assignment Word, Excel, PPT tanpa masalah', 'ok', '✓ Sesuai'],
            ['teams.png',                 'Online Class & Meeting', 'Zoom, Teams, Google Meet — 1080p',                  'ok', '✓ Sesuai'],
            ['valorant.png',              'Valorant',               '1080p · ~120 FPS · Medium-High',                    'ok', '✓ Boleh Main'],
            ['roblox.png',                'Roblox',                 '1080p · ~200 FPS · Max Settings',                   'ok', '✓ Boleh Main'],
            ['minecraft.png',             'Minecraft',              '1080p · ~100 FPS · Smooth',                         'ok', '✓ Boleh Main'],
        ],
    ],
];

// Office PCs whose integrated graphics handle light games: swap "Gaming Berat ✗" for "Light Gaming ✓"
if (in_array(pc_key($product['name']), ['pixel lite', 'pixel azure'], true)) {
    $capabilities['office']['items'][6] = ['🎮', 'Light Gaming', 'Game ringan pada tetapan rendah–sederhana', 'ok', '✓ Sesuai'];
}

// Office laptops list only what they're good for: no "Heavy Video Editing ✗" / "Gaming Berat ✗" rows
if (product_group($product['category_slug']) === 'laptop' && $pc_type === 'office') {
    $capabilities['office']['items'] = array_values(array_filter($capabilities['office']['items'], fn($item) => $item[3] !== 'no'));
}

// Gaming PCs: per-PC FPS estimates (includes/fps_data.php). Under 60 FPS shows as ~ (playable) rather than ✓
if ($fps = fps_data()[pc_key($product['name'])] ?? null) {
    $settings = fps_settings();
    // Higher-end builds are shown at the resolution they're built for (1440p, 4K)
    $res = fps_resolution(pc_key($product['name']));
    $capabilities['gaming']['note'] = str_replace('pada 1080p', "pada $res", $capabilities['gaming']['note']);
    foreach ($capabilities['gaming']['items'] as &$item) {
        $game = $item[1];
        if (!isset($fps[$game])) continue;
        $values = (array)$fps[$game];
        $parts  = [];
        foreach ($values as $variant => $n) {
            $label   = $game === 'Roblox' && $n >= 240 ? '240 FPS (had maksimum)' : "~$n FPS";
            $parts[] = is_string($variant) ? "$label ($variant)" : $label;
        }
        $item[2] = "$res · " . implode(' / ', $parts) . ' · ' . $settings[$game];
        $item[3] = min($values) >= 60 ? 'ok' : 'mid';
        $item[4] = $item[3] === 'ok' ? 'Lancar' : 'Boleh Main';
    }
    unset($item);
}

// ✓ first, then ~, then ✗ (usort is stable, so each group keeps its listed order)
$rank = ['ok' => 0, 'mid' => 1, 'no' => 2];
foreach ($capabilities as &$group) {
    usort($group['items'], fn($a, $b) => $rank[$a[3]] <=> $rank[$b[3]]);
}
unset($group);

// App logo image, emoji, or a coloured tile with the short name centred
function cap_logo(string|array $logo): string
{
    if (is_array($logo)) {
        [$text, $bg, $fg] = $logo;
        $size = [2 => 16, 3 => 14, 4 => 11, 5 => 9][strlen($text)] ?? 8;
        return '<svg viewBox="0 0 40 40" aria-hidden="true">'
            . '<rect width="40" height="40" rx="6" fill="' . e($bg) . '"/>'
            . '<text x="20" y="21" text-anchor="middle" dominant-baseline="middle" font-size="' . $size . '" font-weight="900" fill="' . e($fg) . '">' . e($text) . '</text>'
            . '</svg>';
    }
    if (str_ends_with($logo, '.png')) {
        $src = asset_image('../', 'apps', $logo);
        return $src ? '<img src="' . e($src) . '" alt="" loading="lazy">' : '';
    }
    return '<span class="cap-emoji" aria-hidden="true">' . $logo . '</span>';
}

// Breadcrumb + back link
if ($is_pc) {
    $section_link = ['Custom PCs', 'pc-categories.php'];
    $list_link    = 'products.php?type=' . urlencode($product['category_slug']);
} else {
    $section_link = ['Accessories', 'products.php'];
    $list_link    = 'products.php?cat=' . urlencode($product['category_slug']);
}

// Photo gallery: product_images rows first, otherwise the single products.image
$stmt = $pdo->prepare("SELECT filename FROM product_images WHERE product_id = ? ORDER BY sort_order, id");
$stmt->execute([$id]);
$gallery = [];
foreach (array_merge($stmt->fetchAll(PDO::FETCH_COLUMN), [$product['image']]) as $file) {
    $src = asset_image('../', 'products', $file);
    if ($src && !in_array($src, $gallery, true)) $gallery[] = $src;
}
$nav_active = $is_pc ? 'pcs' : 'shop';
$base       = '../';
$page_title = $product['name'] . ' — PixelCraft';
require '../includes/header.php';
?>

<section class="section pd-section">
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="../index.php">Home</a>
        <span>›</span>
        <a href="<?= $section_link[1] ?>"><?= $section_link[0] ?></a>
        <span>›</span>
        <a href="<?= e($list_link) ?>"><?= e($product['category_name']) ?></a>
        <span>›</span>
        <span class="current"><?= e($product['name']) ?></span>
    </nav>

    <a href="<?= e($list_link) ?>" class="back-link">← Back to <?= e($product['category_name']) ?></a>

    <div class="pd-layout">
        <!-- LEFT: image + video -->
        <div class="pd-media">
            <?php if ($gallery): ?>
            <div class="pd-gallery">
                <div class="pd-image has-photo">
                    <img src="<?= e($gallery[0]) ?>" alt="<?= e($product['name']) ?>" class="pd-main-img">
                </div>
                <?php if (count($gallery) > 1): ?>
                <div class="pd-thumbs">
                    <?php foreach ($gallery as $i => $src): ?>
                    <button type="button" class="pd-thumb<?= $i === 0 ? ' active' : '' ?>" data-src="<?= e($src) ?>"
                        aria-label="Gambar <?= $i + 1 ?>">
                        <img src="<?= e($src) ?>" alt="" loading="lazy">
                    </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="pd-image pd-image-placeholder">
                <span class="pd-ph-icon"><?= category_icon($product['category_slug']) ?></span>
                <span class="logo"><?= logo_img('../', true) ?></span>
                <span class="pd-ph-name"><?= e($product['name']) ?></span>
                <span class="pd-ph-note">Image unavailable for now</span>
            </div>
            <?php endif; ?>

            <?php if ($is_pc): ?>
            <div class="pd-video">
                <?php if ($video): ?>
                <iframe src="<?= e($video) ?>" title="<?= e($product['name']) ?> — performance video"
                    allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowfullscreen loading="lazy"></iframe>
                <?php else: ?>
                <div class="pd-video-placeholder">🎮 Performance video coming soon</div>
                <?php endif; ?>
            </div>
            <?php perk_list($perks, $perk_icons, 'pd-perks-desktop'); ?>
            <?php endif; ?>
        </div>

        <!-- RIGHT: info + configurator -->
        <div class="pd-info">
            <span class="pd-badge"><?= e(strtoupper($product['category_name'])) ?></span>
            <!-- CPU logo sits inline after the name, sized to the heading text -->
            <h1>
                <?= e($product['name']) ?><?php if ($cpu_badge): ?><img src="<?= e($cpu_badge) ?>" alt="<?= e($cpu) ?>" title="<?= e($cpu) ?>" class="pd-cpu-badge"><?php endif; ?>
            </h1>

            <p class="pd-price">
                <?php if ($show_from && (!$default || $default === $variants[0])): ?><span class="pd-price-from" id="priceFrom">From</span><?php endif; ?>
                <span id="displayPrice"><?= rm($default ? (float)$default['price'] : (float)$product['price']) ?></span>
            </p>
            <?php if ($variants && $show_from): ?>
            <p class="pd-price-note">Harga berubah mengikut konfigurasi yang dipilih.</p>
            <?php elseif (count($configs) > 1): ?>
            <p class="pd-price-note">Harga bermula dari konfigurasi asas. Pilih set di bawah.</p>
            <?php endif; ?>

            <?php if ($is_pc): ?>
            <div class="jcl-teaser">
                <span class="jcl-icon" aria-hidden="true">💳</span>
                <div class="jcl-text">
                    <?php if ($has_jcl): ?>
                    <p id="jclTeaser"<?= $default['jcl_3yr'] === null ? ' hidden' : '' ?>>
                        atau <strong id="jclMonthly">RM <?= number_format((float)$default['jcl_3yr'], 2) ?></strong>/bulan melalui JCL 3 tahun
                    </p>
                    <?php endif; ?>
                    <p class="jcl-sub">Tersedia ansuran bulanan melalui JCL, Atome, Shopee PayLater &amp; lebih.</p>
                </div>
                <a href="ansuran.php">Ketahui lebih →</a>
            </div>
            <?php endif; ?>

            <?php
            // Laptops: specs in a table like the PCs', features as a list, both from the description (laptop_details())
            $laptop_desc = product_group($product['category_slug']) === 'laptop' && trim((string)$product['description']) !== '';
            if ($laptop_desc) {
                [$lap_intro, $lap_rows, $lap_features] = laptop_details($product['description']);
                // Specs filled in on the admin form fill any row the description doesn't cover
                foreach (array_filter($specs) as $label => $value) {
                    $lap_rows[$label] ??= $value;
                }
            }
            ?>
            <?php if ($laptop_desc): ?>
            <hr class="pd-divider">
            <?php if ($lap_intro): ?><p class="pd-desc pd-lap-intro"><?= e($lap_intro) ?></p><?php endif; ?>
            <?php if ($lap_rows): ?>
            <div class="table-wrap">
                <table class="spec-table">
                    <tbody>
                        <?php foreach ($lap_rows as $label => $value): ?>
                        <tr>
                            <th scope="row"><?= e($label) ?></th>
                            <td><?= e($value) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php if ($lap_features): ?>
            <div class="pd-features">
                <h3>Features</h3>
                <ul>
                    <?php foreach ($lap_features as $feature): ?><li><?= e($feature) ?></li><?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            <hr class="pd-divider">
            <?php elseif ($is_pc): ?>
            <hr class="pd-divider">
            <div class="table-wrap">
                <table class="spec-table">
                    <tbody>
                        <?php foreach ($specs as $label => $value): ?>
                        <tr>
                            <th scope="row"><?= $label ?></th>
                            <td class="<?= $value ? '' : 'tba' ?>"><?= $value ? e($value) : 'TBA' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <hr class="pd-divider">
            <?php elseif ($product['description']): ?>
            <p class="pd-desc"><?= nl2br(e($product['description'])) ?></p>
            <?php endif; ?>

            <?php if ($stock > 0): ?>
            <form method="POST" class="pd-buy" data-variants="<?= e(json_encode($variant_json)) ?>">
                <?php foreach ($chip_groups as $group => [$label, $values]): ?>
                <div class="selector-group">
                    <span class="selector-label" id="lbl-<?= $group ?>"><?= $label ?></span>
                    <div class="chip-row" role="group" aria-labelledby="lbl-<?= $group ?>" data-group="<?= $group ?>"
                        <?= $group === 'config' ? 'data-input="config"' : '' ?>>
                        <?php $i = 0; foreach ($values as $value => $text): ?>
                        <?php $on = isset($picked[$group]) ? (string)$value === $picked[$group] : $i === 0; $i++; ?>
                        <button type="button" class="chip<?= $on ? ' chip-active' : '' ?>" data-value="<?= e($value) ?>"
                            aria-pressed="<?= $on ? 'true' : 'false' ?>">
                            <?php if ($group === 'color'): ?><span class="chip-swatch" style="background: <?= e(strtolower($value)) ?>"></span><?php endif; ?>
                            <?= e($text) ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if ($variants): ?>
                <input type="hidden" name="selected_variant_id" value="<?= (int)$default['id'] ?>">
                <?php elseif ($configs): ?>
                <input type="hidden" name="config" value="<?= e($configs[0]) ?>">
                <?php endif; ?>

                <div class="selector-group">
                    <label class="selector-label" for="quantity">Kuantiti</label>
                    <div class="qty-row">
                        <button type="button" class="qty-btn" data-step="-1" aria-label="Kurang">−</button>
                        <input type="number" id="quantity" name="quantity" value="1" min="1" max="<?= $max_qty ?>" class="qty-input">
                        <button type="button" class="qty-btn" data-step="1" aria-label="Tambah">+</button>
                    </div>
                </div>

                <p class="stock" id="stockStatus">✓ Dalam stok</p>

                <div class="product-actions">
                    <button type="submit" name="action" value="cart" class="btn btn-outline">🛒 Tambah ke Troli</button>
                    <button type="submit" name="action" value="buy_now" class="btn">⚡ Beli Sekarang</button>
                </div>
            </form>
            <?php else: ?>
            <p class="stock out">Stok habis</p>
            <?php endif; ?>

            <?php if ($is_pc): ?>
            <?php perk_list($perks, $perk_icons, 'pd-perks-mobile'); ?>

            <div class="pd-services">
                <h4>⭐ Pixel Craft Exclusive Services</h4>
                <ul>
                    <?php foreach ($services as [$title, $text]): ?>
                    <li>
                        <span class="pd-service-check" aria-hidden="true">✅</span>
                        <p><strong><?= e($title) ?></strong> — <?= e($text) ?></p>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if ($pc_type && isset($capabilities[$pc_type])): $cap = $capabilities[$pc_type]; ?>
<section class="section-band cap-section pc-<?= e($product['category_slug']) ?>">
    <div class="section">
        <div class="cap-wrap">
            <div class="section-head">
                <h2 class="section-title">What Can This PC Do?</h2>
            </div>
            <h3 class="cap-title"><?= $cap['title'] ?></h3>

            <?php $marks = ['ok' => '✓', 'mid' => '~', 'no' => '✗']; ?>
            <div class="cap-grid">
                <?php foreach ($cap['items'] as [$logo, $name, $detail, $status, $badge]): ?>
                <div class="cap-software">
                    <span class="cap-logo"><?= cap_logo($logo) ?></span>
                    <span class="cap-name"><?= e($name) ?></span>
                </div>
                <div class="cap-box cap-box-<?= $status ?>">
                    <span class="cap-mark" role="img" aria-label="<?= e($badge) ?>"><?= $marks[$status] ?></span>
                    <span class="cap-detail"><?= e($detail) ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($cap['note']): ?>
            <p class="cap-note"><?= e($cap['note']) ?></p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php require '../includes/footer.php'; ?>

<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

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

if (!$product) {
    header('Location: pc-categories.php');
    exit;
}

$tiers   = pc_tiers();
$is_pc   = isset($tiers[$product['category_slug']]);
$stock   = (int)$product['stock'];
$max_qty = min(10, $stock);

// Spec row wins; otherwise fall back to the category's default type (accessories have none)
$pc_type = $product['pc_type'] ?: ($tiers[$product['category_slug']]['pc_type'] ?? null);

// Priced configurations (RAM/SSD + set + GPU + colour), cheapest first
$stmt = $pdo->prepare("SELECT id, ram, storage, set_type, gpu_variant, color, price, jcl_3yr, stock
    FROM product_variants WHERE product_id = ? ORDER BY price, id");
$stmt->execute([$id]);
$variants = $stmt->fetchAll();

// No variants yet: RAM/SSD sets from product_specs, e.g. "8GB DDR4 / 256GB NVMe" (no price change)
$configs = $variants ? [] : array_values(array_filter(array_map('trim', explode('|', (string)$product['config_options']))));

$set_labels = ['cpu_only' => 'CPU Only', 'full_set' => 'Full Set'];

// The value each chip group takes for a variant
function variant_keys(array $v): array
{
    return [
        'ramssd' => $v['ram'] . ' + ' . str_replace(' NVMe', '', $v['storage']),
        'set'    => $v['set_type'],
        'gpu'    => (string)$v['gpu_variant'],
        'color'  => (string)$v['color'],
    ];
}

function variant_label(array $v, array $set_labels): string
{
    $parts = [$v['ram'] . ' / ' . $v['storage'], $set_labels[$v['set_type']]];
    if ($v['gpu_variant']) $parts[] = $v['gpu_variant'];
    if ($v['color']) $parts[] = $v['color'];
    return implode(' · ', $parts);
}

// Chip groups: value => label, in order of first appearance (so the first chip of each is the cheapest variant)
$options      = ['ramssd' => [], 'set' => [], 'gpu' => [], 'color' => []];
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
if ($variants) {
    $chip_groups['ramssd'] = ['RAM dan SSD', $options['ramssd']];
    $chip_groups['set']    = ['Set', $options['set']];
    if (count($options['gpu']) > 1)   $chip_groups['gpu']   = ['Graphics Card', $options['gpu']];
    if (count($options['color']) > 1) $chip_groups['color'] = ['Color', $options['color']];
} elseif ($configs) {
    $chip_groups['config'] = ['RAM dan SSD', array_combine($configs, $configs)];
}

$default   = $variants[0] ?? null;
$has_jcl   = $variants && array_filter(array_column($variants, 'jcl_3yr'), fn($j) => $j !== null);
$show_from = $variants ? count($variants) > 1 : count($configs) > 1;

// Tambah ke Troli / Beli Sekarang
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $stock > 0) {
    $limit = $stock;

    if ($variants) {
        // Only variants of this product; anything else falls back to the cheapest
        $chosen = $default;
        foreach ($variants as $v) {
            if ((int)$v['id'] === (int)($_POST['selected_variant_id'] ?? 0)) $chosen = $v;
        }
        $limit = min($limit, (int)$chosen['stock']);
        $_SESSION['cart_variant'][$id] = (int)$chosen['id'];
        $_SESSION['cart_config'][$id]  = variant_label($chosen, $set_labels);
    } else {
        // Remember the chosen set (only values we actually offer)
        $config = $_POST['config'] ?? '';
        if (in_array($config, $configs, true)) {
            $_SESSION['cart_config'][$id] = $config;
        }
    }

    if ($limit > 0) {
        $qty     = max(1, min((int)($_POST['quantity'] ?? 1), $max_qty));
        $in_cart = $_SESSION['cart'][$id] ?? 0;
        $_SESSION['cart'][$id] = min($in_cart + $qty, $limit);
    }

    $buy_now = ($_POST['action'] ?? '') === 'buy_now';
    header('Location: ' . ($buy_now ? 'checkout.php' : 'cart.php'));
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

// Prices are whole ringgit for PCs; show cents only when there are any
function rm(float $amount): string
{
    return 'RM ' . number_format($amount, fmod($amount, 1) ? 2 : 0);
}

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

// "Apa Yang Boleh PC Ini Lakukan?" — estimates until real benchmarks are published.
// Each item: [logo, name, detail, status (ok / mid / no), badge]
// logo: an image in assets/images/apps/, an emoji, or [short text, tile colour, text colour] for a drawn tile
$capabilities = [
    'gaming' => [
        'title' => '🎮 Boleh Main Apa?',
        'note'  => '* FPS adalah anggaran sahaja bergantung kepada tetapan sistem dan versi permainan.',
        'items' => [
            [['GTA',  '#F7A11A', '#1a1a1a'], 'GTA V',               '1080p · ~90 FPS · High Settings',  'ok', 'Lancar'],
            [['VAL',  '#FF4655', '#fff'],    'Valorant',            '1080p · ~200 FPS · Max Settings',  'ok', 'Lancar'],
            [['CS2',  '#F0A000', '#1a1a1a'], 'CS2',                 '1080p · ~150 FPS · High Settings', 'ok', 'Lancar'],
            [['FN',   '#00C3FF', '#fff'],    'Fortnite',            '1080p · ~80 FPS · High Settings',  'ok', 'Lancar'],
            [['RBLX', '#E31C1C', '#fff'],    'Roblox',              '1080p · ~200 FPS · Max Settings',  'ok', 'Lancar'],
            [['ML',   '#0055A5', '#fff'],    'Mobile Legends (PC)', '1080p · ~200 FPS · Max Settings',  'ok', 'Lancar'],
        ],
    ],
    'office' => [
        'title' => '💼 Sesuai Untuk Apa?',
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
        'title' => '🎬 Perisian Yang Disokong',
        'note'  => null,
        'items' => [
            [['Prem',  '#00005B', '#9999FF'], 'Adobe Premiere Pro', 'Edit video 1080p smooth / 4K basic', 'ok',  '✓ Disokong'],
            ['davinci.png',                   'DaVinci Resolve',    '1080p edit / 4K dengan proxy',       'ok',  '✓ Disokong'],
            [['Blend', '#EA7600', '#fff'],    'Blender',            '3D modelling & basic render',        'mid', '~ Sederhana'],
            [['Ps',    '#001E36', '#31A8FF'], 'Adobe Photoshop',    'Photo editing penuh — lancar',       'ok',  '✓ Disokong'],
            [['Lr',    '#001C33', '#74B8FD'], 'Adobe Lightroom',    'Edit & export gambar — lancar',      'ok',  '✓ Disokong'],
            [['Ae',    '#FF61F6', '#fff'],    'After Effects',      'Motion graphics & composition HD',   'ok',  '✓ Disokong'],
        ],
    ],
    'student' => [
        'title' => '📚 Sesuai Untuk Pelajar',
        'note'  => '* FPS adalah anggaran sahaja. Untuk kerja berat seperti video editing, pertimbangkan PC Editing.',
        'items' => [
            ['word.png',                  'Microsoft Office',       'Siapkan assignment Word, Excel, PPT tanpa masalah', 'ok', '✓ Sesuai'],
            ['teams.png',                 'Online Class & Meeting', 'Zoom, Teams, Google Meet — 1080p',                  'ok', '✓ Sesuai'],
            [['VAL',  '#FF4655', '#fff'], 'Valorant',               '1080p · ~120 FPS · Medium-High',                    'ok', '✓ Boleh Main'],
            [['RBLX', '#E31C1C', '#fff'], 'Roblox',                 '1080p · ~200 FPS · Max Settings',                   'ok', '✓ Boleh Main'],
            [['MC',   '#62B030', '#fff'], 'Minecraft',              '1080p · ~100 FPS · Smooth',                         'ok', '✓ Boleh Main'],
        ],
    ],
];

// Office PCs whose integrated graphics handle light games: swap "Gaming Berat ✗" for "Light Gaming ✓"
if (in_array($product['name'], ['PC Pixel Lite', 'PC Pixel Azure'], true)) {
    $capabilities['office']['items'][6] = ['🎮', 'Light Gaming', 'Game ringan pada tetapan rendah–sederhana', 'ok', '✓ Sesuai'];
}

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

    <a href="<?= e($list_link) ?>" class="back-link">← Kembali ke <?= e($product['category_name']) ?></a>

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
                <span class="logo">Pixel<span>Craft</span></span>
                <span class="pd-ph-name"><?= e($product['name']) ?></span>
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
            <?php endif; ?>
        </div>

        <!-- RIGHT: info + configurator -->
        <div class="pd-info">
            <span class="pd-badge"><?= e(strtoupper($product['category_name'])) ?></span>
            <h1><?= e($product['name']) ?></h1>

            <p class="pd-price">
                <?php if ($show_from): ?><span class="pd-price-from" id="priceFrom">From</span><?php endif; ?>
                <span id="displayPrice"><?= rm($default ? (float)$default['price'] : (float)$product['price']) ?></span>
            </p>
            <?php if ($variants && $show_from): ?>
            <p class="pd-price-note">Harga berubah mengikut konfigurasi yang dipilih.</p>
            <?php elseif (count($configs) > 1): ?>
            <p class="pd-price-note">Harga bermula dari konfigurasi asas. Pilih set di bawah.</p>
            <?php endif; ?>

            <?php if ($has_jcl): ?>
            <div class="jcl-teaser" id="jclTeaser"<?= $default['jcl_3yr'] === null ? ' hidden' : '' ?>>
                <span aria-hidden="true">💳</span>
                <span>atau <strong id="jclMonthly">RM <?= number_format((float)$default['jcl_3yr'], 2) ?></strong>/bulan melalui JCL 3 tahun</span>
                <a href="ansuran.php#jcl">Ketahui lebih →</a>
            </div>
            <?php endif; ?>

            <?php if ($is_pc): ?>
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
                        <button type="button" class="chip<?= $i === 0 ? ' chip-active' : '' ?>" data-value="<?= e($value) ?>"
                            aria-pressed="<?= $i++ === 0 ? 'true' : 'false' ?>">
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
            <div class="pd-ansuran">
                <p>💳 Tersedia ansuran bulanan melalui JCL, Atome, Shopee PayLater &amp; lebih.</p>
                <a href="ansuran.php">Ketahui Lebih Lanjut →</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if ($pc_type && isset($capabilities[$pc_type])): $cap = $capabilities[$pc_type]; ?>
<section class="section-band">
    <div class="section">
        <div class="section-head">
            <h2 class="section-title">Apa Yang Boleh PC Ini Lakukan?</h2>
        </div>
        <h3 class="cap-title"><?= $cap['title'] ?></h3>

        <?php
        // Left: what it handles; right: what it doesn't (only when there is something to list)
        $sides = ['ok' => [], 'no' => []];
        foreach ($cap['items'] as $item) {
            $sides[$item[3] === 'no' ? 'no' : 'ok'][] = $item;
        }
        $sides = array_filter($sides);
        ?>
        <div class="cap-split<?= count($sides) > 1 ? '' : ' single' ?>">
            <?php foreach ($sides as $side => $items): ?>
            <table class="cap-table cap-table-<?= $side ?>">
                <thead>
                    <tr><th colspan="2"><?= $side === 'ok' ? '✓ Sesuai' : '✗ Tidak Sesuai' ?></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($items as [$logo, $name, $detail, $status, $badge]): ?>
                    <tr>
                        <td>
                            <div class="cap-app">
                                <span class="cap-logo"><?= cap_logo($logo) ?></span>
                                <div>
                                    <div class="cap-name"><?= e($name) ?></div>
                                    <div class="cap-detail"><?= e($detail) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="cap-status"><span class="cap-badge cap-badge-<?= $status ?>"><?= e($badge) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endforeach; ?>
        </div>

        <?php if ($cap['note']): ?>
        <p class="cap-note"><?= e($cap['note']) ?></p>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php require '../includes/footer.php'; ?>

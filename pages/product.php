<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// LEFT JOIN so the page works before a product has a product_specs row
$stmt = $pdo->prepare("SELECT p.*, c.name AS category_name, c.slug AS category_slug,
        s.cpu, s.gpu, s.ram, s.storage, s.color, s.video_url, s.pc_type
    FROM products p
    JOIN categories c ON p.category_id = c.id
    LEFT JOIN product_specs s ON s.product_id = p.id
    WHERE p.id = ?");
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    header('Location: products.php');
    exit;
}

$tiers   = pc_tiers();
$is_pc   = isset($tiers[$product['category_slug']]);
$stock   = (int)$product['stock'];
$max_qty = min(10, $stock);

// Spec row wins; otherwise fall back to the category's default type (accessories have none)
$pc_type = $product['pc_type'] ?: ($tiers[$product['category_slug']]['pc_type'] ?? null);

// Add to Cart / Buy Now
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $stock > 0) {
    $qty     = max(1, min((int)($_POST['quantity'] ?? 1), $max_qty));
    $in_cart = $_SESSION['cart'][$id] ?? 0;
    $_SESSION['cart'][$id] = min($in_cart + $qty, $stock);

    $buy_now = ($_POST['action'] ?? '') === 'buy';
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

$specs = [
    'CPU'     => $product['cpu'],
    'GPU'     => $product['gpu'],
    'RAM'     => $product['ram'],
    'Storage' => $product['storage'],
    'Color'   => $product['color'],
];

// "What can this PC handle?" — placeholder data until real benchmarks are added
$games = [
    ['name' => 'GTA V',     'settings' => '1080p · High',   'fps' => 110],
    ['name' => 'Valorant',  'settings' => '1080p · High',   'fps' => 240],
    ['name' => 'CS2',       'settings' => '1080p · High',   'fps' => 200],
    ['name' => 'Fortnite',  'settings' => '1080p · Medium', 'fps' => 144],
];
$office_tasks = ['Documents (Word, Google Docs)', 'Spreadsheets (Excel, Google Sheets)', 'Video calls (Zoom, Teams, Meet)', 'Web browsing & email'];
$software = [
    ['name' => 'Adobe After Effects', 'use' => 'Motion graphics & compositing', 'rating' => 4],
    ['name' => 'DaVinci Resolve',     'use' => '4K video editing & colour',     'rating' => 5],
    ['name' => 'Blender',             'use' => '3D modelling & rendering',      'rating' => 4],
    ['name' => 'Adobe Photoshop',     'use' => 'Photo editing & design',        'rating' => 5],
];
$student_tasks = ['Online classes (Zoom, Google Meet)', 'Assignments (Word, PowerPoint, Canva)', 'Research & web browsing'];
$light_games   = [
    ['name' => 'Minecraft', 'settings' => '1080p · Medium', 'fps' => 90],
    ['name' => 'Roblox',    'settings' => '1080p · Medium', 'fps' => 60],
    ['name' => 'Valorant',  'settings' => '1080p · Low',    'fps' => 60],
];

$list_page  = $is_pc ? 'products.php?type=' : 'products.php?cat=';
$nav_active = $is_pc ? 'pcs' : 'shop';
$base       = '../';
$page_title = $product['name'] . ' - PixelCraft';
require '../includes/header.php';

// Renders a games/FPS table
function fps_table(array $games): void
{ ?>
    <div class="table-wrap">
        <table class="fps-table">
            <thead><tr><th>Game</th><th>Settings</th><th>Avg FPS</th></tr></thead>
            <tbody>
                <?php foreach ($games as $g): ?>
                <tr>
                    <td><?= e($g['name']) ?></td>
                    <td><?= e($g['settings']) ?></td>
                    <td>
                        <div class="fps-bar"><span style="width: <?= min(100, round($g['fps'] / 240 * 100)) ?>%"></span></div>
                        <strong><?= (int)$g['fps'] ?></strong>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php }
?>

<section class="section">
    <a href="<?= $list_page . urlencode($product['category_slug']) ?>" class="back-link">← Back to <?= e($product['category_name']) ?></a>

    <div class="pd-layout">
        <!-- LEFT: image + video -->
        <div class="pd-media">
            <?= product_image($product, $base, 'product-detail-img') ?>

            <?php if ($is_pc): ?>
            <div class="pd-video">
                <?php if ($video): ?>
                <iframe src="<?= e($video) ?>" title="<?= e($product['name']) ?> performance video"
                    allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowfullscreen loading="lazy"></iframe>
                <?php else: ?>
                <div class="pd-video-placeholder">
                    <span>▶</span>
                    <p>Performance video coming soon</p>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT: info + buy -->
        <div class="pd-info">
            <span class="pd-badge"><?= category_icon($product['category_slug']) ?> <?= e($product['category_name']) ?></span>
            <h1><?= e($product['name']) ?></h1>
            <p class="pd-price">RM <?= number_format($product['price'], 2) ?></p>

            <?php if ($is_pc): ?>
            <table class="spec-table">
                <?php foreach ($specs as $label => $value): ?>
                <tr>
                    <th><?= $label ?></th>
                    <td class="<?= $value ? '' : 'tba' ?>"><?= $value ? e($value) : 'TBA' ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php elseif ($product['description']): ?>
            <p class="pd-desc"><?= nl2br(e($product['description'])) ?></p>
            <?php endif; ?>

            <?php if ($stock > 0): ?>
            <form method="POST" class="pd-buy">
                <label for="quantity">Quantity</label>
                <select id="quantity" name="quantity" class="qty-select">
                    <?php for ($q = 1; $q <= $max_qty; $q++): ?>
                    <option value="<?= $q ?>"><?= $q ?></option>
                    <?php endfor; ?>
                </select>
                <div class="pd-buttons">
                    <button type="submit" name="action" value="cart" class="btn btn-outline">🛒 Add to Cart</button>
                    <button type="submit" name="action" value="buy" class="btn">Buy Now</button>
                </div>
            </form>
            <p class="stock">✓ In stock</p>
            <?php else: ?>
            <p class="stock out">Out of stock</p>
            <?php endif; ?>

            <?php if ($is_pc): ?>
            <a href="ansuran.php" class="pd-ansuran">
                <span>💳</span>
                <span><strong>Tersedia ansuran</strong> · Ketahui lebih lanjut →</span>
            </a>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if ($pc_type): ?>
<section class="section-band">
    <div class="section">
        <div class="section-head">
            <h2 class="section-title">What Can This PC Handle?</h2>
        </div>

        <?php if ($pc_type === 'gaming'): ?>
        <?php fps_table($games); ?>

        <?php elseif ($pc_type === 'office'): ?>
        <ul class="task-list">
            <?php foreach ($office_tasks as $task): ?>
            <li><span class="check">✓</span> <?= e($task) ?></li>
            <?php endforeach; ?>
        </ul>

        <?php elseif ($pc_type === 'editing'): ?>
        <div class="software-grid">
            <?php foreach ($software as $sw): ?>
            <div class="software-card">
                <h3><?= e($sw['name']) ?></h3>
                <p><?= e($sw['use']) ?></p>
                <div class="rating" aria-label="<?= $sw['rating'] ?> out of 5">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                    <span class="<?= $i <= $sw['rating'] ? 'on' : '' ?>"></span>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php elseif ($pc_type === 'student'): ?>
        <div class="student-grid">
            <div>
                <h3 class="cap-sub">Study & everyday tasks</h3>
                <ul class="task-list">
                    <?php foreach ($student_tasks as $task): ?>
                    <li><span class="check">✓</span> <?= e($task) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div>
                <h3 class="cap-sub">Light gaming</h3>
                <?php fps_table($light_games); ?>
            </div>
        </div>
        <?php endif; ?>

        <p class="cap-note">* Sample figures for illustration. Actual performance for this model will be published once testing is complete.</p>
    </div>
</section>
<?php endif; ?>

<?php require '../includes/footer.php'; ?>

<?php
// Shared admin shell: a sidebar (Dashboard / Products / Price Sync / View Store, then who's logged in and Log out)
// beside the page. A page does its own logic first, then admin_open('Title', 'products'), its content, admin_close().

// The store is in Seremban; PHP here defaults to Europe/Berlin
date_default_timezone_set('Asia/Kuala_Lumpur');

// Line icons (24×24, drawn with currentColor)
function admin_icon(string $name): string
{
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'box'       => '<path d="M21 8 12 3 3 8l9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
        'sync'      => '<path d="M20 11A8 8 0 0 0 6.3 6.3L4 8.5"/><path d="M4 4v4.5h4.5"/><path d="M4 13a8 8 0 0 0 13.7 4.7L20 15.5"/><path d="M20 20v-4.5h-4.5"/>',
        'store'     => '<path d="M3 9.5 4.5 4h15L21 9.5"/><path d="M3 9.5a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M5 12.5V20h14v-7.5"/><path d="M10 20v-5h4v5"/>',
        'external'  => '<path d="M14 4h6v6"/><path d="M10 14 20 4"/><path d="M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/>',
        'logout'    => '<path d="M9 20H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h4"/><path d="m16 16 4-4-4-4"/><path d="M20 12H9"/>',
        'eye'       => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'layers'    => '<path d="m12 3 9 5-9 5-9-5 9-5z"/><path d="m3 13 9 5 9-5"/>',
        'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'alert'     => '<path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4"/><path d="M12 17h.01"/>',
        'check'     => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
        'stock'     => '<path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-7"/><path d="M22 20H2"/>',
        'plus'      => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'lock'      => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? '') . '</svg>';
}

function admin_open(string $title, string $active): void
{
    $username = $_SESSION['admin_username'] ?? 'admin';
    $nav = [
        'dashboard' => ['index.php', 'Dashboard', 'dashboard'],
        'products'  => ['products.php', 'Products', 'box'],
        'sync'      => ['sync.php', 'Price Sync', 'sync'],
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?= e($title) ?> - PixelCraft Admin</title>
    <link rel="stylesheet" href="<?= asset('../', 'assets/css/style.css') ?>">
</head>
<body class="admin-body">
<div class="admin-shell">
    <aside class="admin-side">
        <a href="index.php" class="admin-brand" title="Dashboard"><?= logo_img('../') ?><span class="admin-tag">Admin</span></a>

        <nav class="admin-nav" aria-label="Admin">
            <?php foreach ($nav as $key => [$href, $label, $icon]): ?>
            <a href="<?= $href ?>" class="admin-nav-link<?= $key === $active ? ' is-active' : '' ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>>
                <?= admin_icon($icon) ?><span><?= $label ?></span>
            </a>
            <?php endforeach; ?>
            <a href="../index.php" class="admin-nav-link" target="_blank" rel="noopener" title="Opens the store in a new tab">
                <?= admin_icon('store') ?><span>View Store</span><?= admin_icon('external') ?>
            </a>
        </nav>

        <div class="admin-side-foot">
            <a href="account.php" class="admin-me<?= $active === 'account' ? ' is-active' : '' ?>" title="Your account"<?= $active === 'account' ? ' aria-current="page"' : '' ?>>
                <span class="admin-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($username, 0, 1))) ?></span>
                <span class="admin-me-text"><strong><?= e($username) ?></strong><small>View account</small></span>
            </a>
            <form method="POST" action="index.php">
                <button type="submit" name="logout" value="1" class="admin-nav-link admin-logout"><?= admin_icon('logout') ?><span>Log out</span></button>
            </form>
        </div>
    </aside>

    <main class="admin-main">
<?php
}

function admin_close(): void
{
    echo "    </main>\n</div>\n</body>\n</html>\n";
}

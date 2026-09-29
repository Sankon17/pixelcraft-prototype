<?php
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Asset URL with a version stamp so browsers reload CSS/JS whenever the file changes.
function asset(string $base, string $path): string
{
    $file = __DIR__ . '/../' . $path;
    return $base . $path . (is_file($file) ? '?v=' . filemtime($file) : '');
}

// Store logo: the compact mark for nav bars, the full logo (with tagline) everywhere else
function logo_img(string $base, bool $tagline = false): string
{
    $file = $tagline ? 'pixelcraft-logo.png' : 'pixelcraft-mark.png';
    return '<img src="' . e(asset($base, "assets/images/logo/$file")) . '" alt="PixelCraft" class="logo-img">';
}

function category_icon(string $slug): string
{
    $icons = [
        'laptops'       => '💻',
        'office-pcs'    => '💼',
        'editing-pcs'   => '🎬',
        'gaming-pcs'    => '🖥️',
        'gaming-chairs' => '🪑',
        'mouse'         => '🖱️',
        'keyboard'      => '⌨️',
        'mousepad'      => '🟥',
        'headset'       => '🎧',
        'speaker'       => '🔊',
        'controller'    => '🎮',
        'monitor'       => '🖥️',
        'printer'       => '🖨️',
        'led-lamp'      => '💡',
    ];
    return $icons[$slug] ?? '📦';
}

// Returns a web path for an image in assets/images/<folder>/, or null if the file doesn't exist.
function asset_image(string $base, string $folder, ?string $file): ?string
{
    if (!$file) return null;
    if (preg_match('#^https?://#', $file)) return $file;

    $file = basename($file);
    $disk = __DIR__ . "/../assets/images/$folder/$file";
    return is_file($disk) ? $base . "assets/images/$folder/" . rawurlencode($file) : null;
}

function category_image(string $base, string $slug): ?string
{
    foreach (['jpg', 'png', 'webp'] as $ext) {
        if ($src = asset_image($base, 'categories', "$slug.$ext")) return $src;
    }
    return null;
}

function product_image(array $product, string $base, string $class = 'product-img'): string
{
    $src = asset_image($base, 'products', $product['image'] ?? null);
    if ($src) {
        return '<div class="' . $class . '"><img src="' . e($src) . '" alt="' . e($product['name']) . '" loading="lazy"></div>';
    }
    return '<div class="' . $class . ' is-icon">' . category_icon($product['category_slug'] ?? '') . '</div>';
}

// Official contact channels (from pixelcrafthq.com).
function store_contacts(): array
{
    return [
        'whatsapp' => [
            'sales'      => ['label' => 'Sales',       'display' => '018-944 7695',  'url' => 'https://wa.me/60189447695'],
            'aftersales' => ['label' => 'After-Sales', 'display' => '011-5591 6980', 'url' => 'https://wa.me/601155916980'],
        ],
        'social' => [
            'Instagram' => 'https://www.instagram.com/pixelcraft.hq/',
            'TikTok'    => 'https://www.tiktok.com/@pixel.craft.official',
            'YouTube'   => 'https://www.youtube.com/@PixelCraftsdnbhd',
        ],
    ];
}

// PCs (office / editing / gaming), laptops, or everything else — how the admin groups products
function product_group(?string $slug): string
{
    if ($slug === 'laptops') {
        return 'laptop';
    }
    return isset(pc_tiers()[$slug ?? '']) ? 'pc' : 'accessory';
}

// What's missing or incomplete for a product on the store (used by the admin dashboard and the product list).
// $p needs price, stock, image, category_slug, variant_count, variants_out and the 8 product_specs columns.
// Returns field => [severity, what's wrong, edit-page section to fix it], most serious first.
// Severity 2 = customers can't buy it properly, 1 = it looks unfinished
function product_issues(array $p): array
{
    $specs    = ['cpu', 'gpu', 'motherboard', 'psu', 'cooler', 'casing', 'ram', 'storage'];
    $group    = product_group($p['category_slug'] ?? null);
    $variants = (int)$p['variant_count'];
    $out      = (int)$p['variants_out'];
    $issues   = [];

    if (!$variants && (float)$p['price'] <= 0) {
        $issues['price'] = [2, 'No price — shows RM 0.00', 'edit'];
    }
    if ((int)$p['stock'] <= 0) {
        $issues['stock'] = [2, 'Out of stock — can’t be bought', 'edit'];
    }
    if ($variants && $out === $variants) {
        $issues['variants'] = [2, 'All variants out of stock', 'variants'];
    } elseif ($out) {
        $issues['variants'] = [1, "$out of $variants variants out of stock", 'variants'];
    } elseif ($group === 'pc' && !$variants) {
        $issues['variants'] = [1, 'No variants to choose from', 'variants'];
    }
    if (!asset_image('../', 'products', $p['image'] ?? null)) {
        $issues['photo'] = [1, 'No photo — shows a placeholder', 'photos'];
    }
    if ($group !== 'accessory') {
        $empty = count(array_filter($specs, fn($col) => trim((string)($p[$col] ?? '')) === ''));
        if ($empty === count($specs)) {
            $issues['specs'] = [1, 'Specs not filled in — all show TBA', 'specs'];
        } elseif ($empty) {
            $issues['specs'] = [1, "$empty of " . count($specs) . ' specs empty — show TBA', 'specs'];
        }
    }

    uasort($issues, fn($a, $b) => $b[0] <=> $a[0]);
    return $issues;
}

function pc_tiers(): array
{
    // Order here = order of the cards. Each slug has a matching .pc-<slug> accent in style.css.
    // 'pc_type' is the default "What can this PC handle?" section when a product has no product_specs row.
    return [
        'laptops' => [
            'name'    => 'Laptops',
            'tagline' => 'Portable computing for students and professionals',
            'uses'    => ['Acer · Asus · Lenovo', 'Study, work & travel', 'Take it anywhere'],
            'pc_type' => 'student',
        ],
        'office-pcs' => [
            'name'    => 'Office PCs',
            'tagline' => 'Reliable everyday performance',
            'uses'    => ['Documents & spreadsheets', 'Browsing & video calls', 'Quiet, compact builds'],
            'pc_type' => 'office',
        ],
        'editing-pcs' => [
            'name'    => 'Editing PCs',
            'tagline' => 'Built for creators',
            'uses'    => ['Premiere Pro & DaVinci Resolve', 'Photoshop & Lightroom', 'Fast storage for large projects'],
            'pc_type' => 'editing',
        ],
        'gaming-pcs' => [
            'name'    => 'Gaming PCs',
            'tagline' => 'High-FPS, RGB, no compromise',
            'uses'    => ['Competitive & AAA titles', 'Streaming while gaming', 'Upgrade-ready platforms'],
            'pc_type' => 'gaming',
        ],
    ];
}

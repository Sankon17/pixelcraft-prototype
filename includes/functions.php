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

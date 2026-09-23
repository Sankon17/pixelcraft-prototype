<?php
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function category_icon(string $slug): string
{
    $icons = [
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

function pc_tiers(): array
{
    return [
        'office-pcs' => [
            'title'   => 'Office',
            'tagline' => 'Reliable everyday performance',
            'uses'    => ['Documents & spreadsheets', 'Browsing & video calls', 'Quiet, compact builds'],
        ],
        'editing-pcs' => [
            'title'   => 'Editing',
            'tagline' => 'Built for creators',
            'uses'    => ['Premiere Pro & DaVinci Resolve', 'Photoshop & Lightroom', 'Fast storage for large projects'],
        ],
        'gaming-pcs' => [
            'title'   => 'Gaming',
            'tagline' => 'High-FPS, RGB, no compromise',
            'uses'    => ['Competitive & AAA titles', 'Streaming while gaming', 'Upgrade-ready platforms'],
        ],
    ];
}

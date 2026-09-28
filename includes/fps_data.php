<?php
// Estimated 1080p FPS for each gaming PC, used by "Apa Yang Boleh PC Ini Lakukan?" on pages/product.php.
//
// How these were worked out (September 2026), so they can be re-checked or updated:
//  - GPU scaling: Tom's Hardware GPU hierarchy, 1080p Ultra averages
//      RTX 5080 166.9 · 5070 Ti 155.4 · 5070 132.6 · 5060 Ti 16GB 105.2 · RX 9060 XT 16GB 98.3
//      RTX 5060 88.5 · 5050 69.3 · 3060 12GB 61.5 · 3050 44.6 · RX 9050 ≈ 30% below RTX 5050 (early reviews)
//  - Per-game anchors (real test results):
//      CS2 (High):        RTX 5050 ≈ 240 FPS (Tom's Hardware RTX 5050 review)
//      Fortnite (Epic):   RTX 5050 ≈ 82 FPS, RTX 3050 < 60, RX 9060 XT tops the chart (same review)
//      GTA V (Ultra):     RTX 5060 ≈ 172 FPS (Notebookcheck); the game engine caps at ~188 FPS
//  - CPU limits (esports titles are CPU-bound at 1080p):
//      CS2: Core Ultra 5 225F ≈ 300, Ryzen 5 7500F ≈ 28% faster, i5-14400F ≈ 300, Ryzen 5 5500 ≈ 230,
//           Ryzen 7 9850X3D ≈ 612 (TechSpot / review data)
//      Valorant: Ryzen 5 5500 ≈ 390, faster CPUs scaled from there
//  - Result = the lower of the CPU and GPU limit, rounded down.
//  - Roblox tops out at 240 FPS (its own Maximum Frame Rate setting). No reliable per-GPU Roblox benchmarks exist, so
//    entry-level builds (Ryzen 5 5500, RTX 3050 / RX 9050) use cautious figures below the cap; the rest reach the cap.
//
// fps_data() is keyed by pc_key() of the product name.
// Values: a number, or [variant label => number] when the PC is sold with a choice of GPU.

// Short PC name used as the lookup key, so the "PC " prefix, "(3 Years Warranty)" and "Mini ITX"
// can change in the product name without losing the data: "PC Pixel Atom Mini ITX (3 Years Warranty)" -> "pixel atom"
function pc_key(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/\s*\(.*?\)/', '', $name);
    $name = preg_replace('/^pc\s+/', '', $name);
    $name = preg_replace('/\s+mini itx$/', '', $name);
    return trim(preg_replace('/\s+/', ' ', $name));
}

function fps_settings(): array
{
    return [
        'GTA V'    => 'Ultra',
        'Valorant' => 'High',
        'CS2'      => 'High',
        'Fortnite' => 'Epic',
        'Roblox'   => 'Max',
    ];
}

function fps_data(): array
{
    return [
        'pixel blaze' => [
            'GTA V'    => ['RTX 3050' => 85,  'RTX 3060' => 120],
            'Valorant' => ['RTX 3050' => 350, 'RTX 3060' => 390],
            'CS2'      => ['RTX 3050' => 150, 'RTX 3060' => 210],
            'Fortnite' => ['RTX 3050' => 53,  'RTX 3060' => 73],
            'Roblox'   => ['RTX 3050' => 144, 'RTX 3060' => 200],
        ],
        'pixel blaze plus' => [
            'GTA V' => 130, 'Valorant' => 390, 'CS2' => 230, 'Fortnite' => 82, 'Roblox' => 200,
        ],
        'pixel atom' => [
            'GTA V' => 130, 'Valorant' => 390, 'CS2' => 230, 'Fortnite' => 82, 'Roblox' => 200,
        ],
        'pixel radiant' => [
            'GTA V' => 95, 'Valorant' => 390, 'CS2' => 170, 'Fortnite' => 57, 'Roblox' => 144,
        ],
        'pixel core xii' => [
            'GTA V' => 170, 'Valorant' => 450, 'CS2' => 300, 'Fortnite' => 105, 'Roblox' => 240,
        ],
        'pixel core ultra' => [
            'GTA V' => 170, 'Valorant' => 450, 'CS2' => 300, 'Fortnite' => 105, 'Roblox' => 240,
        ],
        'pixel nitro' => [
            'GTA V' => 170, 'Valorant' => 500, 'CS2' => 300, 'Fortnite' => 105, 'Roblox' => 240,
        ],
        'pixel beast' => [
            'GTA V' => 175, 'Valorant' => 500, 'CS2' => 340, 'Fortnite' => 125, 'Roblox' => 240,
        ],
        'pixel frost' => [
            'GTA V' => 180, 'Valorant' => 500, 'CS2' => 360, 'Fortnite' => 120, 'Roblox' => 240,
        ],
        'pixel core elite' => [
            'GTA V' => 180, 'Valorant' => 540, 'CS2' => 360, 'Fortnite' => 120, 'Roblox' => 240,
        ],
        'pixel hellion' => [
            'GTA V' => 180, 'Valorant' => 500, 'CS2' => 390, 'Fortnite' => 155, 'Roblox' => 240,
        ],
        'pixel raptor' => [
            'GTA V'    => 185,
            'Valorant' => 700,
            'CS2'      => ['RTX 5070 Ti' => 540, 'RTX 5080' => 580],
            'Fortnite' => ['RTX 5070 Ti' => 185, 'RTX 5080' => 195],
            'Roblox'   => 240,
        ],
    ];
}

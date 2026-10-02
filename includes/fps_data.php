<?php
// Estimated FPS for each gaming PC (at 1080p, or the resolution in fps_resolution()),
// used by "What Can This PC Do?" on pages/product.php.
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
// Higher-end builds are shown at the resolution they're meant for (fps_resolution(), October 2026):
//  - 1440p (Beast RX 9060 XT, Frost RTX 5060 Ti, Hellion RTX 5070): the GPU-limited 1080p figure × ~0.72
//    (9060 XT / 5060 Ti) or × ~0.75 (5070), the usual 1080p → 1440p drop for these cards in GPU hierarchy charts,
//    still capped by the Ryzen 5 7500F where the game is CPU-bound. Valorant at 1440p ≈ 380–420 (5070 Ti class ≈ 420–440).
//  - 4K (Raptor RTX 5070 Ti / 5080): GPU-limited 1080p × ~0.50 (5070 Ti) and × ~0.55 (5080). A 13-game suite has the
//    5070 Ti at 163 / 97 FPS and the 5080 at 187 / 114 FPS at 1440p / 4K, so the 5080 pulls ~18% ahead at 4K.
//    Cross-checks: CS2 4K ≈ 195 (5070 Ti) / 215 (5080); Valorant 4K high ≈ 250–400+ (5080), ~400 (5070 Ti);
//    Fortnite 4K Epic is kept cautious (one source claims 120–160 FPS on the 5070 Ti at native 4K Epic).
//
// fps_data() is keyed by pc_key() of the product name.
//
// Gaming laptops (October 2026). Only laptops with a dedicated gaming GPU are listed: the office laptops
// (Acer Aspire Lite 15, Asus ExpertBook B1, Lenovo IdeaPad Slim 3) show "What Is It Good For?" instead of games.
// Laptop GPUs run at a power limit (TGP) set by the maker, which changes their speed a lot, so the figures are for
// each model's own TGP:
//  - MSI Thin 15 B13UC: RTX 3050 4GB Laptop at 45W (MSI spec), i5-13420H. Notebookcheck's RTX 3050 Laptop averages
//    (all TGPs, 35–95W) are GTA V 1080p high 114 / ultra 60 and CS2 med 125 / ultra 71; the 45W version sits
//    ~15% below average, so GTA V ultra ≈ 50 and CS2 high ≈ 90. Valorant high ≈ 129 FPS measured on MSI's GF63 Thin
//    with the same 45W RTX 3050. Fortnite Epic: the desktop RTX 3050 stays under 60, this 45W laptop chip ≈ 35–40.
//  - HP Victus 15-fa2743TX: RTX 4050 6GB Laptop at 75W (HP 15-fa2 spec), i7-14650HX. Notebookcheck's RTX 4050 Laptop
//    averages are GTA V 1080p high 155 / ultra 81 and CS2 high 128 (75W is close to that average); Sportskeeda
//    measured CS2 very high 130 and Fortnite Epic (DLSS off) 82 on a 125W 4050, so ≈ 75 at 75W. Valorant 244 FPS
//    average measured on an RTX 4050 Laptop with the slower i5-12450HX.
//  - Roblox: no per-GPU benchmarks; cautious figures below its 240 FPS cap (as for the desktop builds).
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

// The resolution each PC's figures are for; anything not listed is 1080p
function fps_resolution(string $key): string
{
    return [
        'pixel beast'   => '1440p',
        'pixel frost'   => '1440p',
        'pixel hellion' => '1440p',
        'pixel raptor'  => '4K',
    ][$key] ?? '1080p';
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
        // 1440p (fps_resolution)
        'pixel beast' => [
            'GTA V' => 135, 'Valorant' => 380, 'CS2' => 240, 'Fortnite' => 90, 'Roblox' => 240,
        ],
        // 1440p
        'pixel frost' => [
            'GTA V' => 145, 'Valorant' => 380, 'CS2' => 260, 'Fortnite' => 85, 'Roblox' => 240,
        ],
        'pixel core elite' => [
            'GTA V' => 180, 'Valorant' => 540, 'CS2' => 360, 'Fortnite' => 120, 'Roblox' => 240,
        ],
        // 1440p
        'pixel hellion' => [
            'GTA V' => 180, 'Valorant' => 420, 'CS2' => 340, 'Fortnite' => 115, 'Roblox' => 240,
        ],
        // Gaming laptops (see the notes at the top)
        'msi thin 15 b13uc-3441my' => [
            'GTA V' => 50, 'Valorant' => 130, 'CS2' => 90, 'Fortnite' => 38, 'Roblox' => 110,
        ],
        'hp victus gaming 15-fa2743tx' => [
            'GTA V' => 80, 'Valorant' => 250, 'CS2' => 130, 'Fortnite' => 75, 'Roblox' => 200,
        ],
        // 4K
        'pixel raptor' => [
            'GTA V'    => ['RTX 5070 Ti' => 150, 'RTX 5080' => 175],
            'Valorant' => ['RTX 5070 Ti' => 340, 'RTX 5080' => 380],
            'CS2'      => ['RTX 5070 Ti' => 195, 'RTX 5080' => 215],
            'Fortnite' => ['RTX 5070 Ti' => 90,  'RTX 5080' => 105],
            'Roblox'   => 240,
        ],
    ];
}

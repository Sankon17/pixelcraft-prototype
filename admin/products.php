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

// List tabs, sort order and search. They ride along in the URL (and in every form) so they survive edits, hides and deletes
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
    $q    = $src['q'] ?? '';
    $cats = is_string($src['cats'] ?? null) ? $src['cats'] : '';
    return [
        'type' => is_string($type) && isset(LIST_TYPES[$type]) ? $type : 'all',
        'sort' => is_string($sort) && isset(LIST_SORTS[$sort]) ? $sort : 'price_asc',
        'q'    => is_string($q) ? mb_substr(trim($q), 0, 100) : '',
        // Category filter (the ticked boxes beside the list): slugs, comma-separated
        'cats' => implode(',', array_unique(array_filter(explode(',', $cats), fn($c) => preg_match('/^[a-z0-9-]{1,60}$/', $c)))),
    ];
}

// products.php?type=pc&sort=price_desc&q=rtx (defaults and an empty search are left out of the URL)
function list_url(array $state, array $extra = []): string
{
    $query = array_filter($state + $extra, fn($v, $k) => !($k === 'type' && $v === 'all') && !($k === 'sort' && $v === 'price_asc') && $v !== '', ARRAY_FILTER_USE_BOTH);
    return 'products.php' . ($query ? '?' . http_build_query($query) : '');
}

// What the search box looks through: name, category, CPU, GPU and photo filename
function search_text(array $p): string
{
    return mb_strtolower(implode(' ', array_filter([$p['name'], $p['category_name'], $p['cpu'], $p['gpu'], $p['image']])));
}

// Every word typed has to appear somewhere ("rtx blaze" finds the Pixel Blaze with an RTX card)
function matches_search(array $p, string $q): bool
{
    $text = search_text($p);
    foreach (preg_split('/\s+/', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) as $word) {
        if (!str_contains($text, $word)) {
            return false;
        }
    }
    return true;
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
// Casing colours a PC can come in (Pixel Neo is black only; Pixel Core XII also comes in pink). A PC with 2+ shows a Color choice
const COLOR_OPTIONS = ['Black', 'White', 'Pink'];

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
    $stmt = $pdo->prepare('SELECT id, option_name, option_values, ram, storage, set_type, gpu_variant AS gpu, color, price, stock FROM product_variants WHERE product_id = ? ORDER BY price, id');
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
            'color'    => trim((string)($row['color'] ?? '')) ?: 'Black',
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
        if (!in_array($v['color'], COLOR_OPTIONS, true)) $errors[] = "$row: colour must be " . or_list(COLOR_OPTIONS) . '.';
        if (!is_numeric($v['price']) || $v['price'] <= 0) $errors[] = "$row needs a price above 0.";
        if (!ctype_digit($v['stock'])) $errors[] = "$row: stock must be a whole number of 0 or more.";
        // The store picks a variant by these five; two identical ones could never both be chosen
        $key = mb_strtolower($v['ram'] . '|' . $v['storage'] . '|' . $v['set_type'] . '|' . $v['gpu'] . '|' . $v['color']);
        if (isset($seen[$key])) $errors[] = "$row is the same as variant {$seen[$key]} (RAM, storage, set, graphics card and colour).";
        $seen[$key] ??= $n + 1;
    }
    return $errors;
}

// ── Accessory variants (Shopee-style): up to 3 variation types, e.g. Colour (Red, White, Grey) and Fabric
// (PU Leather, PU). Every combination of their options is a variant with its own price and stock ──
const OPTION_TYPES_MAX  = 3;
const OPTION_VALUES_MAX = 20;
const OPTION_TYPE_MAX   = 30;  // "Colour"
const OPTION_MAX        = 60;  // "PU Leather"
const OPTION_COMBOS_MAX = 100;

// Every combination of the types' options, in order: [Red, PU Leather], [Red, PU], [White, PU Leather] …
function option_combos(array $groups): array
{
    $combos = $groups ? [[]] : [];
    foreach ($groups as $g) {
        $next = [];
        foreach ($combos as $combo) {
            foreach ($g['values'] as $value) {
                $next[] = [...$combo, $value];
            }
        }
        $combos = $next;
    }
    return $combos;
}

function combo_key(array $values): string
{
    return mb_strtolower(json_encode(array_values($values), JSON_UNESCAPED_UNICODE));
}

// The accessory editor's starting point: ['groups' => [[name, values], …], 'rows' => one per variant]
function options_from(?string $groups_json, array $variants): array
{
    $groups = json_decode((string)$groups_json, true);
    $groups = is_array($groups) ? $groups : [];
    $rows = [];
    foreach ($variants as $v) {
        $values = json_decode((string)$v['option_values'], true);
        if (!is_array($values)) {
            // A variant from when this was a PC: named by its RAM / SSD
            $values = [(string)$v['option_name'] !== '' ? (string)$v['option_name'] : trim($v['ram'] . ' / ' . $v['storage'], ' /')];
        }
        $rows[] = ['id' => (int)$v['id'], 'values' => array_map('strval', $values), 'price' => $v['price'], 'stock' => (string)$v['stock']];
    }
    // Variants that don't fit the saved types show as one "Option" type, so nothing is lost
    if ($rows && array_filter($rows, fn($r) => count($r['values']) !== count($groups))) {
        $rows = array_map(fn($r) => ['values' => [implode(' · ', $r['values'])]] + $r, $rows);
        $groups = [['name' => 'Option', 'values' => array_values(array_unique(array_column(array_column($rows, 'values'), 0)))]];
    }
    return ['groups' => $groups, 'rows' => $rows];
}

// As posted: the types, and a row (price, stock, variant id) for every combination of their options
function read_options(array $src): array
{
    $groups = [];
    foreach ((array)($src['groups'] ?? []) as $g) {
        $name   = trim((string)($g['name'] ?? ''));
        $values = array_values(array_filter(array_map(fn($v) => trim((string)$v), (array)($g['values'] ?? [])), fn($v) => $v !== ''));
        if ($name === '' && !$values) continue; // a type left empty
        $groups[] = ['name' => $name, 'values' => $values];
    }
    $posted = [];
    foreach ((array)($src['combos'] ?? []) as $c) {
        $values = json_decode((string)($c['values'] ?? ''), true);
        if (is_array($values)) {
            $posted[combo_key(array_map('strval', $values))] = $c;
        }
    }
    // Too many types or options is an error (option_errors); don't build thousands of rows for it
    $too_many = count($groups) > OPTION_TYPES_MAX || array_filter($groups, fn($g) => count($g['values']) > OPTION_VALUES_MAX);
    $rows = [];
    foreach ($too_many ? [] : option_combos($groups) as $values) {
        $c = $posted[combo_key($values)] ?? [];
        $rows[] = ['id' => (int)($c['id'] ?? 0), 'values' => $values, 'price' => trim((string)($c['price'] ?? '')), 'stock' => trim((string)($c['stock'] ?? ''))];
    }
    return ['groups' => $groups, 'rows' => $rows];
}

// "Red, White, Grey and 2 more"
function some_of(array $labels): string
{
    return implode(', ', array_slice($labels, 0, 4)) . (count($labels) > 4 ? ' and ' . (count($labels) - 4) . ' more' : '');
}

function option_errors(array $options): array
{
    $errors = [];
    if (count($options['groups']) > OPTION_TYPES_MAX) {
        $errors[] = 'Up to ' . OPTION_TYPES_MAX . ' variation types.';
    }
    $names = [];
    foreach ($options['groups'] as $n => $g) {
        $type = $g['name'] !== '' ? $g['name'] : 'Variation ' . ($n + 1);
        $key  = mb_strtolower($g['name']);
        if ($g['name'] === '') {
            $errors[] = "$type needs a name, e.g. Colour.";
        } elseif (mb_strlen($g['name']) > OPTION_TYPE_MAX) {
            $errors[] = "$type: the name must be " . OPTION_TYPE_MAX . ' characters or fewer.';
        } elseif (isset($names[$key])) {
            $errors[] = "There are two variation types called $type.";
        }
        $names[$key] = true;
        if (!$g['values']) {
            $errors[] = "$type needs at least one option, e.g. Red.";
        } elseif (count($g['values']) > OPTION_VALUES_MAX) {
            $errors[] = "$type can have up to " . OPTION_VALUES_MAX . ' options.';
        }
        $seen = [];
        foreach ($g['values'] as $value) {
            if (mb_strlen($value) > OPTION_MAX) $errors[] = "$type: “" . mb_substr($value, 0, 20) . '…” must be ' . OPTION_MAX . ' characters or fewer.';
            if (isset($seen[mb_strtolower($value)])) $errors[] = "$type has “{$value}” twice.";
            $seen[mb_strtolower($value)] = true;
        }
    }
    if (count($options['rows']) > OPTION_COMBOS_MAX) {
        $errors[] = 'That makes ' . count($options['rows']) . ' variants; the most is ' . OPTION_COMBOS_MAX . '. Remove some options.';
        return $errors;
    }
    $no_price  = array_filter($options['rows'], fn($r) => !is_numeric($r['price']) || $r['price'] <= 0);
    $bad_stock = array_filter($options['rows'], fn($r) => !ctype_digit($r['stock']));
    $label = fn($r) => implode(' · ', $r['values']);
    if ($no_price)  $errors[] = 'Give every variant a price above 0 (missing: ' . some_of(array_map($label, $no_price)) . ').';
    if ($bad_stock) $errors[] = 'Stock must be a whole number of 0 or more (' . some_of(array_map($label, $bad_stock)) . ').';
    return $errors;
}

// The whole add/edit form: details, plus specs and variants for PCs and laptops, or variants for accessories.
// $had_variants: the product already has variants (its price comes from them if the Variants section isn't on the form)
function read_full_form(array $post, array $categories, bool $had_variants = false): array
{
    $category     = find_category($categories, (int)($post['category_id'] ?? 0));
    $with_specs   = isset($post['specs']) && has_specs($category);
    $with_options = isset($post['options']['on']) && $category && !has_specs($category);
    $variants     = $with_specs ? read_variants((array)($post['variants'] ?? [])) : [];
    $options      = $with_options ? read_options((array)$post['options']) : ['groups' => [], 'rows' => []];
    $kept         = $with_specs ? array_filter($variants, fn($v) => !$v['remove']) : $options['rows'];
    // With variants the price is the cheapest one's, so the typed price isn't checked (or used)
    $price_from_variants = $with_specs || $with_options ? (bool)$kept : $had_variants;
    [$form, $errors] = read_product_form($post, $categories, $price_from_variants);
    $form['specs']    = $with_specs ? read_specs((array)$post['specs']) : read_specs([]);
    $form['variants'] = $variants;
    $form['options']  = $options;
    if ($with_specs) {
        $errors = [...$errors, ...spec_errors($form['specs']), ...variant_errors($variants)];
    }
    if ($with_options) {
        $errors = [...$errors, ...option_errors($options)];
    }
    return [$form, $errors, $with_specs, $price_from_variants, $with_options];
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
            $same = [$old['ram'], $old['storage'], $old['set_type'], (string)$old['gpu'], (string)$old['color'], (float)$old['price'], (int)$old['stock'], (string)$old['option_name'] . $old['option_values']]
                === [$v['ram'], $v['storage'], $v['set_type'], $v['gpu'], $v['color'], $price, (int)$v['stock'], ''];
            if ($same) continue;
            // option_name is cleared: a variant from when this was an accessory is now named by its RAM / SSD
            $pdo->prepare('UPDATE product_variants SET option_name = NULL, option_values = NULL, ram = ?, storage = ?, set_type = ?, gpu_variant = ?, color = ?, price = ?, stock = ? WHERE id = ?')
                ->execute([$v['ram'], $v['storage'], $v['set_type'], $gpu, $v['color'], $price, (int)$v['stock'], $v['id']]);
            if ($price !== (float)$old['price']) {
                $pdo->prepare('UPDATE product_variants SET jcl_1yr = ?, jcl_2yr = ?, jcl_3yr = ? WHERE id = ?')->execute([...$jcl($price), $v['id']]);
            }
            $counts[1]++;
        } elseif (!$v['remove']) {
            $pdo->prepare('INSERT INTO product_variants (product_id, ram, storage, set_type, gpu_variant, color, price, jcl_1yr, jcl_2yr, jcl_3yr, stock) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$product_id, $v['ram'], $v['storage'], $v['set_type'], $gpu, $v['color'], $price, ...$jcl($price), (int)$v['stock']]);
            $counts[0]++;
        }
    }

    price_from_cheapest($pdo, $product_id);
    return $counts;
}

// The store shows "From RM…": the cheapest variant
function price_from_cheapest(PDO $pdo, int $product_id): void
{
    $pdo->prepare('UPDATE products p JOIN (SELECT MIN(price) AS min_price FROM product_variants WHERE product_id = ?) v SET p.price = v.min_price WHERE p.id = ? AND v.min_price IS NOT NULL')
        ->execute([$product_id, $product_id]);
}

// Applies an accessory's variation types: a variant for every combination (kept by id, so a renamed option keeps its row),
// variants whose combination is gone are removed. No RAM / SSD, and no JCL (only shown for PCs). Returns [added, changed, removed]
function save_options(PDO $pdo, int $product_id, array $options): array
{
    $current = [];
    foreach (variants_of($pdo, $product_id) as $v) {
        $current[(int)$v['id']] = $v;
    }
    $counts = [0, 0, 0];
    $kept = [];

    foreach ($options['rows'] as $r) {
        $label = implode(' · ', $r['values']);
        $json  = json_encode($r['values'], JSON_UNESCAPED_UNICODE);
        $price = round((float)$r['price'], 2);
        $old   = isset($current[$r['id']]) && !isset($kept[$r['id']]) ? $current[$r['id']] : null;

        if ($old) {
            $kept[$r['id']] = true;
            if ([(string)$old['option_name'], (string)$old['option_values'], (float)$old['price'], (int)$old['stock']] === [$label, $json, $price, (int)$r['stock']]) continue;
            $pdo->prepare("UPDATE product_variants SET option_name = ?, option_values = ?, ram = '', storage = '', gpu_variant = NULL, color = NULL, price = ?, stock = ? WHERE id = ?")
                ->execute([$label, $json, $price, (int)$r['stock'], $r['id']]);
            $counts[1]++;
        } else {
            $pdo->prepare("INSERT INTO product_variants (product_id, option_name, option_values, ram, storage, gpu_variant, color, price, stock) VALUES (?, ?, ?, '', '', NULL, NULL, ?, ?)")
                ->execute([$product_id, $label, $json, $price, (int)$r['stock']]);
            $counts[0]++;
        }
    }
    // A removed option (or type) takes its combinations with it
    foreach (array_diff_key($current, $kept) as $id => $_) {
        $pdo->prepare('DELETE FROM product_variants WHERE id = ?')->execute([$id]);
        $counts[2]++;
    }
    $pdo->prepare('UPDATE products SET option_groups = ? WHERE id = ?')
        ->execute([$options['groups'] ? json_encode($options['groups'], JSON_UNESCAPED_UNICODE) : null, $product_id]);

    price_from_cheapest($pdo, $product_id);
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
    . '<input type="hidden" name="sort" value="' . e($state['sort']) . '">'
    . '<input type="hidden" name="q" value="' . e($state['q']) . '" data-list-q>'
    . '<input type="hidden" name="cats" value="' . e($state['cats']) . '" data-list-cats>';

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
        [$form, $errors, $with_specs, , $with_options] = read_full_form($_POST, $categories);
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
            } elseif ($with_options) {
                $added = save_options($pdo, $new_id, $form['options'])[0];
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
        [$form, $errors, $with_specs, $price_from_variants, $with_options] = read_full_form($_POST, $categories, variant_count($pdo, $id) > 0);
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
                $pdo->prepare('UPDATE products SET option_groups = NULL WHERE id = ?')->execute([$id]);
            } elseif ($with_options) {
                $changes = save_options($pdo, $id, $form['options']);
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
            // Back to the list showing the product's own category (its tab, with that category ticked), sort kept
            $state = ['type' => product_group($to['slug']), 'sort' => $state['sort'], 'q' => '', 'cats' => (string)$to['slug']];
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
    $form = ['id' => 0, 'name' => '', 'category_id' => 0, 'price' => '', 'stock' => '10', 'video_url' => '', 'is_active' => false, 'specs' => read_specs([]), 'variants' => [], 'options' => ['groups' => [], 'rows' => []]];
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
        $form['options'] ??= options_from($editing['option_groups'], $form['variants']);
        // A failed save of an accessory posts no specs/variants, but the sections appear if the category is switched to a PC
        $form['specs']    ??= read_specs([]);
        $form['variants'] ??= [];
        $form['options']  ??= ['groups' => [], 'rows' => []];
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
        // Specs, YouTube and RAM/SSD variants are for PCs and laptops (shown too while a new product has no category yet);
        // accessories get their own Variants section instead: an option name, price and stock
        $show_specs   = !$form['category_id'] || has_specs(find_category($categories, $form['category_id']));
        $show_options = !$show_specs;
        // One spare empty row each, so a variant can be added even without JavaScript
        $variant_rows = [...$form['variants'], ['id' => 0, 'ram' => '', 'storage' => '', 'set_type' => 'cpu_only', 'gpu' => '', 'color' => 'Black', 'price' => '', 'stock' => '10', 'remove' => false]];
        // With variants the price is the cheapest one's: shown in the price box, which is then locked
        $variant_prices = array_map(fn($v) => (float)$v['price'], array_filter($show_specs ? $form['variants'] : $form['options']['rows'], fn($v) => !($v['remove'] ?? false) && is_numeric($v['price']) && $v['price'] > 0));
        $price_locked = (bool)$variant_prices;
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
                       data-price<?= $price_locked ? ' readonly' : ' required' ?>>
                <small data-price-hint><?= $price_locked ? 'The cheapest variant. Change prices in Variants below.' : 'Once you add variants below, the price follows the cheapest one.' ?></small>
            </label>

            <label>
                <span>Stock</span>
                <input type="number" name="stock" value="<?= e($form['stock']) ?>" min="0" step="1" required>
            </label>

            <fieldset class="ap-plain ap-wide" data-needs-specs<?= $show_specs ? '' : ' hidden disabled' ?>>
                <label>
                    <span>YouTube link</span>
                    <input type="url" name="video_url" value="<?= e($form['video_url']) ?>" placeholder="https://youtu.be/...">
                    <small>Performance video shown on the product page. Leave empty for none.</small>
                </label>
            </fieldset>

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

            <fieldset class="ap-section ap-wide" id="variants" data-needs-specs data-variant-table="variants"<?= $show_specs ? '' : ' hidden disabled' ?>>
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
                                <th>Colour</th>
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
                                <td>
                                    <select name="variants[<?= $i ?>][color]" aria-label="Colour">
                                        <?php foreach (COLOR_OPTIONS as $colour): ?>
                                        <option value="<?= $colour ?>"<?= $v['color'] === $colour ? ' selected' : '' ?>><?= $colour ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
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
                <button type="button" class="btn btn-sm btn-outline ap-add-variant" data-add-row hidden>＋ Add variant</button>
            </fieldset>

            <fieldset class="ap-section ap-wide" id="options" data-needs-options<?= $show_options ? '' : ' hidden disabled' ?>>
                <legend>Variants</legend>
                <p class="ap-section-note">
                    Add a variation type, such as Colour or Fabric, and its options. Every combination becomes a variant
                    with its own price and stock, and the cheapest is the "From" price. Leave empty if there's only one version.
                </p>
                <?php // options[on] is added by the script once the editor is drawn: without it the server leaves the variants alone ?>
                <div class="ov-types" data-option-types></div>
                <button type="button" class="btn btn-sm btn-outline ap-add-variant" data-add-type>＋ Add variation type</button>

                <div class="ov-table" data-option-table hidden>
                    <div class="ov-apply">
                        <span>Apply to all</span>
                        <input type="number" min="0" step="0.01" placeholder="Price (RM)" aria-label="Price for every variant" data-all-price>
                        <input type="number" min="0" step="1" placeholder="Stock" aria-label="Stock for every variant" data-all-stock>
                        <button type="button" class="btn btn-sm" data-apply-all>Apply</button>
                    </div>
                    <div class="ap-table-wrap ap-variants-wrap">
                        <table class="ap-variants ap-options">
                            <thead data-option-head></thead>
                            <tbody data-option-rows></tbody>
                        </table>
                    </div>
                </div>
                <script type="application/json" data-option-state><?= json_encode($form['options'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
                <noscript><p class="ap-section-note">Switch JavaScript on to edit variants.</p></noscript>
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
        const price = form.querySelector('[data-price]');
        const hint = form.querySelector('[data-price-hint]');
        const money = n => 'RM ' + n.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        // Same flat formula as jcl_monthly(): 1.25% a month over 36 months
        const jcl = price => Math.round(price * (1 + 0.0125 * 36) / 36 * 100) / 100;
        // PC and laptop variants (#variants): rows added and removed here. Accessory variants (#options): further down
        const tables = [...form.querySelectorAll('[data-variant-table]')].map(fs => ({ fs, rows: fs.querySelector('[data-variant-rows]'), prefix: fs.dataset.variantTable }));

        // Prices from whichever Variants section is switched on (a disabled fieldset isn't sent)
        const refresh = () => {
            const prices = [];
            form.querySelectorAll('[data-variant-price]').forEach(input => {
                const value = parseFloat(input.value);
                const tr = input.closest('tr');
                const cell = tr.querySelector('[data-jcl]');
                if (cell) cell.textContent = value > 0 ? money(jcl(value)) : '—';
                if (!input.closest('fieldset').disabled && value > 0 && !tr.querySelector('.ap-remove input:checked')) prices.push(value);
            });
            // The price box follows the cheapest variant, and is locked while there are any
            const locked = prices.length > 0;
            if (locked) price.value = Math.min(...prices).toFixed(2);
            price.readOnly = locked;
            price.required = !locked;
            hint.textContent = locked ? 'The cheapest variant. Change prices in Variants below.' : 'Once you add variants below, the price follows the cheapest one.';
        };

        tables.forEach(({ fs, rows, prefix }) => {
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

            // New row: a copy of the empty spare row with an unused index (variants[7][ram], options[3][name] …)
            const add = fs.querySelector('[data-add-row]');
            add.hidden = false;
            add.addEventListener('click', () => {
                const spare = rows.querySelector('tr.is-new:last-child');
                const row = spare.cloneNode(true);
                const used = [...rows.querySelectorAll('[name$="[id]"]')].map(el => Number(el.name.match(/\[(\d+)\]/)[1]));
                const next = Math.max(-1, ...used) + 1;
                row.querySelectorAll('[name]').forEach(el => {
                    el.name = el.name.replace(new RegExp('^' + prefix + '\\[\\d+\\]'), `${prefix}[${next}]`);
                    if (el.tagName === 'SELECT') el.selectedIndex = 0;
                    else if (el.type !== 'hidden') el.value = el.name.endsWith('[stock]') ? '10' : '';
                });
                rows.appendChild(row);
                refresh();
                row.querySelector('select, input:not([type=hidden])').focus();
            });
        });

        // Accessory variants: variation types (Colour, Fabric …) and a price / stock row for every combination of their options
        const ov = form.querySelector('#options');
        (() => {
            const MAX_TYPES = <?= OPTION_TYPES_MAX ?>, MAX_VALUES = <?= OPTION_VALUES_MAX ?>;
            const state = JSON.parse(ov.querySelector('[data-option-state]').textContent);
            const typesBox = ov.querySelector('[data-option-types]');
            const tableBox = ov.querySelector('[data-option-table]');
            const head = ov.querySelector('[data-option-head]');
            const body = ov.querySelector('[data-option-rows]');
            const addType = ov.querySelector('[data-add-type]');
            const make = (tag, props = {}, attrs = {}, ...kids) => {
                const node = Object.assign(document.createElement(tag), props);
                Object.entries(attrs).forEach(([k, v]) => node.setAttribute(k, v));
                node.append(...kids);
                return node;
            };

            // Each option gets an id of its own, so a row's price and stock stay with it when "Red" is renamed "Merah"
            let uid = 0;
            const types = state.groups.map(g => ({ name: g.name, values: g.values.map(text => ({ uid: ++uid, text })) }));
            const rowData = new Map();
            state.rows.forEach(r => {
                const uids = r.values.map((text, i) => types[i]?.values.find(v => v.text.toLowerCase() === String(text).toLowerCase())?.uid);
                if (uids.length === types.length && uids.every(Boolean)) rowData.set(uids.join('-'), { id: r.id, price: r.price, stock: r.stock });
            });

            function renderTypes() {
                typesBox.replaceChildren();
                types.forEach((t, ti) => {
                    const name = make('input', { type: 'text', value: t.name, maxLength: <?= OPTION_TYPE_MAX ?>, name: `options[groups][${ti}][name]`, placeholder: ['e.g. Colour', 'e.g. Fabric', 'e.g. Size'][ti] }, { 'aria-label': `Variation ${ti + 1} name` });
                    name.addEventListener('input', () => { t.name = name.value; renderTable(); });
                    const drop = make('button', { type: 'button', className: 'ov-remove-type', textContent: 'Remove type' });
                    drop.addEventListener('click', () => { types.splice(ti, 1); renderTypes(); renderTable(); });

                    const values = make('div', { className: 'ov-values' });
                    t.values.forEach((v, vi) => {
                        const input = make('input', { type: 'text', value: v.text, maxLength: <?= OPTION_MAX ?>, name: `options[groups][${ti}][values][]`, placeholder: 'Option' }, { 'aria-label': `Variation ${ti + 1} option ${vi + 1}` });
                        input.addEventListener('input', () => { v.text = input.value; renderTable(); });
                        const x = make('button', { type: 'button', className: 'ap-row-x', textContent: '✕', title: 'Remove this option' }, { 'aria-label': 'Remove this option' });
                        x.addEventListener('click', () => { t.values.splice(vi, 1); renderTypes(); renderTable(); });
                        values.append(make('span', { className: 'ov-value' }, {}, input, x));
                    });
                    if (t.values.length < MAX_VALUES) {
                        const more = make('button', { type: 'button', className: 'ov-add-value', textContent: '＋ Add option' });
                        more.addEventListener('click', () => {
                            t.values.push({ uid: ++uid, text: '' });
                            renderTypes();
                            renderTable();
                            [...typesBox.children][ti].querySelector('.ov-value:last-of-type input').focus();
                        });
                        values.append(more);
                    }
                    typesBox.append(make('div', { className: 'ov-type' }, {},
                        make('div', { className: 'ov-type-head' }, {}, make('span', { className: 'ov-type-label', textContent: `Variation ${ti + 1}` }), name, drop),
                        values));
                });
                addType.hidden = types.length >= MAX_TYPES;
            }

            // Keep what's been typed in the table before it's drawn again
            function harvest() {
                body.querySelectorAll('tr[data-key]').forEach(tr => {
                    rowData.set(tr.dataset.key, {
                        id: Number(tr.querySelector('[name$="[id]"]').value),
                        price: tr.querySelector('[data-variant-price]').value,
                        stock: tr.querySelector('[data-stock]').value,
                    });
                });
            }

            // A row's price and stock; a new combination starts from the row it grew out of
            function lookup(key) {
                if (rowData.has(key)) return rowData.get(key);
                const parts = key.split('-');
                // A type was added: Red → Red · PU Leather and Red · PU
                for (let n = parts.length - 1; n > 0; n--) {
                    const d = rowData.get(parts.slice(0, n).join('-'));
                    if (d) return d;
                }
                // A type was removed: Red · PU Leather → Red
                for (const [k, d] of rowData) {
                    if (k.startsWith(key + '-')) return d;
                }
                return {};
            }

            function renderTable() {
                harvest();
                const filled = types.map(t => t.values.filter(v => v.text.trim() !== ''));
                const on = types.length > 0 && filled.every(vs => vs.length > 0);
                tableBox.hidden = !on;
                head.replaceChildren();
                body.replaceChildren();
                if (on) {
                    head.append(make('tr', {}, {},
                        ...types.map((t, i) => make('th', { textContent: t.name.trim() || `Variation ${i + 1}` })),
                        make('th', { textContent: 'Price (RM)' }), make('th', { textContent: 'Stock' })));
                    let combos = [[]];
                    filled.forEach(vs => { combos = combos.flatMap(c => vs.map(v => [...c, v])); });
                    // The first type's option spans its rows, like Shopee's table
                    const span = combos.length / filled[0].length;
                    // A variant id goes to one row only (the others are new variants)
                    const claimed = new Set();
                    combos.forEach((combo, k) => {
                        const key = combo.map(v => v.uid).join('-');
                        const found = lookup(key);
                        const d = { ...found, id: found.id && !claimed.has(found.id) ? found.id : 0 };
                        claimed.add(d.id);
                        const tr = make('tr', {}, { 'data-key': key });
                        combo.forEach((v, i) => {
                            if (i === 0 && types.length > 1) {
                                if (k % span === 0) tr.append(make('td', { className: 'ov-cell ov-first', textContent: v.text.trim(), rowSpan: span }));
                            } else {
                                tr.append(make('td', { className: 'ov-cell', textContent: v.text.trim() }));
                            }
                        });
                        tr.append(
                            make('td', {}, {},
                                make('input', { type: 'hidden', name: `options[combos][${k}][id]`, value: d.id || 0 }),
                                make('input', { type: 'hidden', name: `options[combos][${k}][values]`, value: JSON.stringify(combo.map(v => v.text.trim())) }),
                                make('input', { type: 'number', name: `options[combos][${k}][price]`, value: d.price ?? '', min: 0, step: '0.01', placeholder: '0.00' }, { 'aria-label': 'Price', 'data-variant-price': '' })),
                            make('td', {}, {},
                                make('input', { type: 'number', name: `options[combos][${k}][stock]`, value: d.stock ?? '10', min: 0, step: 1 }, { 'aria-label': 'Stock', 'data-stock': '' })));
                        body.append(tr);
                    });
                }
                refresh();
            }

            addType.addEventListener('click', () => {
                types.push({ name: '', values: [{ uid: ++uid, text: '' }] });
                renderTypes();
                renderTable();
                typesBox.lastElementChild.querySelector('input').focus();
            });
            body.addEventListener('input', refresh);
            ov.querySelector('[data-apply-all]').addEventListener('click', () => {
                const allPrice = ov.querySelector('[data-all-price]').value;
                const allStock = ov.querySelector('[data-all-stock]').value;
                body.querySelectorAll('tr').forEach(tr => {
                    if (allPrice !== '') tr.querySelector('[data-variant-price]').value = allPrice;
                    if (allStock !== '') tr.querySelector('[data-stock]').value = allStock;
                });
                refresh();
            });
            renderTypes();
            renderTable();
            // Only now is the editor showing every variant, so only now may a save replace them
            ov.append(make('input', { type: 'hidden', name: 'options[on]', value: '1' }));
        })();

        // PCs and laptops: specs, YouTube and RAM/SSD variants. Accessories: their variation types. Disabled fieldsets aren't sent
        const category = form.querySelector('[name=category_id]');
        category.addEventListener('change', () => {
            const pc = category.selectedOptions[0]?.dataset.specs === '1';
            form.querySelectorAll('[data-needs-specs]').forEach(fs => { fs.hidden = !pc; fs.disabled = !pc; });
            form.querySelectorAll('[data-needs-options]').forEach(fs => { fs.hidden = pc; fs.disabled = pc; });
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
            <a href="<?= e(list_url(['type' => $key, 'sort' => $state['sort'], 'q' => $state['q'], 'cats' => ''])) ?>" data-tab-type="<?= $key ?>" class="chip<?= $state['type'] === $key ? ' active' : '' ?>"<?= $state['type'] === $key ? ' aria-current="page"' : '' ?>>
                <?= $label ?> <span class="ap-tab-count"><?= $counts[$key] ?></span>
            </a>
            <?php endforeach; ?>
        </nav>
        <?php if ($state['type'] !== 'all'): ?><input type="hidden" name="type" value="<?= e($state['type']) ?>"><?php endif; ?>
        <label class="ap-search">
            <span aria-hidden="true">🔍</span>
            <input type="search" name="q" value="<?= e($state['q']) ?>" placeholder="Search name, category, CPU or GPU" aria-label="Search products" autocomplete="off" id="productSearch">
        </label>
        <input type="hidden" name="cats" value="<?= e($state['cats']) ?>" data-list-cats>
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

    <?php
    // Filter boxes: the categories in this tab that have products, with how many each
    $picked = array_filter(explode(',', $state['cats']));
    $cat_counts = array_count_values(array_map(fn($p) => (string)$p['category_slug'], $shown));
    $filter_groups = [];
    foreach (['pc' => 'Narrow by PC type', 'laptop' => 'Laptops', 'accessory' => 'Narrow by accessory type'] as $group => $heading) {
        if ($state['type'] !== 'all' && $state['type'] !== $group) continue;
        $items = array_filter($categories[$group], fn($c) => ($cat_counts[$c['slug']] ?? 0) > 0);
        if ($items) $filter_groups[$heading] = $items;
    }
    $in_list = fn($p) => !$picked || in_array((string)$p['category_slug'], $picked, true);
    ?>
    <div class="ap-list">
    <?php if ($filter_groups): ?>
    <aside class="ap-filters" aria-label="Filter by category">
        <div class="ap-filters-head">
            <strong>Filter</strong>
            <button type="button" class="ap-filters-clear" data-cat-clear<?= $picked ? '' : ' hidden' ?>>Clear</button>
        </div>
        <?php foreach ($filter_groups as $heading => $items): ?>
        <fieldset class="ap-filter-group">
            <legend><?= $heading ?></legend>
            <?php foreach ($items as $c): ?>
            <label class="ap-filter-item">
                <input type="checkbox" value="<?= e($c['slug']) ?>" data-cat-filter<?= in_array($c['slug'], $picked, true) ? ' checked' : '' ?>>
                <span><?= e($c['name']) ?></span>
                <em><?= $cat_counts[$c['slug']] ?></em>
            </label>
            <?php endforeach; ?>
        </fieldset>
        <?php endforeach; ?>
    </aside>
    <?php endif; ?>

    <div class="ap-table-wrap">
        <table class="ap-table<?= $state['q'] !== '' || $picked ? ' is-searching' : '' ?>">
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
            <tbody data-no-match hidden><tr><td colspan="9" class="ap-empty" data-no-match-text>No products match.</td></tr></tbody>
            <?php foreach ($sections as $group => [$icon, $label]): ?>
            <?php if (!$grouped[$group]) continue; ?>
            <?php
            // On the All tab each section folds up behind its header (open while one of its products is being edited)
            $foldable = $state['type'] === 'all';
            $open = !$foldable || ($editing && in_array((int)$editing['id'], array_map('intval', array_column($grouped[$group], 'id')), true));
            ?>
            <tbody data-section<?= $foldable ? ' data-group="' . $group . '"' : '' ?><?= $open ? '' : ' class="is-collapsed"' ?>>
                <?php if ($foldable): ?>
                <tr class="ap-section">
                    <th colspan="9">
                        <button type="button" class="ap-section-toggle" data-section-toggle aria-expanded="<?= $open ? 'true' : 'false' ?>">
                            <span class="ap-section-label"><?= $icon ?> <?= $label ?> <span data-section-count><?= count($grouped[$group]) ?></span></span>
                            <svg class="ap-section-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                    </th>
                </tr>
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
                    data-search="<?= e(search_text($p)) ?>" data-cat="<?= e((string)$p['category_slug']) ?>"<?= ($state['q'] !== '' && !matches_search($p, $state['q'])) || !$in_list($p) ? ' hidden' : '' ?>
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
                        <small title="<?= e($p['image']) ?>"><?= e($p['image']) ?><?= $p['photo_count'] > 1 ? ' · ' . (int)$p['photo_count'] . ' photos' : '' ?></small>
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
                            <a href="<?= e(list_url($state, ['edit' => (int)$p['id']])) ?>#<?= $p['group'] === 'accessory' ? 'options' : 'variants' ?>" class="btn btn-sm ap-btn-variants">Variants</a>
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
</div>

<script>
// Search as you type and the category boxes: rows are filtered straight away, and both are kept in the URL, the tab links
// and every form on the page so they survive Edit, Live/Hidden, Delete and changing tab or sort
(() => {
    const box = document.getElementById('productSearch');
    if (!box) return;
    const rows = [...document.querySelectorAll('tr[data-search]')];
    const noMatch = document.querySelector('[data-no-match]');
    const boxes = [...document.querySelectorAll('[data-cat-filter]')];
    const clear = document.querySelector('[data-cat-clear]');

    function apply() {
        const q = box.value.trim();
        const words = q.toLowerCase().split(/\s+/).filter(Boolean);
        const cats = boxes.filter(b => b.checked).map(b => b.value);
        let shown = 0;
        rows.forEach(tr => {
            const hit = words.every(w => tr.dataset.search.includes(w)) && (!cats.length || cats.includes(tr.dataset.cat));
            tr.hidden = !hit;
            if (hit) shown++;
        });
        if (clear) clear.hidden = !cats.length;
        document.querySelectorAll('[data-list-cats]').forEach(input => { input.value = cats.join(','); });
        document.querySelectorAll('tbody[data-section]').forEach(tb => {
            const visible = tb.querySelectorAll('tr[data-search]:not([hidden])').length;
            tb.hidden = visible === 0;
            const count = tb.querySelector('[data-section-count]');
            if (count) count.textContent = visible;
        });
        // While searching or filtering, every section with a match is shown open
        box.closest('.ap-page').querySelector('.ap-table').classList.toggle('is-searching', q !== '' || cats.length > 0);
        noMatch.hidden = shown > 0 || rows.length === 0;
        noMatch.querySelector('[data-no-match-text]').textContent = q
            ? `No products match “${q}”${cats.length ? ' in the ticked categories' : ''}.`
            : 'No products in the ticked categories.';

        document.querySelectorAll('[data-list-q]').forEach(input => { input.value = q; });
        const setQ = url => { q ? url.searchParams.set('q', q) : url.searchParams.delete('q'); return url; };
        document.querySelectorAll('a[data-tab-type]').forEach(a => { a.href = setQ(new URL(a.href)).href; });
        const here = setQ(new URL(location.href));
        cats.length ? here.searchParams.set('cats', cats.join(',')) : here.searchParams.delete('cats');
        history.replaceState(null, '', here.href);
    }
    box.addEventListener('input', apply);
    boxes.forEach(b => b.addEventListener('change', apply));
    if (clear) clear.addEventListener('click', () => { boxes.forEach(b => { b.checked = false; }); apply(); });
    // Enter stays on the page (the list is already filtered)
    box.form.addEventListener('submit', e => { if (document.activeElement === box) { e.preventDefault(); apply(); } });
    // "/" jumps to the search box, Esc clears it
    document.addEventListener('keydown', e => {
        if (e.key === '/' && !e.target.closest('input, textarea, select, [contenteditable]')) { e.preventDefault(); box.focus(); box.select(); }
    });
    box.addEventListener('keydown', e => { if (e.key === 'Escape' && box.value) { box.value = ''; apply(); } });
    if (box.value || boxes.some(b => b.checked)) apply();
})();

// All tab: PCs / Laptops / Accessories fold up behind their headers. Which ones are open is remembered in this browser
(() => {
    const KEY = 'pixelcraft-admin-open-sections';
    let saved = [];
    try { saved = JSON.parse(localStorage.getItem(KEY)) || []; } catch (e) {}
    const sections = [...document.querySelectorAll('tbody[data-group]')];

    function setOpen(tb, open) {
        tb.classList.toggle('is-collapsed', !open);
        tb.querySelector('[data-section-toggle]').setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    function remember() {
        try { localStorage.setItem(KEY, JSON.stringify(sections.filter(tb => !tb.classList.contains('is-collapsed')).map(tb => tb.dataset.group))); } catch (e) {}
    }
    sections.forEach(tb => {
        if (saved.includes(tb.dataset.group)) setOpen(tb, true);
        tb.querySelector('[data-section-toggle]').addEventListener('click', () => {
            setOpen(tb, tb.classList.contains('is-collapsed'));
            remember();
        });
    });

    // Back from a save, delete or Live toggle (products.php#p-12): open that product's section and scroll to it
    const target = location.hash.startsWith('#p-') && document.getElementById(location.hash.slice(1));
    const section = target && target.closest('tbody[data-group]');
    if (section && section.classList.contains('is-collapsed')) {
        setOpen(section, true);
        remember();
        target.scrollIntoView({ block: 'center' });
    }
})();
</script>
<?php admin_close(); ?>

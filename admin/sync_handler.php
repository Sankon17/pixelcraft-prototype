<?php
// Price Sync: reads variant names and prices from the Google Sheet in includes/google_config.php and updates
// product_variants (price, plus JCL 1/2/3 yr worked out from it), then sets products.price to each PC's cheapest variant.
// Rows that aren't PC variants (laptops, chairs, accessories) update products.price when the name matches a product exactly.
// Called by sync.php with fetch(); always answers with JSON.
session_start();
require_once '../includes/db.php';
require_once '../includes/fps_data.php'; // pc_key()

header('Content-Type: application/json; charset=utf-8');

function sync_fail(string $message, int $status = 400): never
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

if (($_SESSION['admin_logged_in'] ?? false) !== true) {
    sync_fail('Your session has expired. Please log in again.', 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sync_fail('Use the Sync button on the Price Sync page.', 405);
}
if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    sync_fail('Session expired. Refresh the page and try again.', 403);
}
// Not in git (it holds the API key), so a fresh copy of the site won't have it
if (!is_file('../includes/google_config.php')) {
    sync_fail('Google Sheets is not set up: includes/google_config.php is missing.', 500);
}
require_once '../includes/google_config.php';

// ── Helpers ─────────────────────────────────────────────────────────
// "PC PIXEL BLAZE (16GB/512GB) FULL SET RTX3060" → name, ram, storage, set_type, gpu_variant
function parse_variant_name(string $name): ?array
{
    if (!preg_match('/^PC\s+PIXEL\s+(.+?)\s*\(\s*(\d+\s*GB)\s*\/\s*(\d+\s*[GT]B)\s*\)\s*(CPU\s+ONLY|FULL\s+SET)\s*(.*)$/i', trim($name), $m)) {
        return null;
    }
    // "(TC)" = tower cooler (Nitro), not a GPU option
    $gpu = trim(str_ireplace('(TC)', '', $m[5]));
    return [
        'key'      => pc_key('Pixel ' . $m[1]),
        'ram'      => strtoupper(str_replace(' ', '', $m[2])),
        'storage'  => strtoupper(str_replace(' ', '', $m[3])),
        'set_type' => stripos($m[4], 'FULL') === 0 ? 'full_set' : 'cpu_only',
        'gpu'      => $gpu === '' ? null : $gpu,
    ];
}

// Same key for the sheet row and the DB row: ignores case and spaces ("RTX 5070 Ti" = "RTX5070Ti")
function variant_key(int $product_id, string $ram, string $storage, string $set_type, ?string $gpu): string
{
    $norm = fn(?string $s) => strtoupper(str_replace(' ', '', (string)$s));
    return implode('|', [$product_id, $norm($ram), $norm($storage), $set_type, $norm($gpu)]);
}

// The same words in any order and case: "PRINTER CANON" and "Canon Printer" both give "canon printer"
function word_key(string $name): string
{
    $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY);
    sort($words);
    return implode(' ', $words);
}

// 1759, "1759", "RM 1,759.00" → 1759.0; anything else → null
function sheet_number(mixed $v): ?float
{
    if (is_string($v)) {
        $v = str_replace([',', ' '], '', preg_replace('/^RM/i', '', trim($v)));
    }
    return is_numeric($v) ? (float)$v : null;
}

// JCL instalment: flat 1.25% a month on the full price, split over the months (RM1,759 → RM70.85 × 36).
// Same formula as jcl_monthly() in admin/products.php
function jcl_monthly(float $price, int $months): float
{
    return round($price * (1 + 0.0125 * $months) / $months, 2);
}

// Every row of the sheet range, header included; fails with a readable message
function fetch_sheet_rows(): array
{
    // UNFORMATTED_VALUE: prices come back as numbers, not "RM 1,759.00"
    $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode(GSHEET_ID) . '/values/' . rawurlencode(GSHEET_RANGE)
         . '?valueRenderOption=UNFORMATTED_VALUE&key=' . rawurlencode(GSHEET_API_KEY);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curl_error = curl_error($ch);

    if ($body === false) {
        sync_fail('Could not reach Google Sheets, nothing was changed. (' . $curl_error . ')', 502);
    }
    $data = json_decode($body, true);
    if ($status !== 200) {
        $why = $data['error']['message'] ?? 'HTTP ' . $status;
        $hint = match ($status) {
            403 => ' Check the sheet is shared as "Anyone with the link can view" and the API key is allowed to use the Sheets API.',
            404 => ' Check the sheet ID in includes/google_config.php.',
            400 => ' Check the sheet ID and range in includes/google_config.php.',
            default => '',
        };
        sync_fail('Google Sheets refused the request, nothing was changed. (' . $why . ')' . $hint, 502);
    }
    if (!is_array($data)) {
        sync_fail('Unexpected reply from Google Sheets, nothing was changed.', 502);
    }
    return $data['values'] ?? []; // no "values" at all when the range is empty
}

// ── Read and sync ───────────────────────────────────────────────────
$rows = fetch_sheet_rows();
if (count($rows) < 2) {
    sync_fail('The sheet has no price rows under the header (' . GSHEET_RANGE . ').');
}

try {
    // PCs that have variants, by short name ("pixel blaze plus" → 33)
    $products = [];
    foreach ($pdo->query('SELECT DISTINCT p.id, p.name FROM products p JOIN product_variants v ON v.product_id = p.id') as $p) {
        $products[pc_key($p['name'])] = (int)$p['id'];
    }

    // Every variant, grouped by key (a key can hold more than one row, e.g. colour options)
    $variants = [];
    foreach ($pdo->query('SELECT id, product_id, ram, storage, set_type, gpu_variant, price FROM product_variants') as $v) {
        $variants[variant_key((int)$v['product_id'], $v['ram'], $v['storage'], $v['set_type'], $v['gpu_variant'])][] = $v;
    }

    // Every product by exact name, ignoring case, for rows that aren't PC variants
    $by_name = [];
    $by_words = []; // same words, any order: the fallback when the exact name isn't found
    foreach ($pdo->query('SELECT p.id, p.name, p.price, (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id) AS variant_count FROM products p') as $p) {
        $by_name[mb_strtolower(trim($p['name']))][] = $p;
        $by_words[word_key($p['name'])][] = $p;
    }

    $update = $pdo->prepare('UPDATE product_variants SET price = ?, jcl_1yr = ?, jcl_2yr = ?, jcl_3yr = ? WHERE id = ?');
    $update_product = $pdo->prepare('UPDATE products SET price = ? WHERE id = ?');
    $log = [];
    $counts = ['updated' => 0, 'changed' => 0, 'skipped' => 0, 'not_found' => 0];
    $touched = [];

    $pdo->beginTransaction();

    // rows[0] is the header; sheet row number = index + 1
    foreach (array_slice($rows, 1, null, true) as $i => $cells) {
        $name  = trim((string)($cells[0] ?? ''));
        $price = sheet_number($cells[1] ?? null);
       if ($name === '' && $price === null) {
            continue; // blank row
        }

        // Category title rows ("LAPTOP", "CHAIR", "ACCESSORIES"): a name with no price that isn't a
        // product or a PC variant. Ignore them so they don't show up as skipped rows.
        if ($price === null
            && !parse_variant_name($name)
            && !isset($by_name[mb_strtolower($name)])
            && !isset($by_words[word_key($name)])) {
            continue;
        }

        $entry = ['row' => $i + 1, 'name' => $name, 'old' => null, 'new' => $price];

        if ($name === '' || $price === null || $price <= 0) {
            $entry += ['status' => 'skipped', 'reason' => 'Missing name or price'];
        } elseif (!$parsed = parse_variant_name($name)) {
            // Not a PC variant: a laptop, chair or accessory named exactly as in Products
            // Exact name first; else the same words in another order, if that is one product only ("PRINTER CANON" = "Canon Printer")
            $found = $by_name[mb_strtolower($name)] ?? [];
            if (!$found && count($by_words[word_key($name)] ?? []) === 1) {
                $found = $by_words[word_key($name)];
            }
            if (!$found) {
                $entry += ['status' => 'not_found', 'reason' => 'No PC variant or product with this name'];
            } elseif (array_filter($found, fn($p) => $p['variant_count'] > 0)) {
                // The store charges each option's own price, so products.price alone would change nothing for customers
                $entry['old'] = (float)$found[0]['price'];
                $entry += ['status' => 'skipped', 'reason' => 'This product has options; set their prices on the Variants page'];
            } else {
                foreach ($found as $p) {
                    $update_product->execute([$price, $p['id']]);
                }
                $entry['old'] = (float)$found[0]['price'];
                $entry['status'] = 'updated';
                $entry['changed'] = abs($entry['old'] - $price) >= 0.005;
                $counts['changed'] += $entry['changed'] ? 1 : 0;
            }
        } elseif (!isset($products[$parsed['key']])) {
            $entry += ['status' => 'not_found', 'reason' => 'No PC called "' . $parsed['key'] . '" with variants'];
        } else {
            $pid = $products[$parsed['key']];
            $matches = $variants[variant_key($pid, $parsed['ram'], $parsed['storage'], $parsed['set_type'], $parsed['gpu'])] ?? [];
            if (!$matches) {
                $entry += ['status' => 'not_found', 'reason' => 'No matching RAM / SSD / set / GPU option for this PC'];
            } else {
                foreach ($matches as $v) {
                    $update->execute([$price, jcl_monthly($price, 12), jcl_monthly($price, 24), jcl_monthly($price, 36), $v['id']]);
                }
                $entry['old'] = (float)$matches[0]['price'];
                $entry['status'] = 'updated';
                $entry['changed'] = abs($entry['old'] - $price) >= 0.005;
                $counts['changed'] += $entry['changed'] ? 1 : 0;
                $touched[$pid] = true;
            }
        }

        $counts[$entry['status']]++;
        $log[] = $entry;
    }

    // "From" price shown on listings = cheapest variant
    if ($touched) {
        $ids = array_keys($touched);
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE products p
            JOIN (SELECT product_id, MIN(price) AS min_price FROM product_variants WHERE product_id IN ($in) GROUP BY product_id) v
              ON v.product_id = p.id
            SET p.price = v.min_price")->execute($ids);
    }

    $pdo->commit();

    // History for the dashboard (database/price_sync_log.sql). Saving it must never undo a sync that already worked
    try {
        $pdo->prepare('INSERT INTO price_sync_log (filename, admin, rows_updated, prices_changed, skipped, not_found, products) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute(['Google Sheets (' . GSHEET_RANGE . ')', $_SESSION['admin_username'] ?? null, $counts['updated'], $counts['changed'], $counts['skipped'], $counts['not_found'], count($touched)]);
    } catch (PDOException $e) {
        error_log('Price sync history not saved: ' . $e->getMessage());
    }

    // Results table order: price changed, skipped, not found, then unchanged; sheet order within each (usort is stable)
    $rank = fn(array $e) => match ($e['status']) {
        'updated'   => empty($e['changed']) ? 3 : 0,
        'skipped'   => 1,
        'not_found' => 2,
    };
    usort($log, fn($a, $b) => $rank($a) <=> $rank($b));

    echo json_encode($counts + ['products' => count($touched), 'log' => $log]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Price sync failed: ' . $e->getMessage());
    sync_fail('Sync failed, nothing was changed. (' . $e->getMessage() . ')', 500);
}

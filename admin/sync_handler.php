<?php
// Price Sync: reads the SUMMARY (DB Import) sheet of PixelCraft_PriceSync_Reference.xlsx and updates
// product_variants (price + JCL 1/2/3 yr), then sets products.price to each PC's cheapest variant.
// Called by sync.php with fetch(); always answers with JSON.
session_start();
require_once '../includes/db.php';
require_once '../includes/fps_data.php'; // pc_key()
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

const SYNC_SHEET    = 'SUMMARY (DB Import)';
const SYNC_MAX_SIZE = 5 * 1024 * 1024;

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
// PhpSpreadsheet needs PHP's zip extension to open .xlsx files
if (!class_exists('ZipArchive')) {
    sync_fail('The server cannot open .xlsx files yet: PHP\'s zip extension is off. Turn on extension=zip in C:\xampp\php\php.ini, then Stop and Start Apache in the XAMPP Control Panel.', 500);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sync_fail('Use the upload form on the Price Sync page.', 405);
}
// A request bigger than post_max_size arrives with no fields at all (not even the token)
if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    sync_fail('The file is too large. Maximum size is 5 MB.');
}
if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    sync_fail('Session expired. Refresh the page and try again.', 403);
}

// ── Validate the upload ─────────────────────────────────────────────
$file = $_FILES['file'] ?? null;
if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
    sync_fail('Please choose an .xlsx file to upload.');
}
if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $file['size'] > SYNC_MAX_SIZE) {
    sync_fail('The file is too large. Maximum size is 5 MB.');
}
if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
    sync_fail('The upload failed. Please try again.');
}
if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
    sync_fail('Only .xlsx files are accepted.');
}
// .xlsx is a zip archive, so some systems report it as application/zip
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
if (!in_array($mime, ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'], true)) {
    sync_fail('That file is not an Excel .xlsx workbook.');
}

$tmp = tempnam(sys_get_temp_dir(), 'pcsync');
// Runs on every exit path (sync_fail() exits, which skips finally blocks)
register_shutdown_function(fn() => @unlink($tmp));
if (!move_uploaded_file($file['tmp_name'], $tmp)) {
    sync_fail('Could not save the uploaded file.', 500);
}

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

function cell_number($sheet, string $cell): ?float
{
    $v = $sheet->getCell($cell)->getCalculatedValue();
    return is_numeric($v) ? (float)$v : null;
}

// ── Read and sync ───────────────────────────────────────────────────
try {
    $reader = IOFactory::createReader('Xlsx');
    $reader->setReadDataOnly(true);
    try {
        $sheet = $reader->load($tmp)->getSheetByName(SYNC_SHEET);
    } catch (Throwable $e) {
        sync_fail('Could not read the file. Is it a valid .xlsx workbook?');
    }
    if (!$sheet) {
        sync_fail('Sheet "' . SYNC_SHEET . '" was not found in this workbook.');
    }

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

    $update = $pdo->prepare('UPDATE product_variants SET price = ?, jcl_1yr = ?, jcl_2yr = ?, jcl_3yr = ? WHERE id = ?');
    $log = [];
    $counts = ['updated' => 0, 'changed' => 0, 'skipped' => 0, 'not_found' => 0];
    $touched = [];

    $pdo->beginTransaction();

    $last = $sheet->getHighestRow();
    for ($row = 3; $row <= $last; $row++) {
        $name  = trim((string)$sheet->getCell("B$row")->getValue());
        $price = cell_number($sheet, "C$row");
        if ($name === '' && $price === null) {
            continue; // blank row
        }

        $entry = ['row' => $row, 'name' => $name, 'old' => null, 'new' => $price];
        $jcl = [cell_number($sheet, "D$row"), cell_number($sheet, "E$row"), cell_number($sheet, "F$row")];

        if ($name === '' || $price === null || $price <= 0) {
            $entry += ['status' => 'skipped', 'reason' => 'Missing name or price'];
        } elseif (in_array(null, $jcl, true)) {
            $entry += ['status' => 'skipped', 'reason' => 'JCL 1/2/3 yr must be numbers'];
        } elseif (!$parsed = parse_variant_name($name)) {
            $entry += ['status' => 'skipped', 'reason' => 'Name not in the "PC PIXEL NAME (RAM/SSD) SET GPU" format'];
        } elseif (!isset($products[$parsed['key']])) {
            $entry += ['status' => 'not_found', 'reason' => 'No PC called "' . $parsed['key'] . '" with variants'];
        } else {
            $pid = $products[$parsed['key']];
            $matches = $variants[variant_key($pid, $parsed['ram'], $parsed['storage'], $parsed['set_type'], $parsed['gpu'])] ?? [];
            if (!$matches) {
                $entry += ['status' => 'not_found', 'reason' => 'No matching RAM / SSD / set / GPU option for this PC'];
            } else {
                foreach ($matches as $v) {
                    $update->execute([$price, round($jcl[0], 2), round($jcl[1], 2), round($jcl[2], 2), $v['id']]);
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

    echo json_encode($counts + ['products' => count($touched), 'log' => $log]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Price sync failed: ' . $e->getMessage());
    sync_fail('Sync failed, nothing was changed. (' . $e->getMessage() . ')', 500);
}

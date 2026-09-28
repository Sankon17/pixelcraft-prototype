<?php
session_start();
require_once '../includes/functions.php';
require_once '../includes/db.php';

if (($_SESSION['admin_logged_in'] ?? false) !== true) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$username = $_SESSION['admin_username'] ?? 'admin';
$msg = '';
$err = '';

// ── AJAX / POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Session expired. Reload the page.']);
        exit;
    }
    $action = $_POST['action'] ?? '';

    // Update a single variant
    if ($action === 'update_variant') {
        $id      = (int)$_POST['id'];
        $price   = (float)$_POST['price'];
        $jcl1    = (float)$_POST['jcl_1yr'];
        $jcl2    = (float)$_POST['jcl_2yr'];
        $jcl3    = (float)$_POST['jcl_3yr'];
        $stock   = (int)$_POST['stock'];
        $ram     = trim($_POST['ram']);
        $storage = trim($_POST['storage']);
        $set_type = in_array($_POST['set_type'], ['cpu_only','full_set']) ? $_POST['set_type'] : 'cpu_only';
        $gpu     = trim($_POST['gpu_variant']) ?: null;

        $stmt = $pdo->prepare(
            'UPDATE product_variants
             SET price=?, jcl_1yr=?, jcl_2yr=?, jcl_3yr=?, stock=?,
                 ram=?, storage=?, set_type=?, gpu_variant=?
             WHERE id=?'
        );
        $stmt->execute([$price, $jcl1, $jcl2, $jcl3, $stock, $ram, $storage, $set_type, $gpu, $id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // Delete a variant
    if ($action === 'delete_variant') {
        $id = (int)$_POST['id'];
        $pdo->prepare('DELETE FROM product_variants WHERE id=?')->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // Add a variant
    if ($action === 'add_variant') {
        $product_id = (int)$_POST['product_id'];
        $ram        = trim($_POST['ram']);
        $storage    = trim($_POST['storage']);
        $set_type   = in_array($_POST['set_type'], ['cpu_only','full_set']) ? $_POST['set_type'] : 'cpu_only';
        $gpu        = trim($_POST['gpu_variant']) ?: null;
        $price      = (float)$_POST['price'];
        $jcl1       = (float)$_POST['jcl_1yr'];
        $jcl2       = (float)$_POST['jcl_2yr'];
        $jcl3       = (float)$_POST['jcl_3yr'];
        $stock      = (int)($_POST['stock'] ?? 10);

        $stmt = $pdo->prepare(
            'INSERT INTO product_variants
             (product_id, ram, storage, set_type, gpu_variant, price, jcl_1yr, jcl_2yr, jcl_3yr, stock)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([$product_id, $ram, $storage, $set_type, $gpu, $price, $jcl1, $jcl2, $jcl3, $stock]);
        echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
        exit;
    }

    // Update product name (the page sends an empty pc_type, which used to wipe it)
    if ($action === 'update_product') {
        $id      = (int)$_POST['id'];
        $name    = trim($_POST['name']);
        if ($name === '') {
            echo json_encode(['ok' => false]);
            exit;
        }
        $pdo->prepare('UPDATE products SET name=? WHERE id=?')->execute([$name, $id]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ── Load products with variant count ─────────────────────────────────────────
$products = $pdo->query(
    'SELECT p.id, p.name, p.pc_type, p.category_id, p.image,
            COUNT(pv.id) AS variant_count,
            MIN(pv.price) AS min_price,
            MAX(pv.price) AS max_price
     FROM products p
     LEFT JOIN product_variants pv ON p.id = pv.product_id
     WHERE p.category_id IN (1,2,3)
     GROUP BY p.id ORDER BY p.category_id, p.id'
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Variants — PixelCraft Admin</title>
    <link rel="stylesheet" href="<?= asset('../', 'assets/css/style.css') ?>">
    <style>
        /* ── Admin products layout ── */
        .admin-products { max-width: 1100px; margin: 0 auto; padding: 32px 16px 80px; }
        .ap-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 28px; flex-wrap: wrap; gap: 12px; }
        .ap-header h1 { font-size: 24px; font-weight: 700; }
        .ap-header h1 span { color: #cc0000; }

        /* Product card */
        .pc-card { background: #111; border: 1px solid #222; border-radius: 10px; margin-bottom: 12px; overflow: hidden; }
        .pc-card-head {
            display: flex; align-items: center; gap: 14px;
            padding: 14px 18px; cursor: pointer;
            transition: background 0.15s;
        }
        .pc-card-head:hover { background: #181818; }
        .pc-card-head.open { background: #161616; }
        .pc-badge {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            padding: 2px 8px; border-radius: 4px; letter-spacing: 0.5px;
        }
        .pc-badge.office { background: #1a3a5c; color: #5ba8ff; }
        .pc-badge.gaming { background: #3a1a1a; color: #ff6b6b; }
        .pc-name { font-weight: 600; font-size: 15px; flex: 1; }
        .pc-meta { font-size: 13px; color: #777; white-space: nowrap; }
        .pc-chevron { color: #555; font-size: 12px; transition: transform 0.2s; }
        .pc-card-head.open .pc-chevron { transform: rotate(180deg); }

        /* Variants table */
        .pc-variants { display: none; border-top: 1px solid #1e1e1e; padding: 0 18px 18px; }
        .pc-variants.open { display: block; }

        .variants-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 14px; }
        .variants-table th {
            text-align: left; padding: 7px 10px; font-size: 11px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.5px; color: #888;
            border-bottom: 1px solid #222;
        }
        .variants-table td { padding: 8px 10px; border-bottom: 1px solid #1a1a1a; vertical-align: middle; }
        .variants-table tr:last-child td { border-bottom: none; }
        .variants-table tr:hover td { background: #141414; }

        /* Inline edit inputs */
        .ve { background: #0d0d0d; border: 1px solid #2a2a2a; border-radius: 4px; color: #eee;
              padding: 5px 8px; font-size: 13px; width: 100%; min-width: 60px; }
        .ve:focus { border-color: #cc0000; outline: none; box-shadow: 0 0 0 2px rgba(204,0,0,0.15); }
        .ve-price { max-width: 90px; }
        .ve-jcl   { max-width: 80px; }
        .ve-stock  { max-width: 55px; }
        .ve-ram    { max-width: 70px; }
        .ve-storage{ max-width: 75px; }
        select.ve  { cursor: pointer; }

        /* Row action buttons */
        .btn-save { background: #1a3a1a; border: 1px solid #2a5a2a; color: #6fcf6f;
                    padding: 5px 12px; border-radius: 4px; font-size: 12px; cursor: pointer; white-space: nowrap; }
        .btn-save:hover { background: #224a22; }
        .btn-save.saved { background: #1e4d1e; color: #4caf50; }
        .btn-del { background: none; border: 1px solid #3a1a1a; color: #cc4444;
                   padding: 5px 9px; border-radius: 4px; font-size: 12px; cursor: pointer; }
        .btn-del:hover { background: #3a1a1a; }

        /* Add variant row */
        .add-variant-row { margin-top: 14px; background: #0d0d0d; border: 1px dashed #2a2a2a;
                           border-radius: 8px; padding: 14px 16px; }
        .add-variant-row h4 { font-size: 12px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; }
        .add-variant-fields { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; }
        .avf-group { display: flex; flex-direction: column; gap: 4px; }
        .avf-group label { font-size: 11px; color: #777; }
        .btn-add { background: #cc0000; border: none; color: #fff; padding: 7px 16px;
                   border-radius: 5px; font-size: 13px; font-weight: 600; cursor: pointer; align-self: flex-end; }
        .btn-add:hover { background: #aa0000; }

        /* Toast */
        #toast { position: fixed; bottom: 24px; right: 24px; background: #1e3a1e; border: 1px solid #2a5a2a;
                 color: #6fcf6f; padding: 12px 20px; border-radius: 8px; font-size: 14px;
                 opacity: 0; pointer-events: none; transition: opacity 0.3s; z-index: 9999; }
        #toast.err { background: #3a1a1a; border-color: #5a2a2a; color: #ff6b6b; }
        #toast.show { opacity: 1; }

        /* Edit product name inline */
        .pc-name-edit { display: none; background: #0d0d0d; border: 1px solid #333; border-radius: 4px;
                        color: #fff; padding: 4px 8px; font-size: 14px; width: 180px; }
        .pc-name-edit:focus { border-color: #cc0000; outline: none; }
        .btn-edit-name { background: none; border: none; color: #555; cursor: pointer; font-size: 12px; padding: 2px 6px; }
        .btn-edit-name:hover { color: #aaa; }
    </style>
</head>
<body>

<header class="site-header">
    <nav class="nav">
        <a href="index.php" class="logo"><?= logo_img('../') ?> <small class="admin-tag">Admin</small></a>
        <div class="admin-user">
            <a href="products.php" class="btn btn-outline btn-sm" style="margin-right:8px">← Products</a>
            <span>👤 <?= e($username) ?></span>
            <form method="POST" action="index.php" style="display:inline">
                <button type="submit" name="logout" value="1" class="btn btn-outline btn-sm">Logout</button>
            </form>
        </div>
    </nav>
</header>

<div class="admin-products">
    <div class="ap-header">
        <h1>🧩 <span>Variants</span> Management</h1>
        <span style="color:#777;font-size:13px"><?= count($products) ?> products · Click a row to manage variants</span>
    </div>

    <?php foreach ($products as $p): ?>
    <?php $badge = in_array($p['pc_type'], ['office']) ? 'office' : 'gaming'; ?>
    <div class="pc-card" data-id="<?= $p['id'] ?>">
        <div class="pc-card-head" onclick="toggleCard(this)">
            <span class="pc-badge <?= $badge ?>"><?= e($p['pc_type'] ?? 'gaming') ?></span>
            <span class="pc-name" id="name-display-<?= $p['id'] ?>"><?= e($p['name']) ?></span>
            <input class="pc-name-edit" id="name-input-<?= $p['id'] ?>"
                   value="<?= e($p['name']) ?>" data-pid="<?= $p['id'] ?>"
                   onclick="event.stopPropagation()"
                   onblur="saveName(<?= $p['id'] ?>)"
                   onkeydown="if(event.key==='Enter')saveName(<?= $p['id'] ?>)">
            <button class="btn-edit-name" title="Edit name"
                    onclick="event.stopPropagation();editName(<?= $p['id'] ?>)">✏️</button>
            <span class="pc-meta"><?= $p['variant_count'] ?> variants ·
                RM <?= number_format($p['min_price'], 0) ?>
                <?= $p['min_price'] != $p['max_price'] ? '– RM ' . number_format($p['max_price'], 0) : '' ?>
            </span>
            <span class="pc-chevron">▼</span>
        </div>
        <div class="pc-variants" id="variants-<?= $p['id'] ?>">
            <?php
            $variants = $pdo->prepare(
                'SELECT * FROM product_variants WHERE product_id=?
                 ORDER BY gpu_variant, set_type, ram, storage'
            );
            $variants->execute([$p['id']]);
            $vrows = $variants->fetchAll();
            ?>
            <table class="variants-table">
                <thead>
                    <tr>
                        <th>RAM</th>
                        <th>Storage</th>
                        <th>Type</th>
                        <th>GPU</th>
                        <th>Price (RM)</th>
                        <th>JCL 1yr</th>
                        <th>JCL 2yr</th>
                        <th>JCL 3yr</th>
                        <th>Stock</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="tbody-<?= $p['id'] ?>">
                <?php foreach ($vrows as $v): ?>
                <tr id="vrow-<?= $v['id'] ?>">
                    <td><input class="ve ve-ram" value="<?= e($v['ram']) ?>" data-field="ram"></td>
                    <td><input class="ve ve-storage" value="<?= e($v['storage']) ?>" data-field="storage"></td>
                    <td>
                        <select class="ve" data-field="set_type">
                            <option value="cpu_only"  <?= $v['set_type']==='cpu_only'  ? 'selected':'' ?>>CPU Only</option>
                            <option value="full_set"  <?= $v['set_type']==='full_set'  ? 'selected':'' ?>>Full Set</option>
                        </select>
                    </td>
                    <td><input class="ve" style="max-width:100px" value="<?= e($v['gpu_variant'] ?? '') ?>" data-field="gpu_variant" placeholder="—"></td>
                    <td><input class="ve ve-price" type="number" step="0.01" value="<?= $v['price'] ?>" data-field="price"></td>
                    <td><input class="ve ve-jcl" type="number" step="0.01" value="<?= $v['jcl_1yr'] ?>" data-field="jcl_1yr"></td>
                    <td><input class="ve ve-jcl" type="number" step="0.01" value="<?= $v['jcl_2yr'] ?>" data-field="jcl_2yr"></td>
                    <td><input class="ve ve-jcl" type="number" step="0.01" value="<?= $v['jcl_3yr'] ?>" data-field="jcl_3yr"></td>
                    <td><input class="ve ve-stock" type="number" value="<?= $v['stock'] ?>" data-field="stock"></td>
                    <td style="white-space:nowrap;display:flex;gap:6px">
                        <button class="btn-save" onclick="saveVariant(<?= $v['id'] ?>, this)">Save</button>
                        <button class="btn-del" onclick="deleteVariant(<?= $v['id'] ?>, <?= $p['id'] ?>)" title="Delete">✕</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Add variant -->
            <div class="add-variant-row">
                <h4>+ Add Variant</h4>
                <div class="add-variant-fields">
                    <div class="avf-group"><label>RAM</label><input class="ve ve-ram" id="add-ram-<?= $p['id'] ?>" placeholder="16GB"></div>
                    <div class="avf-group"><label>Storage</label><input class="ve ve-storage" id="add-storage-<?= $p['id'] ?>" placeholder="512GB"></div>
                    <div class="avf-group"><label>Type</label>
                        <select class="ve" id="add-set-<?= $p['id'] ?>">
                            <option value="cpu_only">CPU Only</option>
                            <option value="full_set">Full Set</option>
                        </select>
                    </div>
                    <div class="avf-group"><label>GPU</label><input class="ve" style="max-width:100px" id="add-gpu-<?= $p['id'] ?>" placeholder="RTX5060"></div>
                    <div class="avf-group"><label>Price</label><input class="ve ve-price" type="number" step="0.01" id="add-price-<?= $p['id'] ?>" placeholder="0.00"></div>
                    <div class="avf-group"><label>JCL 1yr</label><input class="ve ve-jcl" type="number" step="0.01" id="add-jcl1-<?= $p['id'] ?>" placeholder="0.00"></div>
                    <div class="avf-group"><label>JCL 2yr</label><input class="ve ve-jcl" type="number" step="0.01" id="add-jcl2-<?= $p['id'] ?>" placeholder="0.00"></div>
                    <div class="avf-group"><label>JCL 3yr</label><input class="ve ve-jcl" type="number" step="0.01" id="add-jcl3-<?= $p['id'] ?>" placeholder="0.00"></div>
                    <div class="avf-group"><label>Stock</label><input class="ve ve-stock" type="number" id="add-stock-<?= $p['id'] ?>" value="10"></div>
                    <button class="btn-add" onclick="addVariant(<?= $p['id'] ?>)">Add</button>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div id="toast"></div>

<script>
const CSRF = <?= json_encode($_SESSION['csrf']) ?>;
function withCsrf(data) {
    const params = new URLSearchParams(data);
    params.append('csrf', CSRF);
    return params;
}

function toggleCard(head) {
    head.classList.toggle('open');
    const panel = head.nextElementSibling;
    panel.classList.toggle('open');
}

function showToast(msg, isErr = false) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'show' + (isErr ? ' err' : '');
    clearTimeout(t._timer);
    t._timer = setTimeout(() => t.className = '', 2500);
}

async function saveVariant(id, btn) {
    const row = document.getElementById('vrow-' + id);
    const data = { action: 'update_variant', id };
    row.querySelectorAll('[data-field]').forEach(el => {
        data[el.dataset.field] = el.value;
    });
    const res = await fetch('variants.php', { method: 'POST', body: withCsrf(data) });
    const json = await res.json();
    if (json.ok) {
        btn.textContent = '✓ Saved';
        btn.classList.add('saved');
        setTimeout(() => { btn.textContent = 'Save'; btn.classList.remove('saved'); }, 1800);
        showToast('Variant saved ✓');
    } else {
        showToast('Save failed', true);
    }
}

async function deleteVariant(id, pid) {
    if (!confirm('Delete this variant?')) return;
    const res = await fetch('variants.php', {
        method: 'POST',
        body: withCsrf({ action: 'delete_variant', id })
    });
    const json = await res.json();
    if (json.ok) {
        document.getElementById('vrow-' + id).remove();
        showToast('Variant deleted');
    } else {
        showToast('Delete failed', true);
    }
}

async function addVariant(pid) {
    const data = {
        action:      'add_variant',
        product_id:  pid,
        ram:         document.getElementById('add-ram-' + pid).value,
        storage:     document.getElementById('add-storage-' + pid).value,
        set_type:    document.getElementById('add-set-' + pid).value,
        gpu_variant: document.getElementById('add-gpu-' + pid).value,
        price:       document.getElementById('add-price-' + pid).value,
        jcl_1yr:     document.getElementById('add-jcl1-' + pid).value,
        jcl_2yr:     document.getElementById('add-jcl2-' + pid).value,
        jcl_3yr:     document.getElementById('add-jcl3-' + pid).value,
        stock:       document.getElementById('add-stock-' + pid).value,
    };
    if (!data.ram || !data.storage || !data.price) {
        showToast('RAM, Storage and Price are required', true); return;
    }
    const res = await fetch('variants.php', { method: 'POST', body: withCsrf(data) });
    const json = await res.json();
    if (json.ok) {
        showToast('Variant added — reload to see it');
        // Clear fields
        ['ram','storage','gpu','price','jcl1','jcl2','jcl3'].forEach(f => {
            const el = document.getElementById('add-' + f + '-' + pid);
            if (el) el.value = '';
        });
        document.getElementById('add-stock-' + pid).value = '10';
    } else {
        showToast('Add failed', true);
    }
}

function editName(pid) {
    document.getElementById('name-display-' + pid).style.display = 'none';
    const inp = document.getElementById('name-input-' + pid);
    inp.style.display = 'inline-block';
    inp.focus();
}

async function saveName(pid) {
    const inp = document.getElementById('name-input-' + pid);
    const display = document.getElementById('name-display-' + pid);
    const name = inp.value.trim();
    if (!name) { inp.style.display='none'; display.style.display=''; return; }
    const res = await fetch('variants.php', {
        method: 'POST',
        body: withCsrf({ action: 'update_product', id: pid, name, pc_type: '' })
    });
    const json = await res.json();
    if (json.ok) {
        display.textContent = name;
        showToast('Name updated ✓');
    }
    inp.style.display = 'none';
    display.style.display = '';
}

// variants.php?id=31 (from the Products page) opens that PC straight away
const openId = new URLSearchParams(location.search).get('id');
const openCard = openId && document.querySelector('.pc-card[data-id="' + CSS.escape(openId) + '"]');
if (openCard) {
    toggleCard(openCard.querySelector('.pc-card-head'));
    openCard.scrollIntoView({ block: 'start' });
}
</script>

</body>
</html>

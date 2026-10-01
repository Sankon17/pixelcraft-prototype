<?php
// Orders: statuses and saving an order. Used by checkout (pages/order_handler.php) and the admin
// (admin/orders.php, order_detail.php, order_update.php, order_create.php).

// orders.status value => label, in the order an order moves through them (Shoppego-style fulfilment)
function order_statuses(): array
{
    return [
        'pending'      => 'Awaiting processing',
        'processing'   => 'Processing',
        'packed'       => 'Packed',
        'shipped'      => 'Shipped',
        'ready_pickup' => 'Ready for pickup',
        'completed'    => 'Completed',
        'cancelled'    => 'Cancelled',
    ];
}

// Pick Up orders are never "Shipped", and Postage orders are never "Ready for pickup"
function order_statuses_for(string $delivery): array
{
    $all = order_statuses();
    unset($all[$delivery === 'Pick Up' ? 'shipped' : 'ready_pickup']);
    return $all;
}

// Saves an order and its items and takes the stock off, all or nothing.
// $order: customer_name, phone, email, address, delivery_method, payment_method (labels), status (optional).
// $lines: from cart_lines() (or built the same way): product_id, variant_id, name, detail, qty, unit_price, line_total.
// Returns ['id' => new order id], or ['short' => sentences about what isn't in stock] (nothing saved then).
function save_order(PDO $pdo, array $order, array $lines): array
{
    $pdo->beginTransaction();
    try {
        // Lock the stock rows, then check the whole order against them (two lines can share a product)
        $want_product = [];
        $want_variant = [];
        foreach ($lines as $line) {
            $want_product[$line['product_id']] = ($want_product[$line['product_id']] ?? 0) + $line['qty'];
            if ($line['variant_id']) {
                $want_variant[$line['variant_id']] = ($want_variant[$line['variant_id']] ?? 0) + $line['qty'];
            }
        }
        $lock = fn(string $table, array $ids) => $pdo->query("SELECT id, stock FROM $table WHERE id IN (" . implode(',', array_map('intval', array_keys($ids))) . ') FOR UPDATE')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        $product_stock = $lock('products', $want_product);
        $variant_stock = $want_variant ? $lock('product_variants', $want_variant) : [];

        $short = [];
        foreach ($lines as $line) {
            // The whole order against the product's overall stock, and against this variant's own stock
            $p_left = (int)($product_stock[$line['product_id']] ?? 0);
            $v_left = $line['variant_id'] ? (int)($variant_stock[$line['variant_id']] ?? 0) : PHP_INT_MAX;
            $over_p = $want_product[$line['product_id']] > $p_left;
            $over_v = $line['variant_id'] && $want_variant[$line['variant_id']] > $v_left;
            if ($over_p || $over_v) {
                $left = $over_v ? $v_left : $p_left;
                $what = $line['name'] . ($line['detail'] ? " ({$line['detail']})" : '');
                $short[] = $left > 0 ? "Only $left of $what can be ordered right now." : "$what is sold out.";
            }
        }
        if ($short) {
            $pdo->rollBack();
            return ['short' => array_values(array_unique($short))];
        }

        $pdo->prepare('INSERT INTO orders (customer_name, phone, email, address, delivery_method, payment_method, total_amount, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$order['customer_name'], $order['phone'], $order['email'], $order['address'], $order['delivery_method'],
                $order['payment_method'], round(array_sum(array_column($lines, 'line_total')), 2), $order['status'] ?? 'pending']);
        $id = (int)$pdo->lastInsertId();

        $add_item  = $pdo->prepare('INSERT INTO order_items (order_id, product_id, variant_id, product_name, variant_detail, qty, unit_price) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $less_prod = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ?');
        $less_var  = $pdo->prepare('UPDATE product_variants SET stock = stock - ? WHERE id = ?');
        foreach ($lines as $line) {
            $add_item->execute([$id, $line['product_id'], $line['variant_id'], $line['name'], $line['detail'], $line['qty'], round($line['unit_price'], 2)]);
            // A PC's own stock is its overall limit, and each variant has its own too: both go down
            $less_prod->execute([$line['qty'], $line['product_id']]);
            if ($line['variant_id']) {
                $less_var->execute([$line['qty'], $line['variant_id']]);
            }
        }
        $pdo->commit();
        return ['id' => $id];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

// ── Admin order list filters (admin/orders.php and orders_export.php share them) ──
// Only known keys and sensible values: status, q (search), payment, delivery, from / to (dates)
function orders_filter(array $src): array
{
    $date = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    return array_filter([
        'status'   => isset(order_statuses()[$src['status'] ?? '']) ? $src['status'] : '',
        'q'        => mb_substr(trim((string)($src['q'] ?? '')), 0, 100),
        'payment'  => isset(payment_methods()[$src['payment'] ?? '']) ? $src['payment'] : '',
        'delivery' => isset(delivery_methods()[$src['delivery'] ?? '']) ? $src['delivery'] : '',
        'from'     => $date($src['from'] ?? ''),
        'to'       => $date($src['to'] ?? ''),
    ], fn($v) => $v !== '');
}

function orders_url(array $f, array $change = [], string $page = 'orders.php'): string
{
    $q = array_filter(array_merge($f, $change), fn($v) => $v !== '' && $v !== null);
    return $page . ($q ? '?' . http_build_query($q) : '');
}

// WHERE for everything except the status (the tabs show a count per status for the other filters)
function orders_where(array $f, array &$args): string
{
    $sql = [];
    if (isset($f['q'])) {
        // Order number ("12" or "#00012"), name, phone (digits only also match "013-321 3141"), email
        $digits = preg_replace('/\D/', '', $f['q']);
        $sql[] = '(o.customer_name LIKE ? OR o.email LIKE ? OR o.phone LIKE ?'
            . ($digits !== '' ? " OR REPLACE(REPLACE(REPLACE(o.phone, '-', ''), ' ', ''), '+', '') LIKE ? OR o.id = ?" : '') . ')';
        array_push($args, "%{$f['q']}%", "%{$f['q']}%", "%{$f['q']}%");
        if ($digits !== '') array_push($args, "%$digits%", (int)$digits);
    }
    if (isset($f['payment']))  { $sql[] = 'o.payment_method = ?';  $args[] = payment_methods()[$f['payment']]; }
    if (isset($f['delivery'])) { $sql[] = 'o.delivery_method = ?'; $args[] = delivery_methods()[$f['delivery']]; }
    if (isset($f['from']))     { $sql[] = 'o.created_at >= ?';     $args[] = $f['from'] . ' 00:00:00'; }
    if (isset($f['to']))       { $sql[] = 'o.created_at <= ?';     $args[] = $f['to'] . ' 23:59:59'; }
    return $sql ? ' WHERE ' . implode(' AND ', $sql) : '';
}


<?php
// "Export" on admin/orders.php: the orders in the current list (same tab, search and filters) as a CSV
// spreadsheet, one row per order, that opens straight in Excel or Google Sheets.
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/cart.php';

if (($_SESSION['admin_logged_in'] ?? false) !== true) {
    header('Location: login.php');
    exit;
}

$f = orders_filter($_GET);
$args  = [];
$where = orders_where($f, $args);
if (isset($f['status'])) {
    $where .= ($where ? ' AND' : ' WHERE') . ' o.status = ?';
    $args[] = $f['status'];
}
$stmt = $pdo->prepare("SELECT o.*,
        (SELECT GROUP_CONCAT(CONCAT(i.product_name, IF(i.variant_detail IS NULL OR i.variant_detail = '', '', CONCAT(' (', i.variant_detail, ')')), ' x', i.qty) ORDER BY i.id SEPARATOR '; ')
         FROM order_items i WHERE i.order_id = o.id) AS items
    FROM orders o$where ORDER BY o.created_at DESC, o.id DESC");
$stmt->execute($args);

$statuses = order_statuses();
$name = 'pixelcraft-orders' . (isset($f['status']) ? '-' . $f['status'] : '') . '-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // so Excel reads "·" and other characters correctly
fputcsv($out, ['Order ID', 'Date', 'Status', 'Customer', 'Phone', 'Email', 'Delivery', 'Address', 'Payment', 'Items', 'Total (RM)']);
foreach ($stmt as $o) {
    // A leading = + - @ would make a spreadsheet treat the cell as a formula
    $safe = fn($v) => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : $v;
    fputcsv($out, array_map($safe, [
        order_number((int)$o['id']),
        date('Y-m-d H:i', strtotime($o['created_at'])),
        $statuses[$o['status']] ?? $o['status'],
        $o['customer_name'],
        $o['phone'],
        $o['email'],
        $o['delivery_method'],
        $o['address'],
        $o['payment_method'],
        $o['items'],
        number_format((float)$o['total_amount'], 2, '.', ''),
    ]));
}
fclose($out);

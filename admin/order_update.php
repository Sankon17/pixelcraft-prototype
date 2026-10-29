<?php
// Changes an order's status (form on admin/order_detail.php), then back to the orders list
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/cart.php';

if (($_SESSION['admin_logged_in'] ?? false) !== true) {
    header('Location: login.php');
    exit;
}

$id     = (int)($_POST['order_id'] ?? 0);
$status = (string)($_POST['status'] ?? '');
// Only the statuses that fit this order's delivery (no "Shipped" for Pick Up, no "Ready for pickup" for Postage)
$stmt = $pdo->prepare('SELECT delivery_method FROM orders WHERE id = ?');
$stmt->execute([$id]);
$delivery = $stmt->fetchColumn();
$labels = $delivery === false ? [] : order_statuses_for($delivery);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Session expired. Please try again.'];
} elseif ($delivery === false) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'That order no longer exists.'];
} elseif (!isset($labels[$status])) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Please choose a valid status for a ' . $delivery . ' order.'];
} else {
    // Cancelling puts the items back in stock; un-cancelling takes them again (includes/orders.php)
    $result = set_order_status($pdo, $id, $status);
    $units  = fn(int $n) => $n . ' item' . ($n === 1 ? '' : 's');
    if (isset($result['short'])) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Order ' . order_number($id) . ' is still Cancelled: '
            . implode('; ', $result['short']) . '. Restock first, then change the status.'];
    } else {
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Order ' . order_number($id) . ' is now ' . $labels[$status] . '.'
            . ($result['restocked'] ? ' ' . $units($result['restocked']) . ' back in stock.' : '')
            . ($result['taken'] ? ' ' . $units($result['taken']) . ' taken from stock again.' : '')];
    }
}

header('Location: orders.php');
exit;

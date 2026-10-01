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
    $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$status, $id]);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Order ' . order_number($id) . ' is now ' . $labels[$status] . '.'];
}

header('Location: orders.php');
exit;

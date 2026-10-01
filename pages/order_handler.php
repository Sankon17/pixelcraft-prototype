<?php
// Turns the checkout form into an order: checks the details, re-checks stock with the rows locked,
// saves the order and its items, takes the stock off, empties the cart and shows the confirmation.
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/cart.php';

function back_to_checkout(array $errors = [], array $notes = []): never
{
    $_SESSION['checkout_errors'] = $errors;
    $_SESSION['checkout_notes']  = $notes;
    $_SESSION['checkout_old']    = array_intersect_key($_POST, array_flip(['name', 'phone', 'email', 'delivery', 'payment', 'address', 'postcode', 'city', 'city_other', 'state']));
    header('Location: checkout.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: checkout.php');
    exit;
}
// The token from the checkout page: stops other sites posting here, and a double-click making two orders
$token = $_SESSION['checkout_token'] ?? '';
unset($_SESSION['checkout_token']);
if ($token === '' || !hash_equals($token, (string)($_POST['token'] ?? ''))) {
    back_to_checkout(['Your checkout page expired. Please check your details and place the order again.']);
}

// ── Customer details ────────────────────────────────────────────────
$in = [];
foreach (['name', 'phone', 'email', 'delivery', 'payment', 'address', 'postcode', 'city', 'city_other', 'state'] as $field) {
    $in[$field] = trim((string)($_POST[$field] ?? ''));
}
$methods    = payment_methods();
$deliveries = delivery_methods();
$errors  = [];
if ($in['name'] === '' || mb_strlen($in['name']) > 100) {
    $errors[] = 'Please enter your full name (up to 100 characters).';
}
$digits = preg_replace('/\D/', '', $in['phone']);
if (!preg_match('/^\+?[\d\s()-]+$/', $in['phone']) || strlen($digits) < 9 || strlen($digits) > 15) {
    $errors[] = 'Please enter a phone number we can call or WhatsApp, e.g. 012-345 6789.';
}
if (!filter_var($in['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($in['email']) > 255) {
    $errors[] = 'Please enter a valid email address.';
}
if (!isset($deliveries[$in['delivery']])) {
    $errors[] = 'Please choose Pick Up or Postage.';
    $full_address = '';
} elseif ($in['delivery'] === 'pickup') {
    // Collected at the store: no shipping address
    $full_address = 'Pick up at store';
} else {
    // Shipping address in parts (address, postcode, city, state; Malaysia only), saved as one line on the order
    if ($in['address'] === '' || mb_strlen($in['address']) > 255) {
        $errors[] = 'Please enter your street address.';
    }
    if (!preg_match('/^\d{5}$/', $in['postcode'])) {
        $errors[] = 'Please enter your 5-digit postcode.';
    }
    $state_cities = malaysian_cities()[$in['state']] ?? null;
    if (!$state_cities) {
        $errors[] = 'Please choose your state.';
    }
    // A city from the chosen state's list, or "Other (not listed)" with the town typed in
    if ($in['city'] === '__other') {
        $city = $in['city_other'];
        if ($city === '' || mb_strlen($city) > 100) {
            $errors[] = 'Please type your city or town.';
        }
    } else {
        $city = $in['city'];
        if ($state_cities && !in_array($city, $state_cities, true)) {
            $errors[] = 'Please choose your city.';
        }
    }
    $full_address = "{$in['address']}, {$in['postcode']} $city, {$in['state']}, Malaysia";
}
if (!isset($methods[$in['payment']])) {
    $errors[] = 'Please choose a payment method.';
}
if ($errors) {
    back_to_checkout($errors);
}

// ── The cart as it stands now ───────────────────────────────────────
$notes = [];
$lines = cart_lines($pdo, $notes);
if (!$lines) {
    $_SESSION['cart_notes'] = $notes ?: ['Your cart is empty.'];
    header('Location: cart.php');
    exit;
}
// Something changed since the checkout page (price, stock): show it before taking the order
if ($notes) {
    back_to_checkout([], array_merge($notes, ['Please check your order summary and place the order again.']));
}

// ── Save the order (includes/orders.php: stock re-checked with the rows locked) ──
try {
    $saved = save_order($pdo, [
        'customer_name'   => $in['name'],
        'phone'           => $in['phone'],
        'email'           => $in['email'],
        'address'         => $full_address,
        'delivery_method' => $deliveries[$in['delivery']],
        'payment_method'  => $methods[$in['payment']],
    ], $lines);
} catch (Throwable $e) {
    error_log('Order failed: ' . $e->getMessage());
    back_to_checkout(['Sorry, something went wrong and your order was not placed. Please try again, or WhatsApp us.']);
}
if (isset($saved['short'])) {
    $_SESSION['cart_notes'] = [...$saved['short'], 'Please change the quantity in your cart, then check out again.'];
    header('Location: cart.php');
    exit;
}
$order_id = $saved['id'];

cart_clear();
// Remember the customer's details for their next order in this session (an address typed earlier is kept for Pick Up orders)
$_SESSION['checkout_details'] = array_merge($_SESSION['checkout_details'] ?? [], checkout_details($_POST));
// Only the person who placed an order can open its receipt (it shows their address and phone): can_view_order()
$_SESSION['my_orders'][] = $order_id;
// Payment is simulated in this prototype: Atome orders go via the Pay with Atome step, everything else straight to the receipt
header('Location: ' . ($in['payment'] === 'atome' ? 'atome_pay.php' : 'order_success.php') . '?order_id=' . $order_id);
exit;

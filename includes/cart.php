<?php
// Session cart, shared by the header badge, product page, cart, checkout and order handler.
// One line per product + chosen option:
//   $_SESSION['cart']['25-8'] = ['product_id' => 25, 'variant_id' => 8, 'config' => null, 'qty' => 2]
// Only ids and quantities live in the session. Names, prices and stock always come fresh from the
// database (cart_lines()), so a price change or a sold-out variant is picked up straight away.

require_once __DIR__ . '/orders.php'; // order statuses and save_order()

const CART_MAX_QTY = 10; // per line, the same cap as the quantity box on the product page

// key => label. The label is what's saved on the order
function payment_methods(): array
{
    // Instalment plans (JCL, Aeon) are arranged with the team directly, so they aren't offered here
    return [
        'card'  => 'Credit / Debit Card',
        'atome' => 'Atome',
    ];
}

// How the order reaches the customer. Postage needs a shipping address
function delivery_methods(): array
{
    return [
        'pickup'  => 'Pick Up',
        'postage' => 'Postage',
    ];
}

// Where an Atome order continues. Atome's merchant API would give a payment link for each order;
// until that's set up this opens Atome (the app, on phones that have it)
const ATOME_URL = 'https://www.atome.my/';

// Checkout details kept in the session ($_SESSION['checkout_details']) so the form refills itself:
// field => longest value kept. Card details have no name="…" on the form, so they are never sent or kept
const CHECKOUT_FIELDS = [
    'name' => 100, 'phone' => 30, 'email' => 255, 'delivery' => 10, 'payment' => 10,
    'address' => 255, 'postcode' => 5, 'state' => 50, 'city' => 100, 'city_other' => 100,
];

function checkout_details(array $src): array
{
    $out = [];
    foreach (CHECKOUT_FIELDS as $field => $max) {
        if (isset($src[$field]) && is_string($src[$field])) {
            $out[$field] = mb_substr(trim($src[$field]), 0, $max);
        }
    }
    return $out;
}


// A customer can open an order's receipt only from the browser that placed it (it shows their phone and address);
// a logged-in admin can open any
function can_view_order(int $id): bool
{
    return in_array($id, $_SESSION['my_orders'] ?? [], true) || ($_SESSION['admin_logged_in'] ?? false) === true;
}

// Delivery is within Malaysia: the State dropdown on checkout
function malaysian_states(): array
{
    return array_keys(malaysian_cities());
}

// Main cities and towns per state, for the City dropdown on checkout (A–Z).
// Not every town is listed, so checkout also offers "Other (not listed)" with a box to type it
function malaysian_cities(): array
{
    $cities = [
        'Johor'             => ['Batu Pahat', 'Iskandar Puteri', 'Johor Bahru', 'Kluang', 'Kota Tinggi', 'Kulai', 'Masai', 'Mersing', 'Muar', 'Pasir Gudang', 'Pontian', 'Segamat', 'Senai', 'Skudai', 'Tangkak', 'Yong Peng'],
        'Kedah'             => ['Alor Setar', 'Baling', 'Bukit Kayu Hitam', 'Gurun', 'Jitra', 'Kuala Kedah', 'Kulim', 'Langkawi', 'Pendang', 'Pokok Sena', 'Sik', 'Sungai Petani', 'Yan'],
        'Kelantan'          => ['Bachok', 'Gua Musang', 'Jeli', 'Kota Bharu', 'Kuala Krai', 'Machang', 'Pasir Mas', 'Pasir Puteh', 'Tanah Merah', 'Tumpat'],
        'Melaka'            => ['Alor Gajah', 'Ayer Keroh', 'Batu Berendam', 'Bukit Beruang', 'Jasin', 'Klebang', 'Masjid Tanah', 'Melaka', 'Merlimau'],
        'Negeri Sembilan'   => ['Bahau', 'Gemas', 'Jempol', 'Kuala Pilah', 'Labu', 'Lukut', 'Mantin', 'Nilai', 'Port Dickson', 'Rantau', 'Rembau', 'Senawang', 'Seremban', 'Tampin'],
        'Pahang'            => ['Bentong', 'Bera', 'Cameron Highlands', 'Genting Highlands', 'Jerantut', 'Kuala Lipis', 'Kuantan', 'Maran', 'Mentakab', 'Pekan', 'Raub', 'Rompin', 'Temerloh'],
        'Perak'             => ['Bagan Serai', 'Batu Gajah', 'Gerik', 'Ipoh', 'Kampar', 'Kuala Kangsar', 'Lumut', 'Manjung', 'Parit Buntar', 'Seri Iskandar', 'Sitiawan', 'Slim River', 'Taiping', 'Tanjung Malim', 'Tapah', 'Teluk Intan'],
        'Perlis'            => ['Arau', 'Kangar', 'Kuala Perlis', 'Padang Besar', 'Simpang Empat'],
        'Pulau Pinang'      => ['Air Itam', 'Balik Pulau', 'Bayan Lepas', 'Bukit Mertajam', 'Butterworth', 'Gelugor', 'George Town', 'Jelutong', 'Kepala Batas', 'Nibong Tebal', 'Perai', 'Seberang Jaya', 'Simpang Ampat', 'Tanjung Bungah'],
        'Sabah'             => ['Beaufort', 'Keningau', 'Kota Belud', 'Kota Kinabalu', 'Kudat', 'Lahad Datu', 'Papar', 'Penampang', 'Putatan', 'Ranau', 'Sandakan', 'Semporna', 'Tawau', 'Tuaran'],
        'Sarawak'           => ['Bau', 'Betong', 'Bintulu', 'Kapit', 'Kuching', 'Lawas', 'Limbang', 'Miri', 'Mukah', 'Samarahan', 'Sarikei', 'Serian', 'Sibu', 'Sri Aman'],
        'Selangor'          => ['Ampang', 'Banting', 'Bangi', 'Batu Caves', 'Cheras', 'Cyberjaya', 'Kajang', 'Klang', 'Kuala Kubu Bharu', 'Kuala Selangor', 'Petaling Jaya', 'Puchong', 'Puncak Alam', 'Rawang', 'Sabak Bernam', 'Selayang', 'Semenyih', 'Sepang', 'Seri Kembangan', 'Shah Alam', 'Subang Jaya', 'Sungai Buloh'],
        'Terengganu'        => ['Besut', 'Chukai', 'Dungun', 'Hulu Terengganu', 'Jerteh', 'Kemaman', 'Kerteh', 'Kuala Nerus', 'Kuala Terengganu', 'Marang', 'Setiu'],
        'W.P. Kuala Lumpur' => ['Kuala Lumpur'],
        'W.P. Labuan'       => ['Labuan'],
        'W.P. Putrajaya'    => ['Putrajaya'],
    ];
    return $cities;
}

// RM 1,759.00 — always with cents, for the cart, checkout and orders
function rm_price(float $amount): string
{
    return 'RM ' . number_format($amount, 2);
}

function order_number(int $id): string
{
    return '#' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
}

function cart_key(int $product_id, ?int $variant_id, ?string $config): string
{
    return $product_id . '-' . ($variant_id ?: 'c' . substr(md5((string)$config), 0, 8));
}

function cart_items(): array
{
    $cart = $_SESSION['cart'] ?? [];
    // Sessions from before this file stored cart[product_id] = qty, with the option in cart_variant / cart_config
    foreach ($cart as $pid => $item) {
        if (is_array($item)) continue;
        $vid = isset($_SESSION['cart_variant'][$pid]) ? (int)$_SESSION['cart_variant'][$pid] : null;
        $cfg = $vid ? null : ($_SESSION['cart_config'][$pid] ?? null);
        unset($cart[$pid]);
        $cart[cart_key((int)$pid, $vid, $cfg)] = ['product_id' => (int)$pid, 'variant_id' => $vid, 'config' => $cfg, 'qty' => max(1, (int)$item)];
        $_SESSION['cart'] = $cart;
    }
    unset($_SESSION['cart_variant'], $_SESSION['cart_config']);
    return $cart;
}

// $limit: how many the shop can sell of this option right now. Returns how many were actually added
// (fewer than $qty, or 0, when the cart already holds as many as the stock allows)
function cart_add(int $product_id, ?int $variant_id, ?string $config, int $qty, int $limit): int
{
    $cart   = cart_items();
    $key    = cart_key($product_id, $variant_id, $config);
    $before = $cart[$key]['qty'] ?? 0;
    $after  = max(1, min($before + $qty, $limit, CART_MAX_QTY));
    $cart[$key] = [
        'product_id' => $product_id,
        'variant_id' => $variant_id,
        'config'     => $variant_id ? null : $config,
        'qty'        => $after,
    ];
    $_SESSION['cart'] = $cart;
    return max(0, $after - $before);
}

// Stock is checked again by cart_lines()
function cart_set_qty(string $key, int $qty): void
{
    $cart = cart_items();
    if (isset($cart[$key])) {
        $cart[$key]['qty'] = max(1, min($qty, CART_MAX_QTY));
        $_SESSION['cart'] = $cart;
    }
}

function cart_remove(string $key): void
{
    $cart = cart_items();
    unset($cart[$key]);
    $_SESSION['cart'] = $cart;
}

function cart_clear(): void
{
    unset($_SESSION['cart']);
}

function cart_count(): int
{
    return (int)array_sum(array_column(cart_items(), 'qty'));
}

// "16GB / 512GB · Full Set · RTX3060" (colour only when it isn't the usual black)
function variant_detail(array $v): string
{
    return implode(' · ', array_filter([
        $v['ram'] . ' / ' . $v['storage'],
        ['cpu_only' => 'CPU Only', 'full_set' => 'Full Set'][$v['set_type']] ?? '',
        $v['gpu_variant'],
        strcasecmp((string)$v['color'], 'Black') ? $v['color'] : null,
    ]));
}

// The cart as the shop can sell it right now. Lines whose product was hidden, deleted or sold out are dropped,
// and quantities above the stock left are lowered; each change adds a sentence to $notes for the customer.
function cart_lines(PDO $pdo, array &$notes = []): array
{
    $cart = cart_items();
    if (!$cart) return [];

    $ids = fn(array $list) => implode(',', array_map('intval', array_unique($list))) ?: '0';
    $products = [];
    foreach ($pdo->query('SELECT p.id, p.name, p.price, p.stock, p.image, p.is_active, c.name AS category_name, c.slug AS category_slug
        FROM products p LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.id IN (' . $ids(array_column($cart, 'product_id')) . ')') as $p) {
        $products[(int)$p['id']] = $p;
    }
    $variants = [];
    foreach ($pdo->query('SELECT id, product_id, ram, storage, set_type, gpu_variant, color, price, stock FROM product_variants
        WHERE id IN (' . $ids(array_filter(array_column($cart, 'variant_id'))) . ')') as $v) {
        $variants[(int)$v['id']] = $v;
    }

    $lines = [];
    foreach ($cart as $key => $item) {
        $p = $products[$item['product_id']] ?? null;
        $v = $item['variant_id'] ? ($variants[$item['variant_id']] ?? null) : null;
        if (!$p || !$p['is_active'] || ($item['variant_id'] && (!$v || (int)$v['product_id'] !== (int)$p['id']))) {
            $notes[] = ($p['name'] ?? 'An item') . ' is no longer available, so it was taken out of your cart.';
            unset($cart[$key]);
            continue;
        }
        $left  = min((int)$p['stock'], $v ? (int)$v['stock'] : PHP_INT_MAX, CART_MAX_QTY);
        $label = $p['name'] . ($v ? ' (' . variant_detail($v) . ')' : '');
        if ($left <= 0) {
            $notes[] = "$label is now out of stock, so it was taken out of your cart.";
            unset($cart[$key]);
            continue;
        }
        if ($item['qty'] > $left) {
            $notes[] = "Only $left of $label can be ordered right now, so the quantity was lowered.";
            $cart[$key]['qty'] = $item['qty'] = $left;
        }
        $price = (float)($v['price'] ?? $p['price']);
        $lines[$key] = [
            'key'        => $key,
            'product_id' => (int)$p['id'],
            'variant_id' => $v ? (int)$v['id'] : null,
            'name'       => $p['name'],
            'category'   => $p['category_name'],
            'category_slug' => $p['category_slug'],
            'image'      => $p['image'],
            'detail'     => $v ? variant_detail($v) : $item['config'],
            'unit_price' => $price,
            'qty'        => (int)$item['qty'],
            'max'        => $left,
            'line_total' => $price * $item['qty'],
        ];
    }
    $_SESSION['cart'] = $cart;
    return $lines;
}

// "Continue shopping" goes back to the store page the customer came from (a product, a category list…),
// not always the homepage. Only pages of this site count, and not the cart / checkout / order pages themselves
function continue_shopping_url(): string
{
    $site = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\') . '/'; // "/pixelcraft/"
    $from = parse_url($_SERVER['HTTP_REFERER'] ?? '');
    $path = $from['path'] ?? '';
    $same_site = ($from['host'] ?? '') === parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)
        && str_starts_with($path, $site)
        && !preg_match('#/(cart|checkout|order_handler|order_success|atome_pay)\.php$|/admin/#', $path);
    if ($same_site) {
        $_SESSION['continue_url'] = $path . (isset($from['query']) ? '?' . $from['query'] : '');
    }
    return $_SESSION['continue_url'] ?? $site . 'index.php';
}

function cart_total(array $lines): float
{
    return array_sum(array_column($lines, 'line_total'));
}

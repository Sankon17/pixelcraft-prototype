<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/cart.php';

// The form saves what the customer types into their session as they go (see the script below),
// and "Not you? Clear my details" empties it. Both need this page's token
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ok = hash_equals($_SESSION['checkout_token'] ?? '', (string)($_POST['token'] ?? ''));
    if ($ok && ($_POST['action'] ?? '') === 'save_details') {
        $_SESSION['checkout_details'] = checkout_details($_POST);
        http_response_code(204);
        exit;
    }
    if ($ok && ($_POST['action'] ?? '') === 'clear_details') {
        unset($_SESSION['checkout_details']);
    }
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
        http_response_code($ok ? 204 : 403);
        exit;
    }
    header('Location: checkout.php');
    exit;
}

// Anything that changed since the cart page (price, stock) is explained at the top
$notes = $_SESSION['checkout_notes'] ?? [];
unset($_SESSION['checkout_notes']);
$lines = cart_lines($pdo, $notes);
if (!$lines) {
    $_SESSION['cart_notes'] = $notes;
    header('Location: cart.php');
    exit;
}
$total = cart_total($lines);

// A failed "Place Order" comes back with its errors and what was typed (pages/order_handler.php);
// otherwise the details saved in this session refill the form
$errors = $_SESSION['checkout_errors'] ?? [];
$old    = $_SESSION['checkout_old'] ?? [];
unset($_SESSION['checkout_errors'], $_SESSION['checkout_old']);
$remembered = !$old && !empty($_SESSION['checkout_details']);
if (!$old) {
    $old = $_SESSION['checkout_details'] ?? [];
}
$val = fn(string $field) => e($old[$field] ?? '');

// One token per checkout page, so the form can't be sent from another site or twice by accident
$_SESSION['checkout_token'] = bin2hex(random_bytes(16));

// What the customer picked last time; nothing is pre-selected on a fresh page
$delivery = $old['delivery'] ?? '';
$payment  = $old['payment'] ?? '';
$atome_logo = asset_image('../', 'payments', 'atome.png');

$base = '../';
$page_title = 'Checkout - PixelCraft';
require '../includes/header.php';
?>

<section class="page-hero">
    <h1><span class="red">Checkout</span></h1>
    <p>Check your order, fill in your details and choose how to pay.</p>
</section>

<section class="section shop-section">
    <?php foreach ($notes as $note): ?>
    <div class="shop-note" role="status"><?= e($note) ?></div>
    <?php endforeach; ?>

    <?php if ($errors): ?>
    <ul class="shop-errors" role="alert">
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <div class="shop-layout is-checkout">
        <form method="POST" action="order_handler.php" class="checkout-form" id="checkoutForm">
            <input type="hidden" name="token" value="<?= e($_SESSION['checkout_token']) ?>">

            <fieldset class="shop-card">
                <legend>Your details</legend>
                <?php if ($remembered): ?>
                <p class="details-saved">
                    <span>✓ We filled in your details from earlier.</span>
                    <button type="submit" form="clearDetails" class="details-clear">Not you? Clear my details</button>
                </p>
                <?php endif; ?>
                <div class="checkout-grid">
                    <label class="is-wide">
                        <span>Full Name</span>
                        <input type="text" name="name" value="<?= $val('name') ?>" maxlength="100" autocomplete="name" required>
                    </label>
                    <label>
                        <span>Phone Number</span>
                        <input type="tel" name="phone" value="<?= $val('phone') ?>" maxlength="30" placeholder="e.g. 012-345 6789" autocomplete="tel" required>
                    </label>
                    <label>
                        <span>Email</span>
                        <input type="email" name="email" value="<?= $val('email') ?>" maxlength="255" placeholder="you@example.com" autocomplete="email" required>
                    </label>
                </div>
            </fieldset>

            <!-- 1. Delivery: Pick Up at the store, or Postage to an address -->
            <fieldset class="shop-card">
                <legend>Delivery</legend>
                <div class="pay-options">
                    <label class="pay-option">
                        <input type="radio" name="delivery" value="pickup"<?= $delivery === 'pickup' ? ' checked' : '' ?> required>
                        <span class="pay-icon" aria-hidden="true">🏬</span>
                        <span class="pay-text"><strong>Pick Up</strong><small>Collect at our Seremban store</small></span>
                    </label>
                    <label class="pay-option">
                        <input type="radio" name="delivery" value="postage"<?= $delivery === 'postage' ? ' checked' : '' ?> required>
                        <span class="pay-icon" aria-hidden="true">📦</span>
                        <span class="pay-text"><strong>Postage</strong><small>Delivered to your address</small></span>
                    </label>
                </div>

                <p class="pay-pickup" data-show-for="pickup"<?= $delivery === 'pickup' ? '' : ' hidden' ?>>
                    Collect your order at our store: 282, Jalan Bandar Senawang 1, Pusat Bandar Senawang, 70450 Seremban.
                    <a href="location.php" target="_blank" rel="noopener">See map →</a>
                </p>

                <?php
                // Shipping address in parts, like the Shoppego checkout. Only switched on (and so sent) for Postage
                $off = $delivery === 'postage' ? ' required' : ' disabled';
                $state = $old['state'] ?? '';
                ?>
                <div class="ship-address" data-show-for="postage"<?= $delivery === 'postage' ? '' : ' hidden' ?>>
                    <h3 class="ship-title">Shipping address</h3>
                    <label class="float-field">
                        <span>Country</span>
                        <select name="country"<?= $off ?>><option value="Malaysia" selected>Malaysia</option></select>
                    </label>
                    <label class="float-field">
                        <input type="text" name="address" value="<?= $val('address') ?>" maxlength="255" placeholder=" " autocomplete="street-address"<?= $off ?>>
                        <span>Address</span>
                    </label>
                    <?php
                    // City follows State: the state's towns, plus "Other (not listed)" with a box to type it
                    $cities = malaysian_cities();
                    $city   = $old['city'] ?? '';
                    $other  = $city === '__other';
                    $other_on = $other && $delivery === 'postage';
                    ?>
                    <div class="ship-row">
                        <label class="float-field">
                            <input type="text" name="postcode" value="<?= $val('postcode') ?>" maxlength="5" inputmode="numeric" pattern="\d{5}" placeholder=" " autocomplete="postal-code" title="5-digit postcode"<?= $off ?>>
                            <span>Postcode</span>
                        </label>
                        <label class="float-field">
                            <span>State</span>
                            <select name="state" autocomplete="address-level1" data-state<?= $off ?>>
                                <option value=""<?= $state === '' ? ' selected' : '' ?> disabled>Choose…</option>
                                <?php foreach (array_keys($cities) as $s): ?>
                                <option<?= $state === $s ? ' selected' : '' ?>><?= e($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="float-field">
                            <span>City</span>
                            <select name="city" autocomplete="address-level2" data-city<?= $off ?>>
                                <?php if (!isset($cities[$state])): ?>
                                <option value="" selected disabled>Choose state first</option>
                                <?php else: ?>
                                <option value="" disabled<?= $city === '' ? ' selected' : '' ?>>Choose…</option>
                                <?php foreach ($cities[$state] as $c): ?>
                                <option<?= $city === $c ? ' selected' : '' ?>><?= e($c) ?></option>
                                <?php endforeach; ?>
                                <option value="__other"<?= $other ? ' selected' : '' ?>>Other (not listed)</option>
                                <?php endif; ?>
                            </select>
                        </label>
                    </div>
                    <label class="float-field" data-city-other<?= $other_on ? '' : ' hidden' ?>>
                        <input type="text" name="city_other" value="<?= $val('city_other') ?>" maxlength="100" placeholder=" "<?= $other_on ? ' required' : ' disabled' ?>>
                        <span>Your city / town</span>
                    </label>
                    <p class="ship-note">Postage fee is confirmed by our team after you order.</p>
                </div>
            </fieldset>

            <!-- 2. Payment: card details, or continue to Atome -->
            <fieldset class="shop-card">
                <legend>Payment</legend>
                <div class="pay-options">
                    <label class="pay-option">
                        <input type="radio" name="payment" value="card"<?= $payment === 'card' ? ' checked' : '' ?> required>
                        <span class="pay-icon" aria-hidden="true">💳</span>
                        <span class="pay-text"><strong>Credit / Debit Card</strong><small>Enter your card details</small></span>
                    </label>
                    <label class="pay-option">
                        <input type="radio" name="payment" value="atome"<?= $payment === 'atome' ? ' checked' : '' ?> required>
                        <?php if ($atome_logo): ?><img src="<?= e($atome_logo) ?>" alt="" class="pay-logo"><?php endif; ?>
                        <span class="pay-text"><strong>Atome</strong><small>3 interest-free payments</small></span>
                    </label>
                </div>

                <?php
                // Card details have no name="…", so the browser never sends them: they are checked on the page only.
                // Charging a card needs a payment gateway; until there is one, nothing is charged or stored
                $card_on = $payment === 'card';
                ?>
                <div class="card-form" data-show-for="card"<?= $card_on ? '' : ' hidden' ?>>
                    <label class="float-field">
                        <input type="text" id="ccName" maxlength="100" placeholder=" " autocomplete="cc-name"<?= $card_on ? ' required' : ' disabled' ?>>
                        <span>Name on card</span>
                    </label>
                    <label class="float-field">
                        <input type="text" id="ccNumber" inputmode="numeric" maxlength="23" placeholder=" " autocomplete="cc-number"<?= $card_on ? ' required' : ' disabled' ?>>
                        <span>Card number</span>
                        <em class="card-brand" id="ccBrand" aria-live="polite"></em>
                    </label>
                    <div class="ship-row is-two">
                        <label class="float-field">
                            <input type="text" id="ccExpiry" inputmode="numeric" maxlength="7" placeholder=" " autocomplete="cc-exp"<?= $card_on ? ' required' : ' disabled' ?>>
                            <span>Expiry (MM / YY)</span>
                        </label>
                        <label class="float-field">
                            <input type="password" id="ccCvv" inputmode="numeric" maxlength="4" placeholder=" " autocomplete="cc-csc"<?= $card_on ? ' required' : ' disabled' ?>>
                            <span>CVV</span>
                        </label>
                    </div>
                    <p class="card-note">🔒 Demo payment: your card details are only checked on this page. They are never sent or stored, and no real charge is made.</p>
                </div>

                <div class="atome-panel" data-show-for="atome"<?= $payment === 'atome' ? '' : ' hidden' ?>>
                    <?php if ($atome_logo): ?><img src="<?= e($atome_logo) ?>" alt="Atome" class="atome-logo"><?php endif; ?>
                    <div>
                        <strong>3 payments of <?= rm_price(round($total / 3, 2)) ?></strong>
                        <p>After you place your order, you’ll continue to the Atome app to complete payment.</p>
                    </div>
                </div>
            </fieldset>

            <button type="submit" class="btn checkout-submit">Place Order · <?= rm_price($total) ?></button>
        </form>
        <!-- "Not you? Clear my details" (a separate form: forms can't be nested) -->
        <form method="POST" action="checkout.php" id="clearDetails" hidden>
            <input type="hidden" name="token" value="<?= e($_SESSION['checkout_token']) ?>">
            <input type="hidden" name="action" value="clear_details">
        </form>

        <aside class="shop-summary">
            <h2>Order Summary</h2>
            <ul class="summary-items">
                <?php foreach ($lines as $line): ?>
                <li>
                    <div>
                        <strong><?= e($line['name']) ?></strong>
                        <?php if ($line['detail']): ?><small><?= e($line['detail']) ?></small><?php endif; ?>
                        <small><?= $line['qty'] ?> × <?= rm_price($line['unit_price']) ?></small>
                    </div>
                    <span><?= rm_price($line['line_total']) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <dl>
                <div class="shop-summary-total"><dt>Subtotal</dt><dd><?= rm_price($total) ?></dd></div>
            </dl>
            <p class="shop-summary-note">Delivery / pickup arrangement will be confirmed after order.</p>
            <a href="cart.php" class="shop-continue">← Edit cart</a>
        </aside>
    </div>
</section>

<script>
const checkout = document.getElementById('checkoutForm');
const submitBtn = checkout.querySelector('.checkout-submit');
const placeLabel = submitBtn.textContent;

// Each choice shows its own panel (data-show-for="pickup" / "postage" / "card" / "atome").
// Fields in a hidden panel are switched off, so they aren't required and aren't sent
function showPanels() {
    const picked = [checkout.elements.delivery.value, checkout.elements.payment.value];
    checkout.querySelectorAll('[data-show-for]').forEach(panel => {
        const on = picked.includes(panel.dataset.showFor);
        panel.hidden = !on;
        panel.querySelectorAll('input, select').forEach(field => {
            field.disabled = !on;
            field.required = on;
        });
    });
    submitBtn.textContent = checkout.elements.payment.value === 'atome' ? placeLabel + ' & continue to Atome' : placeLabel;
    updateOtherCity();
}
checkout.querySelectorAll('[name=delivery], [name=payment]').forEach(radio => radio.addEventListener('change', showPanels));

// ── City follows State (lists in includes/cart.php malaysian_cities()) ──
const CITIES = <?= json_encode(malaysian_cities(), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const stateSelect = checkout.querySelector('[data-state]');
const citySelect = checkout.querySelector('[data-city]');
const otherCity = checkout.querySelector('[data-city-other]');

function fillCities() {
    const list = CITIES[stateSelect.value] || [];
    citySelect.replaceChildren();
    if (!list.length) {
        citySelect.add(new Option('Choose state first', '', true, true));
    } else {
        citySelect.add(new Option('Choose…', '', true, true));
        list.forEach(city => citySelect.add(new Option(city, city)));
        citySelect.add(new Option('Other (not listed)', '__other'));
        // Kuala Lumpur, Labuan, Putrajaya: only one city, so pick it
        if (list.length === 1) citySelect.value = list[0];
    }
    citySelect.options[0].disabled = true;
    updateOtherCity();
}
// "Other (not listed)": show a box to type the town (only needed, and only sent, then)
function updateOtherCity() {
    const show = !otherCity.closest('[data-show-for]').hidden && citySelect.value === '__other';
    const input = otherCity.querySelector('input');
    otherCity.hidden = !show;
    input.disabled = !show;
    input.required = show;
}
stateSelect.addEventListener('change', fillCities);
citySelect.addEventListener('change', () => {
    updateOtherCity();
    if (citySelect.value === '__other') otherCity.querySelector('input').focus();
});

// ── Card details: tidy as you type, and checked before the order can be placed ──
const ccNumber = document.getElementById('ccNumber');
const ccExpiry = document.getElementById('ccExpiry');
const ccCvv    = document.getElementById('ccCvv');
const ccName   = document.getElementById('ccName');
const ccBrand  = document.getElementById('ccBrand');
const digits = s => s.replace(/\D/g, '');
const brandOf = n => /^4/.test(n) ? 'Visa' : /^(5[1-5]|2[2-7])/.test(n) ? 'Mastercard' : /^3[47]/.test(n) ? 'Amex' : '';
// The standard card-number check digit (Luhn)
const luhn = n => [...n].reverse().reduce((sum, d, i) => {
    let x = Number(d) * (i % 2 ? 2 : 1);
    return sum + (x > 9 ? x - 9 : x);
}, 0) % 10 === 0;

function checkCard() {
    const n = digits(ccNumber.value);
    ccNumber.setCustomValidity(n.length >= 13 && n.length <= 19 && luhn(n) ? '' : 'Please check your card number.');

    const [mm, yy] = ccExpiry.value.split('/').map(s => parseInt(digits(s), 10));
    const now = new Date();
    const notExpired = yy + 2000 > now.getFullYear() || (yy + 2000 === now.getFullYear() && mm >= now.getMonth() + 1);
    ccExpiry.setCustomValidity(mm >= 1 && mm <= 12 && yy >= 0 && notExpired ? '' : 'Please enter a valid expiry date (MM / YY) that has not passed.');

    const cvvLength = brandOf(n) === 'Amex' ? 4 : 3;
    ccCvv.setCustomValidity(digits(ccCvv.value).length === cvvLength ? '' : `Please enter the ${cvvLength}-digit CVV.`);
    ccName.setCustomValidity(ccName.value.trim().length >= 2 ? '' : 'Please enter the name on the card.');
}

ccNumber.addEventListener('input', () => {
    const n = digits(ccNumber.value).slice(0, 19);
    ccNumber.value = n.replace(/(\d{4})(?=\d)/g, '$1 ');
    ccBrand.textContent = brandOf(n);
    checkCard();
});
ccExpiry.addEventListener('input', e => {
    let d = digits(ccExpiry.value).slice(0, 4);
    if (d.length === 1 && d > '1') d = '0' + d; // "4" → "04"
    ccExpiry.value = d.length > 2 || (d.length === 2 && e.inputType !== 'deleteContentBackward') ? d.slice(0, 2) + ' / ' + d.slice(2) : d;
    checkCard();
});
ccCvv.addEventListener('input', () => { ccCvv.value = digits(ccCvv.value).slice(0, 4); checkCard(); });
ccName.addEventListener('input', checkCard);
checkCard();

// ── Remember what's typed: saved to this visitor's session (never the card details: they have no name) ──
let saveTimer = null;
function saveDetails() {
    const body = new FormData();
    body.set('action', 'save_details');
    body.set('token', checkout.elements.token.value);
    // Every named field, including ones switched off for Pick Up, so switching back keeps the address
    for (const el of checkout.elements) {
        if (!el.name || el.name === 'token' || (el.type === 'radio' && !el.checked)) continue;
        body.set(el.name, el.value);
    }
    fetch('checkout.php', { method: 'POST', body, headers: { 'X-Requested-With': 'fetch' }, keepalive: true }).catch(() => {});
}
checkout.addEventListener('input', () => { clearTimeout(saveTimer); saveTimer = setTimeout(saveDetails, 600); });
checkout.addEventListener('change', () => { clearTimeout(saveTimer); saveDetails(); });
// Leaving the page (e.g. "Edit cart") straight after typing: save what's there now
window.addEventListener('pagehide', () => { if (saveTimer) { clearTimeout(saveTimer); saveDetails(); } });

// Stop double-clicks placing the order twice
checkout.addEventListener('submit', () => {
    submitBtn.disabled = true;
    submitBtn.textContent = 'Placing your order…';
});
</script>

<?php require '../includes/footer.php'; ?>

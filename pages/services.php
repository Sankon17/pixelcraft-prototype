<?php
require_once '../includes/functions.php';

$base = '../';
$page_title = 'Services - PixelCraft';

// Servicing is handled by the technicians, so enquiries go to the After-Sales WhatsApp
$contacts   = store_contacts();
$aftersales = $contacts['whatsapp']['aftersales'];
$wa_url     = $aftersales['url'] . '?text=' . rawurlencode('Hi PixelCraft, I would like to ask about your PC / laptop services.');

// Individual services: icon, name, price, who it's for, what's included
$services = [
    [
        'icon'  => '🆓',
        'name'  => 'Free Basic Cleaning & Diagnose',
        'price' => 'FREE',
        'for'   => 'PC & Laptop',
        'desc'  => 'Bring in your PC or laptop for a basic clean and a check-up to find out what’s wrong.',
    ],
    [
        'icon'  => '💿',
        'name'  => 'Install Windows & Basic Software',
        'price' => 'RM 50',
        'for'   => 'PC & Laptop',
        'desc'  => 'A fresh Windows installation with the basic software set up and ready to use.',
    ],
    [
        'icon'  => '🔧',
        'name'  => 'Component Installation (Pasang)',
        'price' => 'RM 50',
        'for'   => 'PC',
        'desc'  => 'We fit new parts into your PC:',
        'parts' => ['AIO / Tower Cooler', 'Processor', 'GPU', 'PSU', 'RAM', 'SSD'],
    ],
    [
        'icon'  => '🖥️',
        'name'  => 'Build PC',
        'price' => 'RM 150',
        'for'   => 'PC',
        'desc'  => 'Full PC assembly service: we put your parts together into a complete, working PC.',
    ],
];

// Full packages: name, price, what's included
$packages = [
    ['icon' => '🖥️', 'name' => 'PC Only',       'price' => 'RM 80',  'includes' => ['Deep Cleaning', 'Replace Thermal Paste', 'Cable Management']],
    ['icon' => '💼', 'name' => 'Office Laptop', 'price' => 'RM 100', 'includes' => ['Deep Cleaning', 'CMOS Battery Replacement', 'Replace Thermal Paste']],
    ['icon' => '🎮', 'name' => 'Gaming Laptop', 'price' => 'RM 200', 'includes' => ['Deep Cleaning', 'CMOS Battery Replacement', 'Replace Thermal Paste']],
];

require '../includes/header.php';
?>

<section class="page-hero">
    <p class="svc-kicker">PixelCraft Services</p>
    <h1>OUR <span class="red">SERVICES</span></h1>
    <p>Professional PC &amp; Laptop Services by PixelCraft</p>
</section>

<section class="section">
    <div class="section-head">
        <h2 class="section-title">Individual Services</h2>
    </div>

    <div class="svc-grid">
        <?php foreach ($services as $s): ?>
        <article class="svc-card">
            <div class="svc-card-top">
                <span class="svc-icon" aria-hidden="true"><?= $s['icon'] ?></span>
                <span class="svc-price<?= $s['price'] === 'FREE' ? ' is-free' : '' ?>"><?= e($s['price']) ?></span>
            </div>
            <h3><?= e($s['name']) ?></h3>
            <span class="svc-for"><?= e($s['for']) ?></span>
            <p><?= e($s['desc']) ?></p>
            <?php if (!empty($s['parts'])): ?>
            <ul class="svc-parts">
                <?php foreach ($s['parts'] as $part): ?><li><?= e($part) ?></li><?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="section-band">
    <div class="section">
        <div class="section-head">
            <h2 class="section-title">Full Package Deals</h2>
        </div>
        <p class="svc-intro">A complete service in one visit, at one price.</p>

        <div class="svc-packages">
            <?php foreach ($packages as $pkg): ?>
            <article class="svc-package">
                <span class="svc-icon" aria-hidden="true"><?= $pkg['icon'] ?></span>
                <h3><?= e($pkg['name']) ?></h3>
                <p class="svc-package-price"><?= e($pkg['price']) ?></p>
                <p class="svc-includes-label">Includes</p>
                <ul class="svc-includes">
                    <?php foreach ($pkg['includes'] as $item): ?><li><?= e($item) ?></li><?php endforeach; ?>
                </ul>
                <a href="<?= e($aftersales['url'] . '?text=' . rawurlencode('Hi PixelCraft, I would like to book the ' . $pkg['name'] . ' package (' . $pkg['price'] . ').')) ?>"
                   class="btn btn-outline svc-book" target="_blank" rel="noopener">Book on WhatsApp</a>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section">
    <div class="cta-box">
        <h3>Need help? Contact us</h3>
        <p>Not sure which service you need? Message our technicians on WhatsApp and we’ll sort it out.</p>
        <a href="<?= e($wa_url) ?>" class="btn btn-whatsapp btn-lg" target="_blank" rel="noopener">💬 WhatsApp <?= e($aftersales['display']) ?></a>
        <p class="svc-cta-note">Technicians are in store daily, 9am–6pm · <a href="location.php">Find the store →</a></p>
    </div>
</section>

<?php require '../includes/footer.php'; ?>

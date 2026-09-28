<?php
require_once '../includes/functions.php';

$base = '../';
$page_title = 'Store Location - PixelCraft';

$address = '282, Jalan Bandar Senawang 1, Pusat Bandar Senawang, 70450 Seremban, Negeri Sembilan';
$map_query  = urlencode('PixelCraft, ' . $address);
$map_embed  = 'https://maps.google.com/maps?q=' . $map_query . '&z=16&output=embed';
$map_link   = 'https://www.google.com/maps/search/?api=1&query=' . $map_query;
$contacts   = store_contacts();
$directions = 'https://www.google.com/maps/dir/?api=1&destination=' . urlencode($address);

// Opening hours are the same every day: [open hour, close hour] in 24h.
$hours = [
    'sales'      => ['label' => 'Sales',      'desc' => 'Consultation, custom PC orders & accessories', 'open' => 9, 'close' => 22],
    'technician' => ['label' => 'Technician', 'desc' => 'Repairs, upgrades & servicing',               'open' => 9, 'close' => 18],
];

$now  = new DateTime('now', new DateTimeZone('Asia/Kuala_Lumpur'));
$hour = (int)$now->format('G') + (int)$now->format('i') / 60;

function fmt_hour(int $h): string
{
    return date('g:i A', mktime($h, 0));
}

require '../includes/header.php';
?>

<section class="page-hero">
    <div class="hero-badge">Our Store</div>
    <h1>Visit Our <span class="red">Store</span></h1>
    <p>Drop by for a custom PC consultation, place your order in person, or bring your PC to our technicians.</p>
</section>

<section class="section-band">
    <div class="section">
        <div class="map-frame">
            <div class="map-frame-bar">
                <div class="map-frame-title">
                    <span class="map-pin">📍</span>
                    <div>
                        <strong>PixelCraft Senawang</strong>
                        <span>Pusat Bandar Senawang, Seremban</span>
                    </div>
                </div>
                <a href="<?= e($map_link) ?>" class="map-frame-link" target="_blank" rel="noopener">Open in Google Maps ↗</a>
            </div>
            <div class="map-frame-body">
                <iframe
                    src="<?= e($map_embed) ?>"
                    title="PixelCraft store location on Google Maps"
                    loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade"
                    allowfullscreen></iframe>
            </div>
            <p class="map-frame-hint">Drag to move · Ctrl + scroll (or pinch) to zoom</p>
        </div>

        <div class="store-card">
            <div class="store-block">
                <div class="logo"><?= logo_img($base, true) ?></div>
                <h3>📍 Address</h3>
                <address><?= e($address) ?></address>
            </div>

            <div class="store-block">
                <h3>🕘 Opening Hours <span class="muted-note">Open every day</span></h3>
                <table class="hours-table">
                    <?php foreach ($hours as $h): ?>
                    <?php $is_open = $hour >= $h['open'] && $hour < $h['close']; ?>
                    <tr>
                        <th><?= e($h['label']) ?></th>
                        <td><?= fmt_hour($h['open']) ?> – <?= fmt_hour($h['close']) ?></td>
                        <td><span class="status <?= $is_open ? 'open' : 'closed' ?>"><?= $is_open ? 'Open now' : 'Closed' ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <div class="store-actions">
                <a href="<?= e($directions) ?>" class="btn" target="_blank" rel="noopener">Get Directions →</a>
                <a href="<?= e($contacts['whatsapp']['sales']['url']) ?>" class="btn btn-whatsapp" target="_blank" rel="noopener">WhatsApp Us</a>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-head">
            <h2 class="section-title">Planning Your Visit</h2>
        </div>
        <div class="visit-grid">
            <?php foreach ($hours as $h): ?>
            <div class="visit-card">
                <h3><?= e($h['label']) ?></h3>
                <p class="visit-time"><?= fmt_hour($h['open']) ?> – <?= fmt_hour($h['close']) ?> daily</p>
                <p><?= e($h['desc']) ?></p>
            </div>
            <?php endforeach; ?>
            <div class="visit-card">
                <h3>Ansuran</h3>
                <p class="visit-time">During sales hours</p>
                <p>Ask our sales team about installment plans, or <a href="ansuran.php" class="inline-link">apply for JCL online</a>.</p>
            </div>
        </div>
        <p class="visit-note">🛠️ We don't keep ready stock: every PC is built to order and delivered within <strong>7 working days</strong>. Coming for a repair or upgrade? Please pick up your PC before <strong>6:00 PM</strong>, while our technician is on duty.</p>
    </div>
</section>

<?php require '../includes/footer.php'; ?>

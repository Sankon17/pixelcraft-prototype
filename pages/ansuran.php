<?php
$base = '../';
$page_title = 'Ansuran - PixelCraft';
$lang = 'ms';

$apply_url = 'https://forms.gle/3iVTBiN1ZGBfUsSMA';

$eligibility = [
    ['label' => 'Umur',         'value' => '18 - 60 tahun'],
    ['label' => 'Warganegara',  'value' => 'Malaysia'],
    ['label' => 'Pekerjaan',    'value' => 'Bekerja lebih 3 bulan'],
    ['label' => 'Gaji Asas',    'value' => 'Lebih RM1,000 sebulan'],
    ['label' => 'Pengecualian', 'value' => 'Tidak termasuk anggota tentera (askar) dan pasangan', 'warn' => true],
];

$documents = [
    'Salinan kad pengenalan (depan & belakang)',
    'Slip Gaji 3 bulan terkini',
    'Penyata Bank 3 bulan terkini',
    'Penyata KWSP (EPF Statement) terkini',
    'Bil utiliti (elektrik/air) 1 bulan (alamat mesti sama dengan alamat rumah)',
];

require '../includes/header.php';
?>

<!-- INTRO -->
<section class="page-hero ansuran-hero">
    <div class="hero-badge">Pelan Ansuran</div>
    <h1>Tak perlu risau kalau nak beli <span class="red">setup impian</span> 💻✨</h1>
    <p>Dekat Pixel Craft, anda boleh buat ansuran guna pelbagai kaedah pembayaran. Senang &amp; fleksibel, ikut bajet anda 🔧💻</p>
</section>

<!-- JCL -->
<section class="section-band" id="jcl">
    <div class="section">
        <div class="jcl-intro">
            <span class="jcl-pill">💰 Ansuran JCL</span>
            <h2>Dapatkan PC Gaming Idaman Anda Secara Ansuran! 🎮</h2>
            <p>Miliki PC gaming berprestasi tinggi yang anda impikan kini bukan lagi angan-angan. Kami menawarkan ansuran JCL yang membolehkan anda membuat pembelian secara bulanan yang teratur.</p>
        </div>

        <div class="jcl-grid">
            <div class="info-card">
                <h3><span class="info-num">1</span> Syarat Kelayakan JCL</h3>
                <dl class="elig-list">
                    <?php foreach ($eligibility as $row): ?>
                    <div class="elig-row<?= !empty($row['warn']) ? ' warn' : '' ?>">
                        <dt><?= e($row['label']) ?></dt>
                        <dd><?= e($row['value']) ?></dd>
                    </div>
                    <?php endforeach; ?>
                </dl>
            </div>

            <div class="info-card">
                <h3><span class="info-num">2</span> Dokumen Sokongan</h3>
                <ul class="doc-list">
                    <?php foreach ($documents as $doc): ?>
                    <li><?= e($doc) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <div class="notice">
            <span class="notice-icon">ℹ️</span>
            <p>Sila ambil perhatian, terdapat caj faedah (interest) sebanyak <strong>1.25% setiap bulan</strong>.</p>
        </div>

        <div class="cta-box">
            <div>
                <h3>Sedia untuk memohon?</h3>
                <p>Sediakan dokumen sokongan anda, kemudian isi borang permohonan dalam talian.</p>
            </div>
            <a href="<?= e($apply_url) ?>" class="btn btn-lg" target="_blank" rel="noopener">Mohon Sekarang →</a>
        </div>
    </div>
</section>

<?php require '../includes/footer.php'; ?>

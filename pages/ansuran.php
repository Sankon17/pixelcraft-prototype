<?php
$base = '../';
$page_title = 'Ansuran - PixelCraft';
// English page; the eligibility and documents cards stay in Malay (lang="ms" on those cards)

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
    <div class="hero-badge">Instalment Plans</div>
    <h1>No need to worry about buying your <span class="red">dream setup</span> 💻✨</h1>
    <p>At Pixel Craft, you can pay in instalments with a range of payment methods. Easy &amp; flexible, to suit your budget 🔧💻</p>
</section>

<!-- JCL -->
<section class="section-band" id="jcl">
    <div class="section">
        <div class="jcl-intro">
            <span class="jcl-pill">💰 JCL Instalments</span>
            <h2>Get Your Dream Gaming PC in Instalments! 🎮</h2>
            <p>Owning the high-performance gaming PC you've always wanted is no longer just a dream. We offer JCL instalments so you can pay for your purchase in regular monthly payments.</p>
        </div>

        <div class="jcl-grid">
            <div class="info-card" lang="ms">
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

            <div class="info-card" lang="ms">
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
            <p>Please note that there is an interest charge of <strong>1.25% per month</strong>.</p>
        </div>

        <div class="cta-box">
            <div>
                <h3>Ready to apply?</h3>
                <p>Get your supporting documents ready, then fill in the online application form.</p>
            </div>
            <a href="<?= e($apply_url) ?>" class="btn btn-lg" target="_blank" rel="noopener">Apply Now →</a>
        </div>
    </div>
</section>

<?php require '../includes/footer.php'; ?>

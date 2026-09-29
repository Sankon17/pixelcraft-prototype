</main>

<footer class="site-footer">
    <div class="footer-inner">
        <div>
            <div class="logo"><?= logo_img($base, true) ?></div>
            <p>Malaysia's custom PC builder. Official partner of Armageddon &amp; TT Racing.</p>
            <div class="social-links">
                <?php foreach (store_contacts()['social'] as $name => $url): ?>
                <?php $icon = asset_image($base, 'social', strtolower($name) . '.png'); ?>
                <a href="<?= e($url) ?>" target="_blank" rel="noopener">
                    <?php if ($icon): ?><img src="<?= e($icon) ?>" alt="" class="social-icon"><?php endif; ?>
                    <?= e($name) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <div>
            <h4>Shop</h4>
            <a href="<?= $base ?>pages/pc-categories.php">Custom PCs</a>
            <a href="<?= $base ?>pages/products.php">Accessories</a>
            <a href="<?= $base ?>pages/services.php">Services</a>
            <a href="<?= $base ?>pages/cart.php">Cart</a>
            <a href="<?= $base ?>pages/ansuran.php">Ansuran</a>
        </div>
        <div>
            <h4>Visit Us</h4>
            <span>282, Jalan Bandar Senawang 1,<br>Pusat Bandar Senawang,<br>70450 Seremban, Negeri Sembilan</span>
            <span>Open daily · Sales 9am–10pm</span>
            <?php foreach (store_contacts()['whatsapp'] as $wa): ?>
            <a href="<?= e($wa['url']) ?>" target="_blank" rel="noopener">💬 <?= e($wa['label']) ?>: <?= e($wa['display']) ?></a>
            <?php endforeach; ?>
            <a href="<?= $base ?>pages/location.php">Get directions →</a>
        </div>
    </div>
    <p class="copyright">&copy; <?= date('Y') ?> Pixel Craft Sdn Bhd (1598691-U). All rights reserved.</p>
</footer>

<script src="<?= asset($base, 'assets/js/main.js') ?>"></script>
</body>
</html>

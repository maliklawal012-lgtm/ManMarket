</main>

<footer class="site-footer">
    <div class="container footer-grid">
        <div class="footer-col footer-brand">
            <a href="/market/index.php" class="logo">
                <?php $siteLogo = get_setting('site_logo'); ?>
                <?php if ($siteLogo): ?>
                    <img src="/market/<?= e($siteLogo) ?>" alt="<?= e(get_setting('site_name') ?: 'ManMarket') ?>" class="logo-mark logo-mark-img">
                <?php else: ?>
                    <span class="logo-mark">M</span>
                <?php endif; ?>
                <span class="logo-text light"><?= e(get_setting('site_name')) ?></span>
            </a>
            <p><?= e(get_setting('site_footer_tagline', 'Le plus grand marché en ligne de la ville de Man. Achetez local, soutenez nos vendeurs, faites-vous livrer partout à Man.')) ?></p>
            <div class="footer-social">
                <?php foreach (social_networks() as $netKey => $net): ?>
                    <a href="<?= e($net['url']) ?>" target="_blank" rel="noopener" aria-label="<?= e($net['label']) ?>"><?= icon($netKey, 16) ?></a>
                <?php endforeach; ?>
                <a href="https://wa.me/<?= e(get_setting('site_whatsapp')) ?>" target="_blank" rel="noopener" class="footer-social-whatsapp" aria-label="WhatsApp"><?= icon('whatsapp', 16) ?></a>
            </div>
        </div>

        <div class="footer-col footer-col-links">
            <div class="footer-col-group">
                <h4>Liens rapides</h4>
                <ul>
                    <li><a href="/market/index.php">Accueil</a></li>
                    <li><a href="/market/boutiques.php">Boutiques</a></li>
                    <li><a href="/market/offres.php">Offres</a></li>
                    <li><a href="/market/services.php">Services</a></li>
                    <li><a href="/market/actualites.php">Actualités</a></li>
                    <li><a href="/market/contact.php">Contact</a></li>
                </ul>
            </div>

            <div class="footer-col-group">
                <h4>Catégories</h4>
                <ul>
                    <?php foreach ($navCategories ?? [] as $cat): ?>
                        <li><a href="/market/categorie.php?slug=<?= e($cat['slug']) ?>"><?= e($cat['name']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <div class="footer-col">
            <h4>Contact</h4>
            <ul class="footer-contact">
                <li><?= icon('map-pin', 16) ?> <?= e(get_setting('site_address')) ?></li>
                <li><?= icon('phone', 16) ?> <?= e(get_setting('site_phone')) ?></li>
                <li><?= icon('headset', 16) ?> Support <?= e(mb_strtolower(get_setting('site_support_hours'))) ?></li>
            </ul>
        </div>
    </div>

    <div class="footer-bottom">
        <div class="container footer-bottom-inner">
            <p>&copy; <?= date('Y') ?> <?= e(get_setting('site_name')) ?>. Tous droits réservés. <a href="/market/politique-confidentialite.php" class="link-muted-light">Politique de confidentialité</a></p>
            <p>Fait avec soin pour la ville de Man.</p>
        </div>
    </div>
</footer>

<script src="<?= e(asset_url('assets/js/main.js')) ?>"></script>
</body>
</html>

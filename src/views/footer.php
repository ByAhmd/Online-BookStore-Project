<?php
/**
 * Closes <main> and renders the shared footer. Rendered by page_footer().
 *
 * @var list<string> $scripts
 */

$appName = (string) config('app.name', 'BookWise');
try {
    // Same rule as the navigation: a signed-in customer session wins.
    $footerAdmin = current_user() === null && current_admin() !== null;
} catch (Throwable) {
    // The error page must still render when the database is unavailable.
    $footerAdmin = false;
}
?>
    </main>

    <footer class="site-footer">
        <p class="footer-brand"><?= e($appName) ?></p>
        <p class="footer-tagline"><?= e(t('common.footer.tagline')) ?></p>
        <ul class="footer-links">
            <li><?= e(t('common.footer.copyright', ['app' => $appName])) ?></li>
<?php if ($footerAdmin): ?>
            <li><a href="admin_dashboard.php"><?= e(t('common.footer.dashboard')) ?></a></li>
<?php else: ?>
            <li><a href="Explore_Page.php"><?= e(t('common.footer.explore')) ?></a></li>
<?php endif; ?>
            <li><a href="About_us.php"><?= e(t('common.footer.about_us')) ?></a></li>
        </ul>
    </footer>

    <script src="<?= e(asset('js/common.js')) ?>" defer></script>
<?php foreach ($scripts as $script): ?>
    <script src="<?= e(asset('js/' . $script)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>

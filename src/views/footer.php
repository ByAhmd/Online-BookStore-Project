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
        <p class="footer-tagline">Your personalized reading companion since 2024!</p>
        <ul class="footer-links">
            <li><?= e($appName) ?> &copy; 2024</li>
<?php if ($footerAdmin): ?>
            <li><a href="admin_dashboard.php">Dashboard</a></li>
<?php else: ?>
            <li><a href="Explore_Page.php">Explore</a></li>
<?php endif; ?>
            <li><a href="About_us.php">About Us</a></li>
        </ul>
    </footer>

    <script src="js/common.js" defer></script>
<?php foreach ($scripts as $script): ?>
    <script src="js/<?= e($script) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>

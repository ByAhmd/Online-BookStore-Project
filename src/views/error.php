<?php
/**
 * Generic error page. Rendered by abort() / render_error_page().
 *
 * @var int $status
 * @var string $title
 * @var string $message
 * @var string|null $detail  Only set when APP_DEBUG=true
 */
try {
    // Administrators cannot use the shop pages, so point them at their dashboard.
    $errorAdmin = current_user() === null && current_admin() !== null;
} catch (Throwable) {
    // The database may be what failed.
    $errorAdmin = false;
}
page_header($title);
?>
        <section class="error-page" aria-labelledby="error-title">
            <p class="error-code"><?= (int) $status ?></p>
            <h1 id="error-title"><?= e($title) ?></h1>
            <p><?= e($message) ?></p>
<?php if (!empty($detail)): ?>
            <pre class="error-detail"><?= e($detail) ?></pre>
<?php endif; ?>
            <p class="error-actions">
<?php if ($errorAdmin): ?>
                <a class="btn" href="admin_dashboard.php">Back to dashboard</a>
                <a class="btn btn-secondary" href="Home_Page.php">View storefront</a>
<?php else: ?>
                <a class="btn" href="Home_Page.php">Back to home</a>
                <a class="btn btn-secondary" href="Explore_Page.php">Explore books</a>
<?php endif; ?>
            </p>
        </section>
<?php
page_footer();

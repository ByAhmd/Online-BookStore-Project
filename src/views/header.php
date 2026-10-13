<?php
/**
 * Shared document head + navigation. Rendered by page_header().
 *
 * @var string $title
 * @var list<string> $styles
 * @var string $bodyClass
 */

try {
    $navUser = current_user();
    $navAdmin = $navUser === null ? current_admin() : null;
    $navCartCount = $navUser !== null ? cart_item_count((int) $navUser['id']) : 0;
} catch (Throwable) {
    // The error page must still render when the database is unavailable.
    $navUser = null;
    $navAdmin = null;
    $navCartCount = 0;
}
$appName = (string) config('app.name', 'BookWise');
$navCurrent = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$navLink = static function (string $href, string $label) use ($navCurrent): string {
    $current = $navCurrent === $href ? ' aria-current="page"' : '';
    return '<a href="' . e($href) . '"' . $current . '>' . e($label) . '</a>';
};
$flashes = take_flashes();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title) ?> · <?= e($appName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@300..700&family=Source+Sans+3:ital,wght@0,200..900;1,200..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/common.css">
<?php foreach ($styles as $style): ?>
    <link rel="stylesheet" href="css/<?= e($style) ?>">
<?php endforeach; ?>
</head>
<body class="<?= e($bodyClass) ?>">
    <a class="skip-link" href="#main-content">Skip to main content</a>
    <header class="site-header">
        <nav class="site-nav" aria-label="Main">
            <a class="brand" href="<?= $navAdmin !== null ? 'admin_dashboard.php' : 'Home_Page.php' ?>">
                <?= e($appName) ?><?php if ($navAdmin !== null): ?> <span class="brand-tag">Admin</span><?php endif; ?>
            </a>

            <ul class="nav-links">
<?php if ($navAdmin !== null): ?>
                <li><?= $navLink('admin_dashboard.php', 'Dashboard') ?></li>
                <li><?= $navLink('Home_Page.php', 'Storefront') ?></li>
<?php else: ?>
                <li><?= $navLink('Home_Page.php', 'Home') ?></li>
                <li><?= $navLink('Explore_Page.php', 'Explore') ?></li>
                <li><?= $navLink('About_us.php', 'About') ?></li>
<?php endif; ?>
            </ul>

            <ul class="nav-actions">
<?php if ($navUser !== null): ?>
                <li>
                    <a class="cart-link" href="cart.php" aria-label="Cart, <?= (int) $navCartCount ?> <?= (int) $navCartCount === 1 ? 'item' : 'items' ?>"<?= $navCurrent === 'cart.php' ? ' aria-current="page"' : '' ?>>
                        <img src="imges/cart-icon.png" alt="" width="28" height="28">
                        <span class="cart-badge" aria-hidden="true"><?= (int) $navCartCount ?></span>
                    </a>
                </li>
                <li>
                    <a class="avatar-link" href="profilePage.php" title="My profile">
                        <img class="nav-avatar" src="<?= e(avatar_url($navUser['profile_image'] ?? null)) ?>" alt="My profile" width="44" height="44">
                    </a>
                </li>
<?php endif; ?>
<?php if ($navUser !== null || $navAdmin !== null): ?>
                <li>
                    <form class="logout-form" method="post" action="logout.php">
                        <?= csrf_field() ?>
                        <button type="submit" class="pill pill-dark">Log out</button>
                    </form>
                </li>
<?php else: ?>
                <li><a class="pill pill-dark" href="index.php?form=signup">Sign Up</a></li>
                <li><a class="pill pill-red" href="index.php?form=login">Login</a></li>
<?php endif; ?>
            </ul>
        </nav>
    </header>

    <main id="main-content" class="site-main" tabindex="-1">
<?php if ($flashes !== []): ?>
        <div class="flash-stack">
<?php foreach ($flashes as $flash): ?>
            <div class="flash flash-<?= e($flash['type']) ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>">
                <p dir="auto"><?= e($flash['message']) ?></p>
                <button type="button" class="flash-close" aria-label="Dismiss message">&times;</button>
            </div>
<?php endforeach; ?>
        </div>
<?php endif; ?>

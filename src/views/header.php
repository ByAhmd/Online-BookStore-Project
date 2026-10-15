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
$locale = current_locale();
$navCurrent = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$navLink = static function (string $href, string $label) use ($navCurrent): string {
    $current = $navCurrent === $href ? ' aria-current="page"' : '';
    return '<a href="' . e($href) . '"' . $current . '>' . e($label) . '</a>';
};
$otherLocales = array_diff_key(SUPPORTED_LOCALES, [$locale => true]);
// Public demo without an email provider: emails are shown on inbox.php.
$navInbox = demo_inbox_enabled();
$flashes = take_flashes();
?>
<!DOCTYPE html>
<html lang="<?= e($locale) ?>" dir="<?= e(text_direction()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title) ?> · <?= e($appName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@300..700&family=Source+Sans+3:ital,wght@0,200..900;1,200..900&family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('css/common.css')) ?>">
<?php foreach ($styles as $style): ?>
    <link rel="stylesheet" href="<?= e(asset('css/' . $style)) ?>">
<?php endforeach; ?>
</head>
<body class="<?= e($bodyClass) ?>">
    <a class="skip-link" href="#main-content"><?= e(t('common.skip_link')) ?></a>
    <header class="site-header">
<?php /* The demo banner belongs to the header (the banner landmark), so screen readers moving between landmarks reach it. */ ?>
<?php if (config('app.demo')): ?>
        <p class="demo-banner" role="note">
            <?= e(t($navInbox ? 'common.demo_banner_inbox' : 'common.demo_banner')) ?>
<?php if ($navUser === null && $navAdmin === null): ?>
            <?= t_html('common.demo_try', ['link' => '<a href="index.php?form=login#demo-logins">' . e(t('common.demo_try_link')) . '</a>']) ?>
<?php endif; ?>
        </p>
<?php endif; ?>
        <nav class="site-nav" aria-label="<?= e(t('common.main_navigation')) ?>">
            <a class="brand" href="<?= $navAdmin !== null ? 'admin_dashboard.php' : 'Home_Page.php' ?>">
                <?= e($appName) ?><?php if ($navAdmin !== null): ?> <span class="brand-tag"><?= e(t('common.admin_tag')) ?></span><?php endif; ?>
            </a>

            <ul class="nav-links">
<?php if ($navAdmin !== null): ?>
                <li><?= $navLink('admin_dashboard.php', t('common.nav.dashboard')) ?></li>
                <li><?= $navLink('admin_orders.php', t('common.nav.orders')) ?></li>
                <li><?= $navLink('Home_Page.php', t('common.nav.storefront')) ?></li>
<?php else: ?>
                <li><?= $navLink('Home_Page.php', t('common.nav.home')) ?></li>
                <li><?= $navLink('Explore_Page.php', t('common.nav.explore')) ?></li>
                <li><?= $navLink('About_us.php', t('common.nav.about')) ?></li>
<?php endif; ?>
<?php if ($navInbox): ?>
                <li><a href="inbox.php" aria-label="<?= e(t('common.nav.inbox_label')) ?>"<?= $navCurrent === 'inbox.php' ? ' aria-current="page"' : '' ?>><?= e(t('common.nav.inbox')) ?></a></li>
<?php endif; ?>
            </ul>

            <ul class="nav-actions">
<?php foreach ($otherLocales as $code => $nativeName): ?>
                <li>
                    <a class="lang-switch" href="<?= e(locale_switch_url($code)) ?>" lang="<?= e($code) ?>" hreflang="<?= e($code) ?>"
                       title="<?= e(t('common.language.switch_to', ['language' => $nativeName])) ?>"><?= e($nativeName) ?></a>
                </li>
<?php endforeach; ?>
<?php if ($navUser !== null): ?>
                <li>
                    <a class="cart-link" href="cart.php" aria-label="<?= e(tn('common.nav.cart', (int) $navCartCount)) ?>"<?= $navCurrent === 'cart.php' ? ' aria-current="page"' : '' ?>>
                        <img src="<?= e(asset('imges/cart-icon.png')) ?>" alt="" width="28" height="28">
                        <span class="cart-badge" aria-hidden="true"><?= (int) $navCartCount ?></span>
                    </a>
                </li>
                <li>
                    <a class="avatar-link" href="profilePage.php" title="<?= e(t('common.nav.my_profile')) ?>">
                        <img class="nav-avatar" src="<?= e(asset(avatar_url($navUser['profile_image'] ?? null))) ?>" alt="<?= e(t('common.nav.my_profile')) ?>" width="44" height="44">
                    </a>
                </li>
<?php endif; ?>
<?php if ($navUser !== null || $navAdmin !== null): ?>
                <li>
                    <form class="logout-form" method="post" action="logout.php">
                        <?= csrf_field() ?>
                        <button type="submit" class="pill pill-dark"><?= e(t('common.nav.logout')) ?></button>
                    </form>
                </li>
<?php else: ?>
                <li><a class="pill pill-dark" href="index.php?form=signup"><?= e(t('common.nav.sign_up')) ?></a></li>
                <li><a class="pill pill-red" href="index.php?form=login"><?= e(t('common.nav.login')) ?></a></li>
<?php endif; ?>
            </ul>
        </nav>
    </header>

    <main id="main-content" class="site-main" tabindex="-1">
<?php if ($navAdmin !== null && ($navAdmin['role'] ?? null) !== ADMIN_ROLE_OWNER): ?>
        <p class="read-only-note" role="note"><?= e(t('admin.read_only.note')) ?></p>
<?php endif; ?>
<?php if ($flashes !== []): ?>
        <div class="flash-stack">
<?php foreach ($flashes as $flash): ?>
            <div class="flash flash-<?= e($flash['type']) ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>">
                <p dir="auto"><?= e($flash['message']) ?></p>
                <button type="button" class="flash-close" aria-label="<?= e(t('common.flash.dismiss')) ?>">&times;</button>
            </div>
<?php endforeach; ?>
        </div>
<?php endif; ?>

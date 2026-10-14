<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

/*
 * The demo inbox. A public demo (APP_DEMO=true) with MAIL_DRIVER=database
 * stores its emails instead of sending them and shows them here. A browser
 * only sees the mail of the account it is signed in to and of the accounts
 * it created by signing up (demo_inbox_addresses()), so nobody can read
 * another person's confirmation or password reset links.
 */
if (!demo_inbox_enabled()) {
    abort(404);
}
if (!in_array(request_method(), ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    abort(405);
}
// Messages carry single-use links: keep them out of every cache.
header('Cache-Control: no-store');

$addresses = demo_inbox_addresses();
$isAdmin = current_user() === null && current_admin() !== null;

$timeTag = static function (string $value): string {
    $date = new DateTimeImmutable($value);
    return '<time datetime="' . e($date->format('Y-m-d\TH:i:s')) . '">' . e(display_date($date, true)) . '</time>';
};
// A message keeps its own language and text direction, whatever the interface language.
$messageLanguage = static function (string $locale): string {
    return ' lang="' . e($locale) . '" dir="' . e(text_direction($locale)) . '"';
};

if (isset($_GET['id'])) {
    $id = parse_int($_GET['id']);
    // Missing, expired and other people's messages get the same answer.
    $message = $id !== null ? demo_inbox_message($id, $addresses) : null;
    if ($message === null) {
        abort(404, t('mail.inbox.not_found'));
    }

    page_header($message['subject'], ['inbox.css']);
    ?>
        <div class="page-container inbox-page">
            <p class="inbox-back"><a href="inbox.php"><?= e(t('mail.inbox.back')) ?></a></p>
            <article class="inbox-message card" aria-labelledby="message-subject">
                <h1 class="inbox-message-subject" id="message-subject"<?= $messageLanguage($message['locale']) ?>><?= e($message['subject']) ?></h1>
                <dl class="inbox-message-meta">
                    <div>
                        <dt><?= e(t('mail.inbox.recipient')) ?></dt>
                        <dd><span dir="ltr"><?= e($message['recipient']) ?></span></dd>
                    </div>
                    <div>
                        <dt><?= e(t('mail.inbox.received')) ?></dt>
                        <dd><?= $timeTag($message['created_at']) ?></dd>
                    </div>
                </dl>
                <div class="inbox-message-body"<?= $messageLanguage($message['locale']) ?>><?= demo_mail_text_html($message['text']) ?></div>
            </article>
            <p class="inbox-note"><?= e(t('mail.inbox.links_note')) ?></p>
        </div>
    <?php
    page_footer();
    exit;
}

$messages = demo_inbox_messages($addresses);

page_header(t('mail.inbox.title'), ['inbox.css']);
?>
        <div class="page-container inbox-page">
            <h1 class="page-title"><?= e(t('mail.inbox.title')) ?></h1>
            <div class="inbox-intro">
                <p><?= e(tn('mail.inbox.intro', DEMO_MAIL_RETENTION_HOURS)) ?></p>
                <p><?= e(t($isAdmin ? 'mail.inbox.rule_admin' : 'mail.inbox.rule')) ?></p>
            </div>
<?php if ($addresses === []): ?>
            <div class="empty-state card inbox-guest">
                <p><?= e(t('mail.inbox.guest')) ?></p>
                <p class="inbox-actions">
                    <a class="btn" href="index.php?form=signup"><?= e(t('common.nav.sign_up')) ?></a>
                    <a class="btn btn-secondary" href="index.php?form=login"><?= e(t('common.nav.login')) ?></a>
                </p>
            </div>
<?php else: ?>
            <section class="inbox-list-section card" aria-labelledby="inbox-list-title">
                <h2 class="inbox-list-title" id="inbox-list-title"><?= e(t('mail.inbox.list_title')) ?></h2>
                <p class="inbox-addresses-label" id="inbox-addresses-label"><?= e(t('mail.inbox.addresses')) ?></p>
                <ul class="inbox-addresses" aria-labelledby="inbox-addresses-label">
<?php foreach ($addresses as $address): ?>
                    <li><span dir="ltr"><?= e($address) ?></span></li>
<?php endforeach; ?>
                </ul>
<?php if ($messages === []): ?>
                <p class="inbox-empty"><?= e(t('mail.inbox.empty')) ?></p>
<?php else: ?>
                <p class="inbox-summary"><?= e(tn('mail.inbox.count', count($messages))) ?></p>
                <ul class="inbox-list">
<?php foreach ($messages as $message): ?>
                    <li>
                        <a class="inbox-item" href="inbox.php?id=<?= (int) $message['id'] ?>">
                            <span class="inbox-item-subject"<?= $messageLanguage($message['locale']) ?>><?= e($message['subject']) ?></span>
                            <span class="inbox-item-meta">
                                <span class="inbox-item-recipient"><?= t_html('mail.inbox.to', ['email' => '<span dir="ltr">' . e($message['recipient']) . '</span>']) ?></span>
                                <?= $timeTag($message['created_at']) ?>
                            </span>
                        </a>
                    </li>
<?php endforeach; ?>
                </ul>
<?php endif; ?>
            </section>
<?php endif; ?>
        </div>
<?php
page_footer();

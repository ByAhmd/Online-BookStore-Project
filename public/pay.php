<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

/*
 * The payment simulator's hosted checkout page (src/payment_simulator.php).
 * It stands in for Stripe's payment page while PAYMENT_SIMULATOR is on and
 * does not exist (404) otherwise. Like Stripe's page it needs no store
 * sign-in: the random session id in the link is the key. It never asks for
 * card details. Its buttons choose how the payment ends; the store then
 * learns the outcome exactly as it would from Stripe (success_pay.php,
 * cancel_pay.php, and settle_pending_orders() at the next checkout).
 */

if (!payment_simulator_enabled()) {
    abort(404);
}
if (!in_array(request_method(), ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, POST');
    abort(405);
}

$sessionId = input_string($_GET, 'session', 300);
if (!preg_match('/^cs_[A-Za-z0-9_]{1,250}$/D', $sessionId)) {
    abort(404, t('shop.pay.unknown'));
}
$pageUrl = 'pay.php?session=' . rawurlencode($sessionId);

// Sessions are created by this store, so their return addresses are the
// store's own; anything else is never followed.
$storeUrl = static function (string $url): string {
    return str_starts_with($url, config('app.url') . '/') ? $url : 'cart.php';
};

if (is_post()) {
    verify_csrf();
    $action = input_string($_POST, 'action', 20);
    if (!in_array($action, PAYMENT_SIMULATOR_ACTIONS, true)) {
        abort(400, t('shop.pay.invalid_action'));
    }
    $result = payment_simulator_checkout_action($sessionId, $action);
    if ($result === null) {
        abort(404, t('shop.pay.unknown'));
    }
    if ($result['redirect'] === null) {
        // A declined card: the session stays open, as on Stripe's page.
        flash('error', t('shop.pay.declined'));
        redirect($pageUrl);
    }
    redirect($storeUrl($result['redirect']));
}

$session = payment_simulator_session($sessionId);
if ($session === null) {
    abort(404, t('shop.pay.unknown'));
}

$currency = (string) $session['currency'];
$money = static fn (int $minor): string => format_money(minor_to_decimal($minor), $currency);
$items = is_array($session['simulator']['line_items'] ?? null) ? $session['simulator']['line_items'] : [];
$open = $session['status'] === 'open';
$paid = $session['payment_status'] === 'paid';
$closedState = $session['status'] === 'complete' ? ($paid ? 'paid' : 'processing') : 'expired';
$returnUrl = $storeUrl($session['status'] === 'complete'
    ? str_replace('{CHECKOUT_SESSION_ID}', $sessionId, (string) $session['success_url'])
    : (string) $session['cancel_url']);
$options = [
    'pay' => ['class' => 'btn btn-pay', 'label' => t('shop.pay.pay', ['amount' => $money((int) $session['amount_total'])])],
    'decline' => ['class' => 'btn btn-secondary', 'label' => t('shop.pay.decline')],
    'slow' => ['class' => 'btn', 'label' => t('shop.pay.slow')],
    'cancel' => ['class' => 'btn btn-outline', 'label' => t('shop.pay.cancel')],
];

header('Cache-Control: no-store');
page_header(t('shop.pay.title'), ['checkout.css']);
?>
        <div class="page-container checkout-result">
            <section class="card result-card pay-card" aria-labelledby="pay-title">
                <p class="result-eyebrow"><?= e(t('shop.pay.eyebrow')) ?></p>
                <h1 id="pay-title" class="result-title"><?= e(t('shop.pay.heading', ['store' => config('app.name', 'BookWise')])) ?></h1>
                <p class="pay-notice" role="note"><?= e(t('shop.pay.notice')) ?></p>

<?php if ($items !== []): ?>
                <table class="order-table">
                    <caption class="sr-only"><?= e(t('shop.pay.items_caption')) ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?= e(t('shop.pay.col_book')) ?></th>
                            <th scope="col" class="num"><?= e(t('shop.pay.col_qty')) ?></th>
                            <th scope="col" class="num"><?= e(t('shop.pay.col_total')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
<?php foreach ($items as $item):
    $quantity = (int) ($item['quantity'] ?? 0);
    $unit = (int) ($item['unit_amount'] ?? 0);
    ?>
                        <tr>
                            <td>
                                <span class="order-book" dir="auto"><?= e($item['name'] ?? '') ?></span>
                                <span class="order-unit"><?= e(t('shop.pay.each', ['price' => $money($unit)])) ?></span>
                            </td>
                            <td class="num"><?= $quantity ?></td>
                            <td class="num"><?= e($money($unit * $quantity)) ?></td>
                        </tr>
<?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th scope="row" colspan="2"><?= e(t('shop.pay.total')) ?></th>
                            <td class="num"><?= e($money((int) $session['amount_total'])) ?></td>
                        </tr>
                    </tfoot>
                </table>
<?php endif; ?>

<?php if ($open): ?>
                <form class="pay-form" method="post" action="<?= e($pageUrl) ?>">
                    <?= csrf_field() ?>
                    <p id="pay-choose" class="pay-choose"><?= e(t('shop.pay.choose')) ?></p>
                    <div class="pay-options" role="group" aria-labelledby="pay-choose">
<?php foreach ($options as $action => $option): ?>
                        <div class="pay-option">
                            <button type="submit" class="<?= e($option['class']) ?>" name="action" value="<?= e($action) ?>" aria-describedby="pay-hint-<?= e($action) ?>"><?= e($option['label']) ?></button>
                            <p id="pay-hint-<?= e($action) ?>" class="pay-hint"><?= e(t('shop.pay.' . $action . '_hint')) ?></p>
                        </div>
<?php endforeach; ?>
                    </div>
                </form>
<?php else: ?>
                <p class="result-lead"><?= e(t('shop.pay.closed.' . $closedState)) ?></p>
                <div class="result-actions">
                    <a class="btn btn-pay" href="<?= e($returnUrl) ?>"><?= e(t('shop.pay.back_to_store')) ?></a>
                </div>
<?php endif; ?>
            </section>
        </div>
<?php
page_footer();

<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

/*
 * Checkout, the step between the cart and the payment page.
 * GET shows the books, the totals (prices include VAT, delivery is free) and
 * the delivery address: one of the customer's saved addresses, or a new one
 * typed in here. POST saves a new address in the customer's address book if
 * one was entered, then starts the payment exactly as before (start_checkout())
 * and sends the browser to Stripe or to the payment simulator.
 */

if (!in_array(request_method(), ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, POST');
    abort(405);
}
// Signed-out (e.g. expired) sessions go to the login page rather than a 419.
$user = require_user();
$userId = (int) $user['id'];

if (is_post()) {
    verify_csrf();

    $choice = input_string($_POST, 'address', 20);
    if ($choice === 'new') {
        [$address, $errors] = validate_address_input($_POST);
        $makeDefault = ($_POST['make_default'] ?? null) === '1';
        if ($errors !== []) {
            remember_input(['address' => 'new', 'make_default' => $makeDefault ? '1' : ''] + $address);
            remember_errors($errors);
            flash('error', t('shop.checkout_page.address_invalid'));
            redirect('checkout.php');
        }
        $saved = save_customer_address($userId, $address, null, $makeDefault);
        if ($saved['status'] !== 'created' || $saved['id'] === null) {
            flash('error', tn('shop.checkout_page.address_limit', ADDRESS_MAX_PER_CUSTOMER));
            redirect('checkout.php');
        }
        $addressId = $saved['id'];
    } else {
        $addressId = parse_int($choice);
    }

    // Prices, totals and the payment session are all built server-side from the stored cart.
    try {
        redirect(start_checkout($user, $addressId), 303);
    } catch (CheckoutException $e) {
        flash('error', $e->getMessage());
        redirect($e->getCode() === CHECKOUT_ERROR_ADDRESS ? 'checkout.php' : 'cart.php');
    }
}

$lines = cart_lines($userId);
// The cart explains what is wrong with it (empty, or more copies than in stock).
if ($lines === [] || cart_unavailable_lines($lines) !== []) {
    redirect('cart.php');
}
$totalMinor = cart_total_minor($lines);
$vatMinor = vat_included_minor($totalMinor);
$itemCount = array_sum(array_column($lines, 'quantity'));
$appName = (string) config('app.name', 'BookWise');
$simulated = payment_simulator_enabled();

$addresses = customer_addresses($userId);
$canAdd = count($addresses) < ADDRESS_MAX_PER_CUSTOMER;
$chosen = old('address');
if ($chosen === '' || ($chosen === 'new' && !$canAdd)) {
    $chosen = $addresses !== [] ? (string) $addresses[0]['id'] : 'new';
}
$newValues = [];
$newErrors = [];
foreach (ADDRESS_FIELDS as $field) {
    $newValues[$field] = old($field);
    $newErrors[$field] = form_error($field);
}
$makeDefault = old('make_default') === '1';

header('Cache-Control: no-store');
page_header(t('shop.checkout_page.title'), ['checkout.css']);
?>
        <div class="page-container checkout-page">
            <h1 class="page-title checkout-title"><?= e(t('shop.checkout_page.title')) ?></h1>
            <ol class="checkout-steps" aria-label="<?= e(t('shop.checkout_page.steps_label')) ?>">
                <li><a href="cart.php"><?= e(t('shop.checkout_page.step_cart')) ?></a></li>
                <li aria-current="step"><?= e(t('shop.checkout_page.step_review')) ?></li>
                <li><?= e(t('shop.checkout_page.step_payment')) ?></li>
            </ol>

            <form class="checkout-form" method="post" action="checkout.php" data-once>
                <?= csrf_field() ?>
                <div class="checkout-layout">
                    <div class="checkout-main">
                        <section class="card checkout-card" aria-labelledby="delivery-title">
                            <h2 id="delivery-title" class="checkout-card-title"><?= e(t('shop.checkout_page.delivery_heading')) ?></h2>
                            <p class="checkout-note"><?= e(t('shop.checkout_page.delivery_note')) ?></p>
<?php if ($addresses !== []): ?>
                            <fieldset class="address-options">
                                <legend class="sr-only"><?= e(t('shop.checkout_page.choose_address')) ?></legend>
<?php foreach ($addresses as $address):
    $optionId = 'address-' . (int) $address['id'];
    ?>
                                <div class="address-option">
                                    <input type="radio" id="<?= e($optionId) ?>" name="address" value="<?= (int) $address['id'] ?>"<?= $chosen === (string) $address['id'] ? ' checked' : '' ?> required>
                                    <label for="<?= e($optionId) ?>">
                                        <span class="address-text"><?= address_html($address) ?></span>
<?php if ((int) $address['is_default'] === 1): ?>
                                        <span class="address-badge"><?= e(t('shop.checkout_page.default_badge')) ?></span>
<?php endif; ?>
                                    </label>
                                </div>
<?php endforeach; ?>
<?php if ($canAdd): ?>
                                <div class="address-option address-option-new">
                                    <input type="radio" id="address-new" name="address" value="new"<?= $chosen === 'new' ? ' checked' : '' ?> required>
                                    <label for="address-new"><?= e(t('shop.checkout_page.new_address')) ?></label>
                                    <div class="new-address" id="new-address">
                                        <?php render('address_fields', ['values' => $newValues, 'errors' => $newErrors, 'prefix' => 'new-address', 'required' => false]); ?>
                                        <div class="form-field checkbox-field">
                                            <div class="checkbox-row">
                                                <input type="checkbox" id="new-address-default" name="make_default" value="1"<?= $makeDefault ? ' checked' : '' ?>>
                                                <label for="new-address-default"><?= e(t('shop.checkout_page.make_default')) ?></label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
<?php else: ?>
                                <p class="checkout-note"><?= e(tn('shop.checkout_page.address_limit', ADDRESS_MAX_PER_CUSTOMER)) ?></p>
<?php endif; ?>
                            </fieldset>
                            <p class="checkout-link-row"><a href="profilePage.php?section=addresses"><?= e(t('shop.checkout_page.manage_addresses')) ?></a></p>
<?php else: ?>
                            <input type="hidden" name="address" value="new">
                            <p class="checkout-intro"><?= e(t('shop.checkout_page.new_address_intro')) ?></p>
                            <div class="new-address new-address-only" id="new-address">
                                <?php render('address_fields', ['values' => $newValues, 'errors' => $newErrors, 'prefix' => 'new-address', 'required' => true]); ?>
                            </div>
<?php endif; ?>
                        </section>

                        <section class="card checkout-card" aria-labelledby="items-title">
                            <h2 id="items-title" class="checkout-card-title"><?= e(t('shop.checkout_page.items_heading')) ?></h2>
                            <ul class="checkout-items">
<?php foreach ($lines as $line):
    $cover = image_url($line['cover_image'], '');
    ?>
                                <li class="checkout-item">
<?php if ($cover !== ''): ?>
                                    <?= cover_picture($cover, '48px', ['class' => 'checkout-cover', 'width' => 48, 'height' => 68, 'loading' => 'lazy']) ?>
<?php else: ?>
                                    <span class="checkout-cover checkout-cover-placeholder" aria-hidden="true"></span>
<?php endif; ?>
                                    <span class="checkout-item-text">
                                        <span class="checkout-item-title" dir="auto"><?= e($line['title']) ?></span>
                                        <span class="checkout-item-meta"><?= e(t('shop.checkout_page.quantity', ['count' => $line['quantity']])) ?> · <?= e(t('shop.checkout_page.each', ['price' => format_minor($line['unit_minor'])])) ?></span>
                                    </span>
                                    <span class="checkout-item-total"><?= e(format_minor($line['line_minor'])) ?></span>
                                </li>
<?php endforeach; ?>
                            </ul>
                            <p class="checkout-link-row"><a href="cart.php"><?= e(t('shop.checkout_page.edit_cart')) ?></a></p>
                        </section>
                    </div>

                    <aside class="card checkout-summary" aria-labelledby="summary-title">
                        <h2 id="summary-title" class="checkout-card-title"><?= e(t('shop.checkout_page.summary_title')) ?></h2>
                        <dl class="summary-list">
                            <div class="summary-row">
                                <dt><?= e(t('shop.checkout_page.items')) ?></dt>
                                <dd><?= (int) $itemCount ?></dd>
                            </div>
                            <div class="summary-row">
                                <dt><?= e(t('shop.totals.delivery')) ?></dt>
                                <dd><?= e(t('shop.totals.free')) ?></dd>
                            </div>
                            <div class="summary-row summary-total">
                                <dt><?= e(t('shop.totals.total')) ?></dt>
                                <dd><?= e(format_minor($totalMinor)) ?></dd>
                            </div>
                            <div class="summary-row summary-vat">
                                <dt><?= e(t('shop.totals.vat_included', ['rate' => VAT_RATE_PERCENT])) ?></dt>
                                <dd><?= e(format_minor($vatMinor)) ?></dd>
                            </div>
                        </dl>
                        <button type="submit" class="btn btn-pay">
                            <span class="label-idle"><?= e(t('shop.checkout_page.pay')) ?></span>
                            <span class="label-busy"><?= e(t($simulated ? 'shop.pay.cart_redirecting' : 'shop.cart.redirecting')) ?></span>
                        </button>
                        <p class="secure-note"><?= e($simulated ? t('shop.pay.cart_note') : t('shop.cart.secure_note', ['app' => $appName])) ?></p>
                    </aside>
                </div>
            </form>
        </div>
<?php
page_footer(['checkout.js']);

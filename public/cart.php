<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = require_user();

$lines = cart_lines((int) $user['id']);
$totalMinor = cart_total_minor($lines);
$itemCount = array_sum(array_column($lines, 'quantity'));

page_header('My Cart', ['cart.css']);
?>
        <div class="page-container cart-page">
            <h1 class="page-title cart-title">My Cart</h1>

<?php if ($lines === []): ?>
            <section class="card cart-empty empty-state" aria-labelledby="cart-empty-title">
                <h2 id="cart-empty-title">Your cart is empty</h2>
                <p>Find your next favourite read and add it to your cart.</p>
                <a class="btn btn-pay" href="Explore_Page.php">Explore books</a>
            </section>
<?php else: ?>
            <div class="cart-layout">
                <section class="card cart-card" aria-labelledby="cart-items-title">
                    <h2 id="cart-items-title" class="sr-only">Books in your cart</h2>
                    <table class="cart-table" role="table">
                        <thead role="rowgroup">
                            <tr role="row">
                                <th scope="col" role="columnheader">Book</th>
                                <th scope="col" role="columnheader">Price</th>
                                <th scope="col" role="columnheader">Quantity</th>
                                <th scope="col" role="columnheader">Total</th>
                                <th scope="col" role="columnheader"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody role="rowgroup">
<?php foreach ($lines as $line):
    $cartId = (int) $line['cart_id'];
    $cover = image_url($line['cover_image'], '');
    ?>
                            <tr role="row">
                                <td class="cart-book" role="cell">
                                    <a class="cart-book-link" href="Selected.php?id=<?= (int) $line['book_id'] ?>">
<?php if ($cover !== ''): ?>
                                        <img class="cart-cover" src="<?= e($cover) ?>" alt="" width="56" height="80" loading="lazy">
<?php else: ?>
                                        <span class="cart-cover cart-cover-placeholder" aria-hidden="true"></span>
<?php endif; ?>
                                        <span class="cart-book-text">
                                            <span class="cart-book-title" dir="auto"><?= e($line['title']) ?></span>
                                            <span class="cart-book-author">by <span dir="auto"><?= e($line['author']) ?></span></span>
                                        </span>
                                    </a>
                                </td>
                                <td class="cart-price" role="cell" data-label="Price"><?= e(format_minor($line['unit_minor'])) ?></td>
                                <td class="cart-qty" role="cell" data-label="Quantity">
                                    <form class="qty-form" method="post" action="update_cart.php">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="cart_id" value="<?= $cartId ?>">
                                        <label class="sr-only" for="qty-<?= $cartId ?>">Quantity of <?= e($line['title']) ?></label>
                                        <input class="form-control qty-input" id="qty-<?= $cartId ?>" type="number" name="quantity"
                                               value="<?= (int) $line['quantity'] ?>" min="1" max="<?= CART_MAX_QUANTITY ?>" step="1"
                                               inputmode="numeric" required>
                                        <button type="submit" class="btn btn-secondary btn-small">Update<span class="sr-only"> quantity of <?= e($line['title']) ?></span></button>
                                    </form>
                                </td>
                                <td class="cart-line-total" role="cell" data-label="Total"><?= e(format_minor($line['line_minor'])) ?></td>
                                <td class="cart-remove" role="cell">
                                    <form method="post" action="delete_from_cart.php" data-confirm="Remove “<?= e($line['title']) ?>” from your cart?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="cart_id" value="<?= $cartId ?>">
                                        <button type="submit" class="btn btn-danger btn-small">Remove<span class="sr-only"> <?= e($line['title']) ?></span></button>
                                    </form>
                                </td>
                            </tr>
<?php endforeach; ?>
                        </tbody>
                        <tfoot role="rowgroup">
                            <tr class="total-row" role="row">
                                <th scope="row" role="rowheader" colspan="3">Grand Total</th>
                                <td role="cell" class="total-row-amount"><?= e(format_minor($totalMinor)) ?></td>
                                <td role="cell" class="total-row-spacer"></td>
                            </tr>
                        </tfoot>
                    </table>
                </section>

                <aside class="card cart-summary" aria-labelledby="cart-summary-title">
                    <h2 id="cart-summary-title" class="cart-summary-title">Order summary</h2>
                    <dl class="summary-list">
                        <div class="summary-row">
                            <dt>Items</dt>
                            <dd><?= (int) $itemCount ?></dd>
                        </div>
                        <div class="summary-row summary-total">
                            <dt>Total</dt>
                            <dd><?= e(format_minor($totalMinor)) ?></dd>
                        </div>
                    </dl>
                    <form class="checkout-form" method="post" action="checkout.php" data-once>
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-pay">
                            <span class="label-idle">Proceed to Pay</span>
                            <span class="label-busy">Redirecting to Stripe&hellip;</span>
                        </button>
                    </form>
                    <p class="secure-note">You will be redirected to Stripe's secure checkout. <?= e(config('app.name', 'BookWise')) ?> never stores your card details.</p>
                    <a class="continue-link" href="Explore_Page.php">Continue shopping</a>
                </aside>
            </div>
<?php endif; ?>
        </div>
<?php
page_footer();

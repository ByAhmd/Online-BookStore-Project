<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = require_user();

$lines = cart_lines((int) $user['id']);
$totalMinor = cart_total_minor($lines);
$itemCount = array_sum(array_column($lines, 'quantity'));
// Lines asking for more copies than are in stock block checkout until fixed.
$blocked = cart_unavailable_lines($lines) !== [];
$appName = (string) config('app.name', 'BookWise');

page_header(t('shop.cart.title'), ['cart.css']);
?>
        <div class="page-container cart-page">
            <h1 class="page-title cart-title"><?= e(t('shop.cart.title')) ?></h1>

<?php if ($lines === []): ?>
            <section class="card cart-empty empty-state" aria-labelledby="cart-empty-title">
                <h2 id="cart-empty-title"><?= e(t('shop.cart.empty_title')) ?></h2>
                <p><?= e(t('shop.cart.empty_text')) ?></p>
                <a class="btn btn-pay" href="Explore_Page.php"><?= e(t('shop.cart.explore')) ?></a>
            </section>
<?php else: ?>
<?php if ($blocked): ?>
            <p class="cart-alert" role="alert" id="cart-stock-alert"><?= e(t('shop.cart.blocked')) ?></p>
<?php endif; ?>
            <div class="cart-layout">
                <section class="card cart-card" aria-labelledby="cart-items-title">
                    <h2 id="cart-items-title" class="sr-only"><?= e(t('shop.cart.items_heading')) ?></h2>
                    <table class="cart-table" role="table">
                        <thead role="rowgroup">
                            <tr role="row">
                                <th scope="col" role="columnheader"><?= e(t('shop.cart.col_book')) ?></th>
                                <th scope="col" role="columnheader"><?= e(t('shop.cart.col_price')) ?></th>
                                <th scope="col" role="columnheader"><?= e(t('shop.cart.col_quantity')) ?></th>
                                <th scope="col" role="columnheader"><?= e(t('shop.cart.col_total')) ?></th>
                                <th scope="col" role="columnheader"><span class="sr-only"><?= e(t('shop.cart.col_actions')) ?></span></th>
                            </tr>
                        </thead>
                        <tbody role="rowgroup">
<?php foreach ($lines as $line):
    $cartId = (int) $line['cart_id'];
    $cover = image_url($line['cover_image'], '');
    $problemId = 'stock-problem-' . $cartId;
    ?>
                            <tr role="row" class="<?= $line['available'] ? '' : 'cart-row-unavailable' ?>">
                                <td class="cart-book" role="cell">
                                    <a class="cart-book-link" href="Selected.php?id=<?= (int) $line['book_id'] ?>">
<?php if ($cover !== ''): ?>
                                        <img class="cart-cover" src="<?= e($cover) ?>" alt="" width="56" height="80" loading="lazy">
<?php else: ?>
                                        <span class="cart-cover cart-cover-placeholder" aria-hidden="true"></span>
<?php endif; ?>
                                        <span class="cart-book-text">
                                            <span class="cart-book-title" dir="auto"><?= e($line['title']) ?></span>
                                            <span class="cart-book-author"><?= t_markup('shop.book.by', ['author' => '<span dir="auto">' . e($line['author']) . '</span>']) ?></span>
                                        </span>
                                    </a>
<?php if (!$line['available']): ?>
                                    <p class="cart-stock-problem" id="<?= e($problemId) ?>">
                                        <?= e($line['limit'] === 0 ? t('shop.cart.stock_out_line') : tn('shop.cart.stock_low_line', $line['stock'])) ?>
                                    </p>
<?php elseif (book_stock_status($line['stock']) === 'low'): ?>
                                    <p class="stock-badge stock-low"><?= e(book_availability($line['stock'])) ?></p>
<?php endif; ?>
                                </td>
                                <td class="cart-price" role="cell" data-label="<?= e(t('shop.cart.col_price')) ?>"><?= e(format_minor($line['unit_minor'])) ?></td>
                                <td class="cart-qty" role="cell" data-label="<?= e(t('shop.cart.col_quantity')) ?>">
<?php if ($line['limit'] > 0): ?>
                                    <form class="qty-form" method="post" action="update_cart.php">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="cart_id" value="<?= $cartId ?>">
                                        <label class="sr-only" for="qty-<?= $cartId ?>"><?= e(t('shop.cart.quantity_of', ['title' => $line['title']])) ?></label>
                                        <input class="form-control qty-input" id="qty-<?= $cartId ?>" type="number" name="quantity"
                                               value="<?= (int) $line['quantity'] ?>" min="1" max="<?= (int) $line['limit'] ?>" step="1"
                                               inputmode="numeric" required<?= $line['available'] ? '' : ' aria-invalid="true" aria-describedby="' . e($problemId) . '"' ?>>
                                        <button type="submit" class="btn btn-secondary btn-small"><?= e(t('shop.cart.update')) ?><span class="sr-only"> <?= e(t('shop.cart.update_sr', ['title' => $line['title']])) ?></span></button>
                                    </form>
<?php else: ?>
                                    <span class="cart-qty-static" aria-describedby="<?= e($problemId) ?>"><?= (int) $line['quantity'] ?></span>
<?php endif; ?>
                                </td>
                                <td class="cart-line-total" role="cell" data-label="<?= e(t('shop.cart.col_total')) ?>"><?= e(format_minor($line['line_minor'])) ?></td>
                                <td class="cart-remove" role="cell">
                                    <form method="post" action="delete_from_cart.php" data-confirm="<?= e(t('shop.cart.remove_confirm', ['title' => $line['title']])) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="cart_id" value="<?= $cartId ?>">
                                        <button type="submit" class="btn btn-danger btn-small"><?= e(t('shop.cart.remove')) ?><span class="sr-only"> <?= e($line['title']) ?></span></button>
                                    </form>
                                </td>
                            </tr>
<?php endforeach; ?>
                        </tbody>
                        <tfoot role="rowgroup">
                            <tr class="total-row" role="row">
                                <th scope="row" role="rowheader" colspan="3"><?= e(t('shop.cart.grand_total')) ?></th>
                                <td role="cell" class="total-row-amount"><?= e(format_minor($totalMinor)) ?></td>
                                <td role="cell" class="total-row-spacer"></td>
                            </tr>
                        </tfoot>
                    </table>
                </section>

                <aside class="card cart-summary" aria-labelledby="cart-summary-title">
                    <h2 id="cart-summary-title" class="cart-summary-title"><?= e(t('shop.cart.summary_title')) ?></h2>
                    <dl class="summary-list">
                        <div class="summary-row">
                            <dt><?= e(t('shop.cart.items')) ?></dt>
                            <dd><?= (int) $itemCount ?></dd>
                        </div>
                        <div class="summary-row summary-total">
                            <dt><?= e(t('shop.cart.total')) ?></dt>
                            <dd><?= e(format_minor($totalMinor)) ?></dd>
                        </div>
                    </dl>
<?php if ($blocked): ?>
                    <button type="button" class="btn btn-pay" disabled aria-disabled="true" aria-describedby="cart-stock-alert"><?= e(t('shop.cart.pay')) ?></button>
                    <p class="checkout-blocked-note"><?= e(t('shop.cart.blocked_short')) ?></p>
<?php else: ?>
                    <form class="checkout-form" method="post" action="checkout.php" data-once>
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-pay">
                            <span class="label-idle"><?= e(t('shop.cart.pay')) ?></span>
                            <span class="label-busy"><?= e(t('shop.cart.redirecting')) ?></span>
                        </button>
                    </form>
<?php endif; ?>
                    <p class="secure-note"><?= e(t('shop.cart.secure_note', ['app' => $appName])) ?></p>
                    <a class="continue-link" href="Explore_Page.php"><?= e(t('shop.cart.continue')) ?></a>
                </aside>
            </div>
<?php endif; ?>
        </div>
<?php
page_footer();

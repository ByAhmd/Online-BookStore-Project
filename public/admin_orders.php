<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

require_admin();

if (!in_array(request_method(), ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    abort(405);
}

$status = input_string($_GET, 'status', 20);
if (!in_array($status, ORDER_STATUS_FILTERS, true)) {
    $status = '';
}
// Delivery stage of paid orders (processing, shipped, delivered).
$fulfilment = input_string($_GET, 'fulfilment', 20);
if (!in_array($fulfilment, FULFILMENT_STATUSES, true)) {
    $fulfilment = '';
}
$query = input_string($_GET, 'q', 255);

$listing = admin_order_list($status, $query, $_GET['page'] ?? null, $fulfilment);
$orders = $listing['orders'];
$pagination = $listing['pagination'];
$filtered = $status !== '' || $fulfilment !== '' || $query !== '';

$pageUrl = static function (int $page) use ($status, $fulfilment, $query): string {
    $params = array_filter(['status' => $status, 'fulfilment' => $fulfilment, 'q' => $query], static fn (string $value): bool => $value !== '');
    if ($page > 1) {
        $params['page'] = $page;
    }
    return 'admin_orders.php' . ($params === [] ? '' : '?' . http_build_query($params));
};
$formatDate = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '<span class="muted">—</span>';
    }
    $date = new DateTimeImmutable($value);
    return '<time datetime="' . e($date->format('Y-m-d\TH:i:s')) . '">' . e(display_date($date, true)) . '</time>';
};
// Lets long addresses wrap at the "@" instead of mid-word.
$breakableEmail = static function (string $email): string {
    $at = strrpos($email, '@');
    return $at === false ? e($email) : e(substr($email, 0, $at)) . '<wbr>' . e(substr($email, $at));
};

page_header(t('admin.orders.title'), ['Admin_dashboard.css']);
?>
        <div class="page-container admin-page">
            <header class="admin-heading">
                <h1 class="page-title"><?= e(t('admin.orders.title')) ?></h1>
                <p class="admin-welcome"><a href="admin_dashboard.php"><?= e(t('admin.orders.back_dashboard')) ?></a></p>
            </header>

            <section class="admin-content" aria-labelledby="order-filter-title">
                <h2 id="order-filter-title"><?= e(t('admin.orders.filter_title')) ?></h2>
                <form class="order-filters" method="get" action="admin_orders.php" role="search">
                    <div class="form-field">
                        <label for="order-status"><?= e(t('admin.orders.status_label')) ?></label>
                        <select class="form-control" id="order-status" name="status">
                            <option value=""<?= $status === '' ? ' selected' : '' ?>><?= e(t('admin.orders.status_all')) ?></option>
<?php foreach (ORDER_STATUS_FILTERS as $option): ?>
                            <option value="<?= e($option) ?>"<?= $status === $option ? ' selected' : '' ?>><?= e(t('common.status.' . $option)) ?></option>
<?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-field">
                        <label for="order-fulfilment"><?= e(t('admin.orders.fulfilment_label')) ?></label>
                        <select class="form-control" id="order-fulfilment" name="fulfilment" aria-describedby="order-fulfilment-hint">
                            <option value=""<?= $fulfilment === '' ? ' selected' : '' ?>><?= e(t('admin.orders.fulfilment_all')) ?></option>
<?php foreach (FULFILMENT_STATUSES as $option): ?>
                            <option value="<?= e($option) ?>"<?= $fulfilment === $option ? ' selected' : '' ?>><?= e(order_status_label($option)) ?></option>
<?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-field">
                        <label for="order-search"><?= e(t('admin.orders.search_label')) ?></label>
                        <input class="form-control" type="search" id="order-search" name="q" maxlength="255" dir="ltr" value="<?= e($query) ?>" placeholder="<?= e(t('admin.orders.search_placeholder')) ?>" autocomplete="off" spellcheck="false">
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn admin-submit"><?= e(t('admin.orders.apply')) ?></button>
<?php if ($filtered): ?>
                        <a class="btn btn-secondary" href="admin_orders.php"><?= e(t('admin.orders.reset')) ?></a>
<?php endif; ?>
                    </div>
                    <p class="form-hint filters-hint" id="order-fulfilment-hint"><?= e(t('admin.orders.fulfilment_hint')) ?></p>
                </form>
            </section>

            <section class="admin-content" id="order-list" aria-labelledby="order-list-title">
                <h2 id="order-list-title"><?= e(t('admin.orders.list_title')) ?></h2>
<?php if ($orders === []): ?>
                <div class="empty-state">
                    <p><?= e(t($filtered ? 'admin.orders.no_results' : 'admin.orders.no_orders')) ?></p>
                </div>
<?php else: ?>
                <p class="table-summary" role="status"><?= e(tn('admin.orders.summary', $pagination['total'], [
                    'from' => $pagination['offset'] + 1,
                    'to' => $pagination['offset'] + count($orders),
                ])) ?></p>
                <div class="table-scroll" role="region" aria-labelledby="order-list-title" tabindex="0">
                    <table class="admin-table orders-table order-list-table">
                        <thead>
                            <tr>
                                <th scope="col"><?= e(t('admin.orders.col_order')) ?></th>
                                <th scope="col"><?= e(t('admin.orders.col_date')) ?></th>
                                <th scope="col"><?= e(t('admin.orders.col_customer')) ?></th>
                                <th scope="col" class="num"><?= e(t('admin.orders.col_items')) ?></th>
                                <th scope="col" class="num"><?= e(t('admin.orders.col_total')) ?></th>
                                <th scope="col"><?= e(t('admin.orders.col_status')) ?></th>
                                <th scope="col"><?= e(t('admin.orders.col_delivery')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
<?php foreach ($orders as $order): ?>
                            <tr>
                                <th scope="row"><a href="admin_order.php?id=<?= (int) $order['id'] ?>">#<?= (int) $order['id'] ?></a></th>
                                <td><?= $formatDate($order['created_at']) ?></td>
                                <td>
<?php if ($order['customer_email'] !== null): ?>
                                    <span class="customer-name" dir="auto"><?= e($order['customer_name']) ?></span>
                                    <span class="customer-email"><?= $breakableEmail((string) $order['customer_email']) ?></span>
<?php else: ?>
                                    <span class="customer-name customer-deleted"><?= e(t('admin.orders.deleted_account')) ?></span>
<?php endif; ?>
                                </td>
                                <td class="num"><?= (int) $order['item_count'] ?></td>
                                <td class="num"><?= e(format_money((string) $order['total_amount'], (string) $order['currency'])) ?></td>
                                <td><span class="status-badge status-<?= e($order['status']) ?>"><?= e(t('common.status.' . $order['status'])) ?></span></td>
<?php if ($order['status'] === 'paid' && in_array($order['fulfilment_status'], FULFILMENT_STATUSES, true)): ?>
                                <td><span class="fulfilment-badge status-<?= e($order['fulfilment_status']) ?>"><?= e(order_status_label((string) $order['fulfilment_status'])) ?></span></td>
<?php else: ?>
                                <td><span class="muted"><?= e(t('admin.order.none')) ?></span></td>
<?php endif; ?>
                            </tr>
<?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?= render_pager($pagination, $pageUrl, t('admin.orders.pager')) ?>
<?php endif; ?>
            </section>
        </div>
<?php
page_footer();

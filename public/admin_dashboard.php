<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$admin = require_admin();

if (!in_array(request_method(), ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, POST');
    abort(405);
}

// The books table page to come back to after an action.
$booksPageUrl = static function (int $page): string {
    return 'admin_dashboard.php' . ($page > 1 ? '?page=' . $page : '') . '#manage-books';
};

if (is_post()) {
    // Read-only administrators may look but never change anything.
    require_admin_write();
    verify_csrf();

    switch (input_string($_POST, 'action', 50)) {
        case 'add_book':
            // An uploaded cover file replaces the path/URL field.
            $hasUpload = has_uploaded_file($_FILES, 'cover_upload');
            [$book, $errors] = validate_book_input($_POST, $hasUpload);
            $storedCover = null;
            if ($hasUpload) {
                if ($errors === []) {
                    try {
                        $storedCover = store_cover_upload((array) $_FILES['cover_upload']);
                        $book['cover_image'] = $storedCover;
                    } catch (UploadException $e) {
                        $errors['cover_upload'] = $e->getMessage();
                    }
                } else {
                    // Browsers never re-fill file inputs, so say so next to the field.
                    $errors['cover_upload'] = t('admin.form.cover_reselect');
                }
            }
            if ($errors !== []) {
                remember_input($book);
                remember_errors($errors);
                flash('error', t('admin.dashboard.not_added'));
                redirect('admin_dashboard.php#add-book');
            }
            try {
                create_book($book);
            } catch (Throwable $e) {
                delete_uploaded_cover($storedCover);
                throw $e;
            }
            flash('success', t('admin.dashboard.added', ['title' => bidi_isolate($book['title'])]));
            redirect('admin_dashboard.php');

        case 'delete_book':
            $id = parse_int($_POST['id'] ?? null);
            if ($id === null) {
                abort(404, t('admin.dashboard.book_not_found'));
            }
            $book = find_book($id);
            // delete_book() also deletes the book's uploaded cover file.
            if ($book !== null && delete_book($id)) {
                flash('success', t('admin.dashboard.deleted', ['title' => bidi_isolate($book['title'])]));
            } else {
                flash('error', t('admin.dashboard.delete_missing'));
            }
            redirect($booksPageUrl(paginate(book_count(), ADMIN_PAGE_SIZE, $_POST['page'] ?? null)['page']));

        default:
            abort(400, t('admin.dashboard.unknown_action'));
    }
}

$genres = list_genres();
$booksPagination = paginate(book_count(), ADMIN_PAGE_SIZE, $_GET['page'] ?? null);
$books = all_books_for_admin($booksPagination['per_page'], $booksPagination['offset']);
$orders = recent_orders(20);
// Revenue per order currency: stored orders keep the currency they were paid in.
$salesByCurrency = db_all(
    "SELECT currency, COUNT(*) AS paid_orders, SUM(total_amount) AS revenue
     FROM orders WHERE status = 'paid' GROUP BY currency ORDER BY currency"
);
$paidOrders = array_sum(array_map(static fn (array $row): int => (int) $row['paid_orders'], $salesByCurrency));
$revenueFigures = $salesByCurrency === []
    ? [format_money(0)]
    : array_map(static fn (array $row): string => format_money((string) $row['revenue'], (string) $row['currency']), $salesByCurrency);

$fields = ['title', 'author', 'genre', 'description', 'price', 'stock', 'cover_image', 'cover_upload'];
$errors = [];
$values = [];
foreach ($fields as $field) {
    $values[$field] = old($field);
    if (($message = form_error($field)) !== null) {
        $errors[$field] = $message;
    }
}

// aria-invalid / aria-describedby for a form control, including an optional hint.
$describe = static function (string $field, ?string $hintId = null) use ($errors): string {
    $ids = array_filter([$hintId, isset($errors[$field]) ? "book-$field-error" : null]);
    $attrs = isset($errors[$field]) ? ' aria-invalid="true"' : '';
    return $ids === [] ? $attrs : $attrs . ' aria-describedby="' . e(implode(' ', $ids)) . '"';
};
$fieldError = static function (string $field) use ($errors): string {
    return isset($errors[$field])
        ? '<p class="field-error" id="book-' . e($field) . '-error">' . e($errors[$field]) . '</p>'
        : '';
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
$currency = strtoupper((string) config('stripe.currency'));
// Read-only administrators see the forms switched off and customers' details shortened.
$canWrite = admin_can_write();
$locked = $canWrite ? '' : ' disabled';
$showCustomers = admin_sees_customer_details();

page_header(t('admin.dashboard.title'), ['Admin_dashboard.css']);
?>
        <div class="page-container admin-page">
            <header class="admin-heading">
                <h1 class="page-title"><?= e(t('admin.dashboard.title')) ?></h1>
                <p class="admin-welcome"><?= t_markup('admin.dashboard.signed_in_as', ['name' => '<span dir="auto">' . e(admin_display_name($admin)) . '</span>']) ?></p>
            </header>

            <section class="admin-stats" aria-label="<?= e(t('admin.dashboard.overview')) ?>">
                <div class="stat-card">
                    <p class="stat-label"><?= e(t('admin.dashboard.stat_books')) ?></p>
                    <p class="stat-value"><?= e(number_format(book_count())) ?></p>
                </div>
                <div class="stat-card">
                    <p class="stat-label"><?= e(t('admin.dashboard.stat_genres')) ?></p>
                    <p class="stat-value"><?= e(number_format(count($genres))) ?></p>
                </div>
                <div class="stat-card">
                    <p class="stat-label"><?= e(t('admin.dashboard.stat_paid')) ?></p>
                    <p class="stat-value"><?= e(number_format($paidOrders)) ?></p>
                </div>
                <div class="stat-card">
                    <p class="stat-label"><?= e(t('admin.dashboard.stat_revenue')) ?></p>
                    <p class="stat-value"><?= implode(' ', array_map(static fn (string $figure): string => '<span class="stat-figure">' . e($figure) . '</span>', $revenueFigures)) ?></p>
                </div>
            </section>

            <section class="admin-content" id="add-book" aria-labelledby="add-book-title">
                <h2 id="add-book-title"><?= e(t('admin.dashboard.add_title')) ?></h2>
                <form class="book-form" method="post" action="admin_dashboard.php" enctype="multipart/form-data" data-once>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_book">

                    <div class="form-field">
                        <label for="book-title"><?= e(t('admin.form.title')) ?></label>
                        <input class="form-control" type="text" id="book-title" name="title" maxlength="255" required dir="auto" value="<?= e($values['title']) ?>"<?= $locked ?><?= $describe('title') ?>>
                        <?= $fieldError('title') ?>
                    </div>

                    <div class="form-field">
                        <label for="book-author"><?= e(t('admin.form.author')) ?></label>
                        <input class="form-control" type="text" id="book-author" name="author" maxlength="255" required dir="auto" value="<?= e($values['author']) ?>"<?= $locked ?><?= $describe('author') ?>>
                        <?= $fieldError('author') ?>
                    </div>

                    <div class="form-field">
                        <label for="book-genre"><?= e(t('admin.form.genre')) ?></label>
                        <input class="form-control" type="text" id="book-genre" name="genre" maxlength="100" list="genre-options" autocomplete="off" required dir="auto" value="<?= e($values['genre']) ?>"<?= $locked ?><?= $describe('genre', 'book-genre-hint') ?>>
                        <datalist id="genre-options">
<?php foreach ($genres as $genre): ?>
                            <option value="<?= e($genre) ?>"></option>
<?php endforeach; ?>
                        </datalist>
                        <p class="form-hint" id="book-genre-hint"><?= e(t('admin.form.genre_hint')) ?></p>
                        <?= $fieldError('genre') ?>
                    </div>

                    <div class="form-field">
                        <label for="book-price"><?= e(t('admin.form.price', ['currency' => $currency])) ?></label>
                        <input class="form-control" type="number" id="book-price" name="price" step="0.01" min="1" max="10000" inputmode="decimal" required value="<?= e($values['price']) ?>"<?= $locked ?><?= $describe('price') ?>>
                        <?= $fieldError('price') ?>
                    </div>

                    <div class="form-field">
                        <label for="book-stock"><?= e(t('admin.form.stock')) ?></label>
                        <input class="form-control" type="number" id="book-stock" name="stock" step="1" min="0" max="<?= BOOK_STOCK_MAX ?>" inputmode="numeric" required value="<?= e($values['stock']) ?>"<?= $locked ?><?= $describe('stock', 'book-stock-hint') ?>>
                        <p class="form-hint" id="book-stock-hint"><?= e(t('admin.form.stock_hint', ['max' => BOOK_STOCK_MAX])) ?></p>
                        <?= $fieldError('stock') ?>
                    </div>

                    <div class="form-field form-field-wide">
                        <label for="book-description"><?= e(t('admin.form.description')) ?></label>
                        <textarea class="form-control" id="book-description" name="description" maxlength="5000" rows="5" required dir="auto"<?= $locked ?><?= $describe('description') ?>><?= e($values['description']) ?></textarea>
                        <?= $fieldError('description') ?>
                    </div>

                    <div class="form-field form-field-wide">
                        <label for="book-cover"><?= e(t('admin.form.cover')) ?></label>
                        <input class="form-control" type="text" id="book-cover" name="cover_image" maxlength="255" spellcheck="false" dir="ltr" value="<?= e($values['cover_image']) ?>"<?= $locked ?><?= $describe('cover_image', 'book-cover-hint') ?>>
                        <p class="form-hint" id="book-cover-hint"><?= e(t('admin.form.cover_hint')) ?></p>
                        <?= $fieldError('cover_image') ?>
                    </div>

                    <div class="form-field form-field-wide">
                        <label for="book-cover-upload"><?= e(t('admin.form.cover_upload')) ?></label>
                        <input class="form-control form-file" type="file" id="book-cover-upload" name="cover_upload" accept="image/jpeg,image/png,image/gif,image/webp"<?= $locked ?><?= $describe('cover_upload', 'book-cover-upload-hint') ?>>
                        <p class="form-hint" id="book-cover-upload-hint"><?= e(t('admin.form.cover_upload_hint')) ?></p>
                        <?= $fieldError('cover_upload') ?>
                    </div>

                    <div class="form-actions form-field-wide">
                        <button type="submit" class="btn admin-submit"<?= $locked ?>><?= e(t('admin.dashboard.add_button')) ?></button>
                    </div>
                </form>
            </section>

            <section class="admin-content" id="manage-books" aria-labelledby="manage-books-title">
                <h2 id="manage-books-title"><?= e(t('admin.dashboard.manage_title')) ?></h2>
<?php if ($books === []): ?>
                <div class="empty-state">
                    <p><?= e(t('admin.dashboard.catalog_empty')) ?></p>
                    <a class="btn" href="#add-book"><?= e(t('admin.dashboard.add_a_book')) ?></a>
                </div>
<?php else: ?>
                <p class="table-summary"><?= e(tn('admin.dashboard.books_summary', $booksPagination['total'], [
                    'from' => $booksPagination['offset'] + 1,
                    'to' => $booksPagination['offset'] + count($books),
                ])) ?></p>
                <div class="table-scroll" role="region" aria-label="<?= e(t('admin.table_region', ['title' => t('admin.dashboard.manage_title')])) ?>" tabindex="0">
                    <table class="admin-table books-table">
                        <thead>
                            <tr>
                                <th scope="col"><span class="sr-only"><?= e(t('admin.dashboard.col_cover')) ?></span></th>
                                <th scope="col"><?= e(t('admin.dashboard.col_title')) ?></th>
                                <th scope="col"><?= e(t('admin.dashboard.col_author')) ?></th>
                                <th scope="col"><?= e(t('admin.dashboard.col_genre')) ?></th>
                                <th scope="col" class="num"><?= e(t('admin.dashboard.col_price')) ?></th>
                                <th scope="col" class="num"><?= e(t('admin.dashboard.col_stock')) ?></th>
                                <th scope="col"><?= e(t('admin.dashboard.col_actions')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
<?php foreach ($books as $book):
    $stock = (int) $book['stock'];
    ?>
                            <tr>
                                <td class="cover-cell">
                                    <?= cover_picture(image_url($book['cover_image']), '48px', ['width' => 48, 'height' => 68, 'loading' => 'lazy']) ?>
                                </td>
                                <th scope="row" class="title-cell"><span dir="auto"><?= e($book['title']) ?></span></th>
                                <td><span dir="auto"><?= e($book['author']) ?></span></td>
                                <td><span dir="auto"><?= e($book['genre']) ?></span></td>
                                <td class="num"><?= e(format_money($book['price'])) ?></td>
                                <td class="num stock-cell stock-<?= e(book_stock_status($stock)) ?>"><?= e(number_format($stock)) ?></td>
                                <td>
                                    <div class="row-actions">
                                        <a class="action-edit" href="edit_book.php?id=<?= (int) $book['id'] ?>"><?= e(t($canWrite ? 'admin.dashboard.edit' : 'admin.read_only.view')) ?><span class="sr-only"> “<?= e($book['title']) ?>”</span></a>
                                        <form method="post" action="admin_dashboard.php" data-confirm="<?= e(t('admin.dashboard.delete_confirm', ['title' => $book['title']])) ?>" data-once>
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_book">
                                            <input type="hidden" name="id" value="<?= (int) $book['id'] ?>">
                                            <input type="hidden" name="page" value="<?= (int) $booksPagination['page'] ?>">
                                            <button type="submit" class="action-delete"<?= $locked ?>><?= e(t('admin.dashboard.delete')) ?><span class="sr-only"> “<?= e($book['title']) ?>”</span></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
<?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?= render_pager($booksPagination, $booksPageUrl, t('admin.dashboard.books_pager')) ?>
<?php endif; ?>
            </section>

            <section class="admin-content" id="recent-orders" aria-labelledby="recent-orders-title">
                <div class="section-head">
                    <h2 id="recent-orders-title"><?= e(t('admin.dashboard.recent_title')) ?></h2>
                    <a class="section-link" href="admin_orders.php"><?= e(t('admin.dashboard.view_all_orders')) ?></a>
                </div>
<?php if ($orders === []): ?>
                <div class="empty-state">
                    <p><?= e(t('admin.dashboard.no_orders')) ?></p>
                </div>
<?php else: ?>
                <div class="table-scroll" role="region" aria-label="<?= e(t('admin.table_region', ['title' => t('admin.dashboard.recent_title')])) ?>" tabindex="0">
                    <table class="admin-table orders-table">
                        <thead>
                            <tr>
                                <th scope="col"><?= e(t('admin.orders.col_order')) ?></th>
                                <th scope="col"><?= e(t('admin.orders.col_customer')) ?></th>
                                <th scope="col" class="num"><?= e(t('admin.orders.col_items')) ?></th>
                                <th scope="col" class="num"><?= e(t('admin.orders.col_total')) ?></th>
                                <th scope="col"><?= e(t('admin.orders.col_status')) ?></th>
                                <th scope="col"><?= e(t('admin.orders.col_created')) ?></th>
                                <th scope="col"><?= e(t('admin.orders.col_paid')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
<?php foreach ($orders as $order): ?>
                            <tr>
                                <th scope="row"><a href="admin_order.php?id=<?= (int) $order['id'] ?>">#<?= (int) $order['id'] ?></a></th>
                                <td>
<?php if ($order['customer_email'] !== null): ?>
<?php if ($showCustomers): ?>
                                    <span class="customer-name" dir="auto"><?= e($order['customer_name']) ?></span>
                                    <span class="customer-email"><?= $breakableEmail((string) $order['customer_email']) ?></span>
<?php else: ?>
                                    <span class="customer-name" dir="auto"><?= e(masked_name((string) $order['customer_name'])) ?></span>
                                    <span class="customer-email"><?= e(masked_email((string) $order['customer_email'])) ?></span>
<?php endif; ?>
<?php else: ?>
                                    <span class="customer-name customer-deleted"><?= e(t('admin.orders.deleted_account')) ?></span>
<?php endif; ?>
                                </td>
                                <td class="num"><?= (int) $order['item_count'] ?></td>
                                <td class="num"><?= e(format_money((string) $order['total_amount'], (string) $order['currency'])) ?></td>
                                <td><span class="status-badge status-<?= e($order['status']) ?>"><?= e(t('common.status.' . $order['status'])) ?></span></td>
                                <td><?= $formatDate($order['created_at']) ?></td>
                                <td><?= $formatDate($order['paid_at']) ?></td>
                            </tr>
<?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
<?php endif; ?>
            </section>
        </div>
<?php
page_footer();

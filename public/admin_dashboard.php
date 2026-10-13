<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$admin = require_admin();

if (!in_array(request_method(), ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, POST');
    abort(405);
}

if (is_post()) {
    verify_csrf();

    switch (input_string($_POST, 'action', 50)) {
        case 'add_book':
            [$book, $errors] = validate_book_input($_POST);
            // The CSP only allows https: images, so plain http covers would never display.
            if (!isset($errors['cover_image']) && preg_match('#^http://#i', $book['cover_image'])) {
                $errors['cover_image'] = 'Please use an https:// image URL.';
            }
            if ($errors !== []) {
                remember_input($book);
                remember_errors($errors);
                flash('error', 'The book was not added. Please fix the highlighted fields.');
                redirect('admin_dashboard.php#add-book');
            }
            create_book($book);
            flash('success', '“' . $book['title'] . '” was added.');
            redirect('admin_dashboard.php');

        case 'delete_book':
            $id = parse_int($_POST['id'] ?? null);
            if ($id === null) {
                abort(404, 'That book could not be found.');
            }
            $book = find_book($id);
            if ($book !== null && delete_book($id)) {
                flash('success', '“' . $book['title'] . '” was deleted.');
            } else {
                flash('error', 'That book could not be found. It may already have been deleted.');
            }
            redirect('admin_dashboard.php#manage-books');

        default:
            abort(400, 'Unknown dashboard action.');
    }
}

$genres = list_genres();
$books = all_books_for_admin();
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

$fields = ['title', 'author', 'genre', 'description', 'price', 'cover_image'];
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
    return '<time datetime="' . e($date->format('Y-m-d\TH:i:s')) . '">' . e($date->format('j M Y'))
        . ' <span class="time-of-day">' . e($date->format('H:i')) . '</span></time>';
};
// Lets long addresses wrap at the "@" instead of mid-word.
$breakableEmail = static function (string $email): string {
    $at = strrpos($email, '@');
    return $at === false ? e($email) : e(substr($email, 0, $at)) . '<wbr>' . e(substr($email, $at));
};

page_header('Admin Dashboard', ['Admin_dashboard.css']);
?>
        <div class="page-container admin-page">
            <header class="admin-heading">
                <h1 class="page-title">Admin Dashboard</h1>
                <p class="admin-welcome">Signed in as <span dir="auto"><?= e($admin['name']) ?></span></p>
            </header>

            <section class="admin-stats" aria-label="Store overview">
                <div class="stat-card">
                    <p class="stat-label">Books</p>
                    <p class="stat-value"><?= e(number_format(book_count())) ?></p>
                </div>
                <div class="stat-card">
                    <p class="stat-label">Genres</p>
                    <p class="stat-value"><?= e(number_format(count($genres))) ?></p>
                </div>
                <div class="stat-card">
                    <p class="stat-label">Paid orders</p>
                    <p class="stat-value"><?= e(number_format($paidOrders)) ?></p>
                </div>
                <div class="stat-card">
                    <p class="stat-label">Revenue</p>
                    <p class="stat-value"><?= implode(' ', array_map(static fn (string $figure): string => '<span class="stat-figure">' . e($figure) . '</span>', $revenueFigures)) ?></p>
                </div>
            </section>

            <section class="admin-content" id="add-book" aria-labelledby="add-book-title">
                <h2 id="add-book-title">Add New Book</h2>
                <form class="book-form" method="post" action="admin_dashboard.php" data-once>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_book">

                    <div class="form-field">
                        <label for="book-title">Book Title</label>
                        <input class="form-control" type="text" id="book-title" name="title" maxlength="255" required value="<?= e($values['title']) ?>"<?= $describe('title') ?>>
                        <?= $fieldError('title') ?>
                    </div>

                    <div class="form-field">
                        <label for="book-author">Author</label>
                        <input class="form-control" type="text" id="book-author" name="author" maxlength="255" required value="<?= e($values['author']) ?>"<?= $describe('author') ?>>
                        <?= $fieldError('author') ?>
                    </div>

                    <div class="form-field">
                        <label for="book-genre">Genre</label>
                        <input class="form-control" type="text" id="book-genre" name="genre" maxlength="100" list="genre-options" autocomplete="off" required value="<?= e($values['genre']) ?>"<?= $describe('genre', 'book-genre-hint') ?>>
                        <datalist id="genre-options">
<?php foreach ($genres as $genre): ?>
                            <option value="<?= e($genre) ?>"></option>
<?php endforeach; ?>
                        </datalist>
                        <p class="form-hint" id="book-genre-hint">Pick an existing genre or type a new one.</p>
                        <?= $fieldError('genre') ?>
                    </div>

                    <div class="form-field">
                        <label for="book-price">Price (<?= e(strtoupper((string) config('stripe.currency'))) ?>)</label>
                        <input class="form-control" type="number" id="book-price" name="price" step="0.01" min="1" max="10000" inputmode="decimal" required value="<?= e($values['price']) ?>"<?= $describe('price') ?>>
                        <?= $fieldError('price') ?>
                    </div>

                    <div class="form-field form-field-wide">
                        <label for="book-description">Description</label>
                        <textarea class="form-control" id="book-description" name="description" maxlength="5000" rows="5" required<?= $describe('description') ?>><?= e($values['description']) ?></textarea>
                        <?= $fieldError('description') ?>
                    </div>

                    <div class="form-field form-field-wide">
                        <label for="book-cover">Cover Image</label>
                        <input class="form-control" type="text" id="book-cover" name="cover_image" maxlength="255" required spellcheck="false" value="<?= e($values['cover_image']) ?>"<?= $describe('cover_image', 'book-cover-hint') ?>>
                        <p class="form-hint" id="book-cover-hint">Path under public/, e.g. imges/All_section/biology.png, or an https:// URL</p>
                        <?= $fieldError('cover_image') ?>
                    </div>

                    <div class="form-actions form-field-wide">
                        <button type="submit" class="btn admin-submit">Add Book</button>
                    </div>
                </form>
            </section>

            <section class="admin-content" id="manage-books" aria-labelledby="manage-books-title">
                <h2 id="manage-books-title">Manage Books</h2>
<?php if ($books === []): ?>
                <div class="empty-state">
                    <p>The catalog is empty. Add the first book with the form above.</p>
                    <a class="btn" href="#add-book">Add a book</a>
                </div>
<?php else: ?>
                <div class="table-scroll" role="region" aria-labelledby="manage-books-title" tabindex="0">
                    <table class="admin-table books-table">
                        <thead>
                            <tr>
                                <th scope="col"><span class="sr-only">Cover</span></th>
                                <th scope="col">Title</th>
                                <th scope="col">Author</th>
                                <th scope="col">Genre</th>
                                <th scope="col" class="num">Price</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
<?php foreach ($books as $book): ?>
                            <tr>
                                <td class="cover-cell">
                                    <img src="<?= e(image_url($book['cover_image'])) ?>" alt="" width="48" height="68" loading="lazy">
                                </td>
                                <th scope="row" class="title-cell"><span dir="auto"><?= e($book['title']) ?></span></th>
                                <td><span dir="auto"><?= e($book['author']) ?></span></td>
                                <td><?= e($book['genre']) ?></td>
                                <td class="num"><?= e(format_money($book['price'])) ?></td>
                                <td>
                                    <div class="row-actions">
                                        <a class="action-edit" href="edit_book.php?id=<?= (int) $book['id'] ?>">Edit<span class="sr-only"> “<?= e($book['title']) ?>”</span></a>
                                        <form method="post" action="admin_dashboard.php" data-confirm="<?= e('Delete “' . $book['title'] . '”? This also removes it from carts, favorites and reviews.') ?>" data-once>
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_book">
                                            <input type="hidden" name="id" value="<?= (int) $book['id'] ?>">
                                            <button type="submit" class="action-delete">Delete<span class="sr-only"> “<?= e($book['title']) ?>”</span></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
<?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
<?php endif; ?>
            </section>

            <section class="admin-content" id="recent-orders" aria-labelledby="recent-orders-title">
                <h2 id="recent-orders-title">Recent Orders</h2>
<?php if ($orders === []): ?>
                <div class="empty-state">
                    <p>No orders yet. Orders appear here as soon as customers start checkout.</p>
                </div>
<?php else: ?>
                <div class="table-scroll" role="region" aria-labelledby="recent-orders-title" tabindex="0">
                    <table class="admin-table orders-table">
                        <thead>
                            <tr>
                                <th scope="col">Order</th>
                                <th scope="col">Customer</th>
                                <th scope="col" class="num">Items</th>
                                <th scope="col" class="num">Total</th>
                                <th scope="col">Status</th>
                                <th scope="col">Created</th>
                                <th scope="col">Paid</th>
                            </tr>
                        </thead>
                        <tbody>
<?php foreach ($orders as $order): ?>
                            <tr>
                                <th scope="row">#<?= (int) $order['id'] ?></th>
                                <td>
                                    <span class="customer-name" dir="auto"><?= e($order['customer_name']) ?></span>
                                    <span class="customer-email"><?= $breakableEmail((string) $order['customer_email']) ?></span>
                                </td>
                                <td class="num"><?= (int) $order['item_count'] ?></td>
                                <td class="num"><?= e(format_money((string) $order['total_amount'], (string) $order['currency'])) ?></td>
                                <td><span class="status-badge status-<?= e($order['status']) ?>"><?= e($order['status']) ?></span></td>
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

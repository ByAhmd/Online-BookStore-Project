<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

require_admin();

if (!in_array(request_method(), ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, POST');
    abort(405);
}

$id = parse_int($_GET['id'] ?? null);
$book = $id !== null ? find_book($id) : null;
if ($book === null) {
    abort(404, t('admin.edit.not_found'));
}

$values = $book;
$errors = [];

if (is_post()) {
    verify_csrf();
    if (input_string($_POST, 'action', 50) !== 'update_book') {
        abort(400, t('admin.edit.unknown_action'));
    }

    // An uploaded cover file replaces the path/URL field.
    $hasUpload = has_uploaded_file($_FILES, 'cover_upload');
    [$clean, $errors] = validate_book_input($_POST, $hasUpload);
    $storedCover = null;
    if ($hasUpload) {
        if ($errors === []) {
            try {
                $storedCover = store_cover_upload((array) $_FILES['cover_upload']);
                $clean['cover_image'] = $storedCover;
            } catch (UploadException $e) {
                $errors['cover_upload'] = $e->getMessage();
            }
        } else {
            // Browsers never re-fill file inputs, so say so next to the field.
            $errors['cover_upload'] = t('admin.form.cover_reselect');
        }
    }

    if ($errors === []) {
        try {
            // update_book() deletes the previous uploaded cover when it is replaced.
            $updated = update_book($id, $clean);
        } catch (Throwable $e) {
            delete_uploaded_cover($storedCover);
            throw $e;
        }
        if (!$updated) {
            delete_uploaded_cover($storedCover);
            flash('error', t('admin.edit.gone'));
            redirect('admin_dashboard.php#manage-books');
        }
        flash('success', t('admin.edit.updated', ['title' => $clean['title']]));
        redirect('admin_dashboard.php#manage-books');
    }

    // Re-render the form with the submitted values and per-field errors.
    http_response_code(422);
    $values = $clean;
    if ($hasUpload) {
        $values['cover_image'] = input_string($_POST, 'cover_image', 300);
    }
    flash('error', t('admin.edit.not_saved'));
}

$genres = list_genres();

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
$addedOn = new DateTimeImmutable((string) $book['created_at']);
$stock = (int) $book['stock'];
$currency = strtoupper((string) config('stripe.currency'));

page_header(t('admin.edit.title'), ['Admin_dashboard.css']);
?>
        <div class="page-container admin-page">
            <p class="admin-back"><a href="admin_dashboard.php#manage-books"><span aria-hidden="true" class="back-arrow">&larr;</span> <?= e(t('admin.edit.back')) ?></a></p>

            <header class="admin-heading">
                <h1 class="page-title"><?= e(t('admin.edit.title')) ?></h1>
                <p class="admin-welcome"><span dir="auto"><?= e($book['title']) ?></span> <span class="muted">· #<?= (int) $book['id'] ?></span></p>
            </header>

            <div class="edit-layout">
                <aside class="admin-content cover-preview" aria-labelledby="cover-preview-title">
                    <h2 id="cover-preview-title"><?= e(t('admin.edit.current_cover')) ?></h2>
                    <img src="<?= e(image_url($book['cover_image'])) ?>" alt="<?= e(t('admin.edit.cover_alt', ['title' => $book['title']])) ?>" width="220" height="320">
                    <dl class="book-meta">
                        <dt><?= e(t('admin.edit.price')) ?></dt>
                        <dd><?= e(format_money($book['price'])) ?></dd>
                        <dt><?= e(t('admin.edit.stock')) ?></dt>
                        <dd><span class="stock-cell stock-<?= e(book_stock_status($stock)) ?>"><?= e(number_format($stock)) ?></span></dd>
                        <dt><?= e(t('admin.edit.genre')) ?></dt>
                        <dd dir="auto"><?= e($book['genre']) ?></dd>
                        <dt><?= e(t('admin.edit.added')) ?></dt>
                        <dd><time datetime="<?= e($addedOn->format('Y-m-d\TH:i:s')) ?>"><?= e(display_date($addedOn)) ?></time></dd>
                    </dl>
                </aside>

                <section class="admin-content" aria-labelledby="edit-book-title">
                    <h2 id="edit-book-title"><?= e(t('admin.edit.details')) ?></h2>
                    <form class="book-form" method="post" action="edit_book.php?id=<?= (int) $book['id'] ?>" enctype="multipart/form-data" data-once>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_book">

                        <div class="form-field">
                            <label for="book-title"><?= e(t('admin.form.title')) ?></label>
                            <input class="form-control" type="text" id="book-title" name="title" maxlength="255" required dir="auto" value="<?= e($values['title']) ?>"<?= $describe('title') ?>>
                            <?= $fieldError('title') ?>
                        </div>

                        <div class="form-field">
                            <label for="book-author"><?= e(t('admin.form.author')) ?></label>
                            <input class="form-control" type="text" id="book-author" name="author" maxlength="255" required dir="auto" value="<?= e($values['author']) ?>"<?= $describe('author') ?>>
                            <?= $fieldError('author') ?>
                        </div>

                        <div class="form-field">
                            <label for="book-genre"><?= e(t('admin.form.genre')) ?></label>
                            <input class="form-control" type="text" id="book-genre" name="genre" maxlength="100" list="genre-options" autocomplete="off" required dir="auto" value="<?= e($values['genre']) ?>"<?= $describe('genre') ?>>
                            <datalist id="genre-options">
<?php foreach ($genres as $genre): ?>
                                <option value="<?= e($genre) ?>"></option>
<?php endforeach; ?>
                            </datalist>
                            <?= $fieldError('genre') ?>
                        </div>

                        <div class="form-field">
                            <label for="book-price"><?= e(t('admin.form.price', ['currency' => $currency])) ?></label>
                            <input class="form-control" type="number" id="book-price" name="price" step="0.01" min="1" max="10000" inputmode="decimal" required value="<?= e($values['price']) ?>"<?= $describe('price') ?>>
                            <?= $fieldError('price') ?>
                        </div>

                        <div class="form-field">
                            <label for="book-stock"><?= e(t('admin.form.stock')) ?></label>
                            <input class="form-control" type="number" id="book-stock" name="stock" step="1" min="0" max="<?= BOOK_STOCK_MAX ?>" inputmode="numeric" required value="<?= e($values['stock']) ?>"<?= $describe('stock', 'book-stock-hint') ?>>
                            <p class="form-hint" id="book-stock-hint"><?= e(t('admin.form.stock_hint', ['max' => BOOK_STOCK_MAX])) ?></p>
                            <?= $fieldError('stock') ?>
                        </div>

                        <div class="form-field form-field-wide">
                            <label for="book-description"><?= e(t('admin.form.description')) ?></label>
                            <textarea class="form-control" id="book-description" name="description" maxlength="5000" rows="6" required dir="auto"<?= $describe('description') ?>><?= e($values['description']) ?></textarea>
                            <?= $fieldError('description') ?>
                        </div>

                        <div class="form-field form-field-wide">
                            <label for="book-cover"><?= e(t('admin.form.cover')) ?></label>
                            <input class="form-control" type="text" id="book-cover" name="cover_image" maxlength="255" spellcheck="false" dir="ltr" value="<?= e($values['cover_image']) ?>"<?= $describe('cover_image', 'book-cover-hint') ?>>
                            <p class="form-hint" id="book-cover-hint"><?= e(t('admin.form.cover_hint')) ?></p>
                            <?= $fieldError('cover_image') ?>
                        </div>

                        <div class="form-field form-field-wide">
                            <label for="book-cover-upload"><?= e(t('admin.form.cover_upload')) ?></label>
                            <input class="form-control form-file" type="file" id="book-cover-upload" name="cover_upload" accept="image/jpeg,image/png,image/gif,image/webp"<?= $describe('cover_upload', 'book-cover-upload-hint') ?>>
                            <p class="form-hint" id="book-cover-upload-hint"><?= e(t('admin.form.cover_upload_hint')) ?></p>
                            <?= $fieldError('cover_upload') ?>
                        </div>

                        <div class="form-actions form-field-wide">
                            <button type="submit" class="btn admin-submit"><?= e(t('admin.edit.update_button')) ?></button>
                            <a class="btn btn-secondary" href="admin_dashboard.php#manage-books"><?= e(t('admin.edit.cancel')) ?></a>
                        </div>
                    </form>
                </section>
            </div>
        </div>
<?php
page_footer();

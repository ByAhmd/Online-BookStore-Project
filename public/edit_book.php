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
    abort(404, 'That book could not be found. It may have been deleted.');
}

$values = $book;
$errors = [];

if (is_post()) {
    verify_csrf();
    if (input_string($_POST, 'action', 50) !== 'update_book') {
        abort(400, 'Unknown action.');
    }

    [$clean, $errors] = validate_book_input($_POST);
    // The CSP only allows https: images, so plain http covers would never display.
    if (!isset($errors['cover_image']) && preg_match('#^http://#i', $clean['cover_image'])) {
        $errors['cover_image'] = 'Please use an https:// image URL.';
    }

    if ($errors === []) {
        if (!update_book($id, $clean)) {
            flash('error', 'That book no longer exists. It may have been deleted.');
            redirect('admin_dashboard.php#manage-books');
        }
        flash('success', '“' . $clean['title'] . '” was updated.');
        redirect('admin_dashboard.php#manage-books');
    }

    // Re-render the form with the submitted values and per-field errors.
    http_response_code(422);
    $values = $clean;
    flash('error', 'The changes were not saved. Please fix the highlighted fields.');
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

page_header('Edit Book', ['Admin_dashboard.css']);
?>
        <div class="page-container admin-page">
            <p class="admin-back"><a href="admin_dashboard.php#manage-books">&larr; Back to dashboard</a></p>

            <header class="admin-heading">
                <h1 class="page-title">Edit Book</h1>
                <p class="admin-welcome"><span dir="auto"><?= e($book['title']) ?></span> <span class="muted">· #<?= (int) $book['id'] ?></span></p>
            </header>

            <div class="edit-layout">
                <aside class="admin-content cover-preview" aria-labelledby="cover-preview-title">
                    <h2 id="cover-preview-title">Current cover</h2>
                    <img src="<?= e(image_url($book['cover_image'])) ?>" alt="Current cover of “<?= e($book['title']) ?>”" width="220" height="320">
                    <dl class="book-meta">
                        <dt>Price</dt>
                        <dd><?= e(format_money($book['price'])) ?></dd>
                        <dt>Genre</dt>
                        <dd><?= e($book['genre']) ?></dd>
                        <dt>Added</dt>
                        <dd><time datetime="<?= e($addedOn->format('Y-m-d\TH:i:s')) ?>"><?= e($addedOn->format('j M Y')) ?></time></dd>
                    </dl>
                </aside>

                <section class="admin-content" aria-labelledby="edit-book-title">
                    <h2 id="edit-book-title">Book details</h2>
                    <form class="book-form" method="post" action="edit_book.php?id=<?= (int) $book['id'] ?>" data-once>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_book">

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
                            <input class="form-control" type="text" id="book-genre" name="genre" maxlength="100" list="genre-options" autocomplete="off" required value="<?= e($values['genre']) ?>"<?= $describe('genre') ?>>
                            <datalist id="genre-options">
<?php foreach ($genres as $genre): ?>
                                <option value="<?= e($genre) ?>"></option>
<?php endforeach; ?>
                            </datalist>
                            <?= $fieldError('genre') ?>
                        </div>

                        <div class="form-field">
                            <label for="book-price">Price (<?= e(strtoupper((string) config('stripe.currency'))) ?>)</label>
                            <input class="form-control" type="number" id="book-price" name="price" step="0.01" min="1" max="10000" inputmode="decimal" required value="<?= e($values['price']) ?>"<?= $describe('price') ?>>
                            <?= $fieldError('price') ?>
                        </div>

                        <div class="form-field form-field-wide">
                            <label for="book-description">Description</label>
                            <textarea class="form-control" id="book-description" name="description" maxlength="5000" rows="6" required<?= $describe('description') ?>><?= e($values['description']) ?></textarea>
                            <?= $fieldError('description') ?>
                        </div>

                        <div class="form-field form-field-wide">
                            <label for="book-cover">Cover Image</label>
                            <input class="form-control" type="text" id="book-cover" name="cover_image" maxlength="255" required spellcheck="false" value="<?= e($values['cover_image']) ?>"<?= $describe('cover_image', 'book-cover-hint') ?>>
                            <p class="form-hint" id="book-cover-hint">Path under public/, e.g. imges/All_section/biology.png, or an https:// URL</p>
                            <?= $fieldError('cover_image') ?>
                        </div>

                        <div class="form-actions form-field-wide">
                            <button type="submit" class="btn admin-submit">Update Book</button>
                            <a class="btn btn-secondary" href="admin_dashboard.php#manage-books">Cancel</a>
                        </div>
                    </form>
                </section>
            </div>
        </div>
<?php
page_footer();

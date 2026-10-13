<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

if (!in_array(request_method(), ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, POST');
    abort(405);
}

$user = require_user();
$userId = (int) $user['id'];

$sections = [
    'account' => ['label' => 'Account Info', 'icon' => 'imges/AccountInfo.png'],
    'payment' => ['label' => 'Payment Preference', 'icon' => 'imges/PaymentMethod.png'],
    'orders' => ['label' => 'My Orders', 'icon' => 'imges/Reading.png'],
];

if (is_post()) {
    verify_csrf();
    $action = input_string($_POST, 'action', 50);

    if ($action === 'update_profile') {
        $name = input_string($_POST, 'name', 500);
        $favoriteGenre = input_string($_POST, 'favorite_genre', 500);
        $dateOfBirth = input_string($_POST, 'date_of_birth', 50);

        $errors = array_filter([
            'name' => validate_person_name($name),
            // Free text: more permissive than catalog genre names, so legacy values
            // such as "Sci-Fi/Fantasy" never block a profile or avatar update.
            'favorite_genre' => validate_favorite_genre($favoriteGenre),
            'date_of_birth' => validate_date_of_birth($dateOfBirth),
        ]);
        $input = ['name' => $name, 'favorite_genre' => $favoriteGenre, 'date_of_birth' => $dateOfBirth];

        if ($errors !== []) {
            remember_input($input);
            remember_errors($errors);
            flash('error', 'Please correct the highlighted fields. Your profile was not updated.');
            redirect('profilePage.php?section=account');
        }

        $newAvatar = null;
        $file = $_FILES['profile_image'] ?? null;
        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $newAvatar = store_avatar_upload($file);
            } catch (UploadException $e) {
                remember_input($input);
                remember_errors(['profile_image' => $e->getMessage()]);
                flash('error', $e->getMessage());
                redirect('profilePage.php?section=account');
            }
        }

        try {
            update_user_profile($userId, $name, $favoriteGenre, $dateOfBirth === '' ? null : $dateOfBirth, $newAvatar);
        } catch (Throwable $e) {
            delete_uploaded_avatar($newAvatar);
            throw $e;
        }
        if ($newAvatar !== null) {
            delete_uploaded_avatar($user['profile_image'] ?? null);
        }

        flash('success', 'Your profile has been updated.');
        redirect('profilePage.php?section=account');
    }

    if ($action === 'update_payment') {
        $preference = $_POST['payment_method'] ?? null;
        $preference = is_string($preference) ? trim($preference) : null;
        if ($preference === null || !update_payment_preference($userId, $preference === '' ? null : $preference)) {
            remember_errors(['payment_method' => 'Please choose one of the listed payment preferences.']);
            flash('error', 'Your payment preference was not saved.');
            redirect('profilePage.php?section=payment');
        }

        flash('success', 'Your payment preference has been saved.');
        redirect('profilePage.php?section=payment');
    }

    abort(400, 'Unknown profile action.');
}

$section = $_GET['section'] ?? 'account';
if (!is_string($section) || !isset($sections[$section])) {
    $section = 'account';
}

$genres = list_genres();
$orders = user_orders($userId);

$nameValue = old('name', (string) $user['name']);
$genreValue = old('favorite_genre', (string) ($user['favorite_genre'] ?? 'Unknown'));
$dobValue = old('date_of_birth', (string) ($user['date_of_birth'] ?? ''));
$today = (new DateTimeImmutable('today'))->format('Y-m-d');

$storedPreference = (string) ($user['payment_method'] ?? '');
// Old accounts could type anything here (even card numbers), so unknown values are never echoed back.
$legacyPreference = $storedPreference !== '' && !in_array($storedPreference, PAYMENT_PREFERENCES, true);
$selectedPreference = $legacyPreference ? '' : $storedPreference;

$errors = [];
foreach (['profile_image', 'name', 'favorite_genre', 'date_of_birth', 'payment_method'] as $field) {
    $errors[$field] = form_error($field);
}

/**
 * Accessibility attributes linking a field to its hint and error messages.
 */
$fieldAria = static function (string $field, ?string $hintId = null) use ($errors): string {
    $describedBy = array_filter([$hintId, $errors[$field] !== null ? $field . '-error' : null]);
    $attributes = $errors[$field] !== null ? ' aria-invalid="true"' : '';
    if ($describedBy !== []) {
        $attributes .= ' aria-describedby="' . e(implode(' ', $describedBy)) . '"';
    }
    return $attributes;
};

$fieldError = static function (string $field) use ($errors): string {
    if ($errors[$field] === null) {
        return '';
    }
    return '<p class="field-error" id="' . e($field) . '-error">' . e($errors[$field]) . '</p>';
};

page_header($sections[$section]['label'], ['profilePage.css']);
?>
        <div class="page-container profile-page">
            <div class="profile-shell">
                <aside class="profile-side">
                    <a class="profile-back" href="Home_Page.php">
                        <img src="imges/arrow.png" alt="" width="30" height="30">
                        <span class="sr-only">Back to home</span>
                    </a>
                    <h1 class="profile-side-title">Account Settings</h1>
                    <nav aria-label="Account settings">
                        <ul class="profile-menu">
<?php foreach ($sections as $key => $info): ?>
                            <li>
                                <a class="profile-menu-link" href="profilePage.php?section=<?= e($key) ?>" data-section="<?= e($key) ?>"<?= $key === $section ? ' aria-current="page"' : '' ?>>
                                    <img src="<?= e($info['icon']) ?>" alt="" width="32" height="32">
                                    <span><?= e($info['label']) ?></span>
                                </a>
                            </li>
<?php endforeach; ?>
                            <li>
                                <form class="profile-signout" method="post" action="logout.php">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="profile-menu-link">
                                        <img src="imges/SignOut.png" alt="" width="32" height="32">
                                        <span>Sign out</span>
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </nav>
                </aside>

                <div class="profile-content">
                    <section class="profile-section" id="section-account" data-section-panel="account" aria-labelledby="account-title"<?= $section === 'account' ? '' : ' hidden' ?>>
                        <h2 id="account-title" class="profile-section-title" tabindex="-1">Account Info</h2>

                        <form class="profile-form" method="post" action="profilePage.php" enctype="multipart/form-data" data-once>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_profile">

                            <div class="avatar-picker">
                                <img id="avatar-preview" class="avatar-preview" src="<?= e(avatar_url($user['profile_image'] ?? null)) ?>" alt="Your current profile picture" width="160" height="160" data-avatar-preview>
                                <p class="avatar-status" data-avatar-status aria-live="polite"></p>
                            </div>

                            <div class="form-field">
                                <label for="profile_image">Profile picture</label>
                                <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int) AVATAR_MAX_BYTES ?>">
                                <input type="file" id="profile_image" name="profile_image" class="form-control file-control" accept="image/jpeg,image/png,image/gif,image/webp" data-avatar-input data-max-bytes="<?= (int) AVATAR_MAX_BYTES ?>"<?= $fieldAria('profile_image', 'profile_image-hint') ?>>
                                <p class="form-hint" id="profile_image-hint">JPEG, PNG, GIF or WebP, up to 2 MB</p>
                                <?= $fieldError('profile_image') ?>
                            </div>

                            <div class="form-field">
                                <label for="name">Full name</label>
                                <input type="text" id="name" name="name" class="form-control" dir="auto" value="<?= e($nameValue) ?>" required maxlength="<?= (int) NAME_MAX_LENGTH ?>" autocomplete="name"<?= $fieldAria('name') ?>>
                                <?= $fieldError('name') ?>
                            </div>

                            <div class="form-field">
                                <label for="email">Email</label>
                                <input type="email" id="email" class="form-control" value="<?= e($user['Eaddress']) ?>" readonly aria-describedby="email-hint">
                                <p class="form-hint" id="email-hint">Your email is your sign-in address and cannot be changed here.</p>
                            </div>

                            <div class="form-field">
                                <label for="favorite_genre">Favorite genre</label>
                                <input type="text" id="favorite_genre" name="favorite_genre" class="form-control" dir="auto" value="<?= e($genreValue) ?>" required maxlength="100" list="genre-options" autocomplete="off"<?= $fieldAria('favorite_genre', 'favorite_genre-hint') ?>>
                                <datalist id="genre-options">
<?php foreach ($genres as $genre): ?>
                                    <option value="<?= e($genre) ?>"></option>
<?php endforeach; ?>
                                </datalist>
                                <p class="form-hint" id="favorite_genre-hint">Choose a genre from the list or type your own.</p>
                                <?= $fieldError('favorite_genre') ?>
                            </div>

                            <div class="form-field">
                                <label for="date_of_birth">Date of birth <span class="optional">(optional)</span></label>
                                <input type="date" id="date_of_birth" name="date_of_birth" class="form-control" value="<?= e($dobValue) ?>" min="1900-01-01" max="<?= e($today) ?>" autocomplete="bday"<?= $fieldAria('date_of_birth') ?>>
                                <?= $fieldError('date_of_birth') ?>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn profile-submit">Update Profile</button>
                            </div>
                        </form>
                    </section>

                    <section class="profile-section" id="section-payment" data-section-panel="payment" aria-labelledby="payment-title"<?= $section === 'payment' ? '' : ' hidden' ?>>
                        <h2 id="payment-title" class="profile-section-title" tabindex="-1">Payment Preference</h2>
                        <p class="profile-intro" id="payment-intro">Card details are entered securely on Stripe's checkout page at payment time. <?= e(config('app.name', 'BookWise')) ?> never stores card numbers.</p>
<?php if ($legacyPreference): ?>
                        <p class="profile-notice">Your previous entry was cleared for your security — please choose a preference.</p>
<?php endif; ?>

                        <form class="profile-form" method="post" action="profilePage.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_payment">

                            <div class="form-field">
                                <label for="payment_method">Preferred payment method</label>
                                <select id="payment_method" name="payment_method" class="form-control"<?= $fieldAria('payment_method', 'payment-intro') ?>>
                                    <option value=""<?= $selectedPreference === '' ? ' selected' : '' ?>>No preference</option>
<?php foreach (PAYMENT_PREFERENCES as $preference): ?>
                                    <option value="<?= e($preference) ?>"<?= $selectedPreference === $preference ? ' selected' : '' ?>><?= e($preference) ?></option>
<?php endforeach; ?>
                                </select>
                                <?= $fieldError('payment_method') ?>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn profile-submit">Save Preference</button>
                            </div>
                        </form>
                    </section>

                    <section class="profile-section" id="section-orders" data-section-panel="orders" aria-labelledby="orders-title"<?= $section === 'orders' ? '' : ' hidden' ?>>
                        <h2 id="orders-title" class="profile-section-title" tabindex="-1">My Orders</h2>
<?php if ($orders === []): ?>
                        <div class="empty-state order-empty">
                            <p>You haven't placed any orders yet.</p>
                            <a class="btn" href="Explore_Page.php">Explore books</a>
                        </div>
<?php else: ?>
                        <ol class="order-list">
<?php foreach ($orders as $order): ?>
<?php
    $status = (string) $order['status'];
    $placedAt = new DateTimeImmutable((string) $order['created_at']);
?>
                            <li class="order-card">
                                <div class="order-head">
                                    <h3 class="order-number">Order #<?= (int) $order['id'] ?></h3>
                                    <span class="status-badge status-<?= e($status) ?>"><?= e($status) ?></span>
                                </div>
                                <p class="order-date">Placed <time datetime="<?= e($placedAt->format(DATE_ATOM)) ?>"><?= e($placedAt->format('j M Y, H:i')) ?></time></p>
<?php if ($order['items'] !== []): ?>
                                <ul class="order-items">
<?php foreach ($order['items'] as $item): ?>
                                    <li>
<?php if ($item['book_id'] !== null): ?>
                                        <a href="Selected.php?id=<?= (int) $item['book_id'] ?>" dir="auto"><?= e($item['title']) ?></a>
<?php else: ?>
                                        <span dir="auto"><?= e($item['title']) ?></span>
<?php endif; ?>
                                        <span class="order-qty">× <?= (int) $item['quantity'] ?></span>
                                    </li>
<?php endforeach; ?>
                                </ul>
<?php endif; ?>
                                <p class="order-total">Total <strong><?= e(format_money((string) $order['total_amount'], (string) $order['currency'])) ?></strong></p>
                            </li>
<?php endforeach; ?>
                        </ol>
<?php endif; ?>
                    </section>
                </div>
            </div>
        </div>
<?php
page_footer(['profile.js']);

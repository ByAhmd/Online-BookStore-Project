<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

if (!in_array(request_method(), ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, POST');
    abort(405);
}

$user = require_user();
$userId = (int) $user['id'];
// Demo guests (the demo's one-click accounts) have no password that anyone knows.
$isDemoGuest = is_demo_guest($userId);

$sections = [
    'account' => ['label' => t('account.sections.account'), 'icon' => 'imges/AccountInfo.png'],
    'addresses' => ['label' => t('account.sections.addresses'), 'icon' => null],
    'payment' => ['label' => t('account.sections.payment'), 'icon' => 'imges/PaymentMethod.png'],
    'orders' => ['label' => t('account.sections.orders'), 'icon' => 'imges/Reading.png'],
    'security' => ['label' => t('account.sections.security'), 'icon' => null],
    'delete' => ['label' => t('account.sections.delete'), 'icon' => null],
];

if (is_post()) {
    verify_csrf();
    $action = input_string($_POST, 'action', 50);

    if ($action === 'update_profile') {
        $name = input_string($_POST, 'name', 500);
        $favoriteGenre = input_string($_POST, 'favorite_genre', 500);
        $dateOfBirth = input_string($_POST, 'date_of_birth', 50);

        $errors = array_filter([
            'name' => person_name_error($name),
            // Optional free text: more permissive than catalog genre names, so legacy
            // values such as "Sci-Fi/Fantasy" never block a profile or avatar update.
            'favorite_genre' => $favoriteGenre === '' ? null : favorite_genre_error($favoriteGenre),
            'date_of_birth' => date_of_birth_error($dateOfBirth),
        ]);
        $input = ['name' => $name, 'favorite_genre' => $favoriteGenre, 'date_of_birth' => $dateOfBirth];

        if ($errors !== []) {
            remember_input($input);
            remember_errors($errors);
            flash('error', t('account.profile.invalid'));
            redirect('profilePage.php?section=account');
        }

        $newAvatar = null;
        $file = $_FILES['profile_image'] ?? null;
        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $newAvatar = store_avatar_upload($file);
            } catch (UploadException $e) {
                $message = upload_error_message($e);
                remember_input($input);
                remember_errors(['profile_image' => $message]);
                flash('error', $message);
                redirect('profilePage.php?section=account');
            }
        }

        try {
            update_user_profile($userId, $name, $favoriteGenre !== '' ? $favoriteGenre : FAVORITE_GENRE_UNSET, $dateOfBirth === '' ? null : $dateOfBirth, $newAvatar);
        } catch (Throwable $e) {
            delete_uploaded_avatar($newAvatar);
            throw $e;
        }
        if ($newAvatar !== null) {
            delete_uploaded_avatar($user['profile_image'] ?? null);
        }

        flash('success', t('account.profile.updated'));
        redirect('profilePage.php?section=account');
    }

    if ($action === 'update_language') {
        $locale = input_string($_POST, 'locale', 10);
        if (!update_user_locale($userId, $locale)) {
            remember_errors(['locale' => t('account.language.invalid')]);
            flash('error', t('account.language.not_saved'));
            redirect('profilePage.php?section=account');
        }
        remember_locale_cookie($locale);
        // Confirm in the language that was just chosen.
        use_locale($locale);
        flash('success', t('account.language.saved'));
        use_locale(null);
        redirect('profilePage.php?section=account');
    }

    if ($action === 'save_address') {
        // An address id edits that address (only one of the customer's own); none adds one.
        $addressId = parse_int($_POST['address_id'] ?? null);
        $formUrl = 'profilePage.php?section=addresses' . ($addressId !== null ? '&edit=' . $addressId : '') . '#address-form';
        [$address, $errors] = validate_address_input($_POST);
        $makeDefault = ($_POST['make_default'] ?? null) === '1';
        if ($errors !== []) {
            remember_input($address + ['make_default' => $makeDefault ? '1' : '']);
            remember_errors($errors);
            flash('error', t('account.addresses.invalid'));
            redirect($formUrl);
        }

        $saved = save_customer_address($userId, $address, $addressId, $makeDefault);
        if ($saved['status'] === 'limit') {
            remember_input($address + ['make_default' => $makeDefault ? '1' : '']);
            flash('error', tn('account.addresses.limit', ADDRESS_MAX_PER_CUSTOMER));
            redirect('profilePage.php?section=addresses');
        }
        if ($saved['status'] === 'missing') {
            flash('error', t('account.addresses.not_found'));
            redirect('profilePage.php?section=addresses');
        }
        if ($saved['status'] === 'existing') {
            flash('info', t('account.addresses.existing'));
            redirect('profilePage.php?section=addresses');
        }
        flash('success', t($saved['status'] === 'created' ? 'account.addresses.created' : 'account.addresses.updated'));
        redirect('profilePage.php?section=addresses');
    }

    if ($action === 'default_address' || $action === 'delete_address') {
        $addressId = parse_int($_POST['address_id'] ?? null);
        $done = $addressId !== null && ($action === 'default_address'
            ? set_default_address($userId, $addressId)
            : delete_customer_address($userId, $addressId));
        if (!$done) {
            flash('error', t('account.addresses.not_found'));
        } else {
            flash('success', t($action === 'default_address' ? 'account.addresses.default_set' : 'account.addresses.deleted'));
        }
        redirect('profilePage.php?section=addresses');
    }

    if ($action === 'update_payment') {
        $preference = $_POST['payment_method'] ?? null;
        $preference = is_string($preference) ? trim($preference) : null;
        if ($preference === null || !update_payment_preference($userId, $preference === '' ? null : $preference)) {
            remember_errors(['payment_method' => t('account.payment.invalid')]);
            flash('error', t('account.payment.not_saved'));
            redirect('profilePage.php?section=payment');
        }

        flash('success', t('account.payment.saved'));
        redirect('profilePage.php?section=payment');
    }

    if ($action === 'change_password') {
        if ($isDemoGuest) {
            flash('error', t('account.demo_guest.password_locked'));
            redirect('profilePage.php?section=security');
        }
        // Passwords are used exactly as typed (never trimmed).
        $current = input_password($_POST, 'current_password');
        $new = input_password($_POST, 'new_password');
        $confirmation = input_password($_POST, 'new_password_confirmation');

        $errors = new_password_errors($new, $confirmation, 'new_password', 'new_password_confirmation');
        if ($current === '') {
            $errors = ['current_password' => t('account.security.current_required')] + $errors;
        }
        if ($errors !== []) {
            remember_errors($errors);
            flash('error', t('account.security.not_changed'));
            redirect('profilePage.php?section=security');
        }

        $result = change_user_password($userId, $current, $new, client_ip());
        if ($result['status'] === 'throttled') {
            app_log('warning', 'Password change blocked by throttling', ['user_id' => $userId]);
            flash('error', tn('account.security.throttled', LOGIN_LOCKOUT_MINUTES));
            redirect('profilePage.php?section=security');
        }
        if ($result['status'] !== 'changed' || $result['session_version'] === null) {
            remember_errors(['current_password' => t('account.security.current_wrong')]);
            flash('error', t('account.security.not_changed'));
            redirect('profilePage.php?section=security');
        }

        // Every other session is signed out; this one stays signed in with a new ID.
        keep_session_after_password_change($result['session_version']);
        app_log('info', 'Password changed', ['user_id' => $userId]);
        send_password_changed_email($userId);
        flash('success', t('account.security.changed'));
        redirect('profilePage.php?section=security');
    }

    if ($action === 'delete_account') {
        $password = input_password($_POST, 'delete_password');
        $confirmed = ($_POST['confirm_delete'] ?? null) === 'yes';

        $errors = array_filter([
            // Demo guests have no password: confirming is enough.
            'delete_password' => $password === '' && !$isDemoGuest ? t('account.delete.password_required') : null,
            'confirm_delete' => $confirmed ? null : t('account.delete.confirm_required'),
        ]);
        if ($errors !== []) {
            remember_errors($errors);
            flash('error', t('account.delete.not_deleted'));
            redirect('profilePage.php?section=delete');
        }

        if ($isDemoGuest) {
            // Deletes the picture too; paid orders are kept, anonymised.
            if (delete_demo_guest($userId) === 'unresolved') {
                flash('error', t('account.delete.unresolved'));
                redirect('profilePage.php?section=delete');
            }
            app_log('info', 'Demo guest account deleted', ['user_id' => $userId]);
            logout_session();
            flash('success', t('account.delete.done'));
            redirect('Home_Page.php');
        }

        $result = delete_customer_account($userId, $password, client_ip());
        if ($result['status'] === 'throttled') {
            app_log('warning', 'Account deletion blocked by throttling', ['user_id' => $userId]);
            flash('error', tn('account.delete.throttled', LOGIN_LOCKOUT_MINUTES));
            redirect('profilePage.php?section=delete');
        }
        if ($result['status'] === 'unresolved') {
            flash('error', t('account.delete.unresolved'));
            redirect('profilePage.php?section=delete');
        }
        if ($result['status'] !== 'deleted' || $result['account'] === null) {
            remember_errors(['delete_password' => t('account.delete.password_wrong')]);
            flash('error', t('account.delete.not_deleted'));
            redirect('profilePage.php?section=delete');
        }

        delete_uploaded_avatar(is_string($result['account']['profile_image'] ?? null) ? $result['account']['profile_image'] : null);
        app_log('info', 'Customer account deleted', ['user_id' => $userId]);
        send_account_deleted_email($result['account']);
        logout_session();
        flash('success', t('account.delete.done'));
        redirect('Home_Page.php');
    }

    abort(400, t('account.errors.unknown_action'));
}

$section = $_GET['section'] ?? 'account';
if (!is_string($section) || !isset($sections[$section])) {
    $section = 'account';
}

$account = find_user_account($userId);
$accountLocale = $account['locale'] ?? current_locale();
$genres = list_genres();
$orders = user_orders($userId);
$appName = (string) config('app.name', 'BookWise');

$nameValue = old('name', (string) $user['name']);
$storedGenre = (string) ($user['favorite_genre'] ?? '');
$genreValue = old('favorite_genre', $storedGenre === FAVORITE_GENRE_UNSET ? '' : $storedGenre);
$dobValue = old('date_of_birth', (string) ($user['date_of_birth'] ?? ''));
$today = (new DateTimeImmutable('today'))->format('Y-m-d');

$storedPreference = (string) ($user['payment_method'] ?? '');
// Old accounts could type anything here (even card numbers), so unknown values are never echoed back.
$legacyPreference = $storedPreference !== '' && !in_array($storedPreference, PAYMENT_PREFERENCES, true);
$selectedPreference = $legacyPreference ? '' : $storedPreference;

$errors = [];
foreach (['profile_image', 'name', 'favorite_genre', 'date_of_birth', 'locale', 'payment_method', 'current_password', 'new_password', 'new_password_confirmation', 'delete_password', 'confirm_delete', ...ADDRESS_FIELDS] as $field) {
    $errors[$field] = form_error($field);
}

// Delivery addresses: ?edit=<id> fills the form with one of the customer's own addresses.
$addresses = customer_addresses($userId);
$editId = parse_int($_GET['edit'] ?? null);
$editing = $editId !== null ? find_user_address($userId, $editId) : null;
$canAddAddress = count($addresses) < ADDRESS_MAX_PER_CUSTOMER;
$addressValues = [];
foreach (ADDRESS_FIELDS as $field) {
    $addressValues[$field] = old($field, (string) ($editing[$field] ?? ''));
}
$addressErrors = array_intersect_key($errors, array_flip(ADDRESS_FIELDS));
$makeDefaultChecked = old('make_default') === '1';

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

// Menu icons for sections without an image in imges/ (inline, decorative).
$menuIcons = [
    'addresses' => '<svg class="profile-menu-icon" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>',
    'security' => '<svg class="profile-menu-icon" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/><path d="M12 15v2"/></svg>',
    'delete' => '<svg class="profile-menu-icon" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 7h16"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"/><path d="M9 7V4h6v3"/></svg>',
];

page_header($sections[$section]['label'], ['profilePage.css']);
?>
        <div class="page-container profile-page">
            <div class="profile-shell">
                <aside class="profile-side">
                    <a class="profile-back" href="Home_Page.php">
                        <img src="<?= e(asset('imges/arrow.png')) ?>" alt="" width="30" height="30">
                        <span class="sr-only"><?= e(t('account.back_home')) ?></span>
                    </a>
                    <h1 class="profile-side-title"><?= e(t('account.title')) ?></h1>
                    <nav aria-label="<?= e(t('account.nav_label')) ?>">
                        <ul class="profile-menu">
<?php foreach ($sections as $key => $info): ?>
                            <li>
                                <a class="profile-menu-link<?= $key === 'delete' ? ' profile-menu-danger' : '' ?>" href="profilePage.php?section=<?= e($key) ?>" data-section="<?= e($key) ?>" data-title="<?= e($info['label'] . ' · ' . $appName) ?>"<?= $key === $section ? ' aria-current="page"' : '' ?>>
<?php if ($info['icon'] !== null): ?>
                                    <img src="<?= e(asset($info['icon'])) ?>" alt="" width="32" height="32">
<?php elseif (isset($menuIcons[$key])): ?>
                                    <?= $menuIcons[$key] . "\n" ?>
<?php endif; ?>
                                    <span><?= e($info['label']) ?></span>
                                </a>
                            </li>
<?php endforeach; ?>
                            <li>
                                <form class="profile-signout" method="post" action="logout.php">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="profile-menu-link">
                                        <img src="<?= e(asset('imges/SignOut.png')) ?>" alt="" width="32" height="32">
                                        <span><?= e(t('account.sign_out')) ?></span>
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </nav>
                </aside>

                <div class="profile-content">
                    <section class="profile-section" id="section-account" data-section-panel="account" aria-labelledby="account-title"<?= $section === 'account' ? '' : ' hidden' ?>>
                        <h2 id="account-title" class="profile-section-title" tabindex="-1"><?= e(t('account.sections.account')) ?></h2>

                        <form class="profile-form" method="post" action="profilePage.php" enctype="multipart/form-data" data-once>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_profile">

                            <div class="avatar-picker">
                                <img id="avatar-preview" class="avatar-preview" src="<?= e(asset(avatar_url($user['profile_image'] ?? null))) ?>" alt="<?= e(t('account.profile.avatar_alt')) ?>" width="160" height="160" data-avatar-preview>
                                <p class="avatar-status" data-avatar-status aria-live="polite"></p>
                            </div>

                            <div class="form-field">
                                <label for="profile_image"><?= e(t('account.profile.picture')) ?></label>
                                <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int) AVATAR_MAX_BYTES ?>">
                                <input type="file" id="profile_image" name="profile_image" class="form-control file-control" accept="image/jpeg,image/png,image/gif,image/webp" data-avatar-input data-max-bytes="<?= (int) AVATAR_MAX_BYTES ?>"
                                    data-i18n-choose="<?= e(t('account.profile.js.choose')) ?>"
                                    data-i18n-type-error="<?= e(t('account.profile.js.type_error')) ?>"
                                    data-i18n-size-error="<?= e(t('account.profile.js.size_error')) ?>"
                                    data-i18n-preview-alt="<?= e(t('account.profile.js.preview_alt')) ?>"
                                    data-i18n-selected="<?= e(t('account.profile.js.selected')) ?>"<?= $fieldAria('profile_image', 'profile_image-hint') ?>>
                                <p class="form-hint" id="profile_image-hint"><?= e(t('account.profile.picture_hint')) ?></p>
                                <?= $fieldError('profile_image') ?>
                            </div>

                            <div class="form-field">
                                <label for="name"><?= e(t('account.profile.name')) ?></label>
                                <input type="text" id="name" name="name" class="form-control" dir="auto" value="<?= e($nameValue) ?>" required maxlength="<?= (int) NAME_MAX_LENGTH ?>" autocomplete="name"<?= $fieldAria('name') ?>>
                                <?= $fieldError('name') ?>
                            </div>

                            <div class="form-field">
                                <label for="email"><?= e(t('account.profile.email')) ?></label>
                                <input type="email" id="email" class="form-control" value="<?= e($user['Eaddress']) ?>" readonly aria-describedby="email-hint">
                                <p class="form-hint" id="email-hint"><?= e(t($isDemoGuest ? 'account.demo_guest.email_hint' : 'account.profile.email_hint')) ?></p>
                            </div>

                            <div class="form-field">
                                <label for="favorite_genre"><?= e(t('account.profile.favorite_genre')) ?></label>
                                <input type="text" id="favorite_genre" name="favorite_genre" class="form-control" dir="auto" value="<?= e($genreValue) ?>" maxlength="100" list="genre-options" autocomplete="off" placeholder="<?= e(t('account.profile.favorite_genre_placeholder')) ?>"<?= $fieldAria('favorite_genre', 'favorite_genre-hint') ?>>
                                <datalist id="genre-options">
<?php foreach ($genres as $genre): ?>
                                    <option value="<?= e($genre) ?>"></option>
<?php endforeach; ?>
                                </datalist>
                                <p class="form-hint" id="favorite_genre-hint"><?= e(t('account.profile.favorite_genre_hint')) ?></p>
                                <?= $fieldError('favorite_genre') ?>
                            </div>

                            <div class="form-field">
                                <label for="date_of_birth"><?= e(t('account.profile.date_of_birth')) ?> <span class="optional"><?= e(t('account.profile.optional')) ?></span></label>
                                <input type="date" id="date_of_birth" name="date_of_birth" class="form-control" value="<?= e($dobValue) ?>" min="1900-01-01" max="<?= e($today) ?>" autocomplete="bday"<?= $fieldAria('date_of_birth') ?>>
                                <?= $fieldError('date_of_birth') ?>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn profile-submit"><?= e(t('account.profile.submit')) ?></button>
                            </div>
                        </form>

                        <section class="profile-subsection" aria-labelledby="language-title">
                            <h3 id="language-title" class="profile-subsection-title"><?= e(t('account.language.heading')) ?></h3>
                            <form class="profile-form" method="post" action="profilePage.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update_language">

                                <div class="form-field">
                                    <label for="locale"><?= e(t('account.language.label')) ?></label>
                                    <select id="locale" name="locale" class="form-control"<?= $fieldAria('locale', 'locale-hint') ?>>
<?php foreach (SUPPORTED_LOCALES as $code => $nativeName): ?>
                                        <option value="<?= e($code) ?>" lang="<?= e($code) ?>"<?= $code === $accountLocale ? ' selected' : '' ?>><?= e($nativeName) ?></option>
<?php endforeach; ?>
                                    </select>
                                    <p class="form-hint" id="locale-hint"><?= e(t('account.language.hint')) ?></p>
                                    <?= $fieldError('locale') ?>
                                </div>

                                <div class="form-actions">
                                    <button type="submit" class="btn profile-submit"><?= e(t('account.language.submit')) ?></button>
                                </div>
                            </form>
                        </section>
                    </section>

                    <section class="profile-section" id="section-addresses" data-section-panel="addresses" aria-labelledby="addresses-title"<?= $section === 'addresses' ? '' : ' hidden' ?>>
                        <h2 id="addresses-title" class="profile-section-title" tabindex="-1"><?= e(t('account.sections.addresses')) ?></h2>
                        <p class="profile-intro"><?= e(tn('account.addresses.intro', ADDRESS_MAX_PER_CUSTOMER)) ?></p>

<?php if ($addresses === []): ?>
                        <p class="address-empty"><?= e(t('account.addresses.empty')) ?></p>
<?php else: ?>
                        <ul class="address-list" aria-label="<?= e(t('account.addresses.list_label')) ?>">
<?php foreach ($addresses as $address):
    $addressId = (int) $address['id'];
    $addressOf = t('account.addresses.address_of', ['name' => (string) $address['recipient_name']]);
    $isDefault = (int) $address['is_default'] === 1;
    ?>
                            <li class="address-card<?= $isDefault ? ' is-default' : '' ?>">
                                <p class="address-text"><?= address_html($address) ?></p>
<?php if ($isDefault): ?>
                                <p class="address-badge"><?= e(t('account.addresses.default_badge')) ?></p>
<?php endif; ?>
                                <div class="address-actions">
                                    <a class="btn btn-secondary btn-small" href="profilePage.php?section=addresses&amp;edit=<?= $addressId ?>#address-form"><?= e(t('account.addresses.edit')) ?><span class="sr-only"> <?= e($addressOf) ?></span></a>
<?php if (!$isDefault): ?>
                                    <form method="post" action="profilePage.php">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="default_address">
                                        <input type="hidden" name="address_id" value="<?= $addressId ?>">
                                        <button type="submit" class="btn btn-small"><?= e(t('account.addresses.make_default')) ?><span class="sr-only"> <?= e($addressOf) ?></span></button>
                                    </form>
<?php endif; ?>
                                    <form method="post" action="profilePage.php" data-confirm="<?= e(t('account.addresses.delete_confirm')) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_address">
                                        <input type="hidden" name="address_id" value="<?= $addressId ?>">
                                        <button type="submit" class="btn btn-danger btn-small"><?= e(t('account.addresses.delete')) ?><span class="sr-only"> <?= e($addressOf) ?></span></button>
                                    </form>
                                </div>
                            </li>
<?php endforeach; ?>
                        </ul>
<?php endif; ?>

                        <section class="profile-subsection" id="address-form" aria-labelledby="address-form-title">
                            <h3 id="address-form-title" class="profile-subsection-title"><?= e(t($editing !== null ? 'account.addresses.edit_heading' : 'account.addresses.add_heading')) ?></h3>
<?php if ($editing === null && !$canAddAddress): ?>
                            <p class="profile-notice"><?= e(tn('account.addresses.limit', ADDRESS_MAX_PER_CUSTOMER)) ?></p>
<?php else: ?>
                            <form class="profile-form address-form" method="post" action="profilePage.php" data-once>
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="save_address">
<?php if ($editing !== null): ?>
                                <input type="hidden" name="address_id" value="<?= (int) $editing['id'] ?>">
<?php endif; ?>
                                <?php render('address_fields', ['values' => $addressValues, 'errors' => $addressErrors, 'prefix' => 'address', 'required' => true]); ?>
<?php if ($addresses !== [] && ($editing === null || (int) $editing['is_default'] !== 1)): ?>
                                <div class="form-field checkbox-field">
                                    <div class="checkbox-row">
                                        <input type="checkbox" id="address-make-default" name="make_default" value="1"<?= $makeDefaultChecked ? ' checked' : '' ?>>
                                        <label for="address-make-default"><?= e(t('account.addresses.make_default_label')) ?></label>
                                    </div>
                                </div>
<?php endif; ?>
                                <div class="form-actions">
                                    <button type="submit" class="btn profile-submit"><?= e(t('account.addresses.submit')) ?></button>
<?php if ($editing !== null): ?>
                                    <a class="btn btn-secondary address-cancel" href="profilePage.php?section=addresses"><?= e(t('account.addresses.cancel')) ?></a>
<?php endif; ?>
                                </div>
                            </form>
<?php endif; ?>
                        </section>
                    </section>

                    <section class="profile-section" id="section-payment" data-section-panel="payment" aria-labelledby="payment-title"<?= $section === 'payment' ? '' : ' hidden' ?>>
                        <h2 id="payment-title" class="profile-section-title" tabindex="-1"><?= e(t('account.sections.payment')) ?></h2>
                        <p class="profile-intro" id="payment-intro"><?= e(t(payment_simulator_enabled() ? 'shop.pay.profile_intro' : 'account.payment.intro', ['app' => $appName])) ?></p>
<?php if ($legacyPreference): ?>
                        <p class="profile-notice"><?= e(t('account.payment.legacy_notice')) ?></p>
<?php endif; ?>

                        <form class="profile-form" method="post" action="profilePage.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_payment">

                            <div class="form-field">
                                <label for="payment_method"><?= e(t('account.payment.label')) ?></label>
                                <select id="payment_method" name="payment_method" class="form-control"<?= $fieldAria('payment_method', 'payment-intro') ?>>
                                    <option value=""<?= $selectedPreference === '' ? ' selected' : '' ?>><?= e(t('account.payment.none')) ?></option>
<?php foreach (PAYMENT_PREFERENCES as $preference): ?>
                                    <option value="<?= e($preference) ?>"<?= $selectedPreference === $preference ? ' selected' : '' ?>><?= e(t('account.payment.options.' . $preference)) ?></option>
<?php endforeach; ?>
                                </select>
                                <?= $fieldError('payment_method') ?>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn profile-submit"><?= e(t('account.payment.submit')) ?></button>
                            </div>
                        </form>
                    </section>

                    <section class="profile-section" id="section-orders" data-section-panel="orders" aria-labelledby="orders-title"<?= $section === 'orders' ? '' : ' hidden' ?>>
                        <h2 id="orders-title" class="profile-section-title" tabindex="-1"><?= e(t('account.sections.orders')) ?></h2>
<?php if ($orders === []): ?>
                        <div class="empty-state order-empty">
                            <p><?= e(t('account.orders.empty')) ?></p>
                            <a class="btn" href="Explore_Page.php"><?= e(t('account.orders.explore')) ?></a>
                        </div>
<?php else: ?>
                        <ol class="order-list">
<?php foreach ($orders as $order): ?>
<?php
    // Paid orders show their delivery stage (processing, shipped, delivered).
    $status = order_display_status($order);
    $placedAt = new DateTimeImmutable((string) $order['created_at']);
    $placedHtml = '<time datetime="' . e($placedAt->format(DATE_ATOM)) . '">' . e($placedAt->format(t('account.orders.date_format'))) . '</time>';
?>
                            <li class="order-card">
                                <div class="order-head">
                                    <h3 class="order-number"><a href="order.php?id=<?= (int) $order['id'] ?>"><?= e(t('account.orders.number', ['id' => (int) $order['id']])) ?></a></h3>
                                    <span class="status-badge status-<?= e($status) ?>"><?= e(order_status_label($status)) ?></span>
                                </div>
                                <p class="order-date"><?= t_html('account.orders.placed', ['date' => $placedHtml]) ?></p>
<?php if ($order['items'] !== []): ?>
                                <ul class="order-items">
<?php foreach ($order['items'] as $item): ?>
                                    <li>
<?php if ($item['book_id'] !== null): ?>
                                        <a href="Selected.php?id=<?= (int) $item['book_id'] ?>" dir="auto"><?= e($item['title']) ?></a>
<?php else: ?>
                                        <span dir="auto"><?= e($item['title']) ?></span>
<?php endif; ?>
                                        <span class="order-qty"><?= e(t('account.orders.quantity', ['count' => (int) $item['quantity']])) ?></span>
                                    </li>
<?php endforeach; ?>
                                </ul>
<?php endif; ?>
                                <div class="order-foot">
                                    <a class="order-view" href="order.php?id=<?= (int) $order['id'] ?>"><?= e(t('account.orders.view')) ?><span class="sr-only"> <?= e(t('account.orders.view_sr', ['id' => (int) $order['id']])) ?></span></a>
                                    <p class="order-total"><?= e(t('account.orders.total')) ?> <strong dir="auto"><?= e(format_money((string) $order['total_amount'], (string) $order['currency'])) ?></strong></p>
                                </div>
                            </li>
<?php endforeach; ?>
                        </ol>
<?php endif; ?>
                    </section>

                    <section class="profile-section" id="section-security" data-section-panel="security" aria-labelledby="security-title"<?= $section === 'security' ? '' : ' hidden' ?>>
                        <h2 id="security-title" class="profile-section-title" tabindex="-1"><?= e(t('account.sections.security')) ?></h2>
<?php if ($isDemoGuest): ?>
                        <p class="profile-notice"><?= e(t('account.demo_guest.security')) ?></p>
<?php else: ?>
                        <p class="profile-intro"><?= e(t('account.security.intro')) ?></p>

                        <form class="profile-form password-form" method="post" action="profilePage.php" data-once
                            data-i18n-mismatch="<?= e(t('auth.js.password_mismatch')) ?>" data-i18n-too-long="<?= e(t('auth.js.password_too_long')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="change_password">
                            <?php /* Unnamed, so never submitted: lets password managers pair the new password with the account. */ ?>
                            <input type="email" autocomplete="username" value="<?= e($user['Eaddress']) ?>" readonly hidden>

                            <div class="form-field">
                                <label for="current_password"><?= e(t('account.security.current_password')) ?></label>
                                <input type="password" id="current_password" name="current_password" class="form-control" autocomplete="current-password" maxlength="255" required<?= $fieldAria('current_password') ?>>
                                <?= $fieldError('current_password') ?>
                            </div>

                            <div class="form-field">
                                <label for="new_password"><?= e(t('account.security.new_password')) ?></label>
                                <input type="password" id="new_password" name="new_password" class="form-control" autocomplete="new-password"
                                    minlength="<?= PASSWORD_MIN_LENGTH ?>" maxlength="<?= PASSWORD_MAX_BYTES ?>" data-max-bytes="<?= PASSWORD_MAX_BYTES ?>" required<?= $fieldAria('new_password', 'new_password-hint') ?>>
                                <p class="form-hint" id="new_password-hint"><?= e(tn('auth.password_hint', PASSWORD_MIN_LENGTH)) ?></p>
                                <?= $fieldError('new_password') ?>
                            </div>

                            <div class="form-field">
                                <label for="new_password_confirmation"><?= e(t('account.security.new_password_confirmation')) ?></label>
                                <input type="password" id="new_password_confirmation" name="new_password_confirmation" class="form-control" autocomplete="new-password"
                                    minlength="<?= PASSWORD_MIN_LENGTH ?>" maxlength="<?= PASSWORD_MAX_BYTES ?>" data-match="new_password" required<?= $fieldAria('new_password_confirmation') ?>>
                                <?= $fieldError('new_password_confirmation') ?>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn profile-submit"><?= e(t('account.security.submit')) ?></button>
                            </div>
                        </form>
<?php endif; ?>
                    </section>

                    <section class="profile-section" id="section-delete" data-section-panel="delete" aria-labelledby="delete-title"<?= $section === 'delete' ? '' : ' hidden' ?>>
                        <h2 id="delete-title" class="profile-section-title" tabindex="-1"><?= e(t('account.sections.delete')) ?></h2>
                        <p class="profile-notice danger-notice"><?= e(t('account.delete.intro')) ?></p>
<?php if ($isDemoGuest): ?>
                        <p class="profile-intro"><?= e(tn('account.demo_guest.delete_intro', DEMO_GUEST_TTL_HOURS)) ?></p>
<?php endif; ?>

                        <div class="delete-summary">
                            <h3 class="profile-subsection-title"><?= e(t('account.delete.removed_heading')) ?></h3>
                            <ul class="delete-list">
<?php foreach (['profile', 'shopping', 'reviews', 'purchases', 'security'] as $item): ?>
                                <li><?= e(t('account.delete.removed.' . $item)) ?></li>
<?php endforeach; ?>
                            </ul>
                            <h3 class="profile-subsection-title"><?= e(t('account.delete.kept_heading')) ?></h3>
                            <p class="delete-kept"><?= e(t($isDemoGuest ? 'account.demo_guest.kept' : 'account.delete.kept')) ?></p>
                        </div>

                        <form class="profile-form delete-form" method="post" action="profilePage.php" data-once data-confirm="<?= e(t('account.delete.confirm_prompt')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_account">
<?php if (!$isDemoGuest): ?>
                            <input type="email" autocomplete="username" value="<?= e($user['Eaddress']) ?>" readonly hidden>

                            <div class="form-field">
                                <label for="delete_password"><?= e(t('account.delete.password')) ?></label>
                                <input type="password" id="delete_password" name="delete_password" class="form-control" autocomplete="current-password" maxlength="255" required<?= $fieldAria('delete_password') ?>>
                                <?= $fieldError('delete_password') ?>
                            </div>
<?php endif; ?>

                            <div class="form-field checkbox-field">
                                <div class="checkbox-row">
                                    <input type="checkbox" id="confirm_delete" name="confirm_delete" value="yes" required<?= $fieldAria('confirm_delete') ?>>
                                    <label for="confirm_delete"><?= e(t('account.delete.confirm')) ?></label>
                                </div>
                                <?= $fieldError('confirm_delete') ?>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-danger profile-submit delete-submit"><?= e(t('account.delete.submit')) ?></button>
                            </div>
                        </form>
                    </section>
                </div>
            </div>
        </div>
<?php
page_footer(['profile.js']);

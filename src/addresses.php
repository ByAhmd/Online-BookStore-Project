<?php

declare(strict_types=1);

/**
 * Delivery addresses: the customers' saved addresses (their address book)
 * and the Saudi National Address rules they follow. BookWise delivers within
 * Saudi Arabia only, free of charge.
 *
 * A National Address has a building number (4 digits), a street, a district,
 * a city, a postal code (5 digits) and usually an additional number
 * (4 digits); many also have a short address code of 4 letters and 4 digits
 * (e.g. RRRD2929). Phone numbers are Saudi mobile numbers, stored as
 * +9665XXXXXXXX. Digits may be typed as Arabic-Indic digits (٠-٩); they are
 * stored as 0-9.
 *
 * Checkout copies the chosen address into the order (order_addresses, see
 * src/orders.php), so editing or deleting a saved address never changes an
 * order that was already placed.
 */

/** Saved addresses per customer. */
const ADDRESS_MAX_PER_CUSTOMER = 5;
/** The fields of an address in form order; also the columns of addresses and order_addresses. */
const ADDRESS_FIELDS = [
    'recipient_name', 'phone', 'city', 'district', 'street',
    'building_number', 'postal_code', 'additional_number', 'short_address',
];
/** Fields that may be left empty. */
const ADDRESS_OPTIONAL_FIELDS = ['additional_number', 'short_address'];
/** Longest text values, in characters. */
const ADDRESS_MAX_LENGTHS = ['recipient_name' => 100, 'city' => 60, 'district' => 60, 'street' => 100];
/** Columns read for a saved address. */
const ADDRESS_COLUMNS = 'id, user_id, recipient_name, phone, city, district, street, building_number, postal_code,
                         additional_number, short_address, is_default';

/* ---------------------------------------------------------------------
 * Validation and formatting
 * ------------------------------------------------------------------- */

/**
 * Replaces Arabic-Indic (٠-٩) and Eastern Arabic-Indic (۰-۹) digits with 0-9.
 */
function ascii_digits(string $value): string
{
    static $digits = null;
    if ($digits === null) {
        $digits = [];
        for ($digit = 0; $digit <= 9; $digit++) {
            $digits[mb_chr(0x0660 + $digit, 'UTF-8')] = (string) $digit;
            $digits[mb_chr(0x06F0 + $digit, 'UTF-8')] = (string) $digit;
        }
    }
    return strtr($value, $digits);
}

/**
 * A Saudi mobile number as +9665XXXXXXXX, or null. Accepts the usual ways of
 * writing one (05XXXXXXXX, 5XXXXXXXX, +966 5X XXX XXXX, 009665XXXXXXXX,
 * 9665XXXXXXXX, and the country code followed by the local 0, as in
 * +966 05X XXX XXXX), with spaces, dashes, dots, brackets and the invisible
 * direction marks that copying from right-to-left text adds.
 */
function normalize_saudi_mobile(string $phone): ?string
{
    $compact = preg_replace('/[\s\-.()\x{00A0}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', '', ascii_digits($phone));
    if (!is_string($compact) || !preg_match('/^(?:\+966|00966|966)?0?(5\d{8})$/D', $compact, $match)) {
        return null;
    }
    return '+966' . $match[1];
}

/**
 * +9665XXXXXXXX grouped for reading: "+966 5X XXX XXXX".
 */
function format_saudi_mobile(string $phone): string
{
    if (!preg_match('/^\+966(5\d)(\d{3})(\d{4})$/D', $phone, $match)) {
        return $phone;
    }
    return '+966 ' . $match[1] . ' ' . $match[2] . ' ' . $match[3];
}

/**
 * Validates and normalizes an address form (profile or checkout). Text is
 * trimmed, digits become 0-9, the phone number becomes +9665XXXXXXXX and the
 * short address upper case without spaces. Empty optional fields are ''.
 * Messages are in the visitor's language (account.addresses.validation.*).
 *
 * @param array<string, mixed> $input
 * @return array{0: array<string, string>, 1: array<string, string>} [clean values, errors by field]
 */
function validate_address_input(array $input): array
{
    $values = [];
    foreach (ADDRESS_FIELDS as $field) {
        $values[$field] = input_string($input, $field, 200);
    }
    $errors = [];

    foreach (ADDRESS_MAX_LENGTHS as $field => $max) {
        $value = $values[$field];
        if ($value === '') {
            $errors[$field] = t('account.addresses.validation.' . $field . '_required');
        } elseif (mb_strlen($value) > $max) {
            $errors[$field] = tn('account.addresses.validation.too_long', $max);
        } elseif (preg_match('/[\x00-\x1F\x7F<>]/u', $value) || !preg_match('/[\p{L}\p{N}]/u', $value)) {
            $errors[$field] = t('account.addresses.validation.' . $field . '_invalid');
        }
    }

    if ($values['phone'] === '') {
        $errors['phone'] = t('account.addresses.validation.phone_required');
    } else {
        $phone = normalize_saudi_mobile($values['phone']);
        if ($phone === null) {
            $errors['phone'] = t('account.addresses.validation.phone_invalid');
        } else {
            $values['phone'] = $phone;
        }
    }

    foreach (['building_number' => 4, 'postal_code' => 5, 'additional_number' => 4] as $field => $length) {
        $values[$field] = (string) preg_replace('/\s+/u', '', ascii_digits($values[$field]));
        if ($values[$field] === '' && in_array($field, ADDRESS_OPTIONAL_FIELDS, true)) {
            continue;
        }
        if (!preg_match('/^\d{' . $length . '}$/D', $values[$field])) {
            $errors[$field] = t('account.addresses.validation.' . $field);
        }
    }

    $values['short_address'] = strtoupper((string) preg_replace('/[\s\-]+/u', '', ascii_digits($values['short_address'])));
    if ($values['short_address'] !== '' && !preg_match('/^[A-Z]{4}\d{4}$/D', $values['short_address'])) {
        $errors['short_address'] = t('account.addresses.validation.short_address');
    }

    return [$values, $errors];
}

/**
 * An address as plain-text lines in the active language (emails), e.g.
 * "Sara Ahmed", "1234 King Fahd Road", "Al Olaya", "Riyadh 12345-6789",
 * "Short address: RRRD2929", "+966 51 234 5678". The phone number is wrapped
 * in a left-to-right isolate, so right-to-left text never reverses it.
 *
 * @param array<string, mixed> $address A saved address or an order's copy
 * @return list<string>
 */
function address_lines(array $address): array
{
    $lines = [(string) $address['recipient_name']];
    foreach (address_format_parts($address) as [$key, $replace]) {
        $lines[] = t($key, $replace);
    }
    $lines[] = "\u{2066}" . format_saudi_mobile((string) $address['phone']) . "\u{2069}";
    return $lines;
}

/**
 * The same lines as HTML for pages (one .address-line each). What the
 * customer typed is isolated with <bdi>, so Arabic and Latin text read
 * correctly inside each other, and the phone number runs left to right.
 *
 * @param array<string, mixed> $address
 */
function address_html(array $address): string
{
    $bdi = static fn (mixed $value): string => '<bdi dir="auto">' . e($value) . '</bdi>';
    $lines = ['<span class="address-line address-recipient">' . $bdi($address['recipient_name']) . '</span>'];
    foreach (address_format_parts($address) as [$key, $replace]) {
        $lines[] = '<span class="address-line">' . t_markup($key, array_map($bdi, $replace)) . '</span>';
    }
    $lines[] = '<span class="address-line address-phone"><span dir="ltr">' . e(format_saudi_mobile((string) $address['phone'])) . '</span></span>';
    return implode("\n", $lines);
}

/**
 * The translated lines of an address after the recipient: street, district,
 * city and postal code (with the additional number, "12345-6789"), short address.
 *
 * @param array<string, mixed> $address
 * @return list<array{0: string, 1: array<string, string>}> [translation key, placeholders]
 */
function address_format_parts(array $address): array
{
    // The postal code and the additional number are written as one number ("12345-6789"),
    // so right-to-left text never separates them.
    $additional = (string) ($address['additional_number'] ?? '');
    $postal = (string) $address['postal_code'] . ($additional !== '' ? '-' . $additional : '');
    $parts = [
        ['shop.address.format.street', ['building' => (string) $address['building_number'], 'street' => (string) $address['street']]],
        ['shop.address.format.district', ['district' => (string) $address['district']]],
        ['shop.address.format.city', ['city' => (string) $address['city'], 'postal' => $postal]],
    ];
    $short = (string) ($address['short_address'] ?? '');
    if ($short !== '') {
        $parts[] = ['shop.address.format.short', ['code' => $short]];
    }
    return $parts;
}

/**
 * Column values of an address in ADDRESS_FIELDS order, with empty optional
 * fields as NULL.
 *
 * @param array<string, mixed> $address
 * @return list<string|null>
 */
function address_column_values(array $address): array
{
    $values = [];
    foreach (ADDRESS_FIELDS as $field) {
        $value = (string) ($address[$field] ?? '');
        $values[] = $value === '' && in_array($field, ADDRESS_OPTIONAL_FIELDS, true) ? null : $value;
    }
    return $values;
}

/* ---------------------------------------------------------------------
 * The customer's address book
 * ------------------------------------------------------------------- */

/**
 * The customer's saved addresses: the default first, then in the order they
 * were added.
 *
 * @return list<array<string, mixed>>
 */
function customer_addresses(int $userId): array
{
    return db_all('SELECT ' . ADDRESS_COLUMNS . ' FROM addresses WHERE user_id = ? ORDER BY is_default DESC, id', [$userId]);
}

/**
 * One of the customer's own addresses, or null (also for other customers' addresses).
 *
 * @return array<string, mixed>|null
 */
function find_user_address(int $userId, int $addressId): ?array
{
    return db_one('SELECT ' . ADDRESS_COLUMNS . ' FROM addresses WHERE id = ? AND user_id = ?', [$addressId, $userId]);
}

/**
 * Id of the customer's default address, or null when they have none.
 */
function default_address_id(int $userId): ?int
{
    $id = db_value('SELECT id FROM addresses WHERE user_id = ? AND is_default = 1 ORDER BY id LIMIT 1', [$userId]);
    return $id !== null ? (int) $id : null;
}

/**
 * Remembers for the rest of the session the address the customer chose at
 * checkout, so the checkout page chooses it again (instead of the default)
 * when the payment could not start or was canceled.
 */
function remember_checkout_address(int $addressId): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['checkout_address'] = $addressId;
    }
}

/**
 * The address chosen at the last checkout in this session, or null. It may
 * have been deleted since, or belong to an account that was signed in
 * before: callers use it only when it is one of the customer's addresses.
 */
function remembered_checkout_address(): ?int
{
    $id = $_SESSION['checkout_address'] ?? null;
    return is_int($id) ? $id : null;
}

/**
 * Adds an address ($addressId null) or changes one of the customer's own.
 * The first address becomes the default, and $makeDefault makes this one the
 * default. An address the customer has already saved (every field the same
 * once normalized) is not added a second time: its id is returned instead
 * (status 'existing'), so typing it again at checkout (after a payment that
 * did not start, or in a double submit) never fills the address book with
 * copies. The customer's row is locked meanwhile, so requests at the same
 * time can neither go beyond ADDRESS_MAX_PER_CUSTOMER, nor save the same
 * address twice, nor leave two defaults.
 *
 * status: 'created', 'existing' (already saved, nothing added), 'updated',
 * 'limit' (already ADDRESS_MAX_PER_CUSTOMER addresses, nothing saved) or
 * 'missing' (not the customer's address).
 *
 * @param array<string, string> $address Clean values from validate_address_input()
 * @return array{status: 'created'|'existing'|'updated'|'limit'|'missing', id: int|null}
 */
function save_customer_address(int $userId, array $address, ?int $addressId = null, bool $makeDefault = false): array
{
    return db_transaction(static function () use ($userId, $address, $addressId, $makeDefault): array {
        if (db_one('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]) === null) {
            return ['status' => 'missing', 'id' => null];
        }
        $values = address_column_values($address);

        if ($addressId === null) {
            $saved = db_all('SELECT id, ' . implode(', ', ADDRESS_FIELDS) . ' FROM addresses WHERE user_id = ? ORDER BY id', [$userId]);
            foreach ($saved as $row) {
                if (address_column_values($row) === $values) {
                    if ($makeDefault) {
                        db_run('UPDATE addresses SET is_default = (id = ?) WHERE user_id = ?', [$row['id'], $userId]);
                    }
                    return ['status' => 'existing', 'id' => (int) $row['id']];
                }
            }
            $count = count($saved);
            if ($count >= ADDRESS_MAX_PER_CUSTOMER) {
                return ['status' => 'limit', 'id' => null];
            }
            db_run(
                'INSERT INTO addresses (user_id, ' . implode(', ', ADDRESS_FIELDS) . ')
                 VALUES (?' . str_repeat(', ?', count(ADDRESS_FIELDS)) . ')',
                [$userId, ...$values]
            );
            $id = (int) db()->lastInsertId();
            $status = 'created';
            $makeDefault = $makeDefault || $count === 0;
        } else {
            if (db_value('SELECT 1 FROM addresses WHERE id = ? AND user_id = ?', [$addressId, $userId]) === null) {
                return ['status' => 'missing', 'id' => null];
            }
            $assignments = implode(', ', array_map(static fn (string $field): string => $field . ' = ?', ADDRESS_FIELDS));
            db_run("UPDATE addresses SET $assignments WHERE id = ? AND user_id = ?", [...$values, $addressId, $userId]);
            $id = $addressId;
            $status = 'updated';
        }

        if ($makeDefault) {
            db_run('UPDATE addresses SET is_default = (id = ?) WHERE user_id = ?', [$id, $userId]);
        }
        return ['status' => $status, 'id' => $id];
    });
}

/**
 * Makes one of the customer's addresses their default. False when it is not theirs.
 */
function set_default_address(int $userId, int $addressId): bool
{
    return db_transaction(static function () use ($userId, $addressId): bool {
        db_one('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
        if (db_value('SELECT 1 FROM addresses WHERE id = ? AND user_id = ?', [$addressId, $userId]) === null) {
            return false;
        }
        db_run('UPDATE addresses SET is_default = (id = ?) WHERE user_id = ?', [$addressId, $userId]);
        return true;
    });
}

/**
 * Deletes one of the customer's addresses. When it was the default, the
 * oldest remaining address becomes the default. Orders keep their own copy.
 */
function delete_customer_address(int $userId, int $addressId): bool
{
    return db_transaction(static function () use ($userId, $addressId): bool {
        db_one('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
        if (db_run('DELETE FROM addresses WHERE id = ? AND user_id = ?', [$addressId, $userId])->rowCount() === 0) {
            return false;
        }
        if (db_value('SELECT 1 FROM addresses WHERE user_id = ? AND is_default = 1', [$userId]) === null) {
            db_run('UPDATE addresses SET is_default = 1 WHERE user_id = ? ORDER BY id LIMIT 1', [$userId]);
        }
        return true;
    });
}

<?php
/**
 * The fields of a delivery address (Saudi National Address), shared by the
 * address form on the profile page and the checkout page. Rendered with
 * render('address_fields', [...]); the caller provides the <form>.
 *
 * @var array<string, string> $values Values to show, by field
 * @var array<string, string|null> $errors Validation messages, by field
 * @var string $prefix Prefix of the element ids (unique on the page)
 * @var bool $required Whether the browser requires the mandatory fields (not
 *                     while the fields are hidden until a new address is chosen)
 */

$cities = translation_entry('shop.address.cities', current_locale());
$definitions = [
    'recipient_name' => ['type' => 'text', 'maxlength' => '100', 'autocomplete' => 'shipping name', 'dir' => 'auto'],
    'phone' => ['type' => 'tel', 'maxlength' => '20', 'autocomplete' => 'shipping tel', 'inputmode' => 'tel', 'dir' => 'ltr'],
    'city' => ['type' => 'text', 'maxlength' => '60', 'autocomplete' => 'shipping address-level2', 'dir' => 'auto', 'list' => $prefix . '-cities'],
    'district' => ['type' => 'text', 'maxlength' => '60', 'autocomplete' => 'shipping address-level3', 'dir' => 'auto'],
    'street' => ['type' => 'text', 'maxlength' => '100', 'autocomplete' => 'shipping address-line1', 'dir' => 'auto'],
    'building_number' => ['type' => 'text', 'maxlength' => '4', 'inputmode' => 'numeric', 'dir' => 'ltr'],
    'postal_code' => ['type' => 'text', 'maxlength' => '5', 'autocomplete' => 'shipping postal-code', 'inputmode' => 'numeric', 'dir' => 'ltr'],
    'additional_number' => ['type' => 'text', 'maxlength' => '4', 'inputmode' => 'numeric', 'dir' => 'ltr'],
    'short_address' => ['type' => 'text', 'maxlength' => '9', 'autocapitalize' => 'characters', 'spellcheck' => 'false', 'dir' => 'ltr'],
];
$hints = ['phone', 'building_number', 'postal_code', 'additional_number', 'short_address'];
?>
                            <div class="address-fields">
<?php foreach ($definitions as $field => $attributes):
    $id = $prefix . '-' . $field;
    $error = $errors[$field] ?? null;
    $optional = in_array($field, ADDRESS_OPTIONAL_FIELDS, true);
    $describedBy = array_filter([in_array($field, $hints, true) ? $id . '-hint' : null, $error !== null ? $id . '-error' : null]);
    ?>
                                <div class="form-field address-field address-field-<?= e(str_replace('_', '-', $field)) ?>">
                                    <label for="<?= e($id) ?>"><?= e(t('shop.address.fields.' . $field)) ?><?php if ($optional): ?> <span class="optional"><?= e(t('shop.address.optional')) ?></span><?php endif; ?></label>
                                    <input id="<?= e($id) ?>" name="<?= e($field) ?>" class="form-control" value="<?= e($values[$field] ?? '') ?>"<?php foreach ($attributes as $name => $value): ?> <?= e($name) ?>="<?= e($value) ?>"<?php endforeach; ?><?= $required && !$optional ? ' required' : '' ?><?= $error !== null ? ' aria-invalid="true"' : '' ?><?= $describedBy !== [] ? ' aria-describedby="' . e(implode(' ', $describedBy)) . '"' : '' ?>>
<?php if (in_array($field, $hints, true)): ?>
                                    <p class="form-hint" id="<?= e($id) ?>-hint"><?= e(t('shop.address.hints.' . $field)) ?></p>
<?php endif; ?>
<?php if ($error !== null): ?>
                                    <p class="field-error" id="<?= e($id) ?>-error"><?= e($error) ?></p>
<?php endif; ?>
                                </div>
<?php endforeach; ?>
<?php if (is_array($cities)): ?>
                                <datalist id="<?= e($prefix) ?>-cities">
<?php foreach ($cities as $city): ?>
                                    <option value="<?= e($city) ?>"></option>
<?php endforeach; ?>
                                </datalist>
<?php endif; ?>
                            </div>

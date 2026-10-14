// Book detail page enhancements. Everything works without this file: the star
// rating is plain radio inputs and every action is a regular POST form.
// Interface text comes from data-i18n-* attributes rendered by Selected.php.
document.addEventListener('DOMContentLoaded', () => {
    const fill = (template, values) => Object.keys(values).reduce(
        (result, name) => result.split(':' + name).join(values[name]),
        String(template || '')
    );

    // Show the chosen (or hovered) rating as text next to the stars.
    const options = document.querySelector('.star-options');
    const output = document.getElementById('rating-value');
    if (options && output) {
        const radios = Array.from(options.querySelectorAll('input[name="rating"]'));
        const describe = (value) => (value ? fill(output.dataset.i18nRating, { value }) : '');
        const checkedValue = () => {
            const checked = radios.find((radio) => radio.checked);
            return checked ? checked.value : '';
        };
        const showChecked = () => {
            output.textContent = describe(checkedValue());
        };

        radios.forEach((radio) => {
            radio.addEventListener('change', showChecked);
            const label = options.querySelector(`label[for="${radio.id}"]`);
            if (label) {
                label.addEventListener('mouseenter', () => {
                    output.textContent = describe(radio.value);
                });
            }
        });
        options.addEventListener('mouseleave', showChecked);
        showChecked();
    }

    // Remaining-characters counter for the review text.
    const textarea = document.getElementById('review-comment');
    const hint = document.getElementById('review-comment-hint');
    if (textarea && hint && hint.dataset.i18nCounter) {
        const max = Number(hint.dataset.max) || Number(textarea.getAttribute('maxlength')) || 0;
        if (max > 0) {
            const update = () => {
                const left = Math.max(0, max - textarea.value.length);
                hint.textContent = fill(hint.dataset.i18nCounter, { left: left.toLocaleString(), max: max.toLocaleString() });
            };
            textarea.addEventListener('input', update);
            update();
        }
    }
});

// Returning with the Back button can restore the page from the back/forward
// cache with the data-once buttons still disabled; make them usable again
// (but never the add-to-cart button of a sold-out book).
window.addEventListener('pageshow', (event) => {
    if (!event.persisted) {
        return;
    }
    document.querySelectorAll('.book-detail-page form[data-once] button[type="submit"][aria-disabled="true"]').forEach((button) => {
        const select = button.form ? button.form.querySelector('select[name="quantity"]') : null;
        if (select && select.disabled) {
            return;
        }
        button.disabled = false;
        button.removeAttribute('aria-disabled');
    });
});

// Book detail page enhancements. Everything works without this file: the star
// rating is plain radio inputs and every action is a regular POST form.
document.addEventListener('DOMContentLoaded', () => {
    // Show the chosen (or hovered) rating as text next to the stars.
    const options = document.querySelector('.star-options');
    const output = document.getElementById('rating-value');
    if (options && output) {
        const radios = Array.from(options.querySelectorAll('input[name="rating"]'));
        const describe = (value) => (value ? `${value} of 5 stars` : '');
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
    if (textarea && hint) {
        const max = Number(hint.dataset.max) || Number(textarea.getAttribute('maxlength')) || 0;
        if (max > 0) {
            const update = () => {
                const left = Math.max(0, max - textarea.value.length);
                hint.textContent = `${left.toLocaleString()} of ${max.toLocaleString()} characters left.`;
            };
            textarea.addEventListener('input', update);
            update();
        }
    }
});

// Returning with the Back button can restore the page from the back/forward
// cache with the data-once buttons still disabled; make them usable again.
window.addEventListener('pageshow', (event) => {
    if (!event.persisted) {
        return;
    }
    document.querySelectorAll('.book-detail-page form[data-once] button[type="submit"]').forEach((button) => {
        button.disabled = false;
        button.removeAttribute('aria-disabled');
    });
});

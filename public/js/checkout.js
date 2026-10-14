// Checkout page (checkout.php): after a delivery address was refused, start on
// the first field the server rejected. Everything else works without JavaScript.
document.addEventListener('DOMContentLoaded', () => {
    const invalid = Array.from(document.querySelectorAll('.checkout-form [aria-invalid="true"]'))
        .find((field) => field.offsetParent !== null);
    if (invalid) {
        invalid.focus();
    }
});

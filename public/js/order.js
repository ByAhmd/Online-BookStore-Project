// Order page (order.php): the "Print receipt" button. The page prints as a
// receipt through css/order.css (@media print), also from the browser's own
// print command; while printing, the document is titled as the receipt (the
// title is the default name of a saved PDF). Without JavaScript the button
// stays hidden.
document.addEventListener('DOMContentLoaded', () => {
    const button = document.querySelector('button[data-print]');
    if (!button) {
        return;
    }
    button.hidden = false;
    button.addEventListener('click', () => {
        window.print();
    });

    const pageTitle = document.title;
    const receiptTitle = button.dataset.printTitle || pageTitle;
    window.addEventListener('beforeprint', () => {
        document.title = receiptTitle;
    });
    window.addEventListener('afterprint', () => {
        document.title = pageTitle;
    });
});

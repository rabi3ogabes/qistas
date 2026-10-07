// <dialog class="sheet" data-auto-open> opens itself on load (the upgrade prompt after a limit is hit);
// anything with [data-close-sheet] closes it. A native <dialog> gives focus trapping and Escape for free.
export function initSheets() {
    document.querySelectorAll('dialog.sheet').forEach((dialog) => {
        dialog.querySelectorAll('[data-close-sheet]').forEach((control) => {
            control.addEventListener('click', () => dialog.close());
        });

        // A click on the backdrop (the dialog element itself, outside its content) closes it.
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close();
        });

        if (dialog.hasAttribute('data-auto-open') && typeof dialog.showModal === 'function') {
            dialog.showModal();
        }
    });

    document.querySelectorAll('[data-open-sheet]').forEach((trigger) => {
        trigger.addEventListener('click', () => {
            const dialog = document.getElementById(trigger.getAttribute('data-open-sheet'));
            if (dialog && typeof dialog.showModal === 'function') dialog.showModal();
        });
    });
}

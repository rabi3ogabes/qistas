// One press, one save. A form that is sending shows it on the button that sent it and ignores further presses until the
// next page arrives; every press also carries a one-time token, so even a request the browser sends twice is saved
// once on the server (App\Http\Middleware\PreventDuplicateSubmissions). And when a page comes back with mistakes, the
// first field to fix is brought into view and focused, wherever it is on a long form.

const FIELD = '_once';
// A press that never leaves the page (a download) lets the form go again after this long.
const RELEASE_AFTER_MS = 8000;

function token() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();

    return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
}

function release(form) {
    delete form.dataset.sending;
    form.removeAttribute('aria-busy');
    form.querySelectorAll('[aria-busy="true"]').forEach((button) => button.removeAttribute('aria-busy'));
    document.querySelectorAll(`[form="${form.id}"][aria-busy="true"]`).forEach((button) => button.removeAttribute('aria-busy'));
}

function renew(form) {
    const field = form.querySelector(`input[name="${FIELD}"]`);
    if (field) field.value = token();
    release(form);
}

function onSubmit(event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;
    // Searches and filters only read: they can be sent as often as anyone likes.
    if ((form.getAttribute('method') || 'get').toLowerCase() === 'get' || form.hasAttribute('data-repeatable')) return;

    if (form.dataset.sending === 'true') {
        event.preventDefault();

        return;
    }

    let field = form.querySelector(`input[name="${FIELD}"]`);
    if (!field) {
        field = document.createElement('input');
        field.type = 'hidden';
        field.name = FIELD;
        field.value = token();
        form.appendChild(field);
    }

    form.dataset.sending = 'true';
    form.setAttribute('aria-busy', 'true');
    // The button keeps its name and value in the request (a disabled one would lose them); it only shows it is busy.
    const button = event.submitter instanceof HTMLElement ? event.submitter : form.querySelector('[type="submit"], button:not([type])');
    if (button) button.setAttribute('aria-busy', 'true');

    // Downloads and new tabs leave this page as it is: let the form go again after a moment. The token stays, so a press
    // made while a slow page is still on its way is still recognised as the same one.
    const opensElsewhere = form.target === '_blank' || form.hasAttribute('data-download');
    window.setTimeout(() => { if (form.isConnected) release(form); }, opensElsewhere ? 1500 : RELEASE_AFTER_MS);
}

function focusFirstMistake() {
    const first = document.querySelector('main [aria-invalid="true"], main .field-error:not(:empty)');
    if (!first) return;
    const target = first.matches('.field-error') ? first.closest('.field')?.querySelector('input, select, textarea') || first : first;
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    target.scrollIntoView({ block: 'center', behavior: reduce ? 'auto' : 'smooth' });
    if (typeof target.focus === 'function') target.focus({ preventScroll: true });
}

export function initForms() {
    document.addEventListener('submit', onSubmit);
    // Back to a page the browser kept: its forms are ready again, each with a fresh token for a fresh press.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) document.querySelectorAll('form').forEach(renew);
    });
    focusFirstMistake();
}

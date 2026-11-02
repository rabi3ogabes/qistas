// The browser's own time zone, handed to the server with the sign-up and sign-in forms (go-live basics, spec section 4.2).
//
// A form marked `data-timezone` has a hidden `timezone` field. When the page is ready the field is filled with the zone
// the browser says it is in ("Asia/Qatar"). The server decides what to do with it: a new workspace starts on that
// clock, and a sign-in record notes it. When the browser cannot say, or says something that is not a name, the field is
// left empty and the server falls back on the country. Nothing here reads or changes anything else on the page, and
// nothing is evaluated: the page may forbid inline scripts and `eval`.

const FIELDS = 'form[data-timezone] input[name="timezone"]';

// The zone the browser reports, or '' when it cannot say.
function browserZone() {
    try {
        const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;

        return typeof zone === 'string' ? zone : '';
    } catch {
        return '';
    }
}

// Fills every such field under `root` (the whole document unless a test hands in another).
export function fillTimezones(root = document) {
    const zone = browserZone();
    if (zone === '') return;

    root.querySelectorAll(FIELDS).forEach((field) => {
        field.value = zone;
    });
}

export function initTimezone() {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => fillTimezones(), { once: true });

        return;
    }

    fillTimezones();
}

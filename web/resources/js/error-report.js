// The website's reporter for the error center (design section 9): it listens for the page's own script errors and
// unhandled promise rejections, and tells the server about the ones that are ours, with a beacon to POST /client-errors.
//
// The page is the visitor's, so this is built to say as little as it can and to be harmless:
//
//   - It reports only an error in one of the site's own scripts: a file on the page's own origin, ending .js or .mjs. Not
//     a browser extension's (chrome-extension:, moz-extension:, ...), not another site's, not the browser's opaque
//     "Script error." (what it says about a script it will not show us), and not an error that says nothing about where it
//     came from.
//   - It sends the error's class, its message, a trace of the site's own frames (the host cut off), and the page's route as
//     a pattern: ids replaced by {id}, no query string, no fragment. Nothing else: not the address, the page's text, a
//     form, a cookie, a header or the visitor. The server cleans it once more (ErrorScrubber) and keeps none of the rest.
//   - At most 5 reports leave one page view, and the same error is sent once. A page that loops on an error cannot flood
//     the server, and its first five errors are the five different ones.
//   - It never throws, and it never reports itself: whatever fails inside it is swallowed, and an error raised while it is
//     sending is not sent. The body stays within the 8 KB the endpoint takes, in bytes, whatever the page's text is.
//
// The pure parts are exported and tested through Node (tests/Feature/Errors/ErrorReportScriptTest.php). `initErrorReporting`
// takes the window it works on, so the tests can hand it a stand-in.

const ENDPOINT = '/client-errors';

// The limits the endpoint holds a report to (App\Http\Controllers\ClientErrorController), and the page's share of them.
const MAX_REPORTS = 5;
const MAX_BYTES = 8192;
const MAX_CLASS = 160;
const MAX_MESSAGE = 500;
const MAX_TRACE = 6000;
const MAX_ROUTE = 160;

// A browser extension's scripts: not ours, whatever they say.
const EXTENSION = /^(?:chrome|moz|safari|safari-web|ms-browser|edge)-extension:/i;
// What a browser says instead of an error that came from a script it will not show us.
const OPAQUE = /^script error\.?$/i;
// A frame of a stack: Chrome and Node say `at fn (url:1:2)` or `at url:1:2`, Firefox and Safari say `fn@url:1:2`.
const V8_FRAME = /^\s*at\s+(?:.*?\s\()?([a-z][a-z0-9+.-]*:\/\/[^\s()]+?)(?::\d+){0,2}\)?\s*$/i;
const GECKO_FRAME = /^[^@]*@([a-z][a-z0-9+.-]*:\/\/\S+?)(?::\d+){0,2}\s*$/i;
// The name of an error class, as the browser gives it (TypeError, DOMException's NetworkError).
const CLASS_NAME = /^[A-Za-z_$][\w$.]*$/;
// What a browser puts before the message of an error that has no error object: `Uncaught TypeError: x`, `SyntaxError: x`.
const PREFIXED = /^(?:Uncaught\s+)?([A-Za-z_$][\w$]*(?:Error|Exception)):\s*([\s\S]*)$/;

// A path segment that is an identifier and not a word: the same rules as the server's ErrorScrubber::route(), and the
// long plain tokens a signing link carries.
const ID_SEGMENT = [
    /^\d+$/,
    /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i,
    /^[0-7][0-9A-HJKMNP-TV-Z]{25}$/i,
    /^(?=[0-9a-f]*\d)[0-9a-f]{8,}$/i,
    /^(?=.*\d)(?=.*[A-Za-z])[A-Za-z0-9_-]{16,}$/,
    /^[A-Za-z0-9_-]{8,}:[A-Za-z0-9_-]{16,}$/,
    /^[A-Za-z0-9]{20,}$/,
];

// A text with no half of a surrogate pair in it: JSON that holds one is refused by the server's parser.
function wellFormed(text) {
    if (typeof text.toWellFormed === 'function') return text.toWellFormed();

    let out = '';
    for (let i = 0; i < text.length; i += 1) {
        const code = text.charCodeAt(i);
        if (code >= 0xd800 && code <= 0xdbff) {
            const next = text.charCodeAt(i + 1);
            if (next >= 0xdc00 && next <= 0xdfff) {
                out += text[i] + text[i + 1];
                i += 1;
            } else {
                out += '�';
            }
        } else {
            out += code >= 0xdc00 && code <= 0xdfff ? '�' : text[i];
        }
    }

    return out;
}

// At most `max` characters, never ending in the first half of a pair.
function cut(text, max) {
    if (text.length <= max) return text;

    const last = text.charCodeAt(max - 1);

    return text.slice(0, last >= 0xd800 && last <= 0xdbff ? max - 1 : max);
}

function clean(text, max) {
    return cut(wellFormed(String(text)), max);
}

function byteLength(text) {
    try {
        return new TextEncoder().encode(text).length;
    } catch {
        return text.length * 3;
    }
}

function isId(segment) {
    return ID_SEGMENT.some((pattern) => pattern.test(segment));
}

// The pattern of a page's path: no query string, no fragment, every segment that is an identifier replaced by {id}.
// `/app/customers/0b8a7c1e-6a0e-4d4f-9a52-3f1d1b2c4e5f?tab=payments` is `/app/customers/{id}`. Never throws.
export function routePattern(pathname) {
    try {
        const path = typeof pathname === 'string' ? pathname.split(/[?#]/, 1)[0] : '';
        if (path === '') return '/';

        const rooted = path.startsWith('/') ? path : `/${path}`;

        return clean(rooted.split('/').map((segment) => (isId(segment) ? '{id}' : segment)).join('/'), MAX_ROUTE);
    } catch {
        return '/';
    }
}

// Is `origin` the origin of an http or https page (`https://www.qistasme.com`)? A page with none (about:blank, a file) has
// no scripts of its own to tell it by.
function isOrigin(origin) {
    return typeof origin === 'string' && /^https?:\/\/[^/]+$/i.test(origin);
}

// Is this file one of the site's own scripts: an absolute address on the page's origin that names a .js or .mjs file?
function isOwnScript(file, origin) {
    try {
        if (typeof file !== 'string' || file === '' || EXTENSION.test(file)) return false;

        const url = new URL(file);

        return url.origin === origin && /\.m?js$/i.test(url.pathname);
    } catch {
        return false;
    }
}

// The frames of a stack that name an address, as { line, url }.
function framesOf(stack) {
    if (typeof stack !== 'string') return [];

    const frames = [];
    for (const line of stack.slice(0, 20000).split(/\r?\n/)) {
        if (line.length > 1000) continue;
        const found = V8_FRAME.exec(line) ?? GECKO_FRAME.exec(line);
        if (found !== null) frames.push({ line, url: found[1] });
    }

    return frames;
}

// The error object behind an `error` event (event.error) or an `unhandledrejection` event (event.reason), if there is one.
function errorOf(event) {
    const error = event.error ?? event.reason;

    return error !== null && typeof error === 'object' ? error : null;
}

// Where the error came from: the file the browser names, or for a rejection (which names none) the first frame of its stack.
function scriptOf(event, error) {
    if (typeof event.filename === 'string' && event.filename !== '') return event.filename;

    const frames = error === null ? [] : framesOf(error.stack);

    return frames.length > 0 ? frames[0].url : null;
}

// Should this error be reported? Only one that came from one of the site's own scripts, on the page's own origin.
export function shouldReport(errorEvent, ownOrigin) {
    try {
        if (!isOrigin(ownOrigin) || errorEvent === null || typeof errorEvent !== 'object') return false;
        if (typeof errorEvent.message === 'string' && OPAQUE.test(errorEvent.message.trim())) return false;

        return isOwnScript(scriptOf(errorEvent, errorOf(errorEvent)), ownOrigin);
    } catch {
        return false;
    }
}

// The class and the message of an error, whichever way the browser gave it.
function describeError(event, error) {
    if (error !== null && typeof error.message === 'string') {
        const name = typeof error.name === 'string' && CLASS_NAME.test(error.name) ? error.name : 'Error';

        return { name, message: error.message === '' ? name : error.message };
    }

    if (typeof event.message === 'string' && event.message !== '') {
        const found = PREFIXED.exec(event.message);

        return found === null ? { name: 'Error', message: event.message } : { name: found[1], message: found[2] === '' ? found[1] : found[2] };
    }

    if ('reason' in event) {
        const reason = event.reason;
        const plain = ['string', 'number', 'boolean'].includes(typeof reason) && String(reason) !== '';

        return { name: 'UnhandledRejection', message: plain ? String(reason) : 'Unhandled promise rejection' };
    }

    return { name: 'Error', message: 'Error' };
}

// The site's own frames of a stack, as the server reads them, with the host cut off, within MAX_TRACE.
function ownTrace(stack, origin) {
    const lines = [];
    let size = 0;

    for (const { line, url } of framesOf(stack)) {
        if (!isOwnScript(url, origin)) continue;

        const kept = clean(line.trim().split(origin).join(''), MAX_TRACE);
        if (size + kept.length + 1 > MAX_TRACE) break;
        lines.push(kept);
        size += kept.length + 1;
    }

    return lines.join('\n');
}

// What is sent for an error: { class, message, trace, route }, and nothing else. `location` is the page's (only its origin
// and its path are read; its query string and fragment never are).
export function buildPayload(errorEvent, location) {
    const event = errorEvent !== null && typeof errorEvent === 'object' ? errorEvent : {};
    const error = errorOf(event);
    const origin = location && isOrigin(location.origin) ? location.origin : null;
    const { name, message } = describeError(event, error);

    const payload = { message: clean(message, MAX_MESSAGE), class: clean(name, MAX_CLASS) };
    const trace = error !== null && origin !== null ? ownTrace(error.stack, origin) : '';
    if (trace !== '') payload.trace = trace;
    payload.route = routePattern(location ? location.pathname : '');

    // The endpoint takes 8 KB in bytes, and a trace of Arabic names or a message in Chinese is up to three bytes a
    // character: give up the oldest frames last, then half the message, until it fits.
    while (byteLength(JSON.stringify(payload)) > MAX_BYTES && payload.trace !== undefined) {
        const lines = payload.trace.split('\n');
        lines.pop();
        if (lines.length === 0) delete payload.trace;
        else payload.trace = lines.join('\n');
    }
    while (byteLength(JSON.stringify(payload)) > MAX_BYTES && payload.message.length > 1) {
        payload.message = cut(payload.message, Math.floor(payload.message.length / 2));
    }

    return payload;
}

// A beacon outlives the page and costs it nothing; when the browser will not take one, fetch with keepalive does the same.
// No cookies go with the fetch. Whatever fails here is the reporter's own and is not reported.
function send(win, json) {
    try {
        if (win.navigator && typeof win.navigator.sendBeacon === 'function'
            && win.navigator.sendBeacon(ENDPOINT, new Blob([json], { type: 'application/json' }))) return;
    } catch {
        // fall through to fetch
    }

    try {
        if (typeof win.fetch !== 'function') return;

        const pending = win.fetch(ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: json,
            keepalive: true,
            credentials: 'omit',
        });
        if (pending && typeof pending.catch === 'function') pending.catch(() => {});
    } catch {
        // nothing to do: the report is lost, never raised
    }
}

// Starts reporting on `win` (the page's window). Nothing happens, and nothing throws, in a window that is not a page.
export function initErrorReporting(win) {
    try {
        if (!win || typeof win.addEventListener !== 'function') return;

        let sent = 0;
        let busy = false;
        const seen = new Set();

        const report = (event) => {
            // One report at a time and at most five a page view: an error raised while one is being sent is not sent.
            if (busy || sent >= MAX_REPORTS) return;
            busy = true;

            try {
                if (!shouldReport(event, win.location.origin)) return;

                const payload = buildPayload(event, win.location);
                const same = `${payload.class}|${payload.message}|${(payload.trace ?? '').split('\n', 1)[0]}`;
                if (seen.has(same)) return;

                seen.add(same);
                sent += 1;
                send(win, JSON.stringify(payload));
            } catch {
                // a reporter that fails is quiet
            } finally {
                busy = false;
            }
        };

        win.addEventListener('error', report);
        win.addEventListener('unhandledrejection', report);
    } catch {
        // not a page: nothing to report on
    }
}

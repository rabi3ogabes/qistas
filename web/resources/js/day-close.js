// The end-of-day page's two small helpers (daily-habit wave A, H4).
//
// `dayCount` is the live difference under the counted-cash field. It is only a hint: the server works the real figure out
// again when the day is closed (App\Actions\CloseDay), and the words for it come from the page, so nothing is decided or
// spelled here. Money is never a float: the expected cash arrives as a decimal string, what is typed becomes whole cents in
// a BigInt (fourteen digits and two decimals do not fit a double), and the difference is an integer number of cents.
//
// `copyText` copies the sentence a closed day is shared as, and shows that it did.

const ARABIC_INDIC = '٠١٢٣٤٥٦٧٨٩';
const EXTENDED_ARABIC_INDIC = '۰۱۲۳۴۵۶۷۸۹';

// An amount typed on an Arabic or Urdu keyboard reads the way the server reads it (App\Support\Digits).
function ascii(value) {
    return String(value ?? '')
        .replace(/[٠-٩]/g, (d) => String(ARABIC_INDIC.indexOf(d)))
        .replace(/[۰-۹]/g, (d) => String(EXTENDED_ARABIC_INDIC.indexOf(d)))
        .replace(/٫/g, '.')
        .trim();
}

// Whole cents (a BigInt) of what a person typed when the server would accept it as an amount (at most fourteen digits before
// the point and two after it, nothing else), and null when it would not: no hint is better than a wrong one.
function centsOfTyped(text) {
    const match = /^(\d{1,14})(?:\.(\d{1,2}))?$/.exec(ascii(text));
    if (match === null) return null;

    return BigInt(match[1]) * 100n + BigInt((match[2] ?? '').padEnd(2, '0'));
}

// Whole cents of an amount the server sent as a decimal string, either side of zero.
function centsOfServer(text) {
    const match = /^(-?)(\d+)(?:\.(\d+))?$/.exec(String(text ?? '').trim());
    if (match === null) return null;
    const cents = BigInt(match[2]) * 100n + BigInt((match[3] ?? '').padEnd(2, '0').slice(0, 2));

    return match[1] === '-' ? -cents : cents;
}

// Whole cents as a decimal string without its sign: 500n is "5.00".
function decimalOf(cents) {
    const whole = cents < 0n ? -cents : cents;

    return `${whole / 100n}.${String(whole % 100n).padStart(2, '0')}`;
}

export function dayCount() {
    return {
        counted: '',
        // What a screen reader is told about the difference: set when the field changes (is left), never on every key.
        announced: '',
        expected: null,
        currency: 'USD',
        locale: 'en-u-nu-latn',
        messages: {},

        init() {
            const config = JSON.parse(this.$el.dataset.config);
            this.expected = centsOfServer(config.expected);
            this.currency = config.currency;
            this.locale = config.locale;
            this.messages = config.messages;
            // What was typed before a refused submit comes back in the field, and the hint follows it.
            this.counted = config.initial ?? '';
        },

        // The counted cash less the expected cash, in whole cents, or null while what is typed is not an amount.
        get difference() {
            const counted = centsOfTyped(this.counted);

            return counted === null || this.expected === null ? null : counted - this.expected;
        },

        get hasHint() { return this.difference !== null; },

        get tone() {
            const difference = this.difference;
            if (difference === null) return '';

            return difference === 0n ? 'ok' : (difference < 0n ? 'bad' : 'warn');
        },

        get hint() {
            const difference = this.difference;
            if (difference === null) return '';
            if (difference === 0n) return this.messages.balanced;

            return (difference < 0n ? this.messages.short : this.messages.over).replace(':amount', this.money(difference));
        },

        announce() {
            this.announced = this.hasHint ? `${this.messages.difference}: ${this.hint}` : '';
        },

        money(cents) {
            return new Intl.NumberFormat(this.locale, { style: 'currency', currency: this.currency }).format(decimalOf(cents));
        },
    };
}

// Writes [text] to the clipboard; the long way round for a browser or a page without the clipboard (it needs https).
async function write(text) {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);

            return true;
        }
    } catch (error) {
        // Refused (a permission, a focus rule): try the other way.
    }

    const field = document.createElement('textarea');
    field.value = text;
    field.setAttribute('readonly', '');
    field.style.cssText = 'position:fixed;inset-block-start:0;opacity:0';
    document.body.appendChild(field);
    field.select();
    let copied = false;
    try {
        copied = document.execCommand('copy');
    } catch (error) {
        copied = false;
    }
    field.remove();

    return copied;
}

// When nothing could copy it, the sentence is selected so the person can copy it with their own keys.
function select(node) {
    if (!node) return;
    const range = document.createRange();
    range.selectNodeContents(node);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
}

export function copyText() {
    return {
        copied: false,
        timer: null,

        get idle() { return !this.copied; },
        get done() { return this.copied; },
        // What a screen reader hears once it is copied.
        get announcement() { return this.copied ? (this.$root.dataset.copied ?? '') : ''; },

        // `$el` is the element whose directive is running, and these run from the button and the live region inside the
        // component, not from its root: only the root holds the sentence, so it is `$root` here (`init` alone may use `$el`).
        async copy() {
            if (!(await write(this.$root.dataset.text ?? ''))) {
                select(this.$root.querySelector('.dc-sentence'));

                return;
            }
            this.copied = true;
            window.clearTimeout(this.timer);
            this.timer = window.setTimeout(() => { this.copied = false; }, 2400);
        },
    };
}

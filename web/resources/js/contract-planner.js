// The "open a contract" form's live preview. Like the home-page calculator it asks the server
// (POST /app/contracts/preview) for the schedule, so the numbers come from the engine that writes the real
// contract and never from a second copy of the maths here. Money is only *formatted* here; every amount stays
// an exact decimal string until display. The form works without this file: the preview is a convenience.

const ARABIC_INDIC = '٠١٢٣٤٥٦٧٨٩';
const EXTENDED_ARABIC_INDIC = '۰۱۲۳۴۵۶۷۸۹';

// Amounts may be typed on an Arabic or Urdu keyboard; the server reads them, and so must the preview total.
function ascii(value) {
    return String(value ?? '')
        .replace(/[٠-٩]/g, (d) => String(ARABIC_INDIC.indexOf(d)))
        .replace(/[۰-۹]/g, (d) => String(EXTENDED_ARABIC_INDIC.indexOf(d)))
        .replace(/[٫]/g, '.')
        .replace(/[٬،,\s]/g, '')
        .trim();
}

export function contractPlanner() {
    return {
        // inputs (the form's own fields, bound with x-model)
        type: 'scheduled',
        price: '',
        down: '',
        markupType: 'none',
        markupValue: '',
        count: '6',
        frequency: 'monthly',
        firstDue: '',

        // output
        schedule: null,
        error: '',
        loading: false,

        // wiring (read from data-config in init)
        currency: 'USD',
        locale: 'en-u-nu-latn',
        url: '',
        csrf: '',
        messages: {},
        timer: null,
        controller: null,

        init() {
            const config = JSON.parse(this.$el.dataset.config);
            this.url = config.url;
            this.csrf = config.csrf;
            this.locale = config.locale;
            this.currency = config.currency;
            this.messages = config.messages;
            Object.assign(this, config.initial);

            if (this.isScheduled && this.hasPrice) this.run();
        },

        // Debounced: typing "1500" asks once, not four times.
        changed() {
            clearTimeout(this.timer);
            this.error = '';

            if (!this.isScheduled || !this.hasPrice) {
                if (this.controller) this.controller.abort();
                this.schedule = null;
                this.loading = false;
                return;
            }

            this.loading = true;
            this.timer = setTimeout(() => this.run(), 250);
        },

        async run() {
            if (this.controller) this.controller.abort();
            this.controller = new AbortController();

            try {
                const response = await fetch(this.url, {
                    method: 'POST',
                    signal: this.controller.signal,
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': this.csrf,
                    },
                    body: JSON.stringify({
                        principal: this.price,
                        down_payment: this.down || '0',
                        markup_type: this.markupType,
                        markup_value: this.markupValue || '0',
                        count: this.count,
                        frequency: this.frequency,
                        first_due_date: this.firstDue || null,
                    }),
                });

                const body = await response.json().catch(() => ({}));

                if (response.ok) {
                    this.schedule = body;
                    this.error = '';
                } else if (response.status === 422 && body.errors) {
                    // Never leave numbers on screen that no longer match what is typed.
                    this.schedule = null;
                    this.error = Object.values(body.errors)[0][0];
                } else {
                    this.schedule = null;
                    this.error = this.messages.unavailable;
                }
            } catch (e) {
                if (e.name !== 'AbortError') {
                    this.schedule = null;
                    this.error = this.messages.unavailable;
                }
            } finally {
                this.loading = false;
            }
        },

        money(value) {
            const number = Number(value);
            if (value === null || value === undefined || Number.isNaN(number)) return '';
            return new Intl.NumberFormat(this.locale, { style: 'currency', currency: this.currency }).format(number);
        },

        shortDate(iso) {
            const [y, m, d] = iso.split('-').map(Number);
            return new Intl.DateTimeFormat(this.locale, { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' })
                .format(new Date(Date.UTC(y, m - 1, d)));
        },

        get isScheduled() { return this.type === 'scheduled'; },
        get hasMarkup() { return this.markupType !== 'none'; },
        get hasPrice() { return ascii(this.price) !== ''; },
        get isEmpty() { return !this.hasPrice && this.error === ''; },
        get isCashSale() { return !this.isScheduled && this.hasPrice && this.cashTotal !== ''; },
        get cashTotal() { return this.money(ascii(this.price)); },

        get hasResult() { return this.isScheduled && this.schedule !== null && this.schedule.installments.length > 0; },
        get each() { return this.hasResult ? this.money(this.schedule.installments[0].amount) : ''; },
        get times() { return this.hasResult ? String(this.schedule.installments.length) : ''; },
        get total() { return this.hasResult ? this.money(this.schedule.total) : ''; },
        get financed() { return this.hasResult ? this.money(this.schedule.financed) : ''; },
        get markupAmount() { return this.hasResult ? this.money(this.schedule.markup) : ''; },
        get rows() {
            if (!this.hasResult) return [];
            return this.schedule.installments.map((row) => ({
                number: row.number,
                date: this.shortDate(row.due_date),
                amount: this.money(row.amount),
            }));
        },
        get hasError() { return this.error !== ''; },
        get isLoading() { return this.loading; },
    };
}

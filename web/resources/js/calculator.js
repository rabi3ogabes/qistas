// The home-page instalment calculator. It asks the server (POST /schedule-preview) for the schedule, so the
// numbers come from the same engine that writes real contracts, never from a second copy of the maths in
// JavaScript. Money is only *formatted* here; every amount stays an exact decimal string until display.

export function planCalculator() {
    return {
        // inputs
        price: '1200',
        down: '200',
        count: '6',
        markup: '10',
        currency: 'USD',

        // output
        schedule: null,
        error: '',
        loading: false,

        // wiring (read from data-config in init)
        url: '',
        csrf: '',
        locale: 'en-u-nu-latn',
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
            this.schedule = config.initial;
        },

        // Debounced: typing "1500" asks once, not four times.
        changed() {
            clearTimeout(this.timer);
            this.loading = true;
            this.timer = setTimeout(() => this.run(), 220);
        },

        async run() {
            if (this.controller) this.controller.abort();
            this.controller = new AbortController();
            const percent = String(this.markup || '').trim();

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
                        markup_type: percent === '' || percent === '0' ? 'none' : 'percent',
                        markup_value: percent || '0',
                        count: this.count,
                        frequency: 'monthly',
                    }),
                });

                const body = await response.json().catch(() => ({}));

                if (response.ok) {
                    this.schedule = body;
                    this.error = '';
                } else if (response.status === 422 && body.errors) {
                    this.error = Object.values(body.errors)[0][0];
                } else {
                    this.error = this.messages.unavailable;
                }
            } catch (e) {
                if (e.name !== 'AbortError') this.error = this.messages.unavailable;
            } finally {
                this.loading = false;
            }
        },

        money(value) {
            if (value === null || value === undefined) return '';
            return new Intl.NumberFormat(this.locale, { style: 'currency', currency: this.currency }).format(Number(value));
        },

        shortDate(iso) {
            const [y, m, d] = iso.split('-').map(Number);
            return new Intl.DateTimeFormat(this.locale, { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' })
                .format(new Date(Date.UTC(y, m - 1, d)));
        },

        get hasResult() { return this.schedule !== null && this.schedule.installments.length > 0; },
        get each() { return this.hasResult ? this.money(this.schedule.installments[0].amount) : ''; },
        get times() { return this.hasResult ? String(this.schedule.installments.length) : ''; },
        get total() { return this.hasResult ? this.money(this.schedule.total) : ''; },
        get markupAmount() { return this.hasResult ? this.money(this.schedule.markup) : ''; },
        get financed() { return this.hasResult ? this.money(this.schedule.financed) : ''; },
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

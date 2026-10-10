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
        customRows: [], // the shop's own dates: { key, due_date, amount }
        discountType: 'none',
        discountValue: '',
        items: [], // what was sold: { key, product_id, name, quantity, serial, price, cost }
        products: [],

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
        labels: {},
        timer: null,
        controller: null,
        lastKey: 0,

        init() {
            const config = JSON.parse(this.$el.dataset.config);
            this.url = config.url;
            this.csrf = config.csrf;
            this.locale = config.locale;
            this.currency = config.currency;
            this.messages = config.messages;
            this.labels = config.labels ?? {};
            const { customRows = [], items = [], ...initial } = config.initial;
            Object.assign(this, initial);
            this.products = config.products ?? [];
            this.customRows = customRows.map((row) => this.row(row.due_date, row.amount));
            this.items = items.map((item) => this.item(item));
            if (this.isCustom && this.customRows.length === 0) this.customRows = [this.row(this.firstDue, '')];

            if (this.wantsPreview) this.run();
        },

        row(dueDate = '', amount = '') {
            this.lastKey += 1;
            return { key: this.lastKey, due_date: dueDate ?? '', amount: amount ?? '' };
        },

        // Choosing "on dates I choose" starts from the plan already on screen, so the shop adjusts it instead of retyping it.
        frequencyChanged() {
            if (this.isCustom && !this.customReady) {
                this.customRows = this.hasResult
                    ? this.schedule.installments.map((row) => this.row(row.due_date, row.amount))
                    : [this.row(this.firstDue, '')];
            }
            this.changed();
        },

        addRow() {
            this.customRows.push(this.row());
            this.$nextTick(() => {
                const dates = this.$root.querySelectorAll('.plan-date input[type="date"]');
                dates[dates.length - 1]?.focus();
            });
        },

        removeRow(index) {
            this.customRows.splice(index, 1);
            this.changed();
        },

        item(values = {}) {
            this.lastKey += 1;
            return { key: this.lastKey, product_id: '', name: '', quantity: '1', serial: '', price: '', cost: '', ...values };
        },

        addItem() {
            if (this.canAddItem) this.items.push(this.item());
        },

        removeItem(index) {
            this.items.splice(index, 1);
        },

        // A product picked from the list fills in what it is, its price and its cost.
        pickProduct(index) {
            const item = this.items[index];
            const product = this.products.find((p) => p.id === item.product_id);
            if (!product) return;
            item.name = product.name;
            if (product.price !== '') item.price = product.price;
            if (product.cost !== '') item.cost = product.cost;
        },

        itemName(index, field) {
            return `items[${index}][${field}]`;
        },

        // The items' prices, added up, offered as the contract's price when it differs.
        useItemsTotal() {
            this.price = this.itemsTotal;
            this.changed();
        },

        // The field names the form posts; built here because the CSP build of Alpine cannot read template literals.
        rowName(index, field) {
            return `custom_schedule[${index}][${field}]`;
        },

        rowLabel(kind, index) {
            return `${this.labels[kind] ?? ''} ${index + 1}`.trim();
        },

        // Debounced: typing "1500" asks once, not four times.
        changed() {
            clearTimeout(this.timer);
            this.error = '';

            if (!this.wantsPreview) {
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
                        count: this.isCustom ? null : this.count,
                        frequency: this.frequency,
                        first_due_date: this.isCustom ? null : (this.firstDue || null),
                        custom_schedule: this.isCustom ? this.typedRows : null,
                        discount_type: this.discountType,
                        discount_value: this.hasDiscount ? (this.discountValue || '0') : null,
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
        get isOpen() { return this.type === 'open'; },
        get hasDiscount() { return this.discountType !== 'none'; },
        get canAddItem() { return this.items.length < 10; },
        get itemsTotal() {
            const cents = this.items.reduce((sum, item) => {
                const price = Number(ascii(item.price));
                const quantity = Math.max(1, parseInt(ascii(item.quantity), 10) || 1);
                return Number.isFinite(price) && ascii(item.price) !== '' ? sum + Math.round(price * 100) * quantity : sum;
            }, 0);
            return cents > 0 ? (cents / 100).toFixed(2) : '';
        },
        get offersItemsTotal() { return this.itemsTotal !== '' && this.itemsTotal !== ascii(this.price); },
        get itemsTotalLabel() { return `${this.labels.useTotal ?? ''} ${this.money(this.itemsTotal)}`.trim(); },
        get isCustom() { return this.frequency === 'custom'; },
        // The rows with anything in them; a line left blank is not a payment.
        get typedRows() {
            return this.customRows
                .filter((row) => row.due_date !== '' || ascii(row.amount) !== '')
                .map((row) => ({ due_date: row.due_date, amount: ascii(row.amount) }));
        },
        get customReady() { return this.customRows.some((row) => row.due_date !== '' && ascii(row.amount) !== ''); },
        get wantsPreview() { return this.isScheduled && this.hasPrice && (!this.isCustom || this.customReady); },
        get needsDates() { return this.isScheduled && this.isCustom && this.hasPrice && !this.customReady && this.error === ''; },
        get hasMarkup() { return this.markupType !== 'none'; },
        get hasPrice() { return ascii(this.price) !== ''; },
        get isEmpty() { return !this.isOpen && !this.hasPrice && this.error === ''; },
        get isCashSale() { return this.type === 'cash' && this.hasPrice && this.cashTotal !== ''; },
        get cashTotal() { return this.money(ascii(this.price)); },

        get hasResult() { return this.isScheduled && this.schedule !== null && this.schedule.installments.length > 0; },
        get each() { return this.hasResult ? this.money(this.schedule.installments[0].amount) : ''; },
        get times() { return this.hasResult ? String(this.schedule.installments.length) : ''; },
        get total() { return this.hasResult ? this.money(this.schedule.total) : ''; },
        // The discount at sale the server took off the price, so the financed amount below it adds up.
        get hasDiscountOff() { return this.hasResult && Number(this.schedule.discount ?? 0) > 0; },
        get discountOff() { return this.hasDiscountOff ? this.money(this.schedule.discount) : ''; },
        get financed() { return this.hasResult ? this.money(this.schedule.financed) : ''; },
        get markupAmount() { return this.hasResult ? this.money(this.schedule.markup) : ''; },
        get lastDue() { return this.hasResult ? this.shortDate(this.schedule.installments.at(-1).due_date) : ''; },
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

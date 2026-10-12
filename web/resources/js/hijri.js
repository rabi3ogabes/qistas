// The Hijri twin of a date field (Win Plan CT26). The page hands over the generated Umm al-Qura table (the one
// App\Support\Hijri and the app read), so this follows exactly the same rule: typing a Hijri day fills the date field
// with its Gregorian day, and the Gregorian day picked shows its Hijri day underneath.

const DAY = 86400000;

function ascii(text) {
    return text.replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660))
        .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06F0))
        .replace(/٫/g, '.');
}

function iso(ms) {
    return new Date(ms).toISOString().slice(0, 10);
}

export function hijriField() {
    return {
        hint: '',

        init() {
            const data = this.$el.dataset;
            const hintEl = this.$el.querySelector('.field-hint');
            this.base = hintEl ? hintEl.textContent : '';
            this.hint = this.base;
            this.target = document.getElementById(data.for);
            const [y, m, d] = data.epoch.split('-').map(Number);
            this.epoch = Date.UTC(y, m - 1, d);
            this.first = Number(data.first);
            this.years = data.years.split(',');
            this.months = data.months.split('|');
            this.pattern = data.pattern;
            this.starts = [0];
            this.years.forEach((bits) => {
                for (const bit of bits) this.starts.push(this.starts[this.starts.length - 1] + (bit === '1' ? 30 : 29));
            });
            if (this.target) {
                const show = () => this.show();
                this.target.addEventListener('input', show);
                this.target.addEventListener('change', show);
                this.show();
            }
        },

        typed(event) {
            const day = this.parse(event.target.value);
            if (day === null || !this.target) return;
            this.target.value = day;
            this.target.dispatchEvent(new Event('input', { bubbles: true }));
            this.target.dispatchEvent(new Event('change', { bubbles: true }));
        },

        show() {
            this.hint = this.words(this.target.value) || this.base;
        },

        parse(typed) {
            const text = ascii(typed).trim();
            let match = text.match(/^(\d{4})[-/. ](\d{1,2})[-/. ](\d{1,2})$/);
            if (match) return this.gregorian(Number(match[1]), Number(match[2]), Number(match[3]));
            match = text.match(/^(\d{1,2})[-/. ](\d{1,2})[-/. ](\d{4})$/);
            if (match) return this.gregorian(Number(match[3]), Number(match[2]), Number(match[1]));

            return null;
        },

        length(year, month) {
            const bits = this.years[year - this.first];
            if (bits === undefined || month < 1 || month > 12) return null;

            return bits[month - 1] === '1' ? 30 : 29;
        },

        gregorian(year, month, day) {
            const length = this.length(year, month);
            if (length === null || day < 1 || day > length) return null;

            return iso(this.epoch + (this.starts[(year - this.first) * 12 + month - 1] + day - 1) * DAY);
        },

        words(value) {
            if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return '';
            const [y, m, d] = value.split('-').map(Number);
            const days = Math.round((Date.UTC(y, m - 1, d) - this.epoch) / DAY);
            if (days < 0 || days >= this.starts[this.starts.length - 1]) return '';
            let index = 0;
            while (this.starts[index + 1] <= days) index += 1;

            return this.pattern.replace(':day', String(days - this.starts[index] + 1))
                .replace(':month', this.months[index % 12])
                .replace(':year', String(this.first + Math.floor(index / 12)));
        },
    };
}

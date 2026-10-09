// The basics of an event in its editor: "Everyone" or a set of countries (with a quick filter), the first and last day
// with shortcuts for how long, the time zone that follows the first country chosen unless the admin picked one, the
// places it dresses, and one plain sentence that says what all of that means. The page works without it.

const parse = (json) => {
    try { return JSON.parse(json || 'null'); } catch { return null; }
};

/** "2026-09-23" moved by [days], still as "YYYY-MM-DD" (in UTC, so no time zone shifts the day). */
function addDays(date, days) {
    const [y, m, d] = date.split('-').map(Number);
    const moved = new Date(Date.UTC(y, m - 1, d + days));
    return moved.toISOString().slice(0, 10);
}

function daysBetween(from, to) {
    const ms = Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`);
    return Math.round(ms / 86400000) + 1;
}

export function initEventEditor() {
    const root = document.querySelector('[data-event-editor]');
    if (!root) return;

    const text = parse(root.dataset.text) ?? {};
    const lang = document.documentElement.lang || undefined;
    const everyone = root.querySelector('[data-everyone]');
    const picker = root.querySelector('[data-country-picker]');
    const filter = root.querySelector('[data-country-filter]');
    const countries = [...root.querySelectorAll('[data-country]')];
    const starts = root.querySelector('[data-starts]');
    const ends = root.querySelector('[data-ends]');
    const zone = root.querySelector('[data-zone]');
    const summary = root.querySelector('[data-event-summary]');
    const places = [...root.querySelectorAll('[data-place]')];
    let zoneTouched = false;

    const format = (date) => new Intl.DateTimeFormat(lang, { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(`${date}T12:00:00`));

    function describe() {
        if (!summary) return;
        const chosen = countries.filter((c) => c.checked);
        if (!everyone?.checked && chosen.length === 0) {
            summary.textContent = text.nobody;
            summary.dataset.tone = 'warn';
            return;
        }
        if (!starts.value || !ends.value || ends.value < starts.value) {
            summary.textContent = '';
            return;
        }
        const days = daysBetween(starts.value, ends.value);
        const length = days === 1 ? text.oneDay : text.days.replace(':days', days);
        summary.dataset.tone = '';
        summary.textContent = text.summary
            .replace(':length', length)
            .replace(':from', format(starts.value))
            .replace(':to', format(ends.value))
            .replace(':zone', zone.value.replace(/_/g, ' ').split('/').pop());
    }

    function followCountry() {
        if (zoneTouched || everyone?.checked) return;
        const first = countries.find((c) => c.checked);
        const wanted = first ? text.zoneOf?.[first.value] : null;
        if (wanted && [...zone.options].some((o) => o.value === wanted)) zone.value = wanted;
    }

    everyone?.addEventListener('change', () => {
        picker.hidden = everyone.checked;
        describe();
    });

    filter?.addEventListener('input', () => {
        const query = filter.value.trim().toLowerCase();
        root.querySelectorAll('[data-country-name]').forEach((chip) => {
            chip.hidden = query !== '' && !chip.dataset.countryName.includes(query);
        });
    });
    // Enter in the filter must not save the form.
    filter?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') event.preventDefault();
    });

    countries.forEach((c) => c.addEventListener('change', () => {
        followCountry();
        describe();
    }));

    zone?.addEventListener('change', () => {
        zoneTouched = true;
        describe();
    });

    starts?.addEventListener('change', () => {
        if (starts.value && (!ends.value || ends.value < starts.value)) ends.value = starts.value;
        describe();
    });
    ends?.addEventListener('change', describe);

    root.querySelectorAll('[data-length]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!starts.value) return;
            ends.value = addDays(starts.value, Number(button.dataset.length) - 1);
            ends.dispatchEvent(new Event('input', { bubbles: true }));
            describe();
        });
    });

    // A place the event does not dress: its banner tab says so.
    function markPlaces() {
        places.forEach((place) => {
            const tab = root.querySelector(`[data-tabs="surface"] [data-tab="${place.value}"]`);
            tab?.toggleAttribute('data-not-dressed', !place.checked);
        });
    }
    places.forEach((place) => place.addEventListener('change', markPlaces));

    markPlaces();
    describe();
}

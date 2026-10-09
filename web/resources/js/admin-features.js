// The Feature control page. The page works without this script (every control is a plain form); this makes it quick:
// the switch answers at once and can be undone, a reduction in use asks for its reason in a dialog, and the list can be
// searched and filtered. Vanilla and CSP-safe: no inline handlers, no eval. Events are delegated from the page root so
// rows that are replaced after a change keep working.

const RANK = { off: 0, beta: 1, on: 2 };
const UNDO_MS = 6000;

export function initFeatureControl(root) {
    const text = JSON.parse(root.dataset.text || '{}');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const toasts = root.querySelector('[data-toasts]');
    const dialog = root.querySelector('[data-dialog]');
    const rtl = document.documentElement.dir === 'rtl';
    let query = '';
    let filter = 'all';

    root.dataset.enhanced = '';

    const say = (template, values = {}) => Object.entries(values).reduce((out, [key, value]) => out.replaceAll(`:${key}`, value), template ?? '');
    const rowOf = (element) => element.closest('[data-feature]');
    const labelOf = (row) => row.querySelector('.fc-name')?.textContent.trim() ?? row.dataset.feature;

    // ---- talking to the server ----------------------------------------------------------------------------------

    async function send(method, url, body) {
        const response = await fetch(url, {
            method,
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: body === undefined ? undefined : JSON.stringify(body),
            credentials: 'same-origin',
        });
        let json = null;
        try { json = await response.json(); } catch { /* an empty or non-JSON answer */ }

        return { ok: response.ok, status: response.status, json };
    }

    const problem = (result) => result.json?.error?.message ?? (result.json?.errors ? Object.values(result.json.errors).flat()[0] : null) ?? result.json?.message ?? text.failed;

    // The server renders the truth; after a change the row (and the counts) are taken from a fresh copy of the page.
    async function refresh(key) {
        const response = await fetch(root.dataset.featuresUrl, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
        if (!response.ok) return;
        const fresh = new DOMParser().parseFromString(await response.text(), 'text/html');

        const current = key ? root.querySelector(`[data-feature="${CSS.escape(key)}"]`) : null;
        const next = key ? fresh.querySelector(`[data-feature="${CSS.escape(key)}"]`) : null;
        if (current && next) {
            const wasOpen = current.querySelector('.fc-more')?.open;
            current.replaceWith(next);
            if (wasOpen) next.querySelector('.fc-more').open = true;
        }

        const bar = fresh.querySelector('.fc-bar');
        if (bar) {
            const counts = root.querySelector('.fc-counts');
            counts.replaceWith(bar.querySelector('.fc-counts'));
            root.querySelector('[data-summary]').dataset.on = bar.dataset.on;
            root.querySelector('[data-summary]').dataset.beta = bar.dataset.beta;
            root.querySelector('[data-summary]').dataset.off = bar.dataset.off;
        }
        fresh.querySelectorAll('[data-group]').forEach((group) => {
            const mine = root.querySelector(`[data-group="${CSS.escape(group.dataset.group)}"] .fc-group-counts`);
            if (mine) mine.textContent = group.querySelector('.fc-group-counts').textContent;
        });
        applyFilters();
    }

    // ---- notices and questions --------------------------------------------------------------------------------

    function toast(message, { undo = null, tone = 'ok' } = {}) {
        const item = document.createElement('div');
        item.className = 'fc-toast';
        item.dataset.tone = tone;
        item.append(Object.assign(document.createElement('span'), { textContent: message }));

        const remove = () => item.remove();
        if (undo) {
            const button = Object.assign(document.createElement('button'), { type: 'button', textContent: text.undo });
            button.addEventListener('click', () => { remove(); undo(); });
            item.append(button);
        }
        toasts.append(item);
        setTimeout(remove, tone === 'error' ? UNDO_MS * 1.5 : UNDO_MS);
    }

    /** A line of text is a paragraph; an array of lines is a list. */
    function paragraphOrList(line) {
        if (!Array.isArray(line)) return Object.assign(document.createElement('p'), { textContent: line });

        const list = document.createElement('ul');
        line.forEach((entry) => list.append(Object.assign(document.createElement('li'), { textContent: entry })));

        return list;
    }

    /** Ask before something that matters. Resolves with { reason } when confirmed, or null. */
    function ask({ title, lines = [], reason = true, reasonValue = '' }) {
        return new Promise((resolve) => {
            dialog.querySelector('[data-dialog-title]').textContent = title;
            const body = dialog.querySelector('[data-dialog-body]');
            body.replaceChildren(...lines.map(paragraphOrList));

            const field = dialog.querySelector('[data-dialog-reason]');
            const label = field.closest('label');
            const error = dialog.querySelector('[data-dialog-error]');
            label.hidden = !reason;
            field.value = reasonValue;
            error.hidden = true;

            const confirm = dialog.querySelector('[data-dialog-confirm]');
            const onConfirm = (event) => {
                if (reason && field.value.trim().length < 3) {
                    event.preventDefault();
                    error.textContent = text.reasonHint;
                    error.hidden = false;
                    field.focus();
                }
            };
            const onClose = () => {
                confirm.removeEventListener('click', onConfirm);
                dialog.removeEventListener('close', onClose);
                resolve(dialog.returnValue === 'confirm' ? { reason: field.value.trim() } : null);
            };
            confirm.addEventListener('click', onConfirm);
            dialog.addEventListener('close', onClose);
            dialog.returnValue = 'cancel';
            dialog.showModal();
            if (reason) field.focus();
        });
    }

    // ---- the switch -------------------------------------------------------------------------------------------

    function paint(row, state) {
        row.dataset.state = state;
        const group = row.querySelector('.fc-seg');
        group.dataset.state = state;
        group.querySelectorAll('[role="radio"]').forEach((radio) => {
            const on = radio.dataset.state === state;
            radio.setAttribute('aria-checked', on ? 'true' : 'false');
            radio.tabIndex = on ? 0 : -1;
        });
    }

    async function change(row, to, { reason = null, undo = false } = {}) {
        const key = row.dataset.feature;
        const from = row.dataset.state;
        paint(row, to);

        const result = await send('PUT', `/admin/features/${key}/state`, { state: to, reason, undo });
        if (!result.ok) {
            paint(row, from);
            toast(problem(result), { tone: 'error' });

            return;
        }

        await refresh(key);
        const note = say(text.changed, { feature: result.json?.data?.label ?? key, state: text[to] ?? to });
        const stopped = (result.json?.dependents ?? []).length;
        toast(stopped ? `${note} ${say(text.stops, { features: result.json.dependents.join(', ') })}` : note, {
            undo: undo ? null : () => change(root.querySelector(`[data-feature="${CSS.escape(key)}"]`), from, { undo: true }),
        });
    }

    root.addEventListener('click', async (event) => {
        const radio = event.target.closest('.fc-seg [role="radio"]');
        if (!radio || radio.disabled) return;
        event.preventDefault();

        const row = rowOf(radio);
        const to = radio.dataset.state;
        const from = row.dataset.state;
        if (to === from) return;

        // Reducing something workspaces have been using needs a reason: ask, saying what will happen.
        const used = Number(row.dataset.usage);
        if (RANK[to] < RANK[from] && used > 0) {
            const needed = (row.dataset.requiredBy || '').split(' ').filter(Boolean)
                .map((key) => root.querySelector(`[data-feature="${CSS.escape(key)}"]`)).filter((other) => other && other.dataset.state !== 'off').map(labelOf);
            const answer = await ask({
                title: say(text.reduceTitle, { feature: labelOf(row) }),
                lines: [row.querySelector('.fc-off')?.textContent.trim(), say(text.usedBy, { count: used }), ...(needed.length ? [say(text.stops, { features: needed.join(', ') })] : [])].filter(Boolean),
            });
            if (answer) await change(row, to, { reason: answer.reason });

            return;
        }

        await change(row, to);
    });

    // Arrow keys move through a radio group and choose, as a radio group should.
    root.addEventListener('keydown', (event) => {
        const radio = event.target.closest('.fc-seg [role="radio"]');
        if (!radio || !['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) return;
        event.preventDefault();

        const radios = [...radio.closest('.fc-seg').querySelectorAll('[role="radio"]')].filter((r) => !r.disabled);
        const forward = event.key === 'ArrowDown' || event.key === (rtl ? 'ArrowLeft' : 'ArrowRight');
        const next = radios[(radios.indexOf(radio) + (forward ? 1 : -1) + radios.length) % radios.length];
        next.focus();
        next.click();
    });

    // ---- plans, early access ------------------------------------------------------------------------------------

    root.addEventListener('submit', async (event) => {
        const form = event.target;
        const row = rowOf(form);

        if (form.matches('[data-plan-form]')) {
            event.preventDefault();
            const data = new FormData(form);
            const body = { enabled: data.get('enabled') === '1' };
            if (data.has('limit')) body.limit = data.get('limit') === '' ? null : Number(data.get('limit'));
            const result = await send('PUT', form.action, body);
            if (!result.ok) return toast(problem(result), { tone: 'error' });
            await refresh(row.dataset.feature);
            toast(text.saved);
        } else if (form.matches('.fc-grant')) {
            event.preventDefault();
            const data = Object.fromEntries(new FormData(form));
            const result = await send('POST', form.action, { workspace: data.workspace, reason: data.reason, expires_at: data.expires_at || null });
            if (!result.ok) return toast(problem(result), { tone: 'error' });
            await refresh(row.dataset.feature);
            toast(text.saved);
        } else if (form.matches('.fc-beta form')) {
            event.preventDefault();
            const result = await send('DELETE', form.action);
            if (!result.ok) return toast(problem(result), { tone: 'error' });
            await refresh(row.dataset.feature);
            toast(text.saved);
        } else if (form.matches('[data-preset]')) {
            event.preventDefault();
            await preset(form);
        } else if (form.matches('[data-pause]')) {
            event.preventDefault();
            await pause(form);
        } else if (form.matches('[data-state-form]')) {
            event.preventDefault(); // a click already handled it; never let a stray submit reload the page
        }
    });

    async function preset(form) {
        const reason = form.querySelector('[name="reason"]');
        const preview = await send('GET', form.dataset.preview);
        if (!preview.ok) return toast(problem(preview), { tone: 'error' });

        const changes = preview.json.data;
        const answer = await ask({
            title: say(text.previewTitle, { preset: form.dataset.presetName }),
            lines: changes.length ? [changes.map((c) => say(text.willChange, { feature: root.querySelector(`[data-feature="${CSS.escape(c.feature)}"] .fc-name`)?.textContent.trim() ?? c.feature, from: text[c.from], to: text[c.to] }))] : [text.nothingChanges],
            reasonValue: reason.value,
        });
        if (!answer) return;

        const result = await send('POST', form.action, { reason: answer.reason });
        if (!result.ok) return toast(problem(result), { tone: 'error' });
        location.reload();
    }

    async function pause(form) {
        const reason = form.querySelector('[name="reason"]');
        const answer = await ask({ title: text.pauseTitle, lines: [text.pauseBody], reasonValue: reason.value });
        if (!answer) return;

        const result = await send('POST', form.action, { reason: answer.reason });
        if (!result.ok) return toast(problem(result), { tone: 'error' });
        location.reload();
    }

    // ---- finding a feature --------------------------------------------------------------------------------------

    function applyFilters() {
        let shown = 0;
        root.querySelectorAll('.fc-row').forEach((row) => {
            const matches = (query === '' || row.dataset.search.includes(query))
                && (filter === 'all'
                    || (['on', 'beta', 'off'].includes(filter) && row.dataset.state === filter)
                    || (filter === 'used' && Number(row.dataset.usage) > 0)
                    || (filter === 'attention' && row.dataset.dependencyProblem === 'true'));
            row.hidden = !matches;
            if (matches) shown++;
        });
        root.querySelectorAll('.fc-group').forEach((group) => { group.hidden = !group.querySelector('.fc-row:not([hidden])'); });
        root.querySelector('[data-empty]').hidden = shown > 0;
    }

    root.addEventListener('input', (event) => {
        if (!event.target.matches('[data-search]')) return;
        query = event.target.value.trim().toLowerCase();
        applyFilters();
    });

    root.addEventListener('click', (event) => {
        const button = event.target.closest('[data-filter]');
        if (!button) return;
        filter = button.dataset.filter;
        root.querySelectorAll('[data-filter]').forEach((other) => other.setAttribute('aria-pressed', other === button ? 'true' : 'false'));
        applyFilters();
    });
}

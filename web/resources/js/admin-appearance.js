// The Appearance page of the admin area. The page already works as plain forms; this adds what makes it a studio:
// the preview follows every keystroke (colours come from the server's own engine, so the preview is what will ship),
// pictures are sent the moment they are chosen, the banner editor has tabs, and the actions that cannot be taken back
// ask first. Nothing here is trusted by the server: every value is checked again when the draft is saved.

const HEX = /^#[0-9A-F]{6}$/;
const MAX_BYTES = 2 * 1024 * 1024;
const PICTURE_TYPES = ['image/png', 'image/jpeg'];
const SVG = 'http://www.w3.org/2000/svg';

const parse = (json) => {
    try { return JSON.parse(json || 'null'); } catch { return null; }
};
const kebab = (token) => token.replace(/[A-Z]/g, (c) => `-${c.toLowerCase()}`);

/** "#0f5" or "0F5132" as "#00FF55" / "#0F5132"; null when it is not a colour. */
function normaliseHex(raw) {
    let value = String(raw || '').trim().toUpperCase();
    if (value === '') return null;
    if (!value.startsWith('#')) value = `#${value}`;
    if (/^#[0-9A-F]{3}$/.test(value)) value = `#${[...value.slice(1)].map((c) => c + c).join('')}`;
    return HEX.test(value) ? value : null;
}

function swatch(hex) {
    const svg = document.createElementNS(SVG, 'svg');
    svg.setAttribute('class', 'swatch');
    svg.setAttribute('width', '14');
    svg.setAttribute('height', '14');
    svg.setAttribute('viewBox', '0 0 16 16');
    svg.setAttribute('aria-hidden', 'true');
    const circle = document.createElementNS(SVG, 'circle');
    circle.setAttribute('cx', '8');
    circle.setAttribute('cy', '8');
    circle.setAttribute('r', '7.5');
    circle.setAttribute('fill', hex);
    svg.append(circle);
    return svg;
}

/** Accessible tabs: arrow keys, Home and End move between them; the matching panel shows. */
function initTabs(list, onChange) {
    if (!list) return;
    const tabs = [...list.querySelectorAll('[role="tab"]')];
    const panels = tabs.map((tab) => document.getElementById(tab.getAttribute('aria-controls')));

    const select = (tab, focus = false) => {
        tabs.forEach((t, i) => {
            const on = t === tab;
            t.setAttribute('aria-selected', String(on));
            t.tabIndex = on ? 0 : -1;
            if (panels[i]) panels[i].hidden = !on;
        });
        if (focus) tab.focus();
        onChange?.(tab.dataset.tab);
    };

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => select(tab));
        tab.addEventListener('keydown', (event) => {
            const rtl = getComputedStyle(list).direction === 'rtl';
            const step = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1 }[event.key];
            const i = tabs.indexOf(tab);
            let target = null;
            if (step) target = tabs[(i + step + tabs.length) % tabs.length];
            if (event.key === 'Home') target = tabs[0];
            if (event.key === 'End') target = tabs[tabs.length - 1];
            if (!target) return;
            event.preventDefault();
            select(target, true);
        });
    });

    select(tabs.find((t) => t.getAttribute('aria-selected') === 'true') ?? tabs[0]);
}

export function initAppearanceStudio() {
    const root = document.querySelector('[data-studio]');
    if (!root) return;

    const text = parse(root.dataset.text) ?? {};
    const form = root.querySelector('[data-studio-form]');
    const preview = root.querySelector('[data-preview]');
    const canvas = preview.querySelector('[data-sp-canvas]');
    const canChange = root.dataset.canChange === 'yes';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const colourFields = [...root.querySelectorAll('[data-colour]')];
    const state = {
        palette: parse(root.dataset.palette),
        mode: 'light',
        pictures: {
            logo: preview.dataset.logo || null,
            logo_dark: preview.dataset.logoDark || null,
            hero: preview.dataset.hero || null,
            banner: preview.dataset.bannerPicture || null,
        },
        removed: {},
        lang: {},
        dirty: false,
        submitting: false,
        uploads: 0,
        view: 'website',
        // "Preview as a visitor": what the server says a visitor from a country sees on a date, shown instead of the draft.
        as: null,
        // In an event's editor: the pictures are kept with the event when it is saved, and its colours lie over these.
        eventMode: root.hasAttribute('data-event-editor'),
        baseColours: parse(root.dataset.baseColours) ?? {},
    };

    // ------------------------------------------------------------------ the preview

    function applyPalette() {
        const tokens = (state.as?.tokens ?? state.palette)?.[state.mode];
        if (tokens) {
            for (const [token, hex] of Object.entries(tokens)) canvas.style.setProperty(`--q-${kebab(token)}`, hex);
        }
        canvas.dataset.mode = state.mode;
        showPictures();
    }

    function showView(view) {
        state.view = view;
        preview.querySelectorAll('[data-sp-view]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.spView === view)));
        canvas.querySelectorAll('[data-sp-surface]').forEach((el) => { el.hidden = el.dataset.spSurface !== view; });
        if (state.as) showAs();
    }

    function showPictures() {
        const usable = (slot) => (state.as ? state.as.pictures[slot] : (state.removed[slot] ? null : state.pictures[slot]));
        const logo = (state.mode === 'dark' && usable('logo_dark')) || usable('logo');

        canvas.querySelectorAll('[data-sp-logo]').forEach((holder) => {
            const img = holder.querySelector('[data-sp-logo-img]');
            if (logo) img.src = logo;
            img.hidden = !logo;
            holder.querySelector('[data-sp-logo-drawn]').hidden = Boolean(logo);
        });

        const hero = usable('hero');
        const heroPic = canvas.querySelector('[data-sp-hero-pic]');
        if (hero) heroPic.src = hero;
        heroPic.hidden = !hero;
        canvas.querySelector('[data-sp-hero]')?.classList.toggle('sp-hero-has-pic', Boolean(hero));
    }

    function initPreview() {
        preview.querySelectorAll('[data-sp-view]').forEach((b) => b.addEventListener('click', () => showView(b.dataset.spView)));
        preview.querySelectorAll('[data-sp-mode]').forEach((b) => b.addEventListener('click', () => {
            state.mode = b.dataset.spMode;
            preview.querySelectorAll('[data-sp-mode]').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
            applyPalette();
        }));

        // Beside the editor the preview always shows; above it (a narrower screen) it can fold away, and starts folded.
        const toggle = preview.querySelector('[data-sp-toggle]');
        const narrow = window.matchMedia('(max-width: 81.99rem)');
        const fold = (folded) => {
            preview.toggleAttribute('data-collapsed', folded);
            toggle?.setAttribute('aria-expanded', String(!folded));
            if (toggle) toggle.textContent = folded ? text.showPreview : text.hidePreview;
        };
        toggle?.addEventListener('click', () => fold(!preview.hasAttribute('data-collapsed')));
        fold(narrow.matches);
        narrow.addEventListener('change', (e) => fold(e.matches));
        showView('website');
        initPreviewAs();
    }

    // ------------------------------------------------------------------ preview as a visitor

    const asBox = preview.querySelector('[data-preview-as]');
    const asNote = preview.querySelector('[data-as-note]');
    let asRequest = null;

    /** Draws the visitor's look on the stage: its palette, pictures, and the banner of the place on show. */
    function showAs() {
        if (!state.as) return;
        applyPalette();
        const banner = canvas.querySelector(`[data-sp-banner="${state.view}"]`);
        const b = state.as.banners?.[state.view] ?? null;
        canvas.querySelectorAll('[data-sp-banner]').forEach((el) => { if (el !== banner) el.hidden = true; });
        if (!banner) return;
        banner.hidden = !b;
        if (!b) return;
        banner.removeAttribute('data-off');
        banner.lang = document.documentElement.lang || 'en';
        banner.dir = document.documentElement.dir || 'ltr';
        banner.dataset.tone = b.tone || 'gold';
        banner.querySelector('[data-sp-title]').textContent = b.title;
        const message = banner.querySelector('[data-sp-message]');
        message.textContent = b.message || '';
        message.hidden = !b.message;
        const cta = banner.querySelector('[data-sp-cta]');
        cta.textContent = b.cta_label || '';
        cta.hidden = !(b.cta_label && b.cta_url);
        banner.querySelector('[data-sp-close]').hidden = !b.dismissible;
        const pic = banner.querySelector('[data-sp-banner-pic]');
        if (b.image_url) pic.src = b.image_url;
        pic.hidden = !b.image_url;
    }

    async function fetchAs() {
        const country = asBox.querySelector('[data-as-country]');
        const date = asBox.querySelector('[data-as-date]').value;
        if (!date) return;
        asRequest?.abort();
        asRequest = new AbortController();
        const query = new URLSearchParams({ country: country.value, date, surface: state.view });

        try {
            const response = await fetch(`${asBox.dataset.lookUrl}?${query}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: asRequest.signal });
            const body = await response.json().catch(() => ({}));
            if (!response.ok || !body.data) return;
            const d = body.data;
            state.as = {
                tokens: d.tokens,
                pictures: { logo: d.logo_url, logo_dark: d.logo_dark_url, hero: d.hero_url, banner: d.banner_picture_url },
                banners: { ...(state.as?.banners ?? {}), [state.view]: d.banner },
            };
            const where = country.value ? country.selectedOptions[0].textContent.trim() : text.anywhere;
            const day = new Intl.DateTimeFormat(document.documentElement.lang || undefined, { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(`${date}T12:00:00`));
            asNote.querySelector('[data-as-note-text]').textContent = d.event
                ? text.asEvent.replace(':country', where).replace(':date', day).replace(':event', d.event.name)
                : text.asUsual.replace(':country', where).replace(':date', day);
            asNote.hidden = false;
            preview.setAttribute('data-as', '');
            showAs();
        } catch (error) {
            if (error.name !== 'AbortError') { /* the stage keeps what it shows */ }
        }
    }

    /** Back to the draft being edited. */
    function leaveAs() {
        if (!state.as) return;
        state.as = null;
        asNote.hidden = true;
        preview.removeAttribute('data-as');
        applyPalette();
        Object.keys(state.lang).forEach(updateBanner);
    }

    function initPreviewAs() {
        if (!asBox || !asNote) return;
        asBox.querySelector('[data-as-show]').addEventListener('click', fetchAs);
        asNote.querySelector('[data-as-back]').addEventListener('click', leaveAs);
        // Looking at another place while previewing asks again for that place.
        preview.querySelectorAll('[data-sp-view]').forEach((b) => b.addEventListener('click', () => { if (state.as) fetchAs(); }));
    }

    // ------------------------------------------------------------------ colours

    let paletteTimer = 0;
    let paletteRequest = null;

    function colourError(field, message) {
        const input = field.querySelector('[data-colour-text]');
        const error = field.querySelector('[data-colour-error]');
        if (message) input.setAttribute('aria-invalid', 'true'); else input.removeAttribute('aria-invalid');
        error.textContent = message || '';
        error.hidden = !message;
    }

    function showReadings(report) {
        colourFields.forEach((field) => {
            const reading = report.contrast?.[field.dataset.colour];
            const row = field.querySelector('[data-reading]');
            if (!reading || !row) return;
            row.dataset.pass = reading.pass ? 'yes' : 'no';
            row.querySelector('[data-ratio]').textContent = `${Number(reading.ratio).toFixed(1)}:1`;
            row.querySelector('[data-verdict]').textContent = reading.pass ? text.readable : text.adjusted;
        });

        const box = root.querySelector('[data-readability]');
        box.replaceChildren();
        if (!report.changed?.length) {
            const ok = document.createElement('p');
            ok.className = 'readability-ok';
            ok.textContent = text.allReadable;
            box.append(ok);
            return;
        }
        const intro = document.createElement('p');
        intro.textContent = text.willAdjust;
        const list = document.createElement('ul');
        report.changed.forEach((move) => {
            const item = document.createElement('li');
            const what = document.createElement('span');
            what.textContent = `${text.tokens?.[move.token] ?? move.token} · ${text.modes?.[move.mode] ?? move.mode}`;
            const how = document.createElement('span');
            how.className = 'studio-move';
            how.dir = 'ltr';
            how.append(swatch(move.from), ` ${move.from}  →  `, swatch(move.to), ` ${move.to}`);
            item.append(what, how);
            list.append(item);
        });
        box.append(intro, list);
    }

    function currentColours() {
        const out = {};
        colourFields.forEach((field) => {
            const value = normaliseHex(field.querySelector('[data-colour-text]').value);
            if (value) out[field.dataset.colour] = value;
        });
        return out;
    }

    function markPresets() {
        const now = currentColours();
        const same = (a, b) => Object.keys(a).length === Object.keys(b).length && Object.entries(a).every(([k, v]) => b[k] === v);
        root.querySelectorAll('[data-preset]').forEach((button) => {
            button.setAttribute('aria-pressed', String(same(parse(button.dataset.preset) ?? {}, now)));
        });
    }

    function refreshPalette() {
        window.clearTimeout(paletteTimer);
        paletteTimer = window.setTimeout(async () => {
            paletteRequest?.abort();
            paletteRequest = new AbortController();
            // An event's colours lie over the usual ones, so what is not set is the usual colour, not the factory one.
            const query = new URLSearchParams({ ...state.baseColours, ...currentColours() });

            try {
                const response = await fetch(`${root.dataset.paletteUrl}?${query}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: paletteRequest.signal,
                });
                const body = await response.json().catch(() => ({}));

                if (response.status === 422) {
                    Object.entries(body.errors ?? {}).forEach(([name, messages]) => {
                        const field = colourFields.find((f) => f.dataset.colour === name);
                        if (field) colourError(field, messages[0]);
                    });
                    return;
                }
                if (!response.ok || !body.data) return;

                state.palette = body.data.tokens;
                applyPalette();
                showReadings(body.data);
            } catch (error) {
                if (error.name !== 'AbortError') { /* the preview keeps the last good palette */ }
            }
        }, 160);
    }

    function colourChanged(field) {
        const input = field.querySelector('[data-colour-text]');
        const ok = input.value.trim() === '' || normaliseHex(input.value) !== null;
        colourError(field, ok ? '' : text.notHex);
        markPresets();
        if (ok) refreshPalette();
    }

    function initColours() {
        colourFields.forEach((field) => {
            const well = field.querySelector('[data-colour-well]');
            const input = field.querySelector('[data-colour-text]');
            const factory = field.dataset.factory.toLowerCase();

            well.addEventListener('input', () => {
                input.value = well.value.toUpperCase();
                colourChanged(field);
            });
            input.addEventListener('input', () => {
                const value = normaliseHex(input.value);
                if (value) well.value = value.toLowerCase();
                else if (input.value.trim() === '') well.value = factory;
                colourChanged(field);
            });
            input.addEventListener('blur', () => {
                const value = normaliseHex(input.value);
                if (value && value !== input.value) input.value = value;
            });
            field.querySelector('[data-colour-reset]').addEventListener('click', () => {
                input.value = '';
                well.value = factory;
                colourChanged(field);
                markDirty();
                input.focus();
            });
        });

        root.querySelectorAll('[data-preset]').forEach((button) => {
            button.type = 'button';
            button.addEventListener('click', () => {
                const colours = parse(button.dataset.preset) ?? {};
                colourFields.forEach((field) => {
                    const value = colours[field.dataset.colour] ?? '';
                    field.querySelector('[data-colour-text]').value = value;
                    field.querySelector('[data-colour-well]').value = (value || field.dataset.factory).toLowerCase();
                    colourError(field, '');
                });
                markDirty();
                markPresets();
                refreshPalette();
            });
        });
    }

    // ------------------------------------------------------------------ pictures

    function pictureMessage(pic, { status = '', error = '' } = {}) {
        pic.querySelector('[data-pic-status]').textContent = status;
        const box = pic.querySelector('[data-pic-error]');
        box.textContent = error;
        box.hidden = !error;
    }

    function setBusy(delta) {
        state.uploads += delta;
        root.querySelectorAll('[data-save], [data-publish]').forEach((b) => { b.disabled = state.uploads > 0; });
    }

    async function upload(pic, file) {
        const slot = pic.dataset.pic;
        const input = pic.querySelector('[data-pic-input]');

        if (!PICTURE_TYPES.includes(file.type)) {
            pictureMessage(pic, { error: text.wrongType });
            input.value = '';
            return;
        }
        if (file.size > MAX_BYTES) {
            pictureMessage(pic, { error: text.tooBig });
            input.value = '';
            return;
        }

        pic.setAttribute('aria-busy', 'true');
        pictureMessage(pic, { status: text.uploading });
        setBusy(1);
        const data = new FormData();
        data.append('file', file);

        try {
            const response = await fetch(root.dataset.pictureUrl.replace('__slot__', slot), {
                method: 'POST',
                body: data,
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                credentials: 'same-origin',
            });
            const body = await response.json().catch(() => ({}));

            if (!response.ok || !body.data) {
                pictureMessage(pic, { error: body.errors?.file?.[0] ?? (response.status === 413 ? text.tooBig : text.uploadFailed) });
                return;
            }

            const img = pic.querySelector('[data-pic-img]');
            img.src = body.data.url;
            img.width = body.data.width;
            img.height = body.data.height;
            img.hidden = false;
            pic.querySelector('[data-pic-empty]').hidden = true;
            pic.querySelector('[data-pic-choose-label]').textContent = text.replace;
            pic.querySelector('[data-pic-meta]').textContent = `${body.data.width} × ${body.data.height}`;
            const removeWrap = pic.querySelector('[data-pic-remove-wrap]');
            removeWrap.hidden = false;
            removeWrap.querySelector('input').checked = false;
            pic.removeAttribute('data-removed');
            pic.dataset.has = 'yes';

            state.pictures[slot] = body.data.url;
            state.removed[slot] = false;
            pictureMessage(pic, { status: text.uploaded });
            const kept = pic.querySelector('[data-pic-id]');
            if (kept) {
                // An event keeps the picture by its id when the form is saved: until then it is an unsaved change.
                kept.value = body.data.id;
                markDirty();
            } else {
                setPublishState('draft');
            }
            leaveAs();
            showPictures();
            Object.keys(state.lang).forEach(updateBanner);
        } catch {
            pictureMessage(pic, { error: text.uploadFailed });
        } finally {
            pic.removeAttribute('aria-busy');
            // Already saved: the form must not send it a second time.
            input.value = '';
            setBusy(-1);
        }
    }

    function initPictures() {
        root.querySelectorAll('[data-pic]').forEach((pic) => {
            const slot = pic.dataset.pic;
            const input = pic.querySelector('[data-pic-input]');
            const remove = pic.querySelector('[data-pic-remove]');
            const frame = pic.querySelector('[data-frame]');

            input.addEventListener('change', () => {
                const file = input.files?.[0];
                if (file) upload(pic, file);
            });
            remove?.addEventListener('change', () => {
                pic.toggleAttribute('data-removed', remove.checked);
                state.removed[slot] = remove.checked;
                pictureMessage(pic, { status: remove.checked ? text.removed : '' });
                showPictures();
                Object.keys(state.lang).forEach(updateBanner);
            });

            if (!canChange) return;
            ['dragenter', 'dragover'].forEach((type) => frame.addEventListener(type, (event) => {
                event.preventDefault();
                pic.setAttribute('data-drop', '');
            }));
            ['dragleave', 'drop'].forEach((type) => frame.addEventListener(type, () => pic.removeAttribute('data-drop')));
            frame.addEventListener('drop', (event) => {
                event.preventDefault();
                const file = event.dataTransfer?.files?.[0];
                if (file) upload(pic, file);
            });
        });
    }

    // ------------------------------------------------------------------ banners

    function bannerValue(editor, field, lang) {
        const el = editor.querySelector(lang ? `[data-b="${field}"][data-lang="${lang}"]` : `[data-b="${field}"]`);
        if (!el) return '';
        if (el.type === 'checkbox') return el.checked;
        if (el.type === 'radio') return editor.querySelector(`[data-b="${field}"]:checked`)?.value ?? '';
        return el.value;
    }

    function updateBanner(surface) {
        const editor = root.querySelector(`[data-banner-editor="${surface}"]`);
        const banner = canvas.querySelector(`[data-sp-banner="${surface}"]`);
        if (!editor || !banner) return;

        const words = (lang) => ({
            title: bannerValue(editor, 'title', lang).trim(),
            message: bannerValue(editor, 'message', lang).trim(),
            label: bannerValue(editor, 'cta_label', lang).trim(),
        });
        let lang = state.lang[surface] ?? 'en';
        let shown = words(lang);
        if (!shown.title) {
            lang = 'en';
            shown = words('en');
        }

        const direction = editor.querySelector(`[data-b="title"][data-lang="${lang}"]`)?.dir || 'ltr';
        banner.lang = lang;
        banner.dir = direction;
        banner.hidden = !shown.title;
        banner.toggleAttribute('data-off', !bannerValue(editor, 'enabled'));
        banner.dataset.tone = bannerValue(editor, 'tone') || 'gold';
        banner.querySelector('[data-sp-title]').textContent = shown.title;
        const message = banner.querySelector('[data-sp-message]');
        message.textContent = shown.message;
        message.hidden = !shown.message;
        const cta = banner.querySelector('[data-sp-cta]');
        cta.textContent = shown.label;
        cta.hidden = !(shown.label && bannerValue(editor, 'cta_url').trim());
        banner.querySelector('[data-sp-close]').hidden = !bannerValue(editor, 'dismissible');

        const picture = bannerValue(editor, 'image') && !state.removed.banner ? state.pictures.banner : null;
        const pic = banner.querySelector('[data-sp-banner-pic]');
        if (picture) pic.src = picture;
        pic.hidden = !picture;
    }

    function updateBannerState(surface) {
        const editor = root.querySelector(`[data-banner-editor="${surface}"]`);
        const pill = root.querySelector(`[data-banner-state="${surface}"]`);
        if (!editor || !pill) return;

        const today = root.dataset.today;
        const starts = bannerValue(editor, 'starts_on');
        const ends = bannerValue(editor, 'ends_on');
        let state = ['on', text.showing];
        if (!bannerValue(editor, 'enabled')) {
            state = ['off', text.off];
        } else if (starts && today < starts) {
            const day = new Intl.DateTimeFormat(document.documentElement.lang || undefined, { day: 'numeric', month: 'short' }).format(new Date(`${starts}T00:00:00`));
            state = ['scheduled', text.starts.replace(':date', day)];
        } else if (ends && today > ends) {
            state = ['ended', text.ended];
        }
        pill.dataset.state = state[0];
        pill.textContent = state[1];
    }

    function count(input) {
        const counter = input.closest('.field')?.querySelector('[data-count]');
        if (counter) counter.textContent = `${[...input.value].length}/${input.maxLength}`;
    }

    function initBanners() {
        initTabs(root.querySelector('[data-tabs="surface"]'), (surface) => showView(surface));

        root.querySelectorAll('[data-banner-editor]').forEach((editor) => {
            const surface = editor.dataset.bannerEditor;
            const langTabs = editor.querySelector('[data-tabs^="lang-"]');
            state.lang[surface] = langTabs?.querySelector('[aria-selected="true"]')?.dataset.tab ?? 'en';
            initTabs(langTabs, (lang) => {
                state.lang[surface] = lang;
                updateBanner(surface);
            });

            const changed = (event) => {
                if (!event.target.matches('[data-b]')) return;
                if (event.target.maxLength > 0) count(event.target);
                updateBanner(surface);
                updateBannerState(surface);
            };
            editor.addEventListener('input', changed);
            editor.addEventListener('change', changed);
            updateBanner(surface);
        });
    }

    // ------------------------------------------------------------------ saving

    function setPublishState(kind) {
        if (state.dirty && kind !== 'unsaved') return;
        const line = root.querySelector('[data-publish-state]');
        if (!line) return;
        line.dataset.state = kind;
        line.querySelector('[data-state-text]').textContent = text[kind] ?? line.textContent;
    }

    function markDirty() {
        if (!canChange || state.dirty) return;
        state.dirty = true;
        setPublishState('unsaved');
    }

    function initSaving() {
        form.addEventListener('input', (event) => {
            if (!event.target.matches('[data-pic-input]')) markDirty();
            // Editing again shows the draft being edited, not what a visitor sees.
            leaveAs();
        });
        form.addEventListener('change', (event) => {
            if (!event.target.matches('[data-pic-input]')) markDirty();
        });
        document.addEventListener('submit', () => { state.submitting = true; }, true);
        window.addEventListener('beforeunload', (event) => {
            if (state.dirty && !state.submitting) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
    }

    // ------------------------------------------------------------------ asking first

    function initConfirmations() {
        const dialog = document.querySelector('[data-studio-dialog]');
        if (!dialog || typeof dialog.showModal !== 'function') return;

        const ask = (title, body, confirm) => new Promise((resolve) => {
            dialog.querySelector('[data-dialog-title]').textContent = title;
            dialog.querySelector('[data-dialog-body]').textContent = body;
            dialog.querySelector('[data-dialog-confirm]').textContent = confirm;
            dialog.returnValue = '';
            dialog.addEventListener('close', () => resolve(dialog.returnValue === 'confirm'), { once: true });
            dialog.showModal();
        });

        root.querySelector('[data-discard]')?.addEventListener('click', async (event) => {
            event.preventDefault();
            if (await ask(text.discardTitle, text.discardBody, text.discard)) {
                state.submitting = true;
                document.getElementById('studio-discard')?.submit();
            }
        });

        root.querySelectorAll('form[data-restore]').forEach((restore) => {
            restore.addEventListener('submit', async (event) => {
                if (restore.dataset.confirmed) return;
                event.preventDefault();
                event.stopPropagation();
                state.submitting = false;
                if (await ask(text.restoreTitle.replace(':version', restore.dataset.restore), text.restoreBody, text.restore)) {
                    restore.dataset.confirmed = 'yes';
                    state.submitting = true;
                    restore.submit();
                }
            }, true);
        });

        // Any other button that cannot be taken back (stopping or deleting an event) carries its own question.
        root.querySelectorAll('button[data-confirm]').forEach((button) => {
            button.addEventListener('click', async (event) => {
                event.preventDefault();
                if (await ask(button.dataset.confirm, button.dataset.confirmBody || '', button.dataset.confirmLabel || button.textContent.trim())) {
                    state.submitting = true;
                    button.form?.submit();
                }
            });
        });
    }

    // A mistake sent back by the server is brought into view, in whichever tab it sits.
    function revealFirstError() {
        const invalid = root.querySelector('[aria-invalid="true"]');
        if (!invalid) return;
        invalid.scrollIntoView({ block: 'center' });
        invalid.focus({ preventScroll: true });
    }

    initPreview();
    initColours();
    initPictures();
    initBanners();
    initSaving();
    initConfirmations();
    applyPalette();
    markPresets();
    revealFirstError();
}

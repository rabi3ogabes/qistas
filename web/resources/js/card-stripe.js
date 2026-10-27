// Pro by card (spec 2026-10-27): what the checkout and the billing page share. Stripe.js comes from js.stripe.com through
// Stripe's own loader (never bundled, never a tag in a page), once per page; its fields wear the page's Qistas palette
// and follow it into dark mode.
import { loadStripe } from '@stripe/stripe-js';

const STRIPE_LOCALES = { en: 'en', ar: 'ar', fr: 'fr', es: 'es' };
let loading = null;

export function stripeFor(publishableKey) {
    loading ??= loadStripe(publishableKey, { locale: STRIPE_LOCALES[document.documentElement.lang] ?? 'auto' });
    return loading;
}

// The brand's body faces, served to Stripe's frames by Google Fonts (the frames cannot use the page's own files).
export function stripeFonts() {
    return [{ cssSrc: 'https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600&family=IBM+Plex+Sans+Arabic:wght@400;500;600&family=Noto+Nastaliq+Urdu:wght@400;600&display=swap' }];
}

function isDark() {
    const mode = document.documentElement.getAttribute('data-q-mode');
    if (mode === 'dark' || mode === 'light') return mode === 'dark';
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function withAlpha(color, alpha) {
    const hex = color.replace('#', '');
    if (!/^[0-9a-f]{6}$/i.test(hex)) return color;
    const [r, g, b] = [0, 2, 4].map((i) => parseInt(hex.slice(i, i + 2), 16));
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

// Stripe's Appearance API, read from the CSS variables the page is wearing right now.
export function appearanceFromPage() {
    const css = getComputedStyle(document.documentElement);
    const v = (name) => css.getPropertyValue(name).trim();
    const accent = v('--q-accent');
    const line = v('--q-line');

    return {
        theme: isDark() ? 'night' : 'stripe',
        variables: {
            colorPrimary: v('--q-action') || v('--q-primary'),
            colorBackground: v('--q-surface'),
            colorText: v('--q-ink'),
            colorTextSecondary: v('--q-ink-muted'),
            colorTextPlaceholder: v('--q-ink-muted'),
            colorDanger: v('--q-danger'),
            colorSuccess: v('--q-positive'),
            colorIconTab: v('--q-ink-muted'),
            fontFamily: 'Geist, "IBM Plex Sans Arabic", "Noto Nastaliq Urdu", system-ui, sans-serif',
            fontSizeBase: '16px',
            borderRadius: v('--q-r-md') || '12px',
            spacingUnit: '4px',
        },
        rules: {
            '.Input': { border: `1px solid ${line}`, boxShadow: 'none' },
            '.Input:focus': { borderColor: accent, boxShadow: `0 0 0 3px ${withAlpha(accent, 0.28)}` },
            '.Label': { fontWeight: '500', color: v('--q-ink') },
            '.Tab': { border: `1px solid ${line}`, boxShadow: 'none' },
            '.Tab--selected': { borderColor: accent, boxShadow: `0 0 0 1px ${accent}` },
        },
    };
}

// Re-theme Stripe's fields when the page switches between light and dark.
export function onModeChange(apply) {
    new MutationObserver(apply).observe(document.documentElement, { attributes: true, attributeFilter: ['data-q-mode'] });
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', apply);
}

export async function postJson(url, body = {}) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify(body),
    });
    const data = await response.json().catch(() => ({}));
    return { ok: response.ok, data };
}

export function busy(button, on) {
    if (on) button.setAttribute('aria-busy', 'true');
    else button.removeAttribute('aria-busy');
}

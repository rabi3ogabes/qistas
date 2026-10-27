// The card dashboard on the billing page (spec 2026-10-27 §4.3): a new card through Stripe's field in a sheet, a payment
// the bank holds confirmed on the page, and the exact cost of switching period shown before the owner confirms.
import { appearanceFromPage, busy, onModeChange, postJson, stripeFonts, stripeFor } from './card-stripe';

function fill(template, values) {
    return Object.entries(values).reduce((text, [key, value]) => text.replaceAll(`:${key}`, value), template);
}

function money(amount, currency) {
    try {
        return new Intl.NumberFormat(`${document.documentElement.lang}-u-nu-latn`, { style: 'currency', currency: currency.toUpperCase() }).format(Number(amount));
    } catch {
        return `${amount} ${currency.toUpperCase()}`;
    }
}

function day(isoDate) {
    try {
        return new Intl.DateTimeFormat(`${document.documentElement.lang}-u-nu-latn`, { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(`${isoDate}T12:00:00`));
    } catch {
        return isoDate;
    }
}

export function initCardBilling(root) {
    setUpCardChange(root);
    setUpConfirm(root);
    setUpSwitchPreview(root);
}

function setUpCardChange(root) {
    const save = document.querySelector('[data-card-setup-save]');
    const errorBox = document.querySelector('[data-card-setup-error]');
    if (!save) return;
    let started = false;
    const show = (message) => {
        errorBox.textContent = message || '';
        errorBox.hidden = !message;
    };

    root.querySelectorAll('[data-card-change]').forEach((button) => button.addEventListener('click', async () => {
        if (started) return;
        started = true;
        const answer = await postJson(root.dataset.setupUrl).catch(() => ({ ok: false, data: {} }));
        if (!answer.ok) {
            show(answer.data.message || root.dataset.failed);
            started = false;
            return;
        }

        const stripe = await stripeFor(answer.data.publishable_key);
        const checkout = stripe.initCheckoutElementsSdk({ clientSecret: answer.data.client_secret, elementsOptions: { appearance: appearanceFromPage(), fonts: stripeFonts() } });
        const loaded = await checkout.loadActions();
        if (loaded.type !== 'success') {
            show(loaded.error?.message || root.dataset.failed);
            return;
        }
        onModeChange(() => checkout.changeAppearance(appearanceFromPage()));
        checkout.on('change', (session) => {
            save.disabled = !session.canConfirm;
        });

        const field = document.querySelector('#card-setup');
        field.replaceChildren();
        checkout.createPaymentElement({ layout: 'tabs' }).mount(field);

        save.addEventListener('click', async () => {
            show('');
            busy(save, true);
            const result = await loaded.actions.confirm();
            if (result.type === 'error') {
                show(result.error.message);
                busy(save, false);
            }
        });
    }));
}

function setUpConfirm(root) {
    const button = root.querySelector('[data-card-confirm]');
    if (!button) return;

    button.addEventListener('click', async () => {
        busy(button, true);
        const answer = await postJson(root.dataset.confirmUrl).catch(() => ({ ok: false, data: {} }));
        if (!answer.ok) {
            busy(button, false);
            window.location.reload();
            return;
        }
        const stripe = await stripeFor(answer.data.publishable_key);
        await stripe.handleNextAction({ clientSecret: answer.data.client_secret });
        window.location.reload();
    });
}

function setUpSwitchPreview(root) {
    const trigger = root.querySelector('[data-card-switch]');
    const text = document.querySelector('[data-card-switch-preview]');
    if (!trigger || !text) return;

    trigger.addEventListener('click', async () => {
        const url = new URL(root.dataset.previewUrl, window.location.href);
        url.searchParams.set('period', trigger.dataset.period);
        const response = await fetch(url, { headers: { Accept: 'application/json' } }).catch(() => null);
        if (!response?.ok) return;
        const preview = await response.json();
        const values = { amount: money(preview.amount_due, preview.currency), date: day(preview.date) };
        text.textContent = fill(preview.when === 'now' ? root.dataset.textNow : root.dataset.textLater, values);
    });
}

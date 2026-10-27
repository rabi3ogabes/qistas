// The checkout page (spec 2026-10-27 §4.1): opens the Stripe checkout for the plan and period on the page, mounts Apple
// Pay or Google Pay (when the device has one) and the card field, keeps the total and the Pay button in step with
// Stripe's session, applies promotion codes, and confirms. Stripe redirects to the return page when the payment is done.
import { appearanceFromPage, busy, onModeChange, postJson, stripeFonts, stripeFor } from './card-stripe';

export async function initCardCheckout(root) {
    const pay = root.querySelector('[data-card-pay]');
    const errorBox = root.querySelector('[data-card-error]');
    const total = root.querySelector('[data-card-total]');
    const show = (message) => {
        errorBox.textContent = message || '';
        errorBox.hidden = !message;
    };

    let opened;
    try {
        const answer = await postJson(root.dataset.sessionUrl, { plan: root.dataset.plan, period: root.dataset.period, attempt: root.dataset.attempt });
        if (!answer.ok) {
            show(answer.data.message || root.dataset.failed);
            root.classList.add('is-unavailable');
            return;
        }
        opened = answer.data;
    } catch {
        show(root.dataset.failed);
        return;
    }

    const stripe = await stripeFor(opened.publishable_key);
    if (!stripe) {
        show(root.dataset.failed);
        return;
    }
    const checkout = stripe.initCheckoutElementsSdk({ clientSecret: opened.client_secret, elementsOptions: { appearance: appearanceFromPage(), fonts: stripeFonts() } });
    const loaded = await checkout.loadActions();
    if (loaded.type !== 'success') {
        show(loaded.error?.message || root.dataset.failed);
        return;
    }
    const { actions } = loaded;

    const render = (session) => {
        if (total && session?.total?.total?.amount) total.textContent = session.total.total.amount;
        pay.disabled = !session?.canConfirm;
    };
    render(actions.getSession());
    checkout.on('change', render);
    onModeChange(() => checkout.changeAppearance(appearanceFromPage()));

    const field = root.querySelector('#card-payment');
    field.replaceChildren();
    const payment = checkout.createPaymentElement({ layout: 'tabs' });
    payment.mount(field);
    payment.on('ready', () => root.classList.add('is-ready'));

    const express = root.querySelector('[data-card-express]');
    if (express && typeof checkout.createExpressCheckoutElement === 'function') {
        const wallets = checkout.createExpressCheckoutElement();
        wallets.mount('#card-express');
        wallets.on('ready', ({ availablePaymentMethods }) => {
            express.hidden = !availablePaymentMethods;
        });
        wallets.on('confirm', async (event) => {
            show('');
            const result = await actions.confirm({ expressCheckoutConfirmEvent: event });
            if (result.type === 'error') show(result.error.message);
        });
    }

    pay.addEventListener('click', async () => {
        show('');
        busy(pay, true);
        pay.disabled = true;
        const result = await actions.confirm();
        if (result.type === 'error') {
            show(result.error.message);
            busy(pay, false);
            pay.disabled = !actions.getSession().canConfirm;
        }
    });

    const promo = root.querySelector('[data-card-promo]');
    if (promo) {
        const message = promo.querySelector('[data-card-promo-message]');
        promo.addEventListener('submit', async (event) => {
            event.preventDefault();
            const code = promo.elements.code.value.trim();
            if (!code) return;
            const button = promo.querySelector('button');
            busy(button, true);
            const result = await actions.applyPromotionCode(code);
            busy(button, false);
            message.textContent = result.type === 'error' ? result.error.message : root.dataset.promoApplied;
            promo.classList.toggle('has-error', result.type === 'error');
        });
    }
}

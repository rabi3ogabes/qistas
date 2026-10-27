// CSP-safe Alpine: it never calls eval, so the site can forbid 'unsafe-eval' in its Content-Security-Policy.
import Alpine from '@alpinejs/csp';
import { planCalculator } from './calculator';
import { contractPlanner } from './contract-planner';
import { hijriField } from './hijri';
import { initTheme } from './theme';
import { initSheets } from './sheet';
import { initLanguageSwitchers } from './language';
import { initAdminNav } from './admin-nav';
import { initWelcomeBanners } from './banner';
import { registerServiceWorker } from './install';
import { initForms } from './forms';

window.Alpine = Alpine;
Alpine.data('planCalculator', planCalculator);
Alpine.data('contractPlanner', contractPlanner);
Alpine.data('hijriField', hijriField);
Alpine.data('billingInterval', () => ({
    interval: 'monthly',
    setMonthly() { this.interval = 'monthly'; },
    setYearly() { this.interval = 'yearly'; },
    get isMonthly() { return this.interval === 'monthly'; },
    get isYearly() { return this.interval === 'yearly'; },
}));

initTheme();
initSheets();
initLanguageSwitchers();
initAdminNav();
initWelcomeBanners();
initForms();
Alpine.start();

// A list's "Sort by" applies as soon as it is chosen.
document.querySelectorAll('select[data-autosubmit]').forEach((select) => select.addEventListener('change', () => select.form?.requestSubmit()));

// The admin's Feature control page brings its own script, and only that page downloads it.
const cockpit = document.querySelector('[data-cockpit]');
if (cockpit) import('./admin-features').then(({ initFeatureControl }) => initFeatureControl(cockpit));

// So do the Appearance page and an event's editor.
if (document.querySelector('[data-studio]')) import('./admin-appearance').then(({ initAppearanceStudio }) => initAppearanceStudio());
if (document.querySelector('[data-event-editor]')) import('./admin-events').then(({ initEventEditor }) => initEventEditor());

// Pro by card: the checkout and the card dashboard bring Stripe.js, and only those pages download it.
const cardCheckout = document.querySelector('[data-card-checkout]');
if (cardCheckout) import('./card-checkout').then(({ initCardCheckout }) => initCardCheckout(cardCheckout));
const cardBilling = document.querySelector('[data-card-billing]');
if (cardBilling) import('./card-billing').then(({ initCardBilling }) => initCardBilling(cardBilling));

// The business profile page brings its signature pad.
if (document.querySelector('[data-signature-pad], input[data-autosubmit]')) import('./business-profile').then(({ initBusinessProfile }) => initBusinessProfile());

// The web app can be installed from the browser (Win Plan PL04).
registerServiceWorker();

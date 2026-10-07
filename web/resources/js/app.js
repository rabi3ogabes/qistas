// CSP-safe Alpine: it never calls eval, so the site can forbid 'unsafe-eval' in its Content-Security-Policy.
import Alpine from '@alpinejs/csp';
import { planCalculator } from './calculator';
import { contractPlanner } from './contract-planner';
import { initTheme } from './theme';
import { initSheets } from './sheet';

window.Alpine = Alpine;
Alpine.data('planCalculator', planCalculator);
Alpine.data('contractPlanner', contractPlanner);
Alpine.data('billingInterval', () => ({
    interval: 'monthly',
    setMonthly() { this.interval = 'monthly'; },
    setYearly() { this.interval = 'yearly'; },
    get isMonthly() { return this.interval === 'monthly'; },
    get isYearly() { return this.interval === 'yearly'; },
}));

initTheme();
initSheets();
Alpine.start();

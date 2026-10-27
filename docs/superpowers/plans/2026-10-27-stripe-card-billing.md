# Pro by card (Stripe) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Owners upgrade to a paid plan and pay by card, Apple Pay or Google Pay on a Qistas-designed checkout inside the website and installed web app, and manage the subscription on the billing page, while the phone apps keep the stores' purchase sheets.

**Architecture:** A `App\Billing\Card` module beside `App\Billing\Store`: one `StripeGateway` interface (live on stripe-php 22, fake for tests) is the only code that talks to Stripe; `CardCheckout` opens Checkout Sessions in `ui_mode: elements`; `CardSubscriptions` turns "what Stripe says now" into the workspace's plan and is called by both the webhook and the return page; `CardManagement` runs the owner's actions. The front end is two lazily imported modules that load Stripe.js through `@stripe/stripe-js`.

**Tech Stack:** Laravel 13, stripe/stripe-php 22.0.0 (API `2026-09-30.endive`, `Stripe\Util\ApiVersion::CURRENT`), `@stripe/stripe-js` 10.1.0 (Stripe.js release train `endive`), Alpine CSP build, Pest, Flutter (app status line only).

**Spec:** `docs/superpowers/specs/2026-10-27-stripe-card-billing-design.md` (sections cited as §n).

## Global Constraints

- Card payment only on the website and web app; the API and the Flutter app never offer it, mention it, or link to it (§2 D1).
- One price per plan in the plan's own currency (`plans.price_monthly`, `plans.price_yearly`, `plans.currency`) (D2).
- Trial: admin setting `billing.card.trial_days`, default 14, range 0–90, offered only while `tenants.trial_used_at` is null (D3, §6).
- Checkout Sessions with `ui_mode: 'elements'`; client `stripe.initCheckoutElementsSdk({clientSecret})`; Stripe.js loaded only through `@stripe/stripe-js` `loadStripe` (never bundled, never a `<script>` tag in a Blade view) (D4).
- Stripe prices: lookup key `qistas_{plan}_{month|year}_{currency lowercase}_{minor}`, product found by metadata `qistas_plan`, `tax_behavior: inclusive` (D5, D8).
- Period end is read from `items.data[0].current_period_end` (§6).
- Feature `card_billing`, label "Pro by card", group Membership (platform scope), ships off (D9).
- Settings keys under `billing.card.*` in `platform_settings`; secret key, publishable key and webhook secret Crypt-encrypted; env `STRIPE_SECRET`, `STRIPE_PUBLISHABLE_KEY`, `STRIPE_WEBHOOK_SECRET` win when set (D10).
- `subscriptions.source` value for card subscriptions: `stripe`. Store events: provider `stripe` in `store_events`.
- Never `float` for money: `App\Support\Money` bcmath strings; Stripe minor units via `StripeAmount` only.
- Every user-facing string in English, Arabic, French, Spanish and Urdu: web via `web/scripts/merge_translations.py <batch>` then `--missing` prints nothing; app via `app/tool/i18n.py <batch>`, `--sync`, `--missing`. App-only strings never go into `web/lang`.
- Web gates per task: the task's Pest files, then before each commit `php vendor/bin/pint --dirty` and `php vendor/bin/phpstan analyse --memory-limit=1G`. Full `php vendor/bin/pest --parallel` at the end of Tasks 6, 9, 11 and 12. New migrations run on PGlite (`migrate:fresh` against a PGlite socket on a free port) before pushing.
- App gates: `flutter analyze` (0 issues), `flutter test`; never `dart format`. Version 1.27.0+30 in `pubspec.yaml`, `lib/core/config.dart`, `test/features/settings_test.dart`.
- Web uses Alpine's CSP build: no template literals or complex expressions in directives (`tests/Unit/AlpineExpressionsTest.php`).
- Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Push with `git push origin main` from the user's terminal tool.
- Before any UI edit (Tasks 7, 9, 10 views), load the `impeccable` skill's craft floor and follow the existing design system (`card`, `card-pad`, `btn-gold`, `btn-quiet`, `badge-*`, `field`, `sheet`, `x-layouts.app`).

## Review Focus

1. **Two payments for one workspace.** Card checkout started while a Google Play or bank-transfer plan is current, a Play purchase or bank transfer while a card subscription is current, or two tabs paying at once: the second must be refused, or cancelled and refunded with a notice. Tests in Tasks 5, 6 and 11.
2. **A subscription event for someone else's customer.** A webhook whose customer is unknown, belongs to another mode (test vs live), or whose metadata names another workspace must change nothing. Test in Task 6.
3. **Out-of-order or replayed events.** An old `customer.subscription.updated` arriving after a newer state, or the same event twice: the plan must reflect Stripe's current state, applied once. Test in Task 6.
4. **Price edits in admin.** After the Pro price changes, a new checkout and a period switch use the new Stripe price, while an existing subscriber who does nothing keeps the price they joined at (nothing updates running subscriptions). Tests in Tasks 4 and 8.
5. **Three-decimal and zero-decimal currencies.** A plan priced in KWD (12.345) or JPY must be converted exactly or reported as a problem in admin, never charged wrongly. Test in Task 2.

---

### Task 1: Data, the switch, and one trial per workspace

**Files:**
- Create: `web/database/migrations/2026_10_27_000200_add_card_billing.php`
- Modify: `web/app/Entitlements/Feature.php` (new case `CardBilling = 'card_billing'` in every exhaustive match: type Toggle, label, group Membership, description, offDescription, dependsOn `[]`, default enabled for every plan like `StoreBilling`)
- Modify: `web/app/Models/Tenant.php` (`subscribeTo` origin keys `stripe_subscription_id`, `period`; `@property` for the three new columns; `trial_used_at` cast)
- Modify: `web/app/Models/Subscription.php` (`@property` `stripe_subscription_id`, `period`; source doc adds `stripe`)
- Modify: `web/app/Billing/StoreSubscriptions.php` (`apply` passes `period` from the product's base plan when known, else null; marks the trial used)
- Modify: `web/app/Billing/ManualPayments.php` (approve marks the trial used and writes `period`)
- Create: `web/app/Billing/Trials.php`
- Test: `web/tests/Feature/Billing/Card/CardFoundationTest.php`

**Interfaces:**
- Produces: `App\Billing\Trials::markUsed(Tenant $tenant): void` (sets `trial_used_at` once, never overwrites); `Trials::available(Tenant $tenant): bool` (`trial_used_at === null`); `Feature::CardBilling`; `Tenant::subscribeTo(..., array $origin)` with optional `stripe_subscription_id`, `period`.

- [ ] **Step 1: Write the failing tests**

```php
it('adds the card columns', function () {
    expect(Schema::hasColumns('tenants', ['stripe_customer_id', 'stripe_livemode', 'trial_used_at']))->toBeTrue()
        ->and(Schema::hasColumns('subscriptions', ['stripe_subscription_id', 'period']))->toBeTrue();
});
it('lists Pro by card as a platform switch that ships off', function () {
    expect(Feature::CardBilling->scope())->toBe('platform')
        ->and(Feature::CardBilling->label())->toBe('Pro by card')
        ->and(in_array(Feature::CardBilling, Feature::planFeatures(), true))->toBeFalse();
    // platform state default off: check the same way StoreBilling's test does
});
it('clears the card fields when another source takes over', function () { /* subscribeTo with stripe ids, then subscribeTo admin: both null */ });
it('marks the trial used the first time a purchase turns a plan on', function (string $how) { /* play verify, manual approve: trial_used_at set; a second time keeps the first date */ })->with(['play', 'manual']);
it('does not count a plan given by the team as a trial', function () { /* ChangePlan admin: trial_used_at stays null */ });
```

- [ ] **Step 2: Run** `php vendor/bin/pest tests/Feature/Billing/Card/CardFoundationTest.php` — expect failures on missing columns and `Feature::CardBilling`.
- [ ] **Step 3: Implement.** Migration: `tenants.stripe_customer_id` string(64) nullable unique, `tenants.stripe_livemode` boolean nullable, `tenants.trial_used_at` timestamp nullable; `subscriptions.stripe_subscription_id` string(64) nullable unique, `subscriptions.period` string(8) nullable. Feature wording: label "Pro by card"; description "Owners subscribe to a paid plan on the website by card, Apple Pay or Google Pay, through Stripe; the plan follows Stripe's renewals, failures and cancellations."; off description "The website stops taking card subscriptions. Subscriptions already running keep renewing in Stripe and their messages are still applied." Fix any existing test that lists the Membership features.
- [ ] **Step 4: Run** the task's test file and `tests/Feature/Entitlements`, `tests/Feature/Admin/FeaturesPageTest.php`, `tests/Feature/Billing` — all pass.
- [ ] **Step 5: Translations** for the new Feature strings (batch, merge, `--missing` empty). **PGlite** `migrate:fresh`.
- [ ] **Step 6: Commit** "Card billing groundwork: columns, the Pro by card switch, one trial per workspace".

### Task 2: Settings, amounts, and the gateway contract

**Files:**
- Create: `web/app/Billing/Card/CardSettings.php`, `StripeAmount.php`, `StripeGateway.php`, `FakeStripeGateway.php`, `UnconfiguredStripeGateway.php`, `CardUnavailable.php`, `CardRefused.php`, `CardSignatureInvalid.php`
- Create value objects in `web/app/Billing/Card/Data/`: `CardAccount`, `CardSession`, `CardSubscription`, `CardPaymentMethod`, `CardInvoice`, `CardPreview`, `CardEvent`, `SubscriptionCheckout`
- Modify: `web/config/qistas.php` (`billing.stripe.publishable`), `web/app/Providers/AppServiceProvider.php` (bind `StripeGateway`)
- Test: `web/tests/Unit/Billing/StripeAmountTest.php`, `web/tests/Feature/Billing/Card/CardSettingsTest.php`

**Interfaces:**
- Produces:
  - `CardSettings`: `secretKey(): ?string`, `publishableKey(): ?string`, `webhookSecret(): ?string`, `webhookEndpointId(): ?string`, `mode(): ?string` (`test`|`live` from `sk_test_`/`sk_live_`), `ready(): bool` (secret + publishable + webhook secret, same mode), `saveKeys(string $secret, string $publishable, User $by): void` (throws `ValidationException` on a bad prefix or mixed modes), `saveWebhook(string $id, string $secret): void`, `clear(User $by): void`, `trialDays(): int`, `promotionCodes(): bool`, `collectTaxId(): bool`, `automaticTax(): bool`, `saveOptions(array{trial_days:int,promotion_codes:bool,collect_tax_id:bool,automatic_tax:bool} $options, User $by): void`, `maskedSecret(): ?string` (`sk_live_…a1B2`). Keys `billing.card.secret`, `.publishable`, `.webhook_secret`, `.webhook_id`, `.trial_days`, `.promotion_codes`, `.collect_tax_id`, `.automatic_tax`.
  - `StripeAmount::toMinor(string $decimal, string $currency): int` (throws `InvalidArgumentException` with a reason), `StripeAmount::fromMinor(int $minor, string $currency): string`, `StripeAmount::problem(string $decimal, string $currency): ?string` (null when chargeable). Zero-decimal: BIF CLP DJF GNF JPY KMF KRW MGA PYG RWF UGX VND VUV XAF XOF XPF; three-decimal: BHD JOD KWD OMR TND (×1000, last digit must be 0). Minimums from Stripe's table for USD 0.50, EUR 0.50, GBP 0.30, AED 2.00, SAR 2.00 (others: the USD equivalent check is skipped, only > 0).
  - Exceptions: `CardUnavailable` (Stripe unreachable, 5xx, bad key: "try again"), `CardRefused` (a refusal with a message meant for the person, from Stripe's card errors or from Qistas' own checks such as "not for sale"), `CardSignatureInvalid` (webhook).
  - `StripeGateway` (all methods throw `CardUnavailable` or `CardRefused`):

```php
public function account(): CardAccount;
/** @param list<string> $events @return array{id: string, secret: string} */
public function createWebhook(string $url, array $events): array;
public function deleteWebhook(string $id): void;
public function registerDomain(string $domain): void;
public function priceFor(string $lookupKey, string $planKey, string $productName, int $minor, string $currency, string $interval): string;
/** @param array{name: string, email: string, locale: string, tenant_id: string} $details */
public function createCustomer(array $details, string $idempotencyKey): string;
public function createSubscriptionCheckout(SubscriptionCheckout $checkout, string $idempotencyKey): CardSession;
/** @param array<string, string> $metadata */
public function createSetupCheckout(string $customerId, string $returnUrl, string $locale, array $metadata): CardSession;
public function session(string $id): CardSession;
public function subscription(string $id): CardSubscription;
public function setCancelAtPeriodEnd(string $subscriptionId, bool $cancel): CardSubscription;
public function changePriceNow(string $subscriptionId, string $priceId): CardSubscription;
public function schedulePriceChange(string $subscriptionId, string $priceId): CardSubscription;
public function releaseSchedule(string $subscriptionId): CardSubscription;
public function previewPriceChange(string $subscriptionId, string $priceId): CardPreview;
public function paymentMethod(string $subscriptionId): ?CardPaymentMethod;
public function useSetupPaymentMethod(string $setupIntentId, string $subscriptionId): void;
/** @return list<CardInvoice> */
public function invoices(string $customerId, int $limit): array;
public function invoice(string $id): CardInvoice;
public function invoicePdf(string $id): string;
public function cancelNow(string $subscriptionId, bool $refundLatestPayment): void;
public function event(string $payload, string $signatureHeader, string $secret): CardEvent; // throws CardSignatureInvalid
```

  - Value objects (readonly, public properties): `CardSubscription(string $id, string $customerId, string $status, ?CarbonImmutable $periodEnd, ?CarbonImmutable $trialEnd, bool $cancelAtPeriodEnd, string $priceId, string $interval, array $metadata, ?string $scheduleId, ?string $scheduledPriceId, CarbonImmutable $created, ?string $latestInvoiceId)`; `CardSession(string $id, string $clientSecret, string $status, string $mode, ?string $customerId, ?string $subscriptionId, ?string $setupIntentId, array $metadata)`; `CardInvoice(string $id, ?string $number, CarbonImmutable $created, string $amount, string $currency, string $status, string $billingReason, ?string $subscriptionId, ?string $paymentIntentId, ?string $paymentClientSecret, ?string $paymentStatus)`; `CardPreview(string $amountDue, string $currency, CarbonImmutable $nextCharge)`; `CardPaymentMethod(string $brand, string $last4, int $expMonth, int $expYear, ?string $wallet)`; `CardAccount(string $id, ?string $name, string $country, string $currency, bool $chargesEnabled, bool $livemode)`; `CardEvent(string $id, string $type, array $object, bool $livemode, CarbonImmutable $created)`; `SubscriptionCheckout(string $customerId, string $priceId, ?int $trialDays, array $metadata, bool $promotionCodes, bool $collectTaxId, bool $automaticTax, string $locale, string $returnUrl)`.
  - `FakeStripeGateway`: implements everything in memory; `calls(string $method): list<array>`; `seedSubscription(CardSubscription $s)`; `failNext(Throwable $e)`; `signedEvent(string $type, array $object, string $secret): array{payload: string, header: string}` (real `t=…,v1=hmac` header so the live verifier logic is exercised in Task 6).
  - Binding: `StripeGateway` → `LiveStripeGateway` when `CardSettings::secretKey()` is set (Task 3), else `UnconfiguredStripeGateway`.

- [ ] **Step 1: Write the failing tests** — `StripeAmountTest`: `toMinor('12.00','USD') === 1200`, `toMinor('12.345','KWD')` throws (last digit), `toMinor('12.340','KWD') === 12340`, `toMinor('1500','JPY') === 1500`, `toMinor('1500.50','JPY')` throws, `problem('0.40','USD')` is not null, `fromMinor(12340,'KWD') === '12.3400'`. `CardSettingsTest`: keys saved encrypted (raw `platform_settings.value` does not contain the key), `mode()` from prefix, mixed test/live refused with a field error, `ready()` false without the webhook secret, env wins over the saved key (`config(['qistas.billing.stripe.secret' => 'sk_test_env'])`), `maskedSecret()` shows the last four only, options validated (trial 0–90).
- [ ] **Step 2: Run** both files — fail on missing classes.
- [ ] **Step 3: Implement** the classes above. `CardSettings` follows `BillingSettings` (same `PlatformSetting` read/write, Crypt for secrets, audit `admin.card_settings_saved` with key names only).
- [ ] **Step 4: Run** — pass. Pint, Larastan.
- [ ] **Step 5: Translations** (validation messages). **Commit** "Card settings, Stripe amounts and the gateway contract".

### Task 3: The live gateway on stripe-php

**Files:**
- Create: `web/app/Billing/Card/LiveStripeGateway.php`
- Test: `web/tests/Feature/Billing/Card/LiveStripeGatewayTest.php` (uses a recording `Stripe\HttpClient\ClientInterface` that returns canned JSON and keeps each request's method, path and params)

**Interfaces:**
- Consumes: `StripeGateway`, value objects, `CardSettings::secretKey()`.
- Produces: `LiveStripeGateway::__construct(CardSettings $settings, ?Stripe\HttpClient\ClientInterface $http = null)`; client built as `new StripeClient(['api_key' => …, 'stripe_version' => ApiVersion::CURRENT, 'max_network_retries' => 3])`, timeout 20 s.

- [ ] **Step 1: Write the failing tests** (one per mapping that matters):
  - `createSubscriptionCheckout` posts `/v1/checkout/sessions` with `ui_mode=elements`, `mode=subscription`, `customer`, `line_items[0][price]`, `subscription_data[trial_period_days]` only when trial days > 0, `subscription_data[metadata][tenant_id]`, `metadata[tenant_id]`, `allow_promotion_codes` per flag, `automatic_tax[enabled]`, `tax_id_collection[enabled]` with `customer_update[name]=auto` and `customer_update[address]=auto` when either tax flag is on, `locale`, `return_url`, and the `Idempotency-Key` header.
  - `priceFor` first searches `GET /v1/prices?lookup_keys[]=…&active=true`; when found returns it without creating; otherwise finds the product by `GET /v1/products/search?query=metadata['qistas_plan']:'pro'` or creates it, then creates the price with `recurring[interval]`, `tax_behavior=inclusive`, `lookup_key`.
  - `subscription()` maps `items.data[0].current_period_end` to `periodEnd`, `items.data[0].price.recurring.interval` to `interval`, `trial_end`, `cancel_at_period_end`, `schedule`.
  - `changePriceNow` sends `items[0][id]`, `items[0][price]`, `proration_behavior=always_invoice`, `payment_behavior=error_if_incomplete`.
  - `schedulePriceChange` creates a schedule `from_subscription` then updates its phases: current phase unchanged to its end, next phase the new price.
  - `cancelNow(…, true)` refunds the latest invoice's payment then deletes the subscription with `prorate=false`.
  - `event()` uses `Stripe\Webhook::constructEvent` with tolerance 300; a wrong secret throws `CardSignatureInvalid`.
  - A network error throws `CardUnavailable`; a 402 card error throws `CardRefused` carrying Stripe's message.
- [ ] **Step 2: Run** — fail. **Step 3: Implement.** **Step 4: Run** — pass; Pint, Larastan.
- [ ] **Step 5: Commit** "Live Stripe gateway on stripe-php 22".

### Task 4: Prices and customers

**Files:**
- Create: `web/app/Billing/Card/StripePrices.php`, `CardCustomers.php`
- Test: `web/tests/Feature/Billing/Card/StripePricesTest.php`, `CardCustomersTest.php`

**Interfaces:**
- Consumes: `StripeGateway`, `StripeAmount`, `CardSettings::mode()`.
- Produces: `StripePrices::for(Plan $plan, string $period): string` (price id; throws `CardRefused` with a readable reason when unpriced or not chargeable); `StripePrices::lookupKey(Plan $plan, string $period): string`; `StripePrices::problems(): list<array{plan: string, period: string, problem: string}>`; `CardCustomers::for(Tenant $tenant, User $owner): string`; `CardCustomers::tenantFor(string $customerId, bool $livemode): ?Tenant` (the only lookup from Stripe back to a workspace; ignores a customer stored for the other mode).

- [ ] **Step 1: Write the failing tests:** same plan/period twice → one `priceFor` call result reused and the same lookup key; after `price_yearly` changes from 120 to 150 → a different lookup key; unpriced plan → `CardRefused`; KWD 12.345 appears in `problems()`; `for()` creates a customer once (second call no gateway call), stores `stripe_customer_id` and `stripe_livemode`; a customer stored in test mode is replaced by a new one when the keys are live; `tenantFor` with the wrong mode returns null.
- [ ] **Step 2–4:** run (fail), implement, run (pass). Customer idempotency key `sha256('customer|'.$tenant->id.'|'.$mode)`.
- [ ] **Step 5: Commit** "Stripe prices from the plan's price; one Stripe customer per workspace".

### Task 5: Starting a checkout, and no second payment from another source

**Files:**
- Create: `web/app/Billing/Card/CardCheckout.php`, `web/app/Http/Controllers/Workspace/CardCheckoutController.php`, `web/app/Http/Requests/CardCheckoutRequest.php`
- Modify: `web/routes/app.php` (`GET billing/checkout` → `app.billing.checkout`; `POST billing/card/session` → `app.billing.card.session`, `throttle:20,1`)
- Modify: `web/app/Billing/BillingState.php` (`storeOpen` false while a card subscription is current; new `cardOpen(Tenant, ?User): bool` = canBuy + switch + `CardSettings::ready()` + no current paid subscription)
- Modify: `web/app/Billing/StoreSubscriptions.php::verify` and `web/app/Billing/ManualPayments.php::submit` (refuse while a card subscription is current: API 409 `already_subscribed`, web field error)
- Test: `web/tests/Feature/Billing/Card/CardCheckoutTest.php`, additions to the existing Play and manual billing tests

**Interfaces:**
- Consumes: `CardSettings`, `StripePrices::for`, `CardCustomers::for`, `Trials::available`, `StripeGateway::createSubscriptionCheckout`.
- Produces: `CardCheckout::start(Tenant $tenant, User $owner, string $planKey, string $period, string $attempt): array{client_secret: string, publishable_key: string, trial_days: int}`; throws `CardRefused` (reason for the person) or `CardUnavailable`. JSON endpoint returns `{client_secret, publishable_key}` or 422 `{message}` / 503 `{message}`. Cache key for open-session reuse: `card-checkout:{tenant}:{plan}:{period}` → session id, 23 h; reused only when `session()->status === 'open'`. Lock `card-checkout:{tenant}` 10 s. Idempotency key `sha256(tenant|plan|period|attempt)`. `return_url` = `route('app.billing.card.return').'?session_id={CHECKOUT_SESSION_ID}'`; locale map `en→en, ar→ar, fr→fr, es→es, ur→auto`.

- [ ] **Step 1: Write the failing tests:** owner gets a client secret; manager, accountant and collector get 403; demo and test workspaces refused; switch off → 404 page and 422 JSON; not ready → "Card payments are not set up yet"; current Play plan → refused; current card plan → refused; unknown plan or period → 422; trial days sent only while `trial_used_at` is null and trial setting > 0; open session reused (one gateway create for two calls); expired session → new one; the same attempt token → same idempotency key; audit `billing.card_checkout_started` with plan and period only; `CardUnavailable` → 503 with "Card payments are briefly unavailable. Nothing was charged; try again in a minute."; Play verify while a card plan is current → 409 `already_subscribed`; bank transfer submit while a card plan is current → error; `BillingState` store not available while a card plan is current.
- [ ] **Step 2–4:** run (fail), implement, run (pass). The checkout page view is a minimal placeholder passing data attributes only (`data-card-checkout`, `data-session-url`, `data-plan`, `data-period`, `data-attempt`); Task 9 designs it.
- [ ] **Step 5: Translations. Commit** "Start a card checkout; never take a second payment from another source".

### Task 6: Following Stripe: sync, webhook, notices, duplicate guard

**Files:**
- Create: `web/app/Billing/Card/CardSubscriptions.php`, `web/app/Http/Controllers/StripeWebhookController.php`
- Modify: `web/routes/webhooks.php` (`POST /stripe` → `webhooks.stripe`), `web/app/Billing/BillingNotices.php` (new public `card(Subscription $subscription, string $outcome, array $context = []): void`), `web/app/Notifications/Catalog.php` (types `billing.trial_ending` in `billing_renewal_due`, `billing.action_needed` in `billing_payment_failed`)
- Test: `web/tests/Feature/Billing/Card/CardSubscriptionsTest.php`, `StripeWebhookTest.php`

**Interfaces:**
- Consumes: gateway `subscription`, `session`, `event`, `cancelNow`; `CardCustomers::tenantFor`; `Trials::markUsed`; `Tenant::subscribeTo`; `StoreEvent`.
- Produces: `CardSubscriptions::EVENTS` (list: `checkout.session.completed`, `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `customer.subscription.trial_will_end`, `invoice.paid`, `invoice.payment_failed`, `invoice.payment_action_required`); `sync(string $subscriptionId, bool $livemode): ?Subscription`; `syncSession(string $sessionId, Tenant $tenant): string` (`complete` | `open` | `expired`; throws `ModelNotFoundException` for another workspace's session); `handle(CardEvent $event): string` (outcome recorded on the `StoreEvent`). `BillingNotices::card()` outcomes: `trial_ending`, `renewed`, `payment_failed`, `action_needed`, `ended`, `refunded_duplicate`; context carries `amount`, `currency`, `date` where the wording needs them. Controller: 400 bad payload/signature, 503 when `webhookSecret()` is null, 200 `{outcome}` otherwise, 500 on an exception (event row left unprocessed so Stripe's retry runs it).

- [ ] **Step 1: Write the failing tests** — a dataset over §6's table (each Stripe status → expected plan key, status, until, auto_renew); `trialing` sets `trial_used_at`; `incomplete` changes nothing; an event for an unknown customer → `unknown`, no change; a customer of the other mode → `unknown`; metadata `tenant_id` disagreeing with the customer's workspace → `ignored` and a warning log; a sync for an older subscription than the current card one → no change; a replayed event id → `duplicate`, applied once; two events at once (unique violation) → `duplicate`; duplicate guard: workspace on a current Play plan receives an active card subscription → `cancelNow(id, true)` called, notice `billing.card_refunded_duplicate` audited, plan unchanged; a second card subscription (different id, newer) while one is current → the newer is cancelled and refunded; notices: `trial_will_end` → owner notice type `billing.trial_ending` with the charge amount and date, `invoice.paid` with `billing_reason=subscription_cycle` → `billing.renewed`, first invoice → no renewal notice, `invoice.payment_failed` → `billing.payment_failed`, `invoice.payment_action_required` → `billing.action_needed`, `customer.subscription.deleted` → `billing.expired`; each notice once per period (dedupe key); webhook: valid signature 200, wrong signature 400, missing header 400, unconfigured 503, gateway down during handling → 500 and the event stays unprocessed.
- [ ] **Step 2–4:** run (fail), implement, run (pass). Status mapping exactly §6. `subscribeTo` origin `['source' => 'stripe', 'stripe_subscription_id' => …, 'period' => month|year, 'auto_renew' => ! cancelAtPeriodEnd, 'store_status' => strtoupper(status)]`. Audit `billing.card_subscribed` on the first active/trialing sync, `billing.card_event` per applied event (outcome, status, until).
- [ ] **Step 5: Translations** (notice wording from §7, card-specific: no "Google Play" in them). **Full suite** `pest --parallel`. **Commit** "Follow Stripe subscriptions: one sync, a signed webhook, notices and a duplicate guard".

### Task 7: Return page and the welcome moment

**Files:**
- Modify: `web/app/Http/Controllers/Workspace/CardCheckoutController.php` (`return`), `web/routes/app.php` (`GET billing/card/return` → `app.billing.card.return`)
- Create: `web/resources/views/app/billing/welcome.blade.php`
- Test: `web/tests/Feature/Billing/Card/CardReturnTest.php`

**Interfaces:**
- Consumes: `CardSubscriptions::syncSession`.
- Produces: complete → welcome view with plan name, what it unlocks (`Feature::planFeatures()` enabled for the plan), trial end or next charge date, button to `app.dashboard`; open → redirect to `app.billing.checkout` with plan/period and flash "Your payment wasn't completed; nothing was charged."; another workspace's session → 404; missing `session_id` → redirect to `app.billing`.

- [ ] **Step 1: Write the failing tests** for the four outcomes, that the plan is Pro right after the return without any webhook, and that a manager (not owner) gets 403.
- [ ] **Step 2–4:** run (fail), implement (load impeccable craft floor first for the welcome view), run (pass).
- [ ] **Step 5: Translations. Commit** "Return from checkout straight to Pro, without waiting for the webhook".

### Task 8: Managing the card subscription

**Files:**
- Create: `web/app/Billing/Card/CardManagement.php`, `web/app/Http/Controllers/Workspace/CardBillingController.php`
- Modify: `web/routes/app.php` (§5.2 routes: setup, setup-return, switch preview GET, switch POST, cancel, resume, confirm, receipt; POSTs `throttle:20,1`)
- Test: `web/tests/Feature/Billing/Card/CardManagementTest.php`

**Interfaces:**
- Consumes: gateway management methods; `StripePrices::for`; `CardSubscriptions::sync`.
- Produces on `CardManagement` (each first resolves the workspace's current card subscription, else `CardRefused` "There is no card subscription to change."):
  - `overview(Tenant $tenant): ?array{status: string, until: ?CarbonImmutable, trial_end: ?CarbonImmutable, cancel_at_period_end: bool, period: string, next_amount: ?string, currency: string, scheduled_period: ?string, card: ?CardPaymentMethod, needs_action: bool}` (read live, cached 60 s per subscription, cache dropped by every action and every applied event)
  - `startCardChange(Tenant $tenant): array{client_secret: string, publishable_key: string}` (setup session, metadata `tenant_id`, `purpose=card_change`); setup-return applies `useSetupPaymentMethod` then redirects to billing with "Your new card is saved."
  - `previewSwitch(Tenant $tenant, string $period): array{amount_due: string, currency: string, when: string /* now|period_end */, date: string}` — to yearly: `previewPriceChange`; to monthly: amount = monthly price, when `period_end`
  - `switchPeriod(Tenant $tenant, string $period, User $by): void` — to yearly `changePriceNow`; to monthly `schedulePriceChange`; switching back before the scheduled date `releaseSchedule`
  - `cancel(Tenant $tenant, ?string $reason, User $by): void` (reason one of `too_expensive`, `missing_feature`, `not_using`, `switching`, `other` plus optional text up to 500 chars, stored in the audit entry only), `resume(Tenant $tenant, User $by): void`
  - `receipts(Tenant $tenant): list<CardInvoice>`; `receiptPdf(Tenant $tenant, string $invoiceId): string` (404 when the invoice's customer is not the workspace's)
  - `paymentToConfirm(Tenant $tenant): ?array{client_secret: string, publishable_key: string}` (latest open invoice whose payment status is `requires_action`)

- [ ] **Step 1: Write the failing tests:** every action owner-only (403 for others) and refused without a card subscription; switch to yearly calls `changePriceNow` with the yearly price and the preview shows Stripe's amount; switch to monthly schedules at period end and the overview shows `scheduled_period=month`; switching back releases the schedule; cancel sets cancel-at-period-end and audits the reason; resume clears it; receipts list maps amounts through `StripeAmount::fromMinor`; receipt download streams `application/pdf` with `Content-Disposition: attachment; filename="qistas-receipt-<number>.pdf"`; another workspace's invoice id → 404; payment to confirm returns a client secret only when an invoice requires action; audits `billing.card_changed`, `billing.card_period_switched`, `billing.card_cancelled`, `billing.card_resumed`.
- [ ] **Step 2–4:** run (fail), implement, run (pass).
- [ ] **Step 5: Translations. Commit** "Manage the card subscription inside Qistas".

### Task 9: The checkout and billing interface

**Files:**
- Modify: `web/package.json` (dependency `"@stripe/stripe-js": "10.1.0"`), `web/resources/js/app.js` (lazy imports on `[data-card-checkout]` and `[data-card-billing]`)
- Create: `web/resources/js/card-stripe.js` (shared: `loadStripe` once, `appearanceFromPage()` reading CSS variables, `stripeLocale()`, re-theme on `data-q-mode` change), `web/resources/js/card-checkout.js`, `web/resources/js/card-billing.js`
- Create/Modify views: `web/resources/views/app/billing/checkout.blade.php`, `web/resources/views/app/billing.blade.php` (card dashboard section, sheets for change card / switch / cancel, receipts table, action banner, "Pay by card" entry for Free owners), `web/resources/views/app/billing/_card-*.blade.php` partials
- Modify: `web/resources/css/app-shell.css` (checkout layout: two columns ≥ 1024 px, one column below, summary first; timeline; segmented Monthly/Yearly; promo row; pay button progress)
- Test: `web/tests/Feature/Billing/Card/CardPagesTest.php`

**Interfaces:**
- Consumes: Task 5 JSON (`client_secret`, `publishable_key`), Task 8 endpoints.
- Produces (DOM contract the JS relies on): checkout root `[data-card-checkout]` with `data-session-url`, `data-plan`, `data-period`, `data-attempt`, `data-switch-url` (same page with the other period); mount points `#card-express`, `#card-payment`; `[data-card-total]`, `[data-card-promo]` form with `[data-card-promo-message]`; `[data-card-pay]` button; `[data-card-error]` live region (`role="alert"`). Billing root `[data-card-billing]` with `data-setup-url`, `data-confirm-url`; change-card sheet mount `#card-setup`.
- Front-end behaviour: `initCheckoutElementsSdk({clientSecret, elementsOptions: {appearance, fonts: [{cssSrc: 'https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600&family=IBM+Plex+Sans+Arabic:wght@400;500;600&family=Noto+Nastaliq+Urdu:wght@400;600&display=swap'}], locale}})`; `createExpressCheckoutElement()` into `#card-express` (hidden when it reports no wallet); `createPaymentElement({layout: 'tabs'})` into `#card-payment`; totals from `actions.getSession().total`, refreshed on `checkout.on('change')`; pay button enabled by `session.canConfirm`; promo via `actions.applyPromotionCode(code)` / `removePromotionCode()`; `actions.confirm()` errors into the live region; card change uses a setup session the same way; "Confirm payment" uses `stripe.handleNextAction({clientSecret})` then reloads. No template literals in Alpine directives; plain DOM modules are fine.

- [ ] **Step 1: Write the failing page tests:** checkout page for an eligible owner shows the plan, both periods with the yearly saving, the trial timeline with the date `now()->addDays(14)` when eligible and without it when `trial_used_at` is set, the promo field only when codes are on, `data-card-checkout` with the session URL; the page never contains the secret key or the word `sk_`; no `<script` tag in the page body (the existing XSS guard); billing page for a card subscriber shows status lines for trialing / active / ending / past due (seed the fake), the card brand and last four, the switch label for the other period, cancel or resume, the receipts table, and the action banner when a payment needs confirming; Free owner with the switch on sees "Pay by card"; with it off sees the existing content.
- [ ] **Step 2: Run** — fail. **Step 3: Implement** (impeccable craft floor first; then `npm install`, `npm run build`). **Step 4: Run** the page tests, `tests/Unit/AlpineExpressionsTest.php`, the existing contracts XSS guard test — pass.
- [ ] **Step 5: Visual check** with the preview server (`.claude/launch.json` `qistas-laravel`) and Playwright screenshots at 390 px and 1280 px, light and dark, English and Arabic. The Qistas layout (summary, timeline, switch, dashboard, sheets) is checked with the fake gateway bound only when `app()->isLocal() && env('BILLING_CARD_FAKE') === 'true'` (Stripe's own fields then stay empty, since a fake client secret cannot start Stripe.js); Stripe's fields are checked in Task 12 with real test keys.
- [ ] **Step 6: Translations. Full suite. Commit** "A Qistas checkout and billing dashboard with Stripe's fields inside".

### Task 10: Admin setup and business pages

**Files:**
- Create: `web/app/Http/Controllers/Admin/CardSettingsController.php`, `web/app/Billing/Card/CardConnection.php`, `web/resources/views/admin/billing/_card.blade.php`
- Modify: `web/routes/admin.php` (card routes §5.2 under `billing.card.*`, `throttle:60,1`; business `card/cancel`), `web/resources/views/admin/billing/index.blade.php` (include the panel), `web/app/Http/Controllers/Admin/BusinessController.php` + `web/resources/views/admin/businesses/_plan.blade.php` (card source, status, period, next charge or end, trial end, Stripe dashboard link `https://dashboard.stripe.com/{test/}subscriptions/{id}`, cancel action), `web/app/Actions/Admin/ChangePlan.php` + its view (warning while a card subscription is current)
- Test: `web/tests/Feature/Admin/CardSettingsTest.php`, additions to `web/tests/Feature/Admin/BusinessPlansTest.php`

**Interfaces:**
- Consumes: `CardSettings`, gateway `account`, `createWebhook`, `deleteWebhook`, `registerDomain`, `cancelNow`, `setCancelAtPeriodEnd`; `StripePrices::problems()`; `CardSubscriptions::EVENTS`.
- Produces: `CardConnection::connect(User $by): CardAccount` (account check; deletes the previous endpoint when one is stored; creates the webhook for `url('/webhooks/stripe')` with `CardSubscriptions::EVENTS`; stores id + secret; registers `parse_url(config('app.url'), PHP_URL_HOST)`; audits `admin.card_connected` with mode and account id); `CardConnection::disconnect(User $by): void` (deletes the endpoint when reachable, clears keys; audits `admin.card_disconnected`). Gate `manage-plans` for writes; any platform staff may view.

- [ ] **Step 1: Write the failing tests:** support staff see the panel read-only, super admin saves keys (masked afterwards, never echoed), mixed modes refused, Connect creates the webhook with exactly `CardSubscriptions::EVENTS` and stores the secret, a second Connect deletes the first endpoint, Connect with a bad key shows Stripe's message and saves nothing, Disconnect clears everything, options saved with validation, price check lists a KWD problem, the panel says "Ready to take cards · Test mode" only when `ready()`; business page shows card details and the Stripe link; cancel now calls `cancelNow(id, true)` and the plan returns to Free after sync; cancel at period end sets it; manual plan change shows the warning while a card subscription is current.
- [ ] **Step 2–4:** run (fail), implement (impeccable craft floor for the panel), run (pass).
- [ ] **Step 5: Translations. Commit** "Set up Stripe from the admin area in two minutes".

### Task 11: The API and the phone apps

**Files:**
- Modify: `web/app/Billing/BillingState.php` (adds `period`; never exposes card availability), `docs/api/openapi.yaml` (`/billing` response: `period`, `source` enum adds `stripe`)
- Modify: `app/lib/data/billing.dart` (`period`, `paidOutsideStore` = source in `stripe|manual|admin` with a current plan), `app/lib/features/billing/plans_screen.dart` (`_PaidPlan` line under the current plan card: "Renews :date" when `autoRenew == true`, "Until :date" otherwise; no button, no source wording), version files (1.27.0+30)
- Test: additions to `web/tests/Feature/Api/BillingApiTest.php` (or the existing API billing test file), the OpenAPI drift test; `app/test/features/plans_test.dart` (or the existing plans/billing test file)

**Interfaces:**
- Consumes: Task 1 `subscriptions.period`.
- Produces: API `GET /api/v1/billing` keys unchanged plus `period: "month"|"year"|null`; the app's `BillingState.period`.

- [ ] **Step 1: Write the failing tests:** API billing for a card subscriber returns `source: stripe`, `period: year`, `auto_renew: true`, `store.available: false`; the response JSON contains no `card`, `stripe` key other than the source value, and no URL; OpenAPI drift passes with the new field; app: a card subscriber's Pro card shows "Renews 9 December 2026" and no buy button, and no text containing "card", "website" or a price; a cancelled one shows "Until …"; a Play subscriber is unchanged.
- [ ] **Step 2–4:** run (fail), implement, run (pass). `flutter analyze` 0 issues, `flutter test` green.
- [ ] **Step 5: App translations** (`tool/i18n.py`). **Full web suite. Commit** "The apps show a card-paid plan without selling or mentioning it".

### Task 12: Proof, documentation and release

**Files:**
- Modify: `docs/features/billing.md` (setup steps §11, what happens when §6 table, notices §7), `docs/superpowers/plans/2026-10-27-stripe-card-billing.md` (tick boxes)
- Create: scratchpad `card-mutate.py` (not committed), scratchpad Playwright `card_e2e.py` (not committed)

- [ ] **Step 1: Mutation check** (dry run first, then run; never edit source during the run): owner-only check in `CardCheckout`, trial-once check, each row of the §6 mapping, signature verification, duplicate guard, `tenantFor` mode check, receipt ownership check, Play/manual refusal while a card plan is current. Every mutant must be caught; a survivor gets a better test, then the mutant is run again.
- [ ] **Step 2: End to end with Stripe test mode** (only when the platform owner has put test keys in `web/.env`; never ask for keys in chat): `stripe listen --forward-to http://localhost:8137/webhooks/stripe` or the dashboard's test webhook, then Playwright: success `4242 4242 4242 4242`, 3-D Secure `4000 0025 0000 3155`, decline `4000 0000 0000 9995`, promotion code, trial, cancel/resume, switch both ways, change card, receipt download; a test clock for one renewal and one failed renewal. Without keys, record "end to end not run: no test keys" in the report.
- [ ] **Step 3: Gates:** full `pest --parallel`, Pint `--dirty`, Larastan, `flutter analyze`, `flutter test`, translations `--missing` empty (web and app), PGlite `migrate:fresh`.
- [ ] **Step 4: Commit docs**, push with `git push origin main` (terminal tool), watch the Deploy workflow, Vercel, CI and the post-deploy smoke test (`scratchpad/watch_deploy.py`).
- [ ] **Step 5: Report** to the owner: what shipped, the switch is off, the setup steps (Stripe account in a supported country, test keys, Connect, switch on, test card, Smart Retries, live keys), what was proven and what still needs their keys.

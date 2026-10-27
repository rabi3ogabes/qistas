# Pro by card (Stripe) — design

Date: 27 October 2026. Asked by the owner: "the user can upgrade and make the payment using Stripe in the app without
leaving the app, in a professional, luxury and modern way". Approved in conversation section by section (experience,
architecture, admin and apps, security and testing) on 27 October 2026.

## 1. Intent

Owners of a Qistas workspace upgrade to a paid plan and pay by card, Apple Pay or Google Pay **inside Qistas**: no
redirect to a Stripe page, no second account, Pro on at once, and every later step (card, period, cancel, receipts)
handled on the same billing page. It must feel as premium as the rest of the product and be impossible to pay twice.

Success means:

- an owner goes from "Upgrade" to "Welcome to Pro" on one Qistas page, in their language, light or dark, in under a
  minute, with 3-D Secure when their bank asks;
- the workspace is on the plan the moment Stripe confirms, on the website, the installed web app and both phone apps;
- renewals, failures, cancellations and refunds keep the plan right without anyone touching it;
- the owner of the platform sets it up in `/admin/billing` in about two minutes, without configuring anything in the
  Stripe dashboard except the account itself.

## 2. Decisions

| # | Decision | Why |
|---|---|---|
| D1 | Stripe is offered on the **website and the installed web app** (`/app`, the PWA). The Android and iPhone apps keep the stores' own purchase sheets (Google Play today, Apple later). | Google Play's Payments policy requires Play billing for "cloud software and services (such as … business productivity software … financial management software)" except in its listed alternative-billing countries (EEA, Australia, Brazil, India, Indonesia, Japan, South Africa, South Korea, United States, United Kingdom); no Gulf country is listed. Apple's guideline 3.1.1 is the same outside the US storefront. A card form inside the phone apps would get them rejected or removed. Owner's choice: "Store billing in apps". |
| D2 | One price per plan, in the plan's own currency, as set in the admin area (Pro 12 / 120 USD today). | Owner's choice: "Plan's own price". |
| D3 | A free trial for card subscriptions, length set in admin (default 14 days, 0 = none), card saved at the start, first charge when it ends, **once per workspace**. | Owner's choice. |
| D4 | Checkout Sessions in **`ui_mode: elements`**: a Qistas-designed page with Stripe's Payment Element and Express Checkout Element (Apple Pay, Google Pay) inside it, styled through the Appearance API. | Owner's choice of approach A. Stripe keeps subscriptions, trials, promotion codes, tax, 3-D Secure and SCA; Qistas owns the layout. |
| D5 | Stripe prices are **made by Qistas** from the plan's price (product per plan, price per plan + period + currency + amount, found again by `lookup_key`). Existing subscribers keep the price they joined at until they change period. | Nothing to set up in Stripe; a price change in admin never surprises a subscriber ("founding price lock"). |
| D6 | The workspace's plan follows Stripe through **one** class that always asks Stripe what is true now; the webhook and the return page both call it. | Same rule as Google Play billing (`StoreSubscriptions`): no state machine built from event order. |
| D7 | Managing the subscription happens in Qistas (no Stripe customer portal). | "Without leaving the app". |
| D8 | Prices are **tax-inclusive** (`tax_behavior: inclusive`). Automatic tax and collecting the buyer's tax number are admin switches, both off by default. | The price the owner sees is the price they pay. Automatic tax needs Stripe Tax set up in the platform owner's Stripe account. |
| D9 | A new platform switch **Pro by card** (`card_billing`), next to *Pro through Google Play* and *Pro by bank transfer*, ships **off**. | Same pattern as Win Plan PP13. |
| D10 | Keys live in `/admin/billing`, encrypted. A `STRIPE_SECRET` / `STRIPE_PUBLISHABLE_KEY` / `STRIPE_WEBHOOK_SECRET` environment value, when set, wins over the saved one. | Same rule as the Firebase key. Lets local testing use `.env` without touching the database. |

Stripe account: Stripe accepts businesses from the United Arab Emirates but not from Saudi Arabia, Qatar, Kuwait,
Bahrain, Oman, Egypt or Morocco (stripe.com/global, 26 October 2026). The platform owner opens the account under a
company in a supported country. Test keys work at once, before any verification.

## 3. Out of scope

Card payment inside the phone apps (D1); Apple in-app purchase (needs the owner's Apple developer account; separate
work); a price per country; Stripe's hosted customer portal; refunds from Qistas (the platform owner refunds in the
Stripe dashboard, and the webhook applies the result); creating promotion codes from Qistas (they are made in Stripe);
usage-based or per-seat prices; invoices for plans bought another way.

## 4. What owners see

### 4.1 Checkout (`/app/billing/checkout?plan=pro&period=year`)

Reached from the Plans comparison, the "upgrade" prompt on a Free limit, and the Pro card in Settings. Only the owner of a
real workspace (`BillingState::canBuy`) gets here; anyone else is sent back to `/app/billing` with the reason.

Two columns on a wide screen, one column on a phone (summary first, then payment):

- **Summary:** the plan name and what it unlocks (from `Feature::planFeatures()`), a Monthly / Yearly segmented switch
  showing the yearly saving in money and percent, the trial timeline when the workspace is eligible ("Today · trial
  starts · nothing to pay" → "9 November · first charge 120.00 USD" → "Cancel any time before then"), a promotion code
  field (when the admin allows codes) that shows the discount or Stripe's own message, and the total from
  `actions.getSession()`.
- **Payment:** the Express Checkout Element (Apple Pay or Google Pay, only when the device offers one), "or pay by card",
  the Payment Element, the owner's e-mail prefilled and read-only, an optional business name and tax number when the
  admin collects it, the terms line ("Renews every year until cancelled · cancel any time in Billing"), and the Pay
  button, disabled until `canConfirm`.
- **Look:** Stripe's fields wear the active Qistas theme: `elementsOptions.appearance` built at run time from the page's
  CSS variables (primary, surface, text, danger, radius, focus ring) and the brand font through `elementsOptions.fonts`;
  it re-themes when the page switches light/dark. `locale` follows the page language; Arabic is right-to-left. Urdu
  uses `auto` if Stripe has no Urdu.
- **Paying:** the button shows a progress state; a decline or a validation error appears under the fields without a
  reload; 3-D Secure opens Stripe's own frame. Success goes to the return page.

### 4.2 Return and welcome (`/app/billing/card/return?session_id=…`)

Qistas retrieves the session from Stripe, checks it belongs to the workspace's Stripe customer, and runs the sync
straight away (it does not wait for the webhook). Complete: a "Welcome to Pro" page listing what is now open, with the
trial end or next charge date, and a button to the dashboard. Still open (the buyer came back without paying): back to
the checkout with "Your payment wasn't completed; nothing was charged". A session of another workspace: 404.

### 4.3 Billing page (`/app/billing`)

For a card subscription the page becomes a dashboard:

- plan and status ("Pro · trial until 9 Nov", "Pro · renews 9 Dec · 120.00 USD", "Pro · ends 9 Dec", "Payment
  failed — update your card");
- the card on file (brand, last four, expiry, read live from Stripe) and **Change card**: a sheet with the Payment Element
  in a `mode: setup` session; the new card becomes the subscription's default;
- **Switch to yearly** (charged now: the preview from Stripe's invoice preview shows the exact amount after the unused
  month is credited, then Confirm) or **Switch to monthly** (scheduled for the end of the paid year; shown as "Monthly
  from 9 Dec"; can be undone until then);
- **Cancel**: an optional reason (list + free text), then "Pro stays until 9 Dec; you won't be charged again".
  **Resume** before that date undoes it;
- **Receipts**: date, number, amount, status, and a download that streams the PDF through Qistas;
- when a renewal needs 3-D Secure: a banner with **Confirm payment**, which runs Stripe.js `handleNextAction` on the
  invoice's payment on this page.

The existing bank-transfer and Google Play states keep their current presentation.

### 4.4 Phone apps

`GET /api/v1/billing` gains `period`. A card subscription shows as "Pro · renews 9 Dec" (or "ends 9 Dec" when renewal
is off), with no buy button and no mention of card, website or prices. Google Play buying is unchanged. iPhone: no
change.

## 5. Architecture

All new server code sits in `App\Billing\Card` beside `App\Billing\Store`.

| Unit | Responsibility | Depends on |
|---|---|---|
| `CardSettings` | Keys (secret, publishable, webhook secret, webhook endpoint id; Crypt-encrypted, env wins per D10), `mode()` test/live from the key prefix, `ready()`, trial days (0–90), promotion codes on/off, tax number on/off, automatic tax on/off. Stored in `platform_settings` under `billing.card.*`. | `PlatformSetting` |
| `StripeGateway` (interface) | Every call Qistas makes to Stripe, in Qistas terms: account summary, create/delete webhook endpoint, register payment-method domain, find-or-create product/price by lookup key, create customer, create checkout session (subscription / setup), retrieve session, retrieve subscription, update subscription (cancel at period end, resume, change price now, schedule a price change), preview invoice, list invoices, invoice PDF bytes, invoice payment client secret, cancel subscription now with refund, verify webhook payload. | — |
| `LiveStripeGateway` | `StripeGateway` on `stripe/stripe-php` 22 (`StripeClient`, API version pinned to the library's `ApiVersion::CURRENT`, 3 network retries, 20 s timeout). Throws `CardUnavailable` on network/5xx/auth errors, `CardRefused` with Stripe's message on 4xx a person can act on. | stripe-php |
| `FakeStripeGateway` | In-memory Stripe for tests: records every call, lets a test set subscription states, signs webhook payloads with a test secret. | — |
| `UnconfiguredStripeGateway` | Bound when keys are missing; every call throws `CardUnavailable`. | — |
| `StripeAmount` | Decimal string + currency → Stripe minor units (zero-decimal currencies × 1, three-decimal BHD/JOD/KWD/OMR/TND × 1000 with the last digit 0, others × 100), and back. Refuses amounts below Stripe's minimum charge for the currency or currencies Stripe does not settle. | `Money` |
| `StripePrices` | `for(Plan, period): price id`: lookup key `qistas_{plan}_{month|year}_{currency}_{minor}`; product per plan found by metadata `qistas_plan`; `tax_behavior: inclusive`. `problems(): list` for the admin panel (unpriced plan, below minimum, unsupported currency). | gateway, `StripeAmount` |
| `CardCustomers` | `for(Tenant, User): customer id`: creates once (name = business name, e-mail = owner, `preferred_locales`, metadata `tenant_id`), stores `tenants.stripe_customer_id` + `tenants.stripe_livemode`; a customer of the other mode is never reused. `tenantFor(customer id)` is the only lookup from Stripe back to a workspace. | gateway |
| `CardCheckout` | `start(Tenant, User, plan key, period, attempt token): {client_secret, publishable_key}`. Checks: `canBuy`, switch on, `CardSettings::ready()`, plan for sale, no current paid subscription (any source), per-workspace lock (10 s). Reuses an open session for the same plan/period (kept 23 h in cache) instead of making a second; otherwise creates one with the Stripe idempotency key `sha256(tenant|plan|period|attempt token)`. Session: `ui_mode: elements`, `mode: subscription`, customer, price, `subscription_data.trial_period_days` when eligible, metadata `tenant_id/plan/period` on session and subscription, `allow_promotion_codes` per setting, `automatic_tax`, `tax_id_collection` + `customer_update {name, address: auto}` per settings, `locale`, `return_url`. Audited `billing.card_checkout_started`. | settings, prices, customers, gateway |
| `CardSubscriptions` | `sync(subscription id)`: retrieves the subscription and applies section 6. `syncSession(session id, Tenant)`: for the return page. `handle(event)`: the webhook's dispatcher. Records `trial_used_at`. Sends notices (section 7). Guards against a second subscription (section 9). | gateway, customers, `Tenant::subscribeTo`, `Notifier` |
| `CardManagement` | Owner actions on the workspace's own subscription: `card()`, `startCardChange()` (setup session), `previewSwitch(period)`, `switchPeriod(period)`, `cancel(reason)`, `resume()`, `receipts()`, `receiptPdf(invoice id)`, `paymentToConfirm()`. Each checks the subscription/invoice belongs to the workspace's customer and is audited. | gateway, customers, sync |
| `StripeWebhookController` | `POST /webhooks/stripe`: verifies `Stripe-Signature` on the raw body (tolerance 300 s); 400 bad signature or payload, 503 not configured, 200 handled or ignored, 500 on a failure so Stripe retries. Each event id goes once into `store_events` (provider `stripe`). | `CardSubscriptions` |
| Web controllers | `Workspace\CardCheckoutController` (checkout page, session JSON, return), `Workspace\CardBillingController` (manage actions), `Admin\CardSettingsController` (keys, connect, disconnect, settings), admin business action "cancel the card subscription". | the above |
| Front end | `resources/js/card-checkout.js` and `resources/js/card-billing.js` (Vite entries, loaded only on these pages); Stripe.js loaded with `@stripe/stripe-js`'s `loadStripe` (injects the official `js.stripe.com` script, never bundled); Alpine components written in the modules (the web uses Alpine's CSP build). | Alpine, Stripe.js |

Binding: `StripeGateway` resolves to `LiveStripeGateway` when `CardSettings::ready()`, else
`UnconfiguredStripeGateway`; tests bind `FakeStripeGateway`.

### 5.1 Data

Migration `add_card_billing`:

- `tenants.stripe_customer_id` string(64) nullable unique, `tenants.stripe_livemode` boolean nullable,
  `tenants.trial_used_at` timestamp nullable;
- `subscriptions.stripe_subscription_id` string(64) nullable unique, `subscriptions.period` string(8) nullable
  (`month` | `year`, also filled for Google Play from its base plan).

`Tenant::subscribeTo` origin gains `stripe_subscription_id` and `period` (cleared like the store fields when another
source takes over). Card details, receipts and amounts are never stored: they are read from Stripe when shown.
`store_events` stores each Stripe event id once.

### 5.2 Routes

Web, `auth` + workspace + owner (`managesBilling`), CSRF, `throttle:20,1` on the POSTs:

| Method | Path | Name |
|---|---|---|
| GET | `/app/billing/checkout` | `app.billing.checkout` |
| POST | `/app/billing/card/session` | `app.billing.card.session` |
| GET | `/app/billing/card/return` | `app.billing.card.return` |
| POST | `/app/billing/card/setup` | `app.billing.card.setup` |
| GET | `/app/billing/card/setup-return` | `app.billing.card.setup-return` |
| GET | `/app/billing/card/switch` | `app.billing.card.switch.preview` |
| POST | `/app/billing/card/switch` | `app.billing.card.switch` |
| POST | `/app/billing/card/cancel` | `app.billing.card.cancel` |
| POST | `/app/billing/card/resume` | `app.billing.card.resume` |
| POST | `/app/billing/card/confirm` | `app.billing.card.confirm` |
| GET | `/app/billing/card/receipts/{invoice}` | `app.billing.card.receipt` |

Webhook: `POST /webhooks/stripe` (`webhooks.stripe`, in `routes/webhooks.php`: no session, no CSRF, rate limit only).

Admin (super admin, 2FA): `PUT /admin/billing/card/keys`, `POST /admin/billing/card/connect`,
`POST /admin/billing/card/disconnect`, `PUT /admin/billing/card/settings`,
`POST /admin/businesses/{tenant}/card/cancel` (`when`: `now` | `period_end`).

API: no new endpoint (D1). `GET /api/v1/billing` adds `period`; `source` may be `stripe`. `docs/api/openapi.yaml`
updated.

## 6. Stripe status → the workspace

`CardSubscriptions::sync` reads the subscription (expanding the latest invoice) and the period end from its **item**
(`items.data[0].current_period_end`; Stripe moved it off the subscription in 2025):

| Stripe status | Workspace |
|---|---|
| `trialing` | plan from metadata, status `trialing`, until the trial end, `auto_renew = !cancel_at_period_end` |
| `active` | plan, `active`, until the item's period end, `auto_renew = !cancel_at_period_end` |
| `past_due` | plan kept, `past_due`, until the item's period end (Stripe has started the next period and is retrying) |
| `incomplete` | nothing changes (the checkout is still being paid) |
| `incomplete_expired`, `canceled`, `unpaid`, `paused` | back to the default (Free) plan, status `expired`; nothing is deleted |

A sync for a subscription that is not the workspace's current one and not newer than it does nothing (an old event).
`trial_used_at` is set the first time a workspace gets a paid plan through any purchase or trial: a card trial, a card
payment, a Google Play purchase (`StoreSubscriptions`), an approved bank transfer (`ManualPayments`). Admin-given plans
do not count. The checkout offers the trial only while it is empty.

## 7. Notices

Through the existing `Notifier` and notification centre:

| Stripe event | Type (kind) | Says |
|---|---|---|
| `customer.subscription.trial_will_end` | `billing.trial_ending` (`billing_renewal_due`) | "Your Pro trial ends on 9 Nov; your card will be charged 120.00 USD." |
| `invoice.paid` for a renewal (`billing_reason = subscription_cycle`) | `billing.renewed` (`billing_renewed`) | "Pro renewed until 9 Dec 2027." |
| `invoice.payment_failed` | `billing.payment_failed` (`billing_payment_failed`) | "We couldn't charge your card for Pro. Update it in Billing; Pro stays on meanwhile." |
| `invoice.payment_action_required` | `billing.action_needed` (`billing_payment_failed`) | "Your bank asks you to confirm the Pro payment in Billing." |
| `customer.subscription.deleted` | `billing.expired` (`billing_expired`) | "Pro has ended; your workspace is on Free. Nothing was deleted." |

The hourly `qistas:billing-notices` keeps skipping subscriptions that renew by themselves (`auto_renew = true`). Every
sentence exists in the five languages.

## 8. Admin

`/admin/billing` gets a **Card payments (Stripe)** panel:

1. Secret key and publishable key (password fields; saved encrypted; shown afterwards only as `sk_live_…a1B2`). A
   test key with a live key, or a key that is not a Stripe secret/publishable key, is refused.
2. **Connect**: account summary from Stripe (business name, country, default currency, charges enabled), then the
   webhook endpoint for `{APP_URL}/webhooks/stripe` is created with the events of sections 6–7 and the library's API
   version, its signing secret stored, and `APP_URL`'s host registered as a payment-method domain (Apple Pay / Google
   Pay). The panel then reads "Ready to take cards · Test mode" or "· Live mode", with the account name. **Reconnect**
   replaces the endpoint; **Disconnect** deletes it and clears the keys.
3. Settings: trial days, promotion codes, collect tax number, automatic tax.
4. Price check: each paid plan's monthly and yearly price with "ready" or the problem from `StripePrices::problems()`.

`/admin/features` lists **Pro by card** (platform scope, group Membership, no plan chips), off by default. Business
pages show the source "Card (Stripe)", status, period, next charge or end, trial end, and a link to the subscription in
the Stripe dashboard. The manual plan change warns when a card subscription is running and offers **Cancel the card
subscription** now (with a prorated refund of the unused time) or at the end of the period.

## 9. Security and failure handling

- Card numbers only ever enter Stripe's frames (PCI SAQ A). The secret key never leaves the server; the publishable key
  is sent only to the checkout and billing pages.
- The browser sends a plan key and a period, never an amount; the price comes from `StripePrices`.
- Every owner action checks that the subscription, session or invoice belongs to the workspace's own Stripe customer;
  anything else is a 404.
- Webhook: signature checked on the raw body; each event id applied once; the workspace comes from
  `CardCustomers::tenantFor`, never from metadata alone (metadata must agree, or the event is logged and ignored).
- A second subscription for a workspace that already has a current paid plan (two tabs paying at the same moment, or a
  Play purchase in between) is cancelled at once and its first payment refunded; the owner gets a notice. The lock and
  the reuse of open sessions make this rare.
- Stripe slow or down: "Card payments are briefly unavailable. Nothing was charged; try again in a minute." Logged
  without card data.
- A webhook that fails on our side answers 500, so Stripe retries for up to three days; applying an event twice is
  harmless.
- Audit log: `billing.card_checkout_started`, `billing.card_subscribed`, `billing.card_changed`,
  `billing.card_period_switched`, `billing.card_cancelled`, `billing.card_resumed`, `billing.card_refunded_duplicate`,
  `admin.card_settings_saved`, `admin.card_connected`, `admin.card_disconnected`, `admin.card_subscription_cancelled`.
  No card data in any of them.
- Demo and test workspaces never reach checkout.

## 10. Testing

- **TDD** for every unit: Pest feature tests against `FakeStripeGateway` for checkout (owner only, switch, ready,
  plan for sale, already subscribed, trial eligibility, open-session reuse, idempotency key), return page (complete,
  open, another workspace), webhook (real signatures made with a test secret, bad signature 400, unconfigured 503,
  replay ignored, every row of section 6, notices of section 7, duplicate guard), management actions (ownership,
  switch preview and both directions, cancel/resume, receipts stream, payment to confirm), admin (key validation,
  connect creates the endpoint and stores its secret, disconnect, settings, price check, business cancel), `StripeAmount`
  (USD, JPY, KWD, minimums), `StripePrices` (lookup reuse, new price after a price change), `BillingState`/API `period`.
- **Mutation checks** on: owner-only, trial once, the status table, signature verification, the duplicate guard, the
  ownership checks.
- **App:** widget tests for the card-subscription status line and the absence of a buy button.
- **PostgreSQL:** the migration on PGlite before pushing.
- **Gates:** Pest (parallel), Pint `--dirty`, Larastan, `flutter analyze`, `flutter test`; translations complete in
  five languages.
- **End to end, locally, with Stripe test mode:** the platform owner puts their test keys in `web/.env` (never in
  chat). Playwright runs: success `4242 4242 4242 4242`, 3-D Secure `4000 0025 0000 3155`, decline
  `4000 0000 0000 9995`, promotion code, trial, cancel/resume, switch both ways, change card, receipt download; Stripe
  CLI or the dashboard's test clock for a renewal and a failed renewal.

## 11. Rollout

1. Ship with **Pro by card** off.
2. The platform owner creates the Stripe account (company in a supported country), pastes the **test** keys in
   `/admin/billing`, presses Connect, switches **Pro by card** on, and buys once with `4242 4242 4242 4242`.
3. In Stripe → Billing → Revenue recovery: Smart Retries on, and "cancel the subscription" after the last retry.
   Optional: Stripe's customer e-mails, Stripe Tax.
4. When Stripe has verified the account: live keys, Connect again.

Documentation: `docs/features/billing.md` (setup steps, what happens when, the table of section 6),
`docs/api/openapi.yaml`, and the app version bump.

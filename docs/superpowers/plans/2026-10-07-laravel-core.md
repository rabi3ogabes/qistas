# Laravel Core Implementation Plan (website · user web app · admin dashboard · API)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (native, in-session) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** A secure, tenant-isolated Laravel application where anyone can open a free account (website or API), use a minimal feature set, upgrade to Pro, and where an admin decides per feature what is Free and what is Pro.

**Architecture:** One Laravel 13 app in `web/`. Pure domain services (`Money`, `ScheduleGenerator`, `Entitlements`, `PaymentAllocator`) are framework-light and unit-tested; HTTP layers (website, `/app`, `/admin`, `/api/v1`) are thin and call them. Tenant scoping is a global scope on every tenant model. SQLite for tests, Postgres (Supabase) for production.

**Tech Stack:** PHP 8.4, Laravel 13, Fortify (auth, 2FA), Sanctum (API), Pest, Blade + Alpine + Tailwind 4 (Vite), bcmath, stripe/stripe-php.

**Spec:** `docs/superpowers/specs/2026-10-07-qistas-platform-design.md`

## Global Constraints

- PHP `^8.4`; Laravel `^13`; tests run on SQLite in memory (`php artisan test`), migrations must also be valid on PostgreSQL (no SQLite-only syntax, no `enum()` columns — use strings with validation).
- Money: `decimal(18,4)`; PHP arithmetic only through `App\Support\Money` (bcmath strings). No floats for money anywhere.
- Primary keys are UUIDs (`HasUuids`). Every tenant-owned table has `tenant_id`.
- Entitlement failures: web → redirect back with an upgrade sheet; API → HTTP `402` and `{"error":{"code","message","feature","limit","used","upgrade_url"}}`.
- Features: `customers` limit 5 / unlimited, `active_contracts` limit 5 / unlimited, `pdf_statements` quota 3 per month / unlimited, `export_csv` off / on, `advanced_reports` off / on, `custom_branding` off / on, `api_tokens` limit 1 / unlimited (Free / Pro defaults).
- Admin area requires `platform_role` in (`admin`,`super_admin`) AND a confirmed 2FA secret.
- Passwords: `Password::min(10)->mixedCase()->numbers()->symbols()` (+ `uncompromised()` outside tests).
- All user-visible strings go through `__()`; `en` and `ar` complete, `ar` renders `dir="rtl"`.
- Transactions are immutable: corrections are `reversal` rows.

## Review Focus

- Free user at the limit tries again via API **and** web form → 402/upgrade sheet, no row created, counter unchanged (Task 6, 7).
- Downgrade from Pro to Free with 40 customers → all 40 still listed/editable, creating #41 blocked (Task 6).
- Tenant A guesses tenant B's UUID on every route (web + API) → 404, never 403/200 (Task 3, 7, 13).
- Payment retried with the same `Idempotency-Key` → one ledger row, same response (Task 8).
- Payment larger than the outstanding balance, zero, negative, or with 5 decimals → rejected with a field error (Task 8).
- Month-end schedules (31 Jan monthly), 1 installment, 60 installments, principal not divisible by count → installments sum exactly to the total (Task 2).
- Suspended tenant / user logs in → blocked with a clear message; API token stops working (Task 4, 13).
- Admin edits the matrix while a user is mid-request → next check uses the new values, no stale cache (Task 5).
- Registering with an existing email gives the same response timing/message class as a new one for password reset (no enumeration) (Task 4).

---

### Task 1: Scaffold, tooling, configuration

**Files:** Create `web/` (composer create-project), `web/.env.example`, `web/config/qistas.php`, `web/tests/Pest.php`, `web/routes/health.php`; Modify root `.gitignore`, `.vercelignore`.

**Interfaces:** Produces `GET /up` (Laravel health) and `config('qistas')` keys: `app_name`, `locales` (`en`,`ar`,`fr`,`es`,`ur`), `rtl_locales` (`ar`,`ur`), `currency_default`, `billing.gateway` (`fake|stripe`).

- [ ] Step 1: Failing test `tests/Feature/HealthTest.php::test_up_endpoint_returns_ok_with_security_headers` asserting `GET /up` is 200 (headers asserted in Task 14).
- [ ] Step 2: Run `php artisan test` → fails (no app). Scaffold with `composer create-project laravel/laravel web`; require `laravel/fortify laravel/sanctum stripe/stripe-php bacon/bacon-qr-code`; dev `pestphp/pest pestphp/pest-plugin-laravel larastan/larastan`; `npm i tailwindcss@4 @tailwindcss/vite alpinejs`.
- [ ] Step 3: Configure `.env.example` (SQLite local, commented Supabase `DB_URL`, `DB_SSLMODE=require`), `phpunit.xml` to SQLite memory, bcrypt rounds 12 / Argon2id when available.
- [ ] Step 4: Run tests → pass. Commit `chore(web): scaffold Laravel 13 with Fortify, Sanctum, Pest`.

### Task 2: Money and schedule generator (pure domain)

**Files:** Create `web/app/Support/Money.php`, `web/app/Domain/Schedule/ScheduleGenerator.php`, `web/app/Domain/Schedule/ScheduleRequest.php`, `shared/schedule-vectors.json`; Test `web/tests/Unit/MoneyTest.php`, `web/tests/Unit/ScheduleGeneratorTest.php`.

**Interfaces:** Produces `Money::add|sub|mul|div|cmp(string,string): string|int` with scale 4, `Money::round2(string): string`; `ScheduleGenerator::generate(ScheduleRequest): array<int,array{number:int,due_date:string,amount:string}>`; `ScheduleRequest(principal, downPayment, markupType: 'none'|'fixed'|'percent', markupValue, count, frequency: 'weekly'|'biweekly'|'monthly', firstDueDate)`.

- [ ] Step 1: Tests: `test_installments_sum_exactly_to_financed_total_plus_markup` over every vector in `shared/schedule-vectors.json` (≥ 12 vectors incl. 100.00/3, 31 Jan monthly → 28 Feb/31 Mar, count 1, count 60, percent markup 10 on 1000, fixed markup, weekly/biweekly, down payment = principal rejected); `test_money_never_uses_float` (1e-7 style inputs rounded half up to 4 dp).
- [ ] Step 2: Run → fail. Step 3: Implement; monthly rule: same day-of-month clamped to month end, computed from the first due date (not from the previous installment). Last installment = total − sum(previous). Step 4: pass. Step 5: commit.

### Task 3: Tenancy, users and registration

**Files:** Create migrations `users`, `tenants`, `tenant_users`, `audit_logs`; models `User`, `Tenant`; `App\Tenancy\BelongsToTenant` (global scope + `creating` fills `tenant_id`), `CurrentTenant` (singleton), middleware `ResolveTenant`; `App\Actions\RegisterTenantOwner`; Test `tests/Feature/Tenancy/*`.

**Interfaces:** Produces `CurrentTenant::get(): Tenant`, `RegisterTenantOwner::handle(array $data): User` (creates user, tenant, owner membership, free subscription, audit row `account.registered`), trait `BelongsToTenant`.

- [ ] Tests: `test_registration_creates_user_tenant_owner_and_free_subscription`; `test_tenant_scope_hides_other_tenants_rows`; `test_binding_another_tenants_uuid_returns_404`; `test_creating_without_tenant_context_throws`.
- [ ] Implement; run; commit.

### Task 4: Authentication (Fortify), 2FA, sessions, suspension

**Files:** `FortifyServiceProvider` (custom views, rate limiters), `App\Http\Middleware\EnsureAccountActive`, `EnsureEmailVerifiedForBilling`, `RequireTwoFactorForAdmins`; views under `resources/views/auth/*`; Test `tests/Feature/Auth/*`.

**Interfaces:** Produces named routes `login`, `register`, `password.request`, `two-factor.login`, `verification.notice`; `account.suspended` page.

- [ ] Tests: weak password rejected; 6th failed login in a minute → 429; unknown email and wrong password give the identical message; password reset request responds identically for unknown email; suspended user cannot sign in; session id changes after login; admin without confirmed 2FA is redirected to 2FA setup on `/admin`.
- [ ] Implement; run; commit.

### Task 5: Entitlements (the free/pro engine)

**Files:** Create migrations `plans`, `features`, `plan_features`, `subscriptions`, `tenant_overrides`, `usage_counters`; `App\Features\Feature` (backed enum + metadata: type, unit, default free/pro), `App\Models\{Plan,PlanFeature,Subscription,TenantOverride,UsageCounter}`, `App\Entitlements\Entitlements`, `App\Entitlements\Entitlement` (value object), `LimitReached`/`FeatureLocked` exceptions, `Console\SyncFeatures` (`qistas:sync-features`), `PlanSeeder`; Test `tests/Feature/Entitlements/*`.

**Interfaces:** Produces `Entitlements::for(Tenant): self`, `->check(Feature): Entitlement` with `enabled(): bool`, `limit(): ?int`, `used(): int`, `remaining(): ?int`, `unlimited(): bool`; `->assertCanCreate(Feature)` throws `LimitReached`; `->consume(Feature, int $n=1)` for quotas; `->toArray()` (the API payload shape in the spec); route middleware `feature:export_csv`.

- [ ] Tests: free defaults match the Global Constraints; pro is unlimited; override beats plan and expires; admin edit is visible on the very next call (no stale cache); downgrade keeps data but blocks creation; quota resets on month change; `toArray()` shape.
- [ ] Implement; run; commit.

### Task 6: Customers (limit-gated)

**Files:** migration `customers`; model `Customer` (BelongsToTenant, encrypted `national_id`); `CustomerPolicy`; Form Requests; `App\Actions\CreateCustomer`; Test `tests/Feature/Customers/*`.

**Interfaces:** Produces `CreateCustomer::handle(Tenant,array): Customer` (asserts the `customers` limit inside a DB transaction with a row lock on the tenant to stop races).

- [ ] Tests: create up to the limit; the next → `LimitReached`; two concurrent creations at limit−1 create only one; search by name/phone; national id stored encrypted (raw DB value ≠ plaintext).
- [ ] Implement; run; commit.

### Task 7: Contracts and installments

**Files:** migrations `contracts`, `installments`; models; `App\Actions\CreateContract` (uses `ScheduleGenerator`, asserts `active_contracts`); `ContractPolicy`; Test `tests/Feature/Contracts/*`.

- [ ] Tests: contract creation stores installments equal to the generator; at limit → blocked; settled contracts do not count as active; another tenant's customer id → 404/validation error.
- [ ] Implement; run; commit.

### Task 8: Transactions (payments) and allocation

**Files:** migration `transactions` (unique `(tenant_id, idempotency_key)`), model (no update/delete: model events throw), `App\Domain\Ledger\PaymentAllocator`, `App\Actions\RecordPayment`, `VoidTransaction`; Test `tests/Unit/PaymentAllocatorTest.php`, `tests/Feature/Payments/*`.

**Interfaces:** Produces `RecordPayment::handle(Contract, string $amount, string $method, ?string $idempotencyKey): Transaction`; allocation oldest-due first; contract becomes `settled` when outstanding = 0.

- [ ] Tests: partial then full payment; overpayment, zero, negative, > 4 dp rejected; same idempotency key → one row; void creates a `reversal` and re-opens installments; transaction rows cannot be updated or deleted.
- [ ] Implement; run; commit.

### Task 9: Dashboard metrics

**Files:** `App\Reports\DashboardMetrics` (`outstanding`, `collected_this_month`, `overdue`, `active_customers`, `due_today[]`, `collection_rate`), Test `tests/Feature/Reports/DashboardMetricsTest.php`. Commit.

### Task 10: Website and design system

**Files:** `resources/css/app.css` (Qistas tokens as Tailwind 4 `@theme`), Blade components (`x-button`, `x-field`, `x-card`, `x-badge`, `x-meter`, `x-upgrade-sheet`), layouts `layouts/site.blade.php`, `layouts/app.blade.php`, pages home, pricing (**rendered from `plans` + `plan_features`**), features, login/register; language switcher; Test `tests/Feature/Site/*`.

- [ ] Tests: home 200; pricing shows exactly the features the admin enabled for each plan and flips when the matrix changes; `?lang=ar` renders `dir="rtl"`; viewport meta has `viewport-fit=cover`; inputs ≥ 16px class.
- [ ] Implement; run; commit.

### Task 11: User web app (`/app`)

**Files:** controllers + views for dashboard, customers, contracts (wizard with live schedule preview via `POST /app/contracts/preview`), payments, settings (profile, security: 2FA, sessions, password), billing page; Test `tests/Feature/App/*`.

- [ ] Tests: guest redirected; usage meter text "3 of 5"; limit hit shows the upgrade sheet; statements consume `pdf_statements` quota; CSV export 402/locked for Free, works for Pro.
- [ ] Implement; run; commit.

### Task 12: Billing

**Files:** `App\Billing\{PaymentGateway,FakeGateway,StripeGateway,Checkout}`, `BillingController`, `StripeWebhookController`, migration `webhook_events`; Test `tests/Feature/Billing/*`.

**Interfaces:** `PaymentGateway::checkout(Tenant, Plan, string $interval): Checkout` (`url`, `ref`), `::verifyWebhook(string $payload, string $signature): array`.

- [ ] Tests: fake checkout → success activates Pro and entitlements change immediately; cancel at period end keeps Pro until the end then Free; Stripe webhook with bad signature → 400; same event id twice → processed once; unverified email cannot start checkout.
- [ ] Implement; run; commit.

### Task 13: Admin dashboard (`/admin`)

**Files:** controllers + views: overview, accounts (search, filter, suspend/reactivate, grant Pro, override feature), **plans & features matrix editor**, subscriptions, audit log; `AdminPolicy`; Test `tests/Feature/Admin/*`.

- [ ] Tests: non-admin 404; admin without 2FA redirected; matrix save changes `Entitlements` for a live tenant at once; every change writes an audit row with before/after; suspend blocks user and API token; cannot demote the last super admin.
- [ ] Implement; run; commit.

### Task 14: REST API v1

**Files:** `routes/api.php`, `App\Http\Controllers\Api\V1\*`, API Resources, `ApiError` renderer, OpenAPI `docs/api/openapi.yaml`; Test `tests/Feature/Api/*`.

**Interfaces:** `POST /api/v1/auth/register|login|logout`, `GET /api/v1/me` (user, tenant, entitlements), `GET /api/v1/plans`, `customers`, `contracts` (+ `POST contracts/preview`), `POST contracts/{id}/payments` (honours `Idempotency-Key`), `GET dashboard`, `POST billing/checkout`.

- [ ] Tests: every endpoint has a happy path, an auth failure, a validation failure and a cross-tenant 404; limit failure shape is exactly the Global Constraint; token abilities/expiry; rate limit 429.
- [ ] Implement; run; commit.

### Task 15: Security hardening

**Files:** `App\Http\Middleware\SecurityHeaders` (CSP nonce), `config/session.php`, `config/cors.php`, `App\Support\Audit`; Test `tests/Feature/Security/*`.

- [ ] Tests: headers present on web and API responses; CSP has a nonce and no `unsafe-inline` for scripts; session cookie flags; audit rows for login, plan change, export; mass-assignment attempt on `tenant_id`/`platform_role` ignored; `composer audit` clean.
- [ ] Implement; run; commit.

### Task 16: Supabase SQL, CI, docs

**Files:** `supabase/migrations/…_app_role_and_rls.sql`, `docs/SUPABASE.md`, `.github/workflows/ci.yml` (add Laravel job), `web/README.md`; Test: CI job green locally via `php artisan test`.

- [ ] RLS SQL for every tenant table (role `qistas_app`, policies on `current_setting('app.tenant_id', true)::uuid`), Laravel sets it per request on `pgsql`; labelled unexecuted. Commit.

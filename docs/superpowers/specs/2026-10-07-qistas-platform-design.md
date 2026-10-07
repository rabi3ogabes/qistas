# Qistas Platform — Design (Website · Dashboard · API · App)

Date: 2026-10-07 · Status: building (decisions below came directly from the owner) · Supersedes the "stack" section of the master prompt only where noted.

## 1. Owner decisions (verbatim intent)

* **PHP** for the main website and **Laravel** for the dashboard; **Flutter** for the app; **Supabase** (Postgres) for the database.
* Security and user experience come first, on the website, the dashboard and the app.
* Anyone can **create a free account** with a minimal feature set, from the website **or** from the app, and use the same account on both.
* **Pro** is paid, unlimited, and has every feature.
* The **admin chooses which features are Free and which are Pro** (and the limits), without a code change.
* Professional, luxury, modern, smart.

## 2. Architecture

```
                 ┌─────────────────────────── web/ : ONE Laravel 13 application (PHP 8.4) ───────────────────────────┐
 Visitors ─────► │ Website (/ , /pricing, /features)   User web app (/app)   Admin dashboard (/admin)   REST API /api/v1 │
 Flutter app ──► │                              ▲ the same domain services, policies and entitlement checks ▲            │
                 └──────────────────────────────────────────────┬─────────────────────────────────────────────────────┘
                                                                │ pgsql (SSL), pooled
                                                       Supabase Postgres  (+ Storage later)
```

Decisions and why:

1. **One Laravel codebase serves the website, the user web app, the admin dashboard and the API.** "Main website in PHP" and "Laravel for the dashboard" are both satisfied (Laravel is PHP) and there is a single place where authentication, tenancy and entitlements are enforced. Two PHP code bases would duplicate the part that must never disagree. *Assumption flagged for the owner.*
2. **The Flutter app never talks to the database directly.** It calls `/api/v1` with a Sanctum token. Free/Pro rules, limits and tenant isolation therefore cannot be bypassed by a modified client.
3. **Supabase is managed Postgres.** Laravel owns the schema (migrations are portable: Postgres in production, SQLite for fast tests). A hand-written `supabase/` SQL adds the parts Laravel cannot express: a non-owner application role and Row Level Security as a second line of defence behind Laravel's tenant scoping.
4. **Entitlements are data, not code branches.** The code declares *which features exist* (`App\Features\Feature`); the database stores *which plan gets what* (`plan_features`). The admin edits the matrix; every check reads it.
5. **Billing is a gateway interface** (`fake` for development and tests, `stripe` for web checkout, `manual` for admin grants). Mobile in-app purchase (App Store / Google Play) is required by store rules for unlocking digital features inside the apps; it plugs into the same `subscriptions` table and is out of scope until store accounts exist.

## 3. Roles and areas

| Role | Where | Notes |
|---|---|---|
| Visitor | website | register, login, read pricing |
| Tenant owner (every new account) | `/app`, API | one workspace per account; roles `manager/accountant/collector/viewer` exist in the schema, invites are a Pro feature (later) |
| `admin` / `super_admin` | `/admin` | **mandatory 2FA**, separate from tenant sessions, every action audited |

## 4. Plans and entitlements

* Plans: `free` (default for every new tenant), `pro`; more can be added in the admin.
* Feature types: **toggle** (on/off), **limit** (max count, `null` = unlimited), **quota** (per calendar month, `null` = unlimited).
* Resolution order for one tenant and feature: **active tenant override → plan value → denied**. Overrides carry a reason and an optional expiry.
* **Downgrade never deletes data**: records above a limit stay readable; only *creating* more is blocked.
* Limit failures are a first-class result: the web shows an upgrade sheet; the API answers `402` with `{"error":{"code":"limit_reached"|"feature_locked","feature":"customers","limit":5,"upgrade_url":"…"}}`.

Initial catalogue (enforced in v1) and default Free/Pro values, all editable by the admin:

| Feature key | Type | Free | Pro |
|---|---|---|---|
| `customers` | limit | 5 | unlimited |
| `active_contracts` | limit | 5 | unlimited |
| `pdf_statements` | quota / month | 3 | unlimited |
| `export_csv` | toggle | off | on |
| `advanced_reports` | toggle | off | on |
| `custom_branding` | toggle | off | on |
| `api_tokens` | limit | 1 | unlimited |

More features (investors, reminders, multi-currency, backups, AI, customer portal…) are added to the enum as their modules ship; the admin matrix shows whatever the code declares.

## 5. Domain (v1)

Customers → Contracts (scheduled / cash / open, principal, down payment, markup fixed % amount / percent / interest-free, frequency weekly · bi-weekly · monthly, first due date) → Installments (generated, rounded to 2 dp, last one absorbs the residual) → Transactions (immutable ledger: payment, refund, discount, adjustment, **reversal**; allocated oldest-installment-first; idempotency key per tenant). Money is `decimal(18,4)` in the database and computed with `bcmath` strings in PHP, never floats. The schedule generator has **shared test vectors** (`shared/schedule-vectors.json`) that the PHP and Dart implementations must both pass.

## 6. Security requirements (acceptance, not aspiration)

* Passwords: min 10, mixed case, number, symbol, checked against known breaches in production; Argon2id when available. Login throttling per email+IP, lockout after repeated failures, generic error messages (no account enumeration), timing-safe.
* Email verification required before upgrading or exporting. TOTP 2FA available to everyone, **required for admins**; recovery codes; sessions listed with remote sign-out; session id rotated on login; cookies `Secure`, `HttpOnly`, `SameSite=Lax`.
* Tenant isolation: global scope on every tenant model, route-model binding through the scope (cross-tenant ids return 404), policies, and tests that attempt every cross-tenant read/write. Postgres RLS mirrors this.
* CSRF on web, Sanctum tokens for the API (named per device, expiring, revocable), per-route rate limits, idempotency keys on money endpoints.
* Security headers on every response: CSP with per-request nonce, HSTS, `X-Content-Type-Options`, frame denial, referrer, permissions policies.
* National ID and other sensitive fields encrypted at rest (`encrypted` cast); logs never contain secrets; mass-assignment protected; all input validated by Form Requests; all output escaped.
* Audit log (who, what, when, IP, user agent) for sign-ins, plan and entitlement changes, suspensions, exports, payments voided.
* Webhooks verified by signature and processed once (event table).
* `composer audit` and `npm audit` in CI.

## 7. UX requirements

Luxury and calm: the Qistas design tokens (navy, champagne gold, ivory) drive the website, dashboard and app; light and dark; English and Arabic with full RTL at launch of v1, French/Spanish/Urdu next (keys are in lang files from day one). Mobile-first web (`dvh`, safe areas, 16 px inputs, tap feedback, no hover traps), skeleton and empty states, inline validation, optimistic confirmation, one-tap upgrade sheet whenever a limit is hit, accessibility checked with axe. Free users always see how much they have used ("3 of 5 customers").

## 8. Out of scope for this milestone (tracked, not forgotten)

Investors, products, expenses, reminders engine, WhatsApp/SMS sending, PDF engine, Google Sheets/BYO database storage modes, backups, AI assistant, OCR, collector mode, customer/investor portals, multi-branch, store billing, theme-engine API in Laravel (the static Theme Studio stays the prototype), translations for FR/ES/UR beyond the shared keys.

## 9. Verification strategy

TDD with Pest for every Laravel behaviour (unit for money and schedules, feature for HTTP, API contract tests); `flutter analyze`, `flutter test` and a web build for the app; shared schedule vectors; axe on the main web pages; CI on every push. Postgres-specific parts (RLS, `pgsql` connection) cannot be executed on this machine without credentials and are labelled **unexecuted** until a Supabase project is connected.

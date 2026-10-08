# Qistas Feature Programme: Build Brief

> **Audience:** Claude (Claude Code), building inside the existing repository.
> **Owner:** the Qistas product owner. **Status:** draft for owner review. Nothing here is built yet.
> **Goal:** turn Qistas from an instalment *tracker* into the app that **collects the money for the owner**, so that a business owner chooses it over the alternatives, pays the membership every month, and never wants to leave.
> **One rule above all others:** *every feature has an admin on/off switch* (Part 3). A feature that cannot be switched off from the admin console is not finished.

---

## 0. How to use this document

**Read it completely before writing code.** Then work in this order.

1. **Do not start implementing until the owner has approved this brief** (or the part of it they name). Use the `brainstorming` skill's gates: for each epic, confirm the design in chat, write a short plan with `writing-plans`, then build with `test-driven-development`. Hidden complexity upgrades the process; say so and stop.
2. **Build one epic at a time, in the order of Part 9.** Inside an epic build one feature at a time. One feature = one commit (or a small series), tests green, pushed.
3. **Foundation first (Epic 0).** Nothing else ships before the feature-control system, file storage, PDF service, settings store, scheduler, and usage metering exist (Epic 0). They are small, and every later feature stands on them.
4. **Ask only when a choice is the owner's** (Part 10 lists them with my recommendation). Everything else: decide, state the decision in the commit message, move on.
5. **Never ask the owner to paste a secret in chat.** Secrets (gateway keys, WhatsApp tokens, storage keys, AI keys) are set by the owner in the Vercel project settings. You name the variable and what it is for.
6. **Never create accounts on, or press the demo buttons of, the live site.** Verify locally and with read-only requests.
7. **After every commit, push to GitHub** and report the live link. If your harness has a note about how the owner's credentials work (they are in the owner's own terminal, not in your shell), follow it.
8. **Report honestly.** If a test fails, say so with the output. If a step was skipped, say so. If something cannot be verified without a phone, a gateway account, or WhatsApp approval, say exactly that.

**Quality bar for everything (from the owner's standing instructions): luxury, modern, smart, professional, easy.** Premium and refined, never generic; efficient; polished and production-ready, with no placeholders; the shortest path for the user. Use the installed design skills for any UI (`impeccable`, `frontend-design`, `ui-ux-pro-max`, `emil-design-eng`, `animate`), and `output-skill` so nothing is truncated.

---

## 1. Product frame

**Who pays.** The owner of a shop that sells **on its own credit** (phones, electronics, furniture, appliances, cars, gold, school fees…) in the Gulf, Levant, North Africa, and Pakistan-style markets. Phone-first, often one to five staff, Arabic first and then English, French, Spanish, and Urdu.

**What they compare us with.**
| Group | Examples | Gap we own |
|---|---|---|
| Ledger apps | Khatabook, OkCredit, Vyapar | Balance books, not instalment contracts; not Arabic-first |
| Buy-now-pay-later | Tabby, Tamara, MISpay, valU, Aman | Merchant pays about 3–4% per sale and loses the customer relationship; cannot serve own-credit sales |
| ERP modules | Odoo instalment and post-dated-cheque modules, SMACC, Nebim | Heavy, desktop-first, no WhatsApp or mobile |
| Lending cores | LoanPro, Mambu | Enterprise-sized |

**Positioning.** *Sell on your own credit. Keep your margin and your customer. Collect like a bank.*

**Why they pay every month.** Qistas must visibly make or save them more than it costs: reminders that bring money in earlier, fewer forgotten instalments, risk-aware terms, repeat sales, and a monthly receipt that proves it. Their ledger history is also valuable to them, and we never hold it hostage: read-only access and export always survive a lapsed membership.

**Principles.**
1. **We are software, not a lender.** Qistas never lends, never holds or moves customer money, never guarantees. Pay links send money straight to the owner's own gateway account.
2. **Advisory, never automatic, for anything about people.** Scores, risk, and offers suggest. A human decides. No silent blocking.
3. **The ledger is sacred.** Money is only written through the existing actions; corrections are reversals; nothing is edited in place.
4. **Honest numbers.** Anything we estimate says it is an estimate and shows how. Anything we attribute ("collected within 48 hours of a reminder") is labelled as exactly that, never as proof of cause.
5. **Consent and privacy by default** (Saudi PDPL and similar): record opt-in for messaging, encrypt national IDs, private files, owner-only data.
6. **Everything is switchable** by the admin, and off means *off*, safely.

**Non-goals.** Lending or financing; holding funds; locking customers' phones or devices; sharing customer data between shops (a later, consented, legally reviewed phase); inventory/POS; payroll; general accounting.

---

## 2. The codebase you must respect

Verified against the repository on 2026-10-08. Re-check anything you rely on.

| Area | Fact |
|---|---|
| Stack | Laravel 13, PHP 8.4, Pest, Pint, Larastan; Flutter app in `app/` (Riverpod, go_router, dio); Supabase Postgres (transaction pooler); Vercel container Function (no persistent disk, no always-on worker). Live: https://qistas-puce.vercel.app |
| Repo | `web/` (website, `/app` web app, `/admin`, `/api/v1`), `app/` (Flutter), `docs/`, `shared/schedule-vectors.json`, `.github/workflows` |
| Tenancy | Every business table uses `App\Tenancy\BelongsToTenant` (fails closed with no tenant). UUID primary keys (`HasUuids`). Roles in `App\Tenancy\TenantRole`: owner, manager, accountant, collector, viewer. `canWrite()` = not viewer; `canDelete()` = owner or manager |
| Money | `App\Support\Money` (bcmath strings, 2 decimals). Dart: `lib/core/money.dart` (BigInt cents). Never a float anywhere |
| Ledger | `transactions` are immutable (model throws on update/delete). Written only by `App\Actions\{RecordPayment, VoidTransaction, CreateContract}`. Corrections are reversals. Payments are allocated oldest-instalment-first by `App\Domain\Ledger\PaymentAllocator`; `ContractSettlement` settles a contract when nothing is left. `RecordPayment` is idempotent by key |
| Schedule | `App\Domain\Schedule\ScheduleGenerator` is the source of truth; `app/lib/domain/schedule_generator.dart` is a port. **Both must pass `shared/schedule-vectors.json`.** Contracts and instalments are fixed after creation; instalments change only through payments |
| Statuses | contract: `active`, `settled`, `cancelled` (plus the derived "late" view). instalment: `pending`, `partial`, `paid`. Audit: `App\Support\Audit::record(action, subject, changes)` (never put secrets in `changes`) |
| Entitlements | `App\Entitlements\{Feature, FeatureType, Entitlements, PlanSettings, Entitlement}`. `Feature` enum is the catalogue (the code declares a feature exists; `plan_features` says which plan has it; `tenant_overrides` grants/denies for one workspace with reason and expiry). Types: `Toggle`, `Limit` (live count), `Quota` (per calendar month, atomic `consume`). Resolution: override, then plan, then denied. **Nothing is cached** so an admin edit applies at the next call. Route guard alias `feature:<key>` (`EnsureFeature`). `FeatureLocked`/`LimitReached` become HTTP 402 and the app's upgrade sheet |
| Declared but not built | Plan toggles `pdf_statements` (quota), `export_csv`, `advanced_reports`, `custom_branding` exist in the catalogue and on the pricing page, **but nothing implements them.** Epic F builds them so the pricing page tells the truth |
| API | `routes/api.php`, `/api/v1`, Sanctum bearer tokens. Errors have one shape (`App\Http\ApiErrors`). `docs/api/openapi.yaml` is **drift-tested**: update it with every endpoint |
| Admin | `routes/admin.php` under `/admin`, middleware group `admin` (signed in, active, platform staff else 404, 2FA confirmed). Today it has the overview and test-workspace tools. Layout `resources/views/components/layouts/admin.blade.php`, styles `resources/css/admin.css` |
| i18n | Web: `web/lang/app/{ar,fr,es,ur}.json` (English is the key; `scripts/merge_translations.py`; `AppFirstTranslationLoader` makes these override framework strings). App: `app/assets/i18n/*.json`, `python tool/i18n.py --missing/--sync`; a Dart test fails on any missing sentence. Five languages everywhere, RTL for Arabic and Urdu. Arabic count wording must be **count-neutral** ("الأقساط المستحقة اليوم: :count"), and English needs separate 1-vs-many strings |
| App quality gates | `flutter analyze` must report **zero** issues (strict, including infos). `flutter test` green. Screens are viewable without a device: `QISTAS_SCREENSHOTS=build/shots/x flutter test test/visual` (real fonts and shadows). Test lessons are in `app/README.md` and the project memory |
| DB security | After migrations run `php artisan qistas:secure-database` (row-level security on every table, API roles cut off). New tables must be covered; add a test that no table is left without RLS where the command can run |
| Infra gaps you must plan for | **(a)** No persistent disk → files need S3-compatible object storage (Supabase Storage or Cloudflare R2), private, signed URLs. **(b)** No worker → scheduled jobs and the queue run from a cron-called endpoint (`CRON_SECRET`, Vercel Cron) executing `schedule:run` and `queue:work --stop-when-empty --max-time=50`. **(c)** E-mail is not configured (no SMTP): do not make any feature depend on e-mail; use WhatsApp or in-app first. **(d)** Billing gateway is `fake`; Stripe/local gateways are not connected |
| Mobile store rule | No purchase links inside the iOS/Android app. Plans are bought on the website |
| Commands | `php vendor/bin/pest`, `vendor/bin/pint --dirty` (never bare), `vendor/bin/phpstan analyse`; `flutter analyze`, `flutter test`. Pipe Pest output through a summariser: failures print huge HTML |

---

## 3. Feature control: the on/off system (build this first)

The owner's requirement: **the admin can press on/off to enable or disable each feature.** This is the spine of the programme; every feature below registers with it.

### 3.1 Three layers, one resolution

A feature is usable by a workspace only if **all** of these agree:

| Layer | Who controls it | Meaning |
|---|---|---|
| **Platform state** (new) | Platform admin | `off` (nobody), `beta` (only workspaces with an active override), `on` (everyone the plan allows) |
| **Plan** (existing `plan_features`) | Platform admin | Which plans include it, and the limit or quota |
| **Workspace override** (existing `tenant_overrides`) | Platform admin, with reason and expiry | Grants or denies for one workspace. During `beta`, an active *enabled* override is what lets a workspace in |
| **Owner preference** (new, `tenant_settings`) | Workspace owner | "Use this" and its policy (e.g. late-fee amounts). Both the admin's and the owner's switch must be on for behaviour to run |

Resolution inside `Entitlements::entitlement()`, first match wins:
1. Platform state `off` → disabled, **reason `platform_off`**.
2. Platform state `beta` and no active enabled override for this workspace → disabled, reason `platform_off`.
3. Existing logic: workspace override → plan value → denied (**reason `plan_locked`**).
4. Otherwise enabled, reason `on`.

`Entitlement::toArray()` and `/api/v1/me` gain `status: on | plan_locked | platform_off` per feature. **Clients differ on purpose:** `plan_locked` shows the feature locked with an upgrade path (402 sheet); `platform_off` shows nothing at all, or "not available right now" if the user reached it by an old link. The two must never be confused: do not show "Upgrade" for something the admin switched off.

### 3.2 Data

```
platform_features
  feature_key  string PK (matches the Feature enum value)
  state        enum('off','beta','on')  NOT NULL DEFAULT 'off'      -- new features ship dark
  reason       text NULL                -- required when turning OFF a feature that has activity
  changed_by_user_id uuid NULL, changed_at timestamptz
  created_at, updated_at
feature_usage_daily
  tenant_id uuid, feature_key string, day date, hits int   UNIQUE(tenant_id, feature_key, day)
tenant_settings
  tenant_id uuid, key string, value jsonb   UNIQUE(tenant_id, key)    -- validated per feature by a schema in code
```
`feature_usage_daily` is incremented by `FeatureUsage::hit(Feature $f)` on a feature's *successful primary action* (counts only, never content). It feeds the admin "used by N workspaces in 30 days" badge. A row for every enum case is created by a migration/seeder with `state='off'`; the catalogue-integrity test (3.6) fails if one is missing.

### 3.3 Code contract: every `Feature` case declares

Extend the `Feature` enum (the existing comment says the admin matrix lists whatever is declared here). Each new case must define, and a test must enforce:
- `type()` (Toggle/Limit/Quota), `label()`, `summary()` strings, translated in 5 languages;
- `group()` (the epic), `description()` (one sentence for the admin card), `dependsOn(): Feature[]`;
- `defaultFor($planKey)` (the existing exhaustive match; use the suggested plan matrix in Part 4);
- **`offBehaviour(): string`**: one plain sentence describing what happens to existing data and running processes when switched off (shown in the confirmation dialog);
- `scope(): 'workspace' | 'platform'` (billing capabilities in Epic H are `platform`: switchable, but not assigned to plans).

### 3.4 Enforcement points (the "off means off" rules)

1. **HTTP:** every route belonging to a feature carries `feature:<key>`. `EnsureFeature` is extended: a `plan_locked` result keeps today's behaviour (402). A `platform_off` result returns **403** with code `feature_unavailable` (add to `ApiErrors` and `docs/api/openapi.yaml`).
2. **Actions/domain:** each Action that performs a feature's work calls `Entitlements::for($tenant)->assertEnabled(...)` itself, so a queued job or a console command cannot bypass the route guard.
3. **Background jobs:** every scheduled/queued job re-checks the switch **at run time** (not when queued). A message queued yesterday is dropped (logged as `skipped: feature_off`) if the feature is off when it is due.
4. **Clients:** the Flutter app and the web app read `/me` and hide anything not `on`. If any call returns `feature_unavailable`, the app refreshes the account and removes the UI. Web Blade uses `@feature('key')`.
5. **Data is never deleted by a switch.** Off hides *new* actions and stops *running* processes. Financial records created while the feature was on stay visible read-only: ledger lines, fees already charged, written-off status, amendments, signed documents, certificates. Uploaded files remain downloadable by the owner. Re-enabling restores everything exactly.
6. **Dependencies:** a feature whose dependency is not enabled is reported `platform_off` with detail `dependency:<key>`. The admin UI explains it.

### 3.5 Admin console: "Feature control" (`/admin/features`)

A flagship screen; it should feel like a cockpit, not a settings table. Uses the admin layout and tokens (navy, gold, ivory; Cormorant headings, Geist body). Mobile-first, RTL, dark mode, keyboard accessible, `prefers-reduced-motion` respected.

**Layout**
- **Summary bar (sticky):** `12 on · 3 beta · 21 off`; search; filters (state, epic, plan, "in use", "has dependency problem"); a **Presets** menu.
- **Epics as collapsible sections** (A to I). Each shows its on/off counts.
- **Feature card**
  - Title, one-line description, size/type badge (Toggle/Limit/Quota).
  - **Segmented control: Off · Beta · On.** (`role="radiogroup"`, arrow-key navigation, optimistic update, 6-second **Undo** toast.)
  - **Plan chips** (Free · Pro · Business…): click toggles the plan's inclusion (writes `plan_features`); Limit/Quota types open a small inline editor for the number or monthly allowance.
  - **Usage badge:** "Used by 23 workspaces in the last 30 days" (from `feature_usage_daily`).
  - **Dependency badges:** "Needs: Message channel" (greyed with an explanation if unmet); "Required by: Auto-reminders".
  - **Details drawer:** the `offBehaviour()` text, full description, a **Beta workspaces** list (add by workspace id or owner e-mail, with reason and optional expiry; this writes `tenant_overrides`), and the **change history** (from the audit log: who, when, from → to, reason).
- **Turning a feature OFF that is in use** (any `feature_usage_daily` in 30 days, or any running process) opens a confirmation: shows the `offBehaviour()` sentence and the usage count, **requires a reason**, and warns about dependents ("Auto-reminders and Promises depend on this and will stop"). Turning something ON is one click.
- **Presets** (each shows a **diff preview** before applying, never applies silently): *Dark launch* (all off), *Essentials* (Epics B, C basics, F), *Full programme* (everything), *Restore previous* (the snapshot taken before the last preset).
- **Kill switch:** a prominent "Pause all automation" control that sets the Autopilot, Promises, Briefs, and Pay-link features to `off` in one action (with the same diff and reason), because those are the ones that touch customers.

**Endpoints (web, admin middleware, audit-logged, CSRF, rate-limited)**
```
GET   /admin/features
PUT   /admin/features/{key}/state            { state, reason? }
PUT   /admin/features/{key}/plans/{plan}     { enabled, limit? }
POST  /admin/features/{key}/beta             { workspace, reason, expires_at? }
DELETE /admin/features/{key}/beta/{override}
POST  /admin/features/presets/{preset}/apply   (+ GET …/preview)
```
CLI parity for operations: `php artisan qistas:features {list|state <key> <off|beta|on> --reason=|plan <key> <plan> <on|off>}`.

Every change calls `Audit::record('feature.state_changed' | 'feature.plan_changed' | 'feature.beta_granted' | 'feature.preset_applied', …)` with before/after. Only `super_admin` may change `platform_features`; `admin` may view. (Confirm with the owner; see Part 10.)

### 3.6 Tests for the switch system (non-negotiable)

- **Catalogue integrity** (Pest dataset over `Feature::cases()`): every case has a `platform_features` row; non-empty `description()` and `offBehaviour()`; labels translated in all 5 languages; `defaultFor('free')`, `('pro')` defined; new cases default platform state `off`; every route registered for the feature has the `feature:<key>` middleware (scan the route collection by naming convention `feature.<key>.*`).
- **Resolution matrix:** platform off/beta/on × plan included/not × override none/granted/denied produces exactly the status table in 3.1. Plan-locked is 402, platform-off is 403 `feature_unavailable`.
- **Off means off:** for each feature, with the state `off`: its API calls are refused; its scheduled jobs do nothing; existing data is still readable where 3.4 says it must be; turning it back on restores behaviour.
- **Beta:** only a workspace with an active enabled override gets in; an expired override stops working.
- **Tenancy:** an override in workspace A never affects workspace B.
- **Admin:** guests get the login redirect; non-staff get 404; staff without confirmed 2FA are challenged; `admin` (non-super) cannot change state; every mutation writes an audit row; usage badge counts correct.
- **Dependency:** enabling a feature whose dependency is off reports the dependency; turning a dependency off flags dependents.
- **Mutation check:** break the resolution order and the guard on a route and confirm the tests fail.

---

## 4. Catalogue at a glance

**Types:** T = Toggle, L = Limit, Q = Quota (per calendar month). **Sizes:** S = days, M = 1–2 weeks, L = weeks or needs an outside account. **Plans** show the *suggested* defaults for `Feature::defaultFor()`; the admin changes them at any time. Every feature **ships with platform state `off`**.

| ID | Key | Feature | T | Size | Needs | Free | Pro | Business |
|---|---|---|---|---|---|---|---|---|
| **A** | | **Contract terms** | | | | | | |
| A1 | `payday_schedule` | Payday-aligned due dates | T | S | | ✓ | ✓ | ✓ |
| A2 | `holiday_schedule` | Ramadan, Eid, Hijri and weekend-aware dates | T | M | | | ✓ | ✓ |
| A3 | `markup_styles` | Flat / reducing / fixed-profit markup with total-cost comparison | T | M | | | ✓ | ✓ |
| A4 | `contract_items` | Product, serial/IMEI, cost price | T | S | | ✓ | ✓ | ✓ |
| A5 | `guarantors` | Guarantors (كفيل) and ID documents | T | M | 0.2 | | ✓ | ✓ |
| A6 | `contract_documents` | Contract PDF with QR and e-signature | T | M | 0.2, 0.3 | 3/mo | ✓ | ✓ |
| A7 | `smart_offer` | Terms suggested from the customer's score | T | M | E1 | | ✓ | ✓ |
| **B** | | **Collecting money** | | | | | | |
| B1 | `payment_targeting` | Choose which instalment a payment covers; paid-ahead | T | S | | ✓ | ✓ | ✓ |
| B2 | `payment_proof` | Proof of payment photo and reference | T | S | 0.2 | ✓ | ✓ | ✓ |
| B3 | `early_settlement` | Pay-off quote and one-tap close | T | M | | ✓ | ✓ | ✓ |
| B4 | `cheque_vault` | Post-dated cheque lifecycle | T | M | 0.2 | | ✓ | ✓ |
| B5 | `customer_portal` | Customer link, no install | T | M | | | ✓ | ✓ |
| B6 | `pay_links` | Pay link or QR to the owner's own gateway | T | L | gateway | | | ✓ |
| **C** | | **Late customers** | | | | | | |
| C1 | `late_fees` | Late-fee and grace policy | T | M | | | ✓ | ✓ |
| C2 | `reschedule` | Reschedule / extend / skip a month | T | M | | ✓ | ✓ | ✓ |
| C3 | `collections_stages` | Stages and contact log | T | M | | | ✓ | ✓ |
| C4 | `watchlist` | Customer watchlist | T | S | | ✓ | ✓ | ✓ |
| C5 | `write_off` | Write-off and repossession status | T | M | | ✓ | ✓ | ✓ |
| **D** | | **Autopilot and communication** | | | | | | |
| D1 | `message_channel` | WhatsApp channel and customer consent | T | M | | | ✓ | ✓ |
| D2 | `auto_reminders` | Reminder cadence | **Q** `reminder_messages` | L | D1 | | 300/mo | 2,000/mo |
| D3 | `promises` | One-tap promises to pay | T | M | D1, D2 | | ✓ | ✓ |
| D4 | `owner_briefs` | Weekly brief and monthly "paid for itself" receipt | T | M | | in-app | ✓ | ✓ |
| **E** | | **Intelligence** | | | | | | |
| E1 | `customer_score` | Qistas Score | T | M | | | ✓ | ✓ |
| E2 | `risk_radar` | Likely-late radar | T | M | E1 | | ✓ | ✓ |
| E3 | `next_sale_nudges` | Next-sale nudges | T | M | E1 | | ✓ | ✓ |
| E4 | `cashflow_forecast` | 30/60/90-day forecast and what-if | T | M | | | ✓ | ✓ |
| E5 | `profit_reports` | Profit per contract and month | T | M | A4 | | ✓ | ✓ |
| **F** | | **Documents and reports** (builds the four plan toggles already declared) | | | | | | |
| F1 | `no_dues_certificate` | No-dues certificate | T | S | 0.3 | ✓ | ✓ | ✓ |
| F2 | `pdf_statements` | Customer statement PDF (existing key, quota) | Q | M | 0.3 | 3/mo | ✓ | ✓ |
| F3 | `export_csv` | CSV/Excel exports and accountant pack (existing key) | T | M | | | ✓ | ✓ |
| F4 | `advanced_reports` | Ageing, collector, monthly collections (existing key) | T | M | | | ✓ | ✓ |
| F5 | `custom_branding` | Shop logo and colour on documents and portal (existing key) | T | S | 0.2 | | ✓ | ✓ |
| **G** | | **Team and operations** | | | | | | |
| G1 | `approvals` | Approval workflow | T | M | | | | ✓ |
| G2 | `branches` | Branches | **L** | M | | 1 | 1 | 10 |
| G3 | `collector_mode` | Routes, targets, cash handover | T | L | | | | ✓ |
| G4 | `collector_offline_receipts` | Offline receipt drafts | T | M | G3 | | | ✓ |
| G5 | `data_import` | CSV/Excel import | T | M | | ✓ | ✓ | ✓ |
| G6 | `ai_import` | Photo-of-notebook import | **Q** `ai_pages` | M | G5 | 3/mo | 50/mo | 300/mo |
| G7 | `voice_assistant` | Arabic voice entry and assistant | **Q** `assistant_requests` | M | | | 100/mo | 1,000/mo |
| **I** | | **Compliance** | | | | | | |
| I1 | `zatca_einvoicing` | ZATCA e-invoicing | T | L | provider | | | ✓ |
| **H** | | **Membership billing** (platform capabilities, Part 6; `scope: platform`) | | | | | | |
| H1–H6 | `billing_autopay`, `billing_dunning`, `billing_grace_readonly`, `billing_pause`, `billing_annual`, `billing_winback` | | T | L | gateway | n/a | n/a | n/a |

Plans: "Business" does not exist yet; admin-created plans start empty by design (`defaultFor()` returns null). Create it as a plan row, or fold Business features into Pro for now (Part 10, decision 1).

---

## 5. Feature specifications

**Reading a spec.** *Goal*: why. *Rules*: behaviour, including edge cases. *Data*: tables and columns (additive migrations only, tenant-scoped, UUID keys, `tenant_id` first in every index). *Surface*: API and UI on web and app. *When off*: the `offBehaviour()` text and what stays. *Done when*: acceptance beyond the global Definition of Done (Part 8).

Naming: routes `feature.<key>.*`; API under `/api/v1`; PHP actions in `App\Actions`, domain logic in `App\Domain\<Area>`; Flutter in `lib/features/<area>`.

### Epic 0: Foundation (build first; no end-user feature)

**0.1 Feature control.** Part 3, complete, with tests.

**0.2 Private object storage.** `App\Support\Files` over the `s3` disk (Supabase Storage S3 endpoint or Cloudflare R2; owner's choice, Part 10). Table `stored_files` (`id, tenant_id, kind, path, mime, size, sha256, uploaded_by_user_id, subject_type, subject_id, created_at, deleted_at`). Paths `tenants/{tenant_id}/{kind}/{uuid}.{ext}`; private bucket; **signed URLs of at most 5 minutes**; allow-list of MIME types per `kind`; size caps (images 6 MB before client compression, PDFs 10 MB); strip EXIF GPS from images; policy: only members of the tenant (and role-appropriate) can fetch. Downloads of `national_id`-type kinds are audited. Env: `FILESYSTEM_DISK=s3`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT` (set by the owner in Vercel). Local and tests use the `local` fake disk. App: image picker + camera + client-side compression (≤1.5 MB JPEG).

**0.3 PDF renderer.** `App\Documents\PdfRenderer` with **mPDF** (correct Arabic shaping and RTL; bundle the Cairo or IBM Plex Sans Arabic and Geist fonts from `app/assets/fonts` licences), Blade templates, QR codes (`endroid/qr-code`), bilingual layouts, brand header/footer, hook for `custom_branding`. Every render consumes the `pdf_statements` quota where the document type says so. Snapshot tests render each template in English and Arabic and assert text extraction and page count; a visual check script writes PNGs for review.

**0.4 Tenant settings.** `App\Settings\TenantSettings` typed accessors over `tenant_settings`, each key declared with a validation schema and default in code; owner screen **Settings → Instalment tools** (web and app) listing only features that are `on` for the workspace, each with its own "Use this" switch and policy fields, grouped by epic. Writes are audited. Changing a setting never rewrites past records.

**0.5 Scheduler and queue on a serverless host.** Route `POST /internal/cron` guarded by `CRON_SECRET` (constant-time compare, rate-limited, no session): runs `schedule:run` then `queue:work --stop-when-empty --max-time=50`. `vercel.json` cron every 5 minutes. Workspaces get `tenants.timezone` (IANA; add the column if missing; default from country). Job conventions: idempotent by unique keys; chunk by tenant; each run **re-checks feature switches**; a run longer than 50 s stops cleanly and resumes next tick. Health: `qistas:cron-status` shows the last run, lag, and failures; the admin overview shows a warning if the last run is older than 15 minutes.

**0.6 Usage metering.** `FeatureUsage::hit()` (Part 3.2) wired into the primary action of every feature as it is built.

Done when: the feature-control tests in 3.6 pass; a fake feature in the test suite proves guard, job skip, and client hiding end to end; cron endpoint refuses without the secret; storage rejects a wrong MIME type and an oversized file; a PDF with Arabic text extracts correctly.

---

### Epic A: Contract terms

#### A1 · Payday-aligned due dates · `payday_schedule` · S
**Goal.** Customers pay when they are paid; collect on salary day.
**Rules.** `customers.payday` (nullable, 1 to 31, or 0 meaning "last day of the month"). Contract option `align_to_payday`. Monthly frequency only (weekly and fortnightly ignore it and the UI hides the option). `ScheduleRequest` gains `dueDay`. Instalment *k* falls on `dueDay` of month *k*, clamped to the month's length (31 in February becomes 28/29). The first due date is the first occurrence of `dueDay` that is at least `min_days_to_first_due` (setting, default 7) after the start date.
**Data.** `customers.payday smallint null`; `contracts.due_day smallint null` (records what was used).
**Surface.** Contract form: switch "Align to the customer's payday" with chips (1st, 15th, 25th, 27th, last day) prefilled from the customer; live preview as today. API `due_day` on preview and create. Customer form gets a Payday field.
**When off.** The option disappears; existing contracts keep their fixed schedule.
**Done when.** New vectors in `shared/schedule-vectors.json` (31st into February and leap years, 1st, last-day, minimum gap) pass in **both** PHP and Dart; breaking the clamp makes a vector fail.

#### A2 · Ramadan, Eid, Hijri and weekend-aware dates · `holiday_schedule` · M
**Goal.** No instalment is due on a day the customer cannot pay; the customer sees why a date moved.
**Rules.** Holiday data per country, seeded 2026–2030 for SA, AE, KW, QA, BH, OM, EG, JO, PK. Hijri dates come from PHP `IntlDateFormatter` calendar `islamic-umalqura` and are documented as ±1 day (moon sighting); the owner can edit or add dates for their workspace. Weekend days per country (default Fri–Sat for most Gulf, Fri for others) and a setting `shift_weekends`. Modes (setting `holiday_mode`): `off`, `shift_after` (move to the first non-blocked day after the block), `pause_ramadan` (no instalment falls in Ramadan; paused instalments move to the end, extending the count by the paused months; **total is unchanged and the owner is shown this before saving**).
**The generator takes `blockedDates` as an input** (a set of ISO dates) so the Dart port needs no Hijri code. The server supplies `GET /holidays?country=&from=&to=`; the app caches per year.
**Data.** `holidays` (`country, key, kind, name_en, name_ar, starts_on, ends_on, source`), `workspace_holidays` (tenant-owned edits); `installments.original_due_date date null`.
**Surface.** Settings: mode, weekend days, workspace holiday editor. Schedule rows show "Moved from 8 Apr because of Eid al-Fitr". The customer portal shows the same note.
**When off.** No shifting for new contracts; existing shifted dates stay with their notes.
**Done when.** Vectors with `blockedDates` pass in PHP and Dart; Hijri conversion is tested against known 2026 Eid dates; Arabic and Urdu holiday names present.

#### A3 · Markup styles · `markup_styles` · M
**Goal.** Match how shops actually price, and show the customer's true cost honestly.
**Rules.** `contracts.markup_method`: `flat` (today's behaviour, default), `reducing` (annual rate on the declining balance, annuity instalments; the last instalment absorbs rounding), `fixed_profit` (profit amount fixed up front; arithmetic as `flat` fixed, different disclosure wording). The form shows **total payable, monthly instalment, and effective annual rate** (computed by IRR; state the method; display one decimal). `ScheduleGenerator` and the Dart port both implement `reducing` with vectors.
**Legal wording** for a Murabaha-style template is **not invented by you**: ask the owner for text approved by their legal adviser per market. Until it is supplied, `fixed_profit` uses neutral wording and the feature stays platform `off`.
**Data.** `contracts.markup_method string default 'flat'`; `contract_templates` (`market, kind, language, body`) populated only with owner-approved text.
**Surface.** Contract form: method selector with an explanatory line each; comparison card; PDF discloses the total cost and the rate.
**When off.** New contracts are `flat`; existing contracts unchanged.
**Done when.** Vectors for `reducing` (rounding and last-instalment rule) pass in PHP and Dart; IRR has a table test.

#### A4 · Product, serial/IMEI, cost · `contract_items` · S
**Goal.** Know what was sold and what it cost (profit reports need it; disputes need the serial).
**Rules.** Up to 10 items per contract: `name`, `sku?`, `serial_or_imei?`, `quantity`, `cost_price?`, `sale_price?`. A 15-digit serial is validated as an IMEI (Luhn). A serial already on another **active** contract in the workspace warns (advisory). If item sale prices are entered and their sum differs from the contract principal, show a warning, never a block. Contract search also finds serials.
**Data.** `contract_items` (tenant-scoped; written with the contract by `CreateContract`; immutable afterwards except `cost_price` correction by owner/manager, audited).
**Surface.** Contract form "What was sold" section; camera barcode scan on the app (optional). Contract screen lists items.
**When off.** Section hidden; items remain on the contract.
**Done when.** Luhn tests, duplicate-serial warning test, search test, tenancy test.

#### A5 · Guarantors and ID documents · `guarantors` · M
**Goal.** The paperwork shops rely on, in the app instead of a drawer.
**Rules.** Up to 3 guarantors per contract: `name`, `phone`, `relation`, `national_id` (**encrypted cast**, masked `••••890` everywhere, never in logs or audit `changes`; "reveal" is a separate audited action), `address`, `employer?`, `notes`, `contactable` (consent to be contacted if the customer is late). Customer and guarantor ID photos (front/back) as `stored_files` kinds `id_front`/`id_back`. Role rules: collectors see guarantor name and phone but not the ID number or photos.
**Data.** `contract_guarantors`, `customer_documents` (`customer_id, kind, file_id`).
**Surface.** Contract form "Guarantor" section and contract screen card with call/WhatsApp; customer screen "Documents". Camera capture with crop and compression.
**Hooks.** Collections stage `guarantor` (C3) can message a `contactable` guarantor via D1.
**When off.** Sections hidden; records and files stay and remain downloadable by the owner.
**Done when.** Masking test (national ID never in a response for a collector, never in a log line), file access policy test, tenancy test.

#### A6 · Contract PDF, QR and e-signature · `contract_documents` · M
**Goal.** A professional signed agreement in under a minute, which cuts disputes.
**Rules.** PDF (Arabic, English, or bilingual, by the customer's language): parties, items, terms, total cost (and rate if `reducing`), full schedule, the late-fee policy in plain words (if C1 is on), guarantors, signature blocks, and a **QR to the public verification page** `/verify/{code}` (valid, shop name, contract reference, date, total; **no personal data beyond initials**). **Signing:** the customer (and guarantors) sign on the owner's phone (finger on a canvas) or through the portal (B5) after a one-time code sent to the customer's phone by WhatsApp or SMS. Store `contract_signatures` (`contract_id, signer_type, name, signature_file_id, signed_at, ip, user_agent, otp_verified, document_sha256`). After signing, regenerate the PDF with the signatures embedded and store its SHA-256. This is a *simple* electronic signature: the owner's lawyer confirms enforceability per country; Nafath (Saudi national single sign-on) is a later upgrade (Part 10).
**Data.** `contract_documents` (`contract_id, kind, file_id, sha256, generated_at`), `contract_signatures`, `verification_codes`.
**Surface.** Contract screen: "Create agreement", "Collect signature", "Send on WhatsApp". Public verify page (no login, `noindex`, rate-limited).
**When off.** No new documents; signed documents and the verify page keep working (verification must outlive the feature).
**Done when.** Arabic text extracts from the PDF; hash stored equals recomputed hash; verify page leaks nothing beyond the list above; OTP expiry and attempt limits tested.

#### A7 · Smart Offer · `smart_offer` · M · needs E1
**Goal.** Suggest sensible terms for this customer, instantly.
**Rules.** Advisory panel in the contract form. Workspace `offer_policy` maps score band to `min_down_percent` and `max_months`. Defaults: Excellent 0% / 12; Good 10% / 9; Watch 25% / 6; High risk 40% / 3; New (no history) 20% / 6. An optional `max_exposure` (sum of the customer's open balance plus the new financed amount) warns if exceeded. "Apply suggestion" fills the form; the owner may deviate with no friction. Record `offer_followed` for later honesty stats. **Never blocks.** Copy uses plain words ("Ahmad has paid 11 of 12 instalments on time; a 10% down payment is suggested").
**Data.** `tenant_settings.offer_policy`; `contracts.offer_followed boolean null`.
**When off.** Panel hidden.
**Done when.** Policy mapping table test; "New" customers get the New policy, not a score; never blocks.

---

### Epic B: Collecting money

#### B1 · Payment targeting and paid-ahead · `payment_targeting` · S
**Goal.** Take the money the way it actually arrives.
**Rules.** `RecordPayment` gains `apply_to`: `oldest` (default, today's behaviour) or an `installment_id`. `PaymentAllocator` fills the chosen instalment first, then spills to the oldest unpaid; never exceeds what is owed; idempotency unchanged. The record sheet shows a **live allocation preview** ("Covers #2 fully and #3 partly; SAR 40 short"). When future instalments are covered, the contract shows **"Paid ahead until 7 Dec"**.
**Data.** None (allocations already record where money went).
**When off.** Sheet shows no chooser; payments allocate oldest-first as before.
**Done when.** Allocator unit table (exact, short, spill, chosen-then-spill, chosen-already-paid); mutation: swap the order and a test fails.

#### B2 · Proof of payment · `payment_proof` · S · needs 0.2
**Goal.** End "I already paid" arguments.
**Rules.** Insert-only `transaction_attachments` (`transaction_id, file_id, reference_no?, created_by`); the transaction itself stays immutable. A duplicate `reference_no` in the workspace shows an advisory warning. Viewers see thumbnails; owners and managers can download.
**Surface.** Record sheet "Add proof" (camera/gallery); payment line shows a clip icon; contract timeline shows the thumbnail.
**When off.** Hidden; attachments remain.
**Done when.** Attachment cannot alter the transaction; duplicate-reference warning test; file policy test.

#### B3 · Early settlement · `early_settlement` · M
**Goal.** Let a customer pay off today with a clear number, and close the contract in one tap.
**Rules.** Quote: `payoff = Σ remaining(unpaid instalments) + unpaid accrued charges (C1) − rebate`. Per-instalment markup share = `amount × markup_amount ÷ (financed + markup_amount)`. **Unearned markup** = the markup share of instalments **not yet due**. `rebate = unearned × rebate_percent` (setting, default **0%**; the owner chooses per quote within `[0, unearned]`). Rounding half-up to 2 decimals; the final remainder follows `ScheduleGenerator`'s rule. The quote is valid for the current day.
**Confirm** is one atomic transaction: `RecordPayment` of the payoff (idempotency key `early:{contract}:{quote_hash}`), unpaid instalments after allocation become `waived` (**new instalment status**), a `contract_adjustments` row records the rebate, the contract settles, audit written. A stale quote (anything changed since) returns **409** with the fresh quote. `ContractSettlement` treats `paid` and `waived` as closed.
**Permissions.** Collectors may quote, not confirm. A rebate above `max_rebate_percent` needs owner/manager.
**Data.** `installments.status` gains `waived`; `contract_adjustments` (`contract_id, type, amount, reason, transaction_id, created_by`).
**Surface.** Contract screen "Pay off early" → sheet: big payoff number (count-up), rebate slider with "customer saves SAR X", confirm; success offers the certificate (F1). API `GET /contracts/{id}/early-settlement?rebate=`, `POST` same path.
**When off.** Button hidden; settled contracts and adjustments stay.
**Done when.** Formula table (hand-computed rows, rounding, no future instalments, partially paid, with fees); concurrency test (a payment during the quote forces 409); idempotent retry; mutation on the rebate formula fails a test. Update the Dart model for the `waived` status and add widget tests.

#### B4 · Post-dated cheque vault · `cheque_vault` · M · needs 0.2
**Goal.** Cheques are how much of this market collects; track them like money.
**Rules.** `cheques`: `contract_id, installment_id?, number, bank, branch?, amount, cheque_date, status, front_file_id?, back_file_id?, bounce_reason?`. Status machine: `received → deposited → cleared | bounced`, plus `returned_to_customer` and `cancelled`; only legal transitions; each audited. **`cleared` records a payment** through `RecordPayment` (method `cheque`, idempotency `cheque:{id}:cleared`). **`bounced`** optionally charges a bounce fee (C1 `bounce_fee`) and bumps the collections stage (C3). **Add a series** ("12 cheques, consecutive numbers, monthly dates") creates N rows. A cheque is never marked cleared without a human action.
**Reminders.** Owner notification 2 days before `cheque_date` ("Deposit cheque #4471, SAR 275"), through the app's notifications and D1 if available.
**Surface.** "Cheques" section: tabs *Due soon / In the bank / Bounced / All*; contract screen shows its cheques; dashboard card "Cheques to deposit this week".
**When off.** Section and reminders stop; cheques remain readable; payments already recorded stay.
**Done when.** Transition matrix test (every illegal move refused); cleared creates exactly one payment even if clicked twice; series creation test.

#### B5 · Customer link, no install · `customer_portal` · M
**Goal.** Customers see their own schedule and receipts without calling the shop.
**Rules.** Public pages `/c/{token}` (web only; the app shares the link). Token: ≥32 random bytes, stored **hashed**, expires in 30 days by default and can be renewed, scoped to one customer, revocable (per link and "revoke all for this customer"). Pages: summary (next due, balance), schedule (with moved-date notes), receipts (receipt PDF), **"Ask for more time"** (creates an `extension_request` for owner approval; approval can create a promise (D3) or open the reschedule wizard (C2)), **"I've paid"** with optional proof upload (creates a pending `payment_claim`; **it never records money**; the owner confirms by recording a payment). Customer language by `customers.language`, with a language switcher; RTL. `X-Robots-Tag: noindex`, strict CSP, rate-limited, a view log (opened at). No national ID, no other customers' data, nothing beyond name and contract facts.
**Data.** `customer_links`, `extension_requests`, `payment_claims`, `customers.language`.
**Surface.** Contract/customer screen: "Send statement link" (system share sheet). Owner inbox for requests.
**When off.** Existing links show a neutral "This page is not available right now"; no data is displayed.
**Done when.** Token tests (hashed at rest, expiry, revoke), isolation test (token for customer A can never reach B), noindex header test, claim never writes the ledger.

#### B6 · Pay link or QR · `pay_links` · L · needs a gateway account
**Goal.** Customers can pay in one tap, and the money goes straight to the owner.
**Rules.** **Qistas never holds funds.** The owner connects **their own** gateway account (one provider first; Part 10). `tenant_gateways` (`provider, credentials (encrypted), mode live|test, status`). `PaymentGateway` interface with one real driver plus a deterministic fake for tests. `payment_intents` (`installment_id|contract_id, amount, gateway_ref, status, expires_at`). Link from the portal and from D2 messages ("Pay now"). Amounts: the due instalment or a custom amount up to what is owed. **Webhook** endpoint verifies the provider signature and rejects replays; success calls `RecordPayment` with idempotency key = gateway reference, method `card` or `mada`. Gateway fees stay with the owner and are not in the ledger. Refunds are done in the gateway and then reflected by an owner-initiated `VoidTransaction`; document this. No card data ever touches Qistas (hosted payment page only).
**Surface.** Owner: Settings → Payments → connect gateway (keys entered by the owner into a secure form, never echoed back); QR on the instalment screen.
**When off.** Links stop working with a neutral page; recorded payments stay; webhooks for already-created intents are still processed (money may be in flight) and logged.
**Done when.** Signature and replay tests; webhook idempotency; fake-gateway end-to-end; secrets never logged. **Blocked until the owner supplies a gateway account and commercial registration.**

---

### Epic C: Late customers

#### C1 · Late-fee and grace policy · `late_fees` · M
**Goal.** A fair, configurable, auditable consequence for paying late.
**Legal flag.** In some markets a late fee may not be taken as income (Sharia-compliant practice routes it to charity) or may be restricted by consumer rules. The feature must support `is_charity` and a separate report, ships with the policy **disabled**, and the settings screen carries a plain-words note to check local rules. The owner's legal adviser decides the wording (Part 10).
**Policy** (workspace default, **snapshotted into `contracts.late_fee_policy` at creation** so later changes never alter old contracts; applying a new policy to existing contracts is an explicit, dated, audited choice): `enabled`, `grace_days` (0 to 30), `fee_type` (`fixed` | `percent_of_remaining`), `amount`, `repeat` (`once` | `weekly` | `monthly`), `cap_per_instalment`, `cap_per_contract`, `is_charity`.
**Rules.** Daily job `qistas:accrue-late-fees` (tenant timezone, 02:00) creates `contract_charges` for each overdue instalment whose grace has passed. **Idempotent:** unique `(installment_id, type, period_key)`; the job catches up any missed periods deterministically up to the caps. The fee base is the unpaid part of the instalment at accrual time. Fees stop when the instalment is paid. **Fees are not instalments:** they never change the schedule and never make an on-time instalment look unpaid.
**Ledger integration.** `PaymentAllocator` order: oldest instalment first; a charge is paid after the instalment it belongs to is settled (`fee_allocation` setting: `after_instalment` default, `before`). Add nullable `transaction_allocations.charge_id` with a check that exactly one of `installment_id` / `charge_id` is set. A contract's *owed* = unpaid instalments + unpaid accrued charges; the dashboard's "Still to collect" includes charges, and the API exposes `owed_breakdown` and a `charges` array.
**Waiving.** `status`: `accrued → paid | waived | void`. Waiving needs a reason (audited). Owner/manager waive directly; collectors and accountants **propose** (until G1 exists, a proposal is a note and notification to the owner).
**Data.** `contract_charges` (`contract_id, installment_id?, type late_fee|bounce_fee, amount, period_key, status, is_charity, waived_reason, waived_by, accrued_on`), `contracts.late_fee_policy jsonb null`.
**Surface.** Settings → Late fees (policy editor with a live example: "A SAR 275 instalment 10 days late costs SAR 10"); instalment row chip "Late fee SAR 10"; contract screen lists charges with Waive; statement and the dashboard show them; fee income vs charity in advanced reports.
**When off.** No accrual and no new fees. Existing charges remain owed or waivable and appear on statements (they are financial records).
**Done when.** Accrual idempotency and catch-up tests; grace boundaries (due date, due+grace, due+grace+1); caps; timezone and daylight-saving boundaries; partial payments change the base; a voided payment re-opens an instalment and later accrual is correct; allocation tests with charges; waive audit; mutation on the grace comparison fails a test.

#### C2 · Reschedule, extend, skip a month · `reschedule` · M
**Goal.** Re-plan a struggling customer's schedule without breaking history.
**Rules.** Schedules are immutable, so a change is a **contract amendment**: unpaid instalments become `superseded`, new instalments are inserted (numbering continues) with `amendment_id`, and paid history is untouched. Types: `skip_month` (append one instalment of the same amount at the end), `extend` (spread the remaining balance over N more months, recomputed by `ScheduleGenerator` from the remaining balance; **no new markup** unless the owner sets `reschedule_markup_percent`, default 0), `custom_date` (move one instalment's date, within `max_shift_days`). Limits: `max_reschedules_per_contract` (default 2). A **reason is required**. Preview first, then confirm; confirm re-validates against current state (it may have changed). Only owner/manager confirm; collectors and accountants request (G1 generalises approvals). The customer is notified (D1) and sees the new schedule in the portal with "Rescheduled on…".
**Data.** `contract_amendments` (`contract_id, type, reason, requested_by, approved_by, old_schedule jsonb, new_schedule jsonb, created_at`); `installments.amendment_id`; instalment status `superseded`.
**Surface.** Contract screen "Reschedule" wizard (type, preview diff old vs new, reason, confirm); amendments in the contract timeline. API `POST /contracts/{id}/amendments/preview` and `/amendments`.
**When off.** Wizard hidden; existing amendments and history remain.
**Done when.** Property test: after any amendment the sum of paid + unpaid equals the contract total (plus agreed markup); paid instalments are untouched; the limit is enforced; stale-preview confirm is refused; Dart models know `superseded`.

#### C3 · Collections stages and contact log · `collections_stages` · M
**Goal.** Every late customer has a stage, a history, and a next step.
**Rules.** `contracts.collections_stage`: `none`, `friendly`, `firm`, `guarantor`, `final_notice`, `legal`. Defaults advance automatically by days late (1, 7, 14, 30; `legal` is **manual only**) and the owner can edit the thresholds or pause auto-advance; any stage can be set manually with a note. A **contact log** (`contact_log`: `customer_id, contract_id?, kind call|whatsapp|sms|visit|note, outcome, note, promise_id?, user_id, at`) shows calls, messages sent by D2, promises, and notes on one **timeline** per customer and contract. "Log a call" is two taps from the instalment. A stage change that needs a message (guarantor, final notice) creates a task for the owner rather than sending silently.
**Data.** `contracts.collections_stage`, `contact_log`.
**Surface.** Customer and contract timelines; a "Collections" list on web and app grouped by stage; the dashboard "Needs you" row shows the stage chip.
**When off.** Stages stop advancing; the timeline stays readable.
**Done when.** Stage thresholds test (including after a payment resets lateness); `legal` never auto-set; log writes are tenant- and role-scoped.

#### C4 · Watchlist · `watchlist` · S
**Goal.** Warn before selling again to someone who burned the shop. **This shop's data only.**
**Rules.** `customers.watch_status`: `none`, `watch`, `caution` plus a required reason. On the contract form for such a customer: a banner with the reason; `caution` asks for an explicit "I understand" tap with a note. **Advisory: never blocks and never decided by software.** Set manually, or suggested after a write-off or a bounced cheque (the owner confirms).
**Surface.** Chip on lists and detail; banner on new contract.
**When off.** The chip and banner disappear; stored statuses and reasons are kept.
**Done when.** Banner and confirmation tests; nothing leaves the workspace.

#### C5 · Write-off and repossession status · `write_off` · M
**Goal.** Close bad debt honestly and keep the books true.
**Rules.** New contract status `written_off` (`written_off_at`, `written_off_by`, `reason`, `written_off_amount`) set by owner/manager through an Action that **does not touch the ledger**: it moves the remaining balance out of "Still to collect" into a separate "Written off" figure. A payment recorded later on a written-off contract is allowed and shown as a **recovery**. Owner can **reinstate**. Optional `repossession` note fields (item recovered, date, value) for goods taken back; no automatic ledger entry. Writing off feeds E1 (score penalty) and C4 (suggest watchlist).
**Surface.** Contract screen "Write off…" with reason and amount confirmation; reports show Written off and Recovered.
**When off.** Hidden; existing written-off contracts and totals remain.
**Done when.** Outstanding totals exclude written-off exactly once; recovery payment test; reinstate test; audit.

---

### Epic D: Autopilot and communication

#### D1 · WhatsApp channel and consent · `message_channel` · M
**Goal.** A safe, compliant way to talk to customers on the channel they actually read.
**Rules.** `MessageChannel` interface (`send(OutboundMessage): DeliveryResult`) with drivers `log` (development and tests) and `whatsapp_cloud` (Meta WhatsApp Business Platform). **Start with a platform-level sender number** and templates that carry the shop name (`:business`); embedded signup for the shop's own number is a later step (Part 10). Templates live in `message_templates` (`key, language, body, variables, whatsapp_template_name, status`), seeded in the five languages; a test fails if any template is missing a language or its variables differ between languages. **Consent:** `customer_consents` (`customer_id, channel, status granted|revoked, source contract_signed|owner_confirmed|portal, granted_at, evidence_note, recorded_by`). **No consent, no business-initiated message**; the owner records consent at contract creation (a checkbox, default off). Inbound "stop" or "إيقاف" revokes consent automatically. Delivery: status webhook (`sent/delivered/read/failed`) at `/webhooks/whatsapp`, verified by token and HMAC `X-Hub-Signature-256`, idempotent by message id. Sending window: not outside 09:00–21:00 in the customer's local time (settings). Per-tenant rate limits.
**Data.** `message_channels`, `message_templates`, `customer_consents`, `outbound_messages` (`customer_id, template_key, language, status, provider_ref, cost_units, error`), `inbound_messages`.
**Surface.** Settings → Messaging: status of the channel, a "Send me a test" button (to the owner's own phone), consent summary ("42 of 50 customers can be messaged"), message feed.
**Env (owner sets in Vercel).** `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_APP_SECRET`, `WHATSAPP_VERIFY_TOKEN`.
**When off.** No sends; the log stays; consent records stay.
**Done when.** HMAC and replay tests; consent gating test (no consent means nothing is sent); stop-keyword test; template completeness test. **Blocked on a WhatsApp Business Platform account and template approval.**

#### D2 · Reminder cadence (Autopilot) · `auto_reminders` · Q `reminder_messages` · M–L · needs D1
**Goal.** The right message, at the right time, in the customer's language, without the owner lifting a finger.
**Rules.** Default cadence relative to each instalment's due date: **−3, 0, +2, +7 days** (editable, each step on/off). Materialised **idempotently** as `reminder_events` (unique per instalment and step) by `qistas:plan-reminders`; dispatched by `qistas:dispatch-reminders` (every 15 minutes). Stops when the instalment is paid or an active promise (D3) covers it. At +7 the owner gets a task "Call Ahmad". Quiet hours and the customer's language apply. Each send **consumes one `reminder_messages` unit atomically before sending**; at 90% the owner is warned; at 100% sending **pauses and the owner is told** (never silent). The job re-checks the feature switch, the quota, and consent for every message at send time.
**Messages.** Utility templates: before-due ("a friendly reminder"), due-today, overdue, firm overdue. Wording is polite and respectful; never threatening; never mentions third parties or debt collection.
**Surface.** **Autopilot** screen: the cadence as a timeline, a live preview of exactly what the customer will receive (in their language), "Send me a test", pause all, monthly allowance meter, and a feed of sent messages with statuses. Per-customer "Do not remind".
**When off.** Pending reminder events are dropped; messages already sent remain in the feed; manual Remind (existing) keeps working.
**Done when.** Idempotent planning test (running twice changes nothing); stop-on-payment; quiet-hours boundaries by timezone; quota atomicity under concurrency; no-consent skip; feature-off skip at run time.

#### D3 · One-tap promises to pay · `promises` · M · needs D1, D2
**Goal.** Turn a reminder into a two-way conversation, and record every commitment.
**Rules.** Reminder messages carry quick-reply buttons: **"I'll pay today" / "In 3 days" / "In a week" / "Call me"** (the dates are limited by `max_promise_days`, default 10). A tap creates a `promise` (`installment_id, promised_for, source whatsapp|staff|portal, status pending|kept|broken|cancelled`). Only replies from the customer's own number are accepted, deduplicated by message id. "Call me" creates an owner task. The job `qistas:evaluate-promises` marks a promise **kept** when the instalment is paid by `promised_for`, **broken** after the day passes; broken promises alert the owner, add a contact-log entry, count in the score (E1), and pause the cadence only while a promise is pending. Staff can also log a promise by hand.
**Surface.** Dashboard "Promises today" card; customer timeline; one tap on a broken promise opens Remind or Reschedule.
**When off.** Buttons are not added to new messages; pending promises are still evaluated and shown (they are records).
**Done when.** Spoofed-number test; duplicate-tap test; kept/broken evaluation across timezones.

#### D4 · Weekly brief and "paid for itself" receipt · `owner_briefs` · M
**Goal.** Give the owner a reason to open the app every week, and proof of value every month.
**Rules.** **Weekly brief** (Sunday 08:00 tenant time): collected this week vs last, who is late, promises due, cheques to deposit, one suggested action. **Monthly receipt:** collected this month, **"collected within 48 hours of a reminder"** (`roi_window_hours`, default 48; labelled exactly that, never as proof of cause), on-time rate, promises kept, and the plan price **only for paid plans**. Delivered in-app first, then by WhatsApp to the owner (their own consent) once D1 exists; **e-mail only after SMTP is configured**. Honest copy: no guarantees, no invented figures; if a number is not available it is omitted.
**Data.** `owner_briefs` (`tenant_id, kind weekly|monthly, period, payload jsonb, delivered_via`).
**Surface.** A card at the top of the dashboard; notification; history page.
**When off.** Stops generating; history stays.
**Done when.** Attribution query test (a payment 47 h after a delivered reminder counts; 49 h does not; a payment before the reminder does not); numbers reconcile to the ledger.

---

### Epic E: Intelligence

#### E1 · Qistas Score · `customer_score` · M
**Goal.** A transparent 0–100 view of how a customer has behaved *with this shop*.
**Rules.** Deterministic, explainable, unit-tested (formula in Appendix A). **New customers have no score** (shown as "New", never 50). Bands: Excellent 80+, Good 60–79, Watch 40–59, High risk <40. Inputs are **payment behaviour and contract facts only**: no gender, nationality, age, address, or any demographic. Recomputed on payment, void, instalment turning overdue (nightly), contract settled, written off, cheque bounced, promise kept or broken. A "Why?" sheet lists the top three factors in plain words. The score is **advisory and internal**: never shown to the customer; the owner can add a note but cannot edit the number. **Data stays inside the workspace.** Sharing scores between shops is out of scope until consent and legal review.
**Data.** `customer_scores` (`customer_id, score null, band, factors jsonb, version, computed_at`).
**Surface.** Score ring and band on customer detail and in pickers; "Why?" sheet.
**When off.** Score hidden; stored values are kept, but not recomputed.
**Done when.** Table test over the formula with hand-computed rows; "New" handling; explainability text in five languages; no demographic field is read (enforced by a test that inspects the calculator's inputs).

#### E2 · Likely-late radar · `risk_radar` · M · needs E1
**Goal.** Warn the owner before an instalment goes late.
**Rules.** Nightly job computes `p_late` for instalments due in the next 7 days from the customer's on-time rate, recent trend, current overdue, amount relative to their usual instalment, and the score band (formula in Appendix A; no machine learning in v1). Stores `risk_predictions`. Dashboard card **"Likely late this week"** (top 5) with one-tap early Remind or Call. **Honest calibration:** after 30 days of data show "the radar was right X% of the time"; until enough samples exist show "still learning".
**When off.** Card hidden; predictions stop.
**Done when.** Formula table test; calibration math test; no prediction for instalments already paid.

#### E3 · Next-sale nudges · `next_sale_nudges` · M · needs E1 (D1 optional)
**Goal.** Turn good payers into repeat sales: more revenue, not just collections.
**Rules.** When a contract settles with **zero late days** (or the customer's band is Excellent), create a `sales_nudge`: "Ahmad finished C-0007 on time. Offer him his next purchase?" with the Smart Offer VIP terms (A7). The owner **approves each message** (no automatic marketing); if D1 and consent exist, a message template is offered, otherwise the owner gets a call prompt. Conversion is recorded when a new contract is created for that customer within 30 days. A daily cap prevents nagging.
**Data.** `sales_nudges` (`customer_id, source_contract_id, kind, status pending|sent|dismissed|converted, created_at`).
**When off.** No new nudges; history stays.
**Done when.** Trigger tests (zero-late vs one late day); conversion window; cap; consent gating.

#### E4 · Cash-flow forecast and what-if · `cashflow_forecast` · M
**Goal.** Answer "what will come in, and can I afford stock?"
**Rules.** Weekly buckets for 30, 60, or 90 days: **expected** = unpaid remaining due in the bucket; **likely** = expected weighted by each customer's on-time probability (their history; with fewer than 3 data points use the workspace average); an **overdue pool** recovered by days-late bucket from the workspace's own history (fallback defaults 0–7 d 80%, 8–30 d 50%, 31–90 d 25%, 90+ d 10%, labelled as estimates). **What-if:** add hypothetical contracts (price, down %, months, markup) computed by `ScheduleGenerator` and see the new curve. Every figure labelled *expected* or *estimate*; no promises.
**Surface.** App and web screen with weekly bars in the brand style, a what-if sheet, export.
**When off.** Hidden.
**Done when.** Bucket math table test; what-if equals real schedule math; estimates labelled in all languages.

#### E5 · Profit per contract and month · `profit_reports` · M · needs A4
**Goal.** Know what each sale really earned.
**Rules.** Recognised profit on a **cash-proportional basis**: `(total − cost) × (collected ÷ total)`, where cost comes from `contract_items` (contracts without cost are listed as "cost unknown" and excluded from totals, never guessed). Monthly table: sales, cost, markup earned, fees (and charity portion), written off, recovered. CSV export.
**When off.** Hidden.
**Done when.** Worked examples reconcile to the ledger; unknown-cost handling test.

---

### Epic F: Documents and reports

These four plan toggles are **already on the pricing page but nothing implements them** (`pdf_statements`, `export_csv`, `advanced_reports`, `custom_branding`). Build them so what is advertised exists. Reuse their existing keys and plan defaults.

#### F1 · No-dues certificate · `no_dues_certificate` · S · needs 0.3
**Goal.** A customer who finishes paying leaves with proof, and the shop's name goes with them.
**Rules.** Available only for a `settled` contract (including early-settled). Numbered per workspace (`certificates`: `number, contract_id, issued_at, file_id, verification_code, status valid|void`). Bilingual PDF: "No amount is outstanding on contract C-0007 as of <date>", the shop's logo and details (F5), QR to `/verify/{code}`. **If a payment on that contract is later voided so that money is owed again, the certificate becomes `void` and the verify page says so.** One tap to share.
**When off.** No new certificates; issued ones and their verify pages keep working.
**Done when.** Void-on-reversal test; numbering is gap-free per tenant; verify page leaks no personal data.

#### F2 · Customer statement PDF · `pdf_statements` (Quota) · M · needs 0.3
**Rules.** Statement for a customer or one contract over a date range: contracts, schedules, payments, charges, balance; English or Arabic; QR to verify. Each generated document consumes one unit of the existing monthly quota; **regenerating the same parameters within 10 minutes does not consume again** (idempotent by a hash of the parameters). `GET /customers/{id}/statement.pdf?from=&to=&contract=`. Roles: owner, manager, accountant; collectors only for their own customers (G3).
**When off.** No new statements can be generated; statements already generated stay downloadable.
**Done when.** Quota consumed once per distinct document; Arabic extracts correctly; totals reconcile to the ledger.

#### F3 · Exports and accountant pack · `export_csv` · M
**Rules.** Customers, contracts, instalments, payments, charges, as CSV or XLSX; **UTF-8 with BOM** so Arabic opens correctly in Excel. More than 5,000 rows run on the queue and arrive as a signed download. **Accountant pack:** a zip with payments by month, outstanding by customer, and the ageing table. Roles: owner, manager, accountant. Every export is audited (who, what, row count); rate-limited. No national IDs in any export.
**When off.** Export buttons are hidden and the endpoints refuse; earlier export files expire normally (signed links last 24 hours).
**Done when.** BOM present; role test; audit row; large export path tested with a low threshold.

#### F4 · Advanced reports · `advanced_reports` · M
**Rules.** Ageing (current, 1–30, 31–60, 61–90, 90+ days; amounts, counts, drill-down), collections by month against expected (collection rate), top debtors, fees (income vs charity), written off and recovered, collector performance (once G3 exists). Date filters, CSV export, web and app. Heavy queries use covering indexes with `tenant_id` first; pagination; no per-row queries.
**When off.** The report screens are hidden; the dashboard and basic lists stay.
**Done when.** Report totals reconcile to the ledger in a seeded fixture; an N+1 guard test passes.

#### F5 · Custom branding · `custom_branding` · S · needs 0.2
**Rules.** Shop logo (PNG/JPEG only; no SVG, to avoid script risk), Arabic and English shop name, phone, commercial registration and VAT number, footer line, and an accent colour chosen from a curated palette or validated by a **WCAG AA contrast check** (the colour must pass on white and on ivory, or it is rejected with the nearest passing suggestion). The project already enforces contrast for the app's tokens (see `app/test/design/theme_test.dart`) and the theme engine (`brand/shared/qistas-theme.js`); port that logic to PHP with its own tests rather than inventing a second rule. Applied to PDFs, certificates, the portal, and WhatsApp template variables. **Not applied to the app's own chrome.**
**When off.** New documents use the default Qistas look; documents already generated keep their branding; the stored logo and colour are kept for when it is re-enabled.
**Done when.** Contrast rejection test; logo type and size tests.

---

### Epic G: Team and operations

#### G1 · Approvals · `approvals` · M
**Goal.** Staff can propose; the owner decides. Money-moving actions get four eyes.
**Rules.** `approval_policies` (settings): per action, who must approve: `void_payment`, `waive_charge`, `reschedule`, `write_off`, `large_rebate`, `cancel_contract`, `delete_customer`. `approval_requests` (`type, subject, payload jsonb, requested_by, status pending|approved|rejected|expired|cancelled, decided_by, decided_at, decision_reason, expires_at`). Default expiry 7 days. On approval the Action **re-validates against the current state** before executing (it may have changed), and the result is reported to both people. A requester cannot approve their own request unless they are the owner (`allow_self_approval`). Approvers are notified in-app (and by WhatsApp if D1 is on). Wire C1 (waive), C2 (reschedule), C5 (write off), B3 (large rebate), and the existing void/cancel actions into it; where a feature says "propose", this is the mechanism.
**Surface.** Approvals inbox with a badge; request and decision shown in the contract timeline.
**When off.** Actions fall back to the existing role rules (owner/manager direct; others refused); pending requests are marked `expired` with a note.
**Done when.** Re-validation test (state changed, so the approval is refused with an explanation); self-approval refused; expiry job; tenancy.

#### G2 · Branches · `branches` (Limit) · M
**Rules.** `branches` (`tenant_id, name, code, is_default`); `branch_id` on customers, contracts, and `tenant_users`. Migration backfills a default branch for every workspace. A manager scoped to a branch sees only that branch (policy). Reports filter by branch; the dashboard has a branch switcher for owners. With a limit of 1 the branch UI is hidden entirely.
**When off.** The branch UI is hidden and the default branch applies to everything; branch assignments are kept and reapply when re-enabled.
**Done when.** Scoping policy tests across lists, search, dashboard, reports; backfill migration test; the limit is enforced like the other Limit features.

#### G3 · Collector mode · `collector_mode` · L
**Goal.** The shop's people on the road collect efficiently and accountably.
**Rules.** Assign customers (or contracts) to collectors (`assignments`). A collector's home is **"My route"**: assigned customers who are due or late, ordered by lateness and optionally by distance (device location used only on that screen and only with permission). **Daily target** (amount and visits) with a progress ring. A collector sees only assigned customers and cannot see other collectors' data. **Cash in hand** per collector = cash payments they recorded since the last handover − handovers; the owner records a `cash_handover` (counted amount, notes); a difference is flagged, never silently absorbed. Optional **location stamp** on a payment (latitude/longitude with accuracy, stored in an attachment row, only if the collector allows it; collectors are told what is recorded).
**Data.** `assignments`, `daily_targets`, `cash_handovers`, `payment_locations`.
**Surface.** Collector home; owner "Team" screen (targets, cash in hand, handovers).
**When off.** Collectors keep the existing collector role behaviour; assignment and handover records stay.
**Done when.** Isolation test (a collector cannot read an unassigned customer through any endpoint, including search); cash arithmetic table test; location stamp is opt-in.

#### G4 · Offline receipt drafts · `collector_offline_receipts` · M · needs G3 · **owner decision required**
**Why a decision.** The app deliberately never queues money ("money must not be guessed"). Collectors on the road need *something*. This feature is the safe compromise, and the owner must approve it (Part 10).
**Rules.** With no connection the app stores a **draft receipt** locally (client-generated UUID idempotency key, amount, method, contract, `captured_at`). Drafts are clearly **"Not yet recorded"** (distinct colour, label, and a "Draft" watermark on any receipt shown to a customer). On reconnect each draft goes through the normal `RecordPayment` with that idempotency key; `paid_at = captured_at` clamped to the last 72 hours; the transaction records `source = 'offline_sync'`. A rejection (contract settled meanwhile, over-payment) moves the draft to **Needs attention** with the reason; nothing is retried silently beyond idempotent-safe retries. Limits: 20 drafts, 72 hours; a collector cannot hand over cash with unsynced drafts.
**Data.** `transactions.source` (nullable string, set only at insert).
**When off.** The app refuses to create drafts offline (the existing offline message); existing drafts can still be synced.
**Done when.** Replay and idempotency tests; clamp tests; a UI test that drafts are never presented as recorded.

#### G5 · CSV/Excel import · `data_import` · M
**Goal.** Switch from a spreadsheet in ten minutes. This is an acquisition feature: free on every plan (Free is capped at 100 rows per file).
**Rules.** Wizard: upload, **auto-detect columns** (Arabic and English headers), map, validate, **dry run** (summary: customers to create, duplicates by phone, contracts, errors with row numbers), import on the queue. Imported contracts use an **opening-balance** form: the remaining instalments become the schedule, and what was already paid is one `payment` transaction dated the import day with note "Imported opening balance" (method `other`), so the ledger balances. Idempotent per row hash. `import_batches` with an **Undo within 24 hours** only if no later payment was recorded on the imported contracts (it cancels them; it never edits the ledger). Libraries: a maintained spreadsheet reader (OpenSpout or PhpSpreadsheet); cap 5,000 rows per file.
**When off.** The wizard is hidden; completed batches and their data stay (the 24-hour undo window still applies).
**Done when.** Dry-run equals real run; opening-balance ledger test; duplicate handling; undo refused after a payment.

#### G6 · Photo-of-notebook import · `ai_import` (Quota `ai_pages`) · M · needs G5
**Goal.** Paper ledgers become data without typing. The wow moment for a shop switching from a notebook.
**Rules.** Up to 10 photos per batch → a Claude model with vision (`QISTAS_AI_MODEL`, default the latest Sonnet) extracts rows (name, phone, amounts, dates) as **structured output** → the same mapping and validation screen as G5, with **confidence flags** per cell, **never an automatic import**. Images are processed once and **deleted within 24 hours**; only the extracted text is kept. Each page consumes one quota unit. Prompt-injection posture: image text is data, never instructions; the model has no tools in this flow. Privacy copy states exactly what happens to the photo. Env: `ANTHROPIC_API_KEY` (set by the owner in Vercel).
**When off.** Photo upload is hidden; unconfirmed drafts are discarded together with their images; data already imported stays.
**Done when.** Mocked-model tests (the real call is behind an interface); deletion job test; quota test; low-confidence cells are highlighted.

#### G7 · Arabic voice entry and assistant · `voice_assistant` (Quota `assistant_requests`) · M
**Goal.** "أحمد دفع ٢٧٥" at the counter, hands busy.
**Rules.** Speech-to-text **on the device** (Arabic first, plus the app languages) produces text. The server (`POST /api/v1/assistant/command`) uses an LLM with a **fixed allow-list of tools** (`find_customer`, `propose_payment`, `answer_question`) and returns a **proposal**: "Record SAR 275 cash from Ahmad Salem on C-0007?". **Nothing is written until the person taps Confirm**; Confirm then calls the normal `RecordPayment` endpoint with an idempotency key. Questions ("who is most late?") are answered from existing report endpoints, **never raw SQL**. All data in the prompt is scoped through the existing tenant-scoped queries. **Customer notes and names are untrusted data:** instructions found inside them are ignored; tested with injection fixtures. Every request is logged (who, what was proposed, what was confirmed) and consumes quota. Role rules apply: a viewer's assistant can answer but never propose a write.
**When off.** The microphone button and assistant disappear; the log of past requests stays.
**Done when.** Allow-list enforcement test; injection fixtures never cause a proposal outside the tool list; confirmation required (a test proves no ledger write without it); quota; Arabic number parsing ("مئتان وخمسة وسبعون", "٢٧٥") table test.

---

### Epic I: Compliance

#### I1 · ZATCA e-invoicing (Saudi Arabia) · `zatca_einvoicing` · L · **scope decision required**
**Why.** ZATCA's Phase 2 integration waves have reportedly reached businesses with VAT-able revenue above SAR 375,000 (wave 24, June 2026), with penalties for non-compliance (**verify the current wave, threshold, and penalty against ZATCA's own announcements before relying on this**). Being compliant is a plausible buying trigger for Saudi shops. It is also a large, precise piece of work (cryptographic stamps, hash chain, QR, reporting within 24 hours).
**Approach.** Do **not** hand-build the cryptography. Integrate a **certified provider or the official SDK** (owner chooses; Part 10). Generate simplified tax invoices (B2C) with the provider, store invoice UUID, hash, QR and report status per invoice, and surface failures to the owner with a retry.
**Open question the owner's accountant must answer before any code:** how instalment sales are invoiced (at the sale, at each instalment, or both) and how down payments, markup, and late fees appear. Do not guess.
**Do not start until** the owner provides accountant guidance and a provider account. Until then the feature stays platform `off`, and Business is marketed without it.
**When off.** No invoices are generated or reported; invoices already issued and their report status stay visible.
**Done when.** (Defined after the owner's decisions.) The provider's sandbox accepts sample invoices; reporting failures are visible and retryable; no invoice is ever edited after reporting.

---

## 6. Epic H: Membership that never lapses

**Goal.** The owner's monthly payment succeeds without effort, failures are recovered politely, and a lapse never costs them their data. This is the revenue engine; build it in parallel with Epic D once the owner has a gateway account (Part 9).

These six are **platform capabilities** (`scope: platform`): they appear in the same Feature-control screen under *Platform*, with the same Off / Beta / On switch, but are not assigned to plans.

**Foundation.** The existing `Subscription` model and `PlanOffer` stay the source of "what plan is in force" (`Subscription::IN_FORCE`; `Tenant::currentPlan()`; no subscription row means Free). Extend states to `trialing, active, past_due, paused, canceled, expired` **without changing what `currentPlan()` returns while a subscription is in its grace period.** Gateway behind the existing `BILLING_GATEWAY` switch (`fake`, plus the owner's chosen provider). **Hosted checkout or tokenisation only: card numbers never touch Qistas.** Webhooks are signature-verified and idempotent.

| ID | Key | What it does |
|---|---|---|
| H1 | `billing_autopay` | Saved payment method (token, brand, last four, expiry) charged each period, including mada and Apple Pay recurring where the gateway supports them. `payment_methods`, `billing_invoices` (`period, amount, vat_amount, status, gateway_ref, pdf`), a **receipt PDF per charge** with VAT at the country rate (KSA 15%, configurable; how subscription invoices must be issued under ZATCA is a Part 10 question). Every charge attempt carries an **idempotency key so a customer is never charged twice**. |
| H2 | `billing_dunning` | Pre-billing notice 3 days before; card-expiry notices at 30 and 7 days; after a failure, retries on days 1, 3, 5, 7 (schedule editable in the admin) with a calm, helpful tone in the owner's language, through the in-app banner (always) and WhatsApp (via the platform channel; e-mail once SMTP exists); a one-tap **"Update card"** link that expires quickly. Retry timing and outcomes are logged for the admin recovery metrics. |
| H3 | `billing_grace_readonly` | After the last failed retry: **read-only mode**. Everything can be read, exported, and receipted; creating or changing records is refused with a clear banner and an "Update payment method" action. **Nothing is ever deleted**; paying restores full access instantly. After 60 days (setting) the workspace is `expired`: sign-in shows only *Export my data* and *Reactivate*, and data is retained for 12 months (confirm retention with the owner's adviser). Implementation: a `ReadOnlyWhenLapsed` guard on all write routes and Actions; API code `subscription_lapsed` (HTTP 402 with `reason: lapsed`). In the iOS/Android app the copy follows the current store rules and carries no purchase link by default. |
| H4 | `billing_pause` | The owner may **pause** for up to 2 months in any 12: the workspace is read-only, billing stops, and it resumes automatically. Pause is offered before cancel. |
| H5 | `billing_annual` | Annual plan with two months free, proration on upgrade and downgrade, renewal notices at 30 and 7 days. |
| H6 | `billing_winback` | **Cancel in at most three taps, always.** The flow asks one question (reason), shows honest value ("This year Qistas recorded SAR 240,000 of payments"), offers pause, and offers an admin-configured discount once per 12 months. Follow-ups at +14 and +45 days, honouring opt-out. No dark patterns. |

**Admin: Billing cockpit** (`/admin/billing`): active, trialing, past-due, paused counts; MRR; failed-payment list with reason codes; recovery rate by retry step; **at-risk accounts** (card expiring, repeated failure, falling usage); actions (retry now, extend grace with a reason, grant a complimentary period via an expiring plan override), all audited.

**Tests.** State-machine table; retries on a fake clock; webhook replay and signature; the read-only matrix (every write route and Action refuses, every read and export passes); pause bounds; proration table; VAT rounding; a double-submit never double-charges; the `currentPlan()` regression suite stays green.

**When a switch is off.** Billing capabilities off means the corresponding behaviour does not run; existing subscriptions are never changed or cancelled by flipping a switch, and no workspace is moved to read-only by an admin toggle alone.

---

## 7. Cross-cutting requirements

**Security and privacy**
- Tenant isolation everywhere (`BelongsToTenant`); every new endpoint and job has an isolation test. Never trust a tenant id from the client.
- National IDs and guarantor IDs: encrypted at rest, masked in UI and exports, absent from logs and `Audit` `changes`; "reveal" is a separate audited action.
- Files private, signed URLs ≤5 minutes, EXIF stripped, MIME and size allow-lists.
- Public surfaces (portal, verify pages, webhooks): hashed tokens, rate limits, `noindex`, strict CSP, no personal data beyond what the page needs.
- Messaging only with recorded consent; honour STOP; quiet hours in the customer's local time.
- Privacy law (Saudi PDPL and similar): consent records, a retention policy per data class, and a **customer data export and delete** path (owner-initiated) that respects ledger retention duties (anonymise rather than delete where records must be kept).
- Secrets: only in environment variables set by the owner; never in code, logs, chat, or the database in clear text (gateway credentials per tenant are encrypted).
- Run the `security-review` skill on each epic.

**Money and data integrity**
- Money is bcmath strings in PHP and BigInt cents in Dart; never floats. Rounding half-up to two decimals with the generator's last-instalment rule.
- Only `RecordPayment`, `VoidTransaction`, `CreateContract` write the ledger (plus the new `SettleEarly`, which *calls* `RecordPayment`). New financial states (fees, adjustments, amendments, write-offs) have their own insert-oriented tables and audit rows.
- Every migration is additive and reversible, with safe defaults; after migrating, `qistas:secure-database` covers the new tables.
- Idempotency for anything retried: payments, charges, reminders, imports, webhooks.

**Internationalisation**
- Five languages for every user-visible string: web `lang/app/*.json` via `scripts/merge_translations.py`; app via `tool/i18n.py`. Reuse the website's wording where it exists.
- Arabic: **count-neutral wording** and correct plurals via `trans_choice` on the web; Urdu in Nastaliq with the existing font handling; RTL mirrors layouts, keeps amounts and phone numbers left-to-right.
- English: separate 1-vs-many strings.
- Customer-facing messages and documents use `customers.language`, falling back to the workspace language.

**Accessibility and design**
- Follow the existing design tokens; contrast is enforced by the existing token test. 48 dp touch targets; works at 200% text on a 360×640 phone; visible focus; `prefers-reduced-motion` respected; screen-reader labels on every icon button.
- App UI: the luxe layer (`lib/core/design/luxe.dart`), serif headings, gold used sparingly, count-up money, soft shadows. Web admin: the admin layout and tokens.
- **Verify visually**: `QISTAS_SCREENSHOTS=build/shots/<name> flutter test test/visual` for the app (add each new screen to `test/visual/screens_test.dart`); local browser screenshots for web. Bounded passes: build, inspect once, fix as a batch, confirm once.

**Performance and operations**
- Indexes with `tenant_id` first; pagination or cursors on every list; no queries in loops (add `Model::preventLazyLoading()` in the test environment).
- Heavy work on the queue run by the cron endpoint; jobs idempotent and re-check switches.
- Structured logs without personal data; job-run records; the admin overview shows cron health and the last failures.

**Documentation**
- Update `docs/api/openapi.yaml` (drift-tested), `app/README.md`, `docs/DEPLOY.md` (new env vars, cron, storage), and add `docs/features/<epic>.md` operator notes per epic ("what the admin switch does").

---

## 8. Definition of done (every feature)

- [ ] Registered in the `Feature` catalogue with `type`, `label`, `description`, `group`, `dependsOn`, `offBehaviour`, plan defaults; platform state `off` at release.
- [ ] Routes carry `feature:<key>`; Actions assert the entitlement; jobs re-check at run time; clients hide when not `on`.
- [ ] **Off-behaviour verified:** refused when off, data preserved, running processes stop, re-enabling restores.
- [ ] Authorisation by role (owner, manager, accountant, collector, viewer) tested; **tenant isolation** tested.
- [ ] Money paths idempotent and tested; ledger invariants unchanged (no in-place edits).
- [ ] Unit and feature tests written **first** (red, then green); edge cases listed in the spec covered; **mutation check** on the key rule(s) (break it, see a test fail, restore).
- [ ] `docs/api/openapi.yaml` updated; the drift test passes.
- [ ] Five languages complete (web and app); RTL checked; 200% text checked.
- [ ] Web: `pint --dirty`, `phpstan analyse`, the full `pest` suite green. App: `flutter analyze` at **zero** issues, full `flutter test` green, new screens added to the visual tests and inspected.
- [ ] Audit rows for state-changing actions; no secrets or national IDs in logs.
- [ ] Admin Feature-control card shows the feature with the right description, plan chips, dependencies, and usage badge.
- [ ] README/DEPLOY/operator notes updated; version bumped in `app/pubspec.yaml` when the app changes.
- [ ] Committed with a clear message, pushed, CI green; live deployment checked with read-only requests; an honest report of anything not verifiable (no device, no gateway, no WhatsApp approval).

---

## 9. Build order and milestones

| Milestone | Contents | Exit criteria |
|---|---|---|
| **M0 Foundation** | 0.1–0.6 | Feature control live in `/admin/features` with a fake feature proven end to end; storage, PDF, settings, cron endpoint working; catalogue-integrity test green |
| **M1 Core instalment operations** | B3 early settlement, F1 certificate, B1 targeting, B2 proof, C4 watchlist, C5 write-off, C1 late fees, C2 reschedule | An owner can settle early, charge and waive fees, reschedule, and write off, each switchable, ledger totals reconcile |
| **M2 Terms and paperwork** | A1, A4, A5, A6, A3, A2, F5 | A contract is created with payday and holiday-aware dates, items, guarantor, and signed on a phone as a branded PDF |
| **M3 Autopilot** | D1, D2, D3, C3, B5, D4 | A reminder cadence reaches a real WhatsApp test number, a button tap creates a promise, a customer opens the portal. *Needs the WhatsApp account* |
| **M4 Intelligence** | E1, A7, E2, E3, E4, E5 | Score and Smart Offer in the contract form, radar card on the dashboard, forecast screen |
| **M5 Operations** | B4, G1, G2, G3, F2, F3, F4, G5, then G6, G7, G4 (if approved), B6 (needs a gateway) | Team workflows and reports; imports from Excel and photo |
| **M6 Membership** | H1–H6 | A test subscription renews, fails, retries, goes read-only, and recovers, all on a fake clock and with the sandbox gateway. *Start in parallel with M3 once the owner has a gateway account* |
| **M7 Compliance** | I1 | Only after the owner's accountant and provider decisions |

Each milestone ends with a release: commit, push, CI green, the APK rebuilt (`app-latest`), and a short note listing what the admin must switch on, in what order.

**Rollout recipe (for every feature):** ship dark → turn on `beta` for the owner's own test workspace → verify on a phone → turn `on` for one real friendly shop → turn `on` for everyone → mention it in the weekly brief. The admin never needs a deploy to do any of these steps.

---

## 10. Decisions the owner must make (with my recommendation) and what to provide

| # | Decision | Recommendation |
|---|---|---|
| 1 | Create a **Business** plan tier? | Yes: Free / Pro / Business, so team and compliance features carry the higher price |
| 2 | Who can change platform feature state? | `super_admin` only; `admin` can view; every change audited |
| 3 | WhatsApp sender model | Start with a **platform sender number** and shop-name templates; add the shop's own number (embedded signup) later. Choose Meta Cloud API directly or a business solution provider |
| 4 | File storage provider | **Supabase Storage** (already in the stack); Cloudflare R2 as the alternative |
| 5 | Payment gateway for membership and pay links | Pick **one** of HyperPay, Tap, or Moyasar according to the owner's bank and commercial registration; all three advertise mada recurring support |
| 6 | **Late-fee** wording, legality, and the charity option | Owner's legal adviser per market; ship disabled |
| 7 | **Murabaha-style** contract wording | Legal adviser supplies approved text; none is invented |
| 8 | E-signature level | Simple electronic signature with a one-time code now; Nafath later; lawyer confirms enforceability |
| 9 | **G4 offline receipt drafts** | Approve for Business, with the safeguards above (drafts are never shown as recorded) |
| 10 | **ZATCA** scope, provider, and invoicing model for instalment sales | Accountant decides first; then choose a certified provider |
| 11 | E-mail (SMTP) provider | Set one up soon (Resend, Postmark, or SES); it also unblocks verification and reset mail |
| 12 | Sharing scores between shops | **No** for now; revisit with consent and legal review |
| 13 | Retention for lapsed workspaces and for personal data | Adviser decides; the brief assumes 12 months of export access |
| 14 | Price points | Out of scope here; anchor to the "paid for itself" receipt and test |

**Accounts and environment variables the owner provides (set in Vercel, never pasted into chat):**

| For | Variables |
|---|---|
| Cron | `CRON_SECRET` |
| Storage | `FILESYSTEM_DISK=s3`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT` |
| WhatsApp (D1) | `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_APP_SECRET`, `WHATSAPP_VERIFY_TOKEN`, plus approved templates |
| Billing and pay links | `BILLING_GATEWAY=<provider>` and that provider's keys and webhook secret (names depend on the choice) |
| AI (G6, G7) | `ANTHROPIC_API_KEY`, `QISTAS_AI_MODEL` |
| E-mail | `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` |
| ZATCA | provider-specific (after decision 10) |

---

## Appendix A: Formulas (v1; deterministic and unit-tested)

**Qistas Score** (per customer, from this shop's data only). Let `n_due` be the number of instalments due so far. If `n_due < 2` and the customer has no settled contract, the score is **null (New)**.

```
on_time_rate   = (instalments paid on or before their due date) / n_due
avg_days_late  = mean days late over instalments paid late or still overdue (capped at 30)
late_penalty   = avg_days_late / 30
overdue_now    = 1 if any instalment is overdue today, else 0
clean_contracts= min(5, settled contracts with zero late days)
down_norm      = min(1, average(down_payment / principal) / 0.30)

score = round(clamp(
          45 * on_time_rate
        + 20 * (1 - late_penalty)
        + 15 * (1 - overdue_now)
        + 10 * (clean_contracts / 5)
        + 10 * down_norm
        - 4  * min(5, broken_promises_last_12_months)
        - 6  * min(3, bounced_cheques_last_12_months)
        - 25 * written_off_contracts, 0, 100))
```
Bands: Excellent ≥ 80, Good 60–79, Watch 40–59, High risk < 40. Inputs never include any demographic attribute.

**Likely-late probability** for an instalment due in the next 7 days (heuristic, to be calibrated against outcomes):
```
p_late = clamp(0.05
        + 0.45 * (1 - on_time_rate)          # workspace average if the customer is New
        + 0.20 * late_penalty
        + 0.15 * overdue_now
        + 0.10 * (amount > 1.5 * customer's average instalment ? 1 : 0)
        + 0.05 * (a promise was broken in the last 30 days ? 1 : 0), 0, 0.95)
```

**Early-settlement rebate:** per-instalment markup share `= amount × markup_amount ÷ (financed + markup_amount)`; unearned markup `= Σ share over unpaid instalments not yet due`; `rebate = unearned × rebate_percent`.

**Cash-proportional profit:** `(total − cost) × (collected ÷ total)`.

## Appendix B: Message template keys (five languages each; variables must match across languages)

`reminder_before_due`, `reminder_due_today`, `reminder_overdue`, `reminder_overdue_firm`, `receipt_payment`, `receipt_paid_in_full`, `promise_confirmation`, `extension_decision`, `guarantor_notice`, `nudge_next_sale`, `signing_code`, `statement_link`, `membership_prebilling`, `membership_card_expiring`, `membership_payment_failed`, `membership_readonly`, `membership_recovered`. Variables use the existing `:name`, `:amount`, `:date`, `:reference`, `:remaining`, `:business` convention. Tone: polite, respectful, never threatening, never mentioning third parties.

## Appendix C: New statuses and their meaning

| Where | Status | Meaning |
|---|---|---|
| instalment | `waived` | Closed by an early-settlement rebate; counts as closed |
| instalment | `superseded` | Replaced by a reschedule; history kept |
| contract | `written_off` | Balance moved out of "Still to collect"; payments after it are recoveries |
| charge | `accrued / paid / waived / void` | Late or bounce fee lifecycle |
| cheque | `received / deposited / cleared / bounced / returned_to_customer / cancelled` | Legal transitions only |
| promise | `pending / kept / broken / cancelled` | Customer commitment |
| approval | `pending / approved / rejected / expired / cancelled` | Four-eyes workflow |
| subscription | `trialing / active / past_due / paused / canceled / expired` | Membership lifecycle |
| platform feature | `off / beta / on` | Admin switch |

## Appendix D: Paste-ready kick-off prompt

> Read `docs/features/QISTAS_FEATURES_BRIEF.md` completely, then confirm your understanding in a short note. Do **not** write code yet. Start with **Milestone M0 (Foundation)**: use the `brainstorming` skill to confirm the design of the Feature-control system (Part 3) and items 0.2–0.6 in chat, write the implementation plan with `writing-plans`, and ask me to approve it. Build with `test-driven-development`. Every feature must have an admin Off/Beta/On switch. After each commit, push and give me the live link. Never ask me to paste secrets in chat; tell me which environment variable to set in Vercel and what it is for.

## Appendix E: Research basis (compiled 2026-10-08)

The market and cost claims in this brief come from vendor pages and trade articles found through web search on the date above. Prices, regulations, and vendor features change: **verify before relying on any figure**, especially the ZATCA waves, the WhatsApp rates, and the competitor prices. Where a claim comes only from a vendor's own marketing (dunning recovery rates, annual-plan churn effects) treat it as an upper bound, not a promise. Effect sizes for Qistas features (how much reminders or scoring improve collection) have **not been measured**: pilot with 10 to 20 shops and publish only measured numbers.

- Ledger apps: [Vyapar pricing (Capterra)](https://www.capterra.com/p/180579/Vyapar/pricing/), [OkCredit vs Khatabook](https://okcredit.in/khatabook-alternative), [Khatabook (Techjockey)](https://techjockey.com/brand/khatabook).
- Buy-now-pay-later: [Tabby research (Sacra)](https://sacra.com/research/tabby), [Tabby for business](https://tabby.ai/business), [MISpay on Shopify](https://apps.shopify.com/mispay?locale=cs), [Aman (Daily News Egypt)](https://www.dailynewsegypt.com/?p=853482), [valU launch (EFG Hermes)](https://efghldg.com/media/news/EFG-Hermes-Launches-‘valU’-for-Instalment-Sale-Services-in-the-Egyptian-Market-), [SAMA BNPL rules](https://www.arabianbusiness.com/gcc/saudi-arabia/saudi-arabia-issues-rules-for-buy-now-pay-later-firms).
- ERP modules: [Odoo eK Installment](https://apps.odoo.com/apps/modules/19.0/ek_installment), [Odoo post-dated cheque module](https://apps.odoo.com/apps/modules/18.0/sim_customer_post_dated_cheque_app), [SMACC installment system](https://www.smacc.com/en/?p=1434), [Nebim instalment sales](https://www.nebim.com.tr/en/instalment-sales).
- Lending cores: [LoanPro, best loan software 2026](https://loanpro.io/blog/best-loan-management-software).
- Billing recovery: [Dunning guide (Finsi)](https://finsi.ai/blog/automated-dunning-software-guide/), [Failed payments and involuntary churn (Kinde)](https://kinde.com/learn/billing/churn/failed-payments-and-involuntary-churn/), [Reduce SaaS churn 2026 (Fungies)](https://fungies.io/reduce-saas-churn-guide-2026/).
- Saudi payments and messaging: [HyperPay mada and Apple Pay recurring](https://www.zawya.com/en/press-release/companies-news/hyperpay-announce-its-support-for-mada-apple-pay-recurring-services-for-merchants-seamless-payment-services-ia2khrg9), [Payment gateways in Saudi Arabia](https://learnwithhasan.com/payment-gateways/country/saudi-arabia/), [WhatsApp CRM for Saudi Arabia 2026](https://www.go4whatsup.com/saudi-arabia/whatsapp-crm-guide-2026/).
- Credit data and e-invoicing: [SIMAH integration 2026 (members-only access)](https://noqta.tn/en/blog/simah-credit-bureau-api-integration-saudi-fintech-2026), [ZATCA e-invoicing guide 2026](https://noqta.tn/en/blog/zatca-fatoorah-e-invoicing-saudi-guide-2026).

*End of brief.*

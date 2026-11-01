# Go-Live Basics (Release 1 of 3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A five-field sign-up that detects the visitor's country and time zone, records every sign-in's device and proxy verdict, drops the public demo, and gives the owner a read-only go-live check and a safe demo purge.

**Architecture:** The sign-up action takes optional business name, country and time zone; what is missing is detected on the server (CDN header, then browser language) and the workspace starts unconfirmed with a placeholder name until the owner confirms one first-run card (name and region), which also unlocks document PDFs. A new append-only, user-owned `sign_in_events` table is written from the Fortify login/registration responses, the credential check (failures) and the phone-app auth controller. The public throw-away demo is removed; `qistas:go-live-check` and `qistas:purge-demo` are new commands.

**Tech Stack:** Laravel 13 / PHP 8.4 (the live server runs PHP 8.5 and PostgreSQL 18), Blade + the Alpine CSP build, Pest, Pint, Larastan level 6; Flutter 3.41.2 (Riverpod, go_router, dio); new dependency `matomo/device-detector`.

**Spec:** `docs/superpowers/specs/2026-11-01-go-live-basics-design.md` (the owner approved it on 2026-11-01). Releases 2 (error center) and 3 (phone code, Google, Outlook) get their own specs and plans: nothing here builds them.

## Plan deviations from the spec (Task 1 writes them into the spec)

Found while reading the code to write this plan. The spec is otherwise binding.

| # | Spec said | What the code showed | This plan |
|---|---|---|---|
| D1 | `purge-demo` matches `demo_expires_at`, an address at `@demo.qistas.test`, or the seeder's address | A real person could type an address at that reserved domain when signing up | Match only `demo_expires_at` set, or the seeder's address (`DemoSeeder::EMAIL`). An account at the demo domain without `demo_expires_at` is a `WARN` in the go-live check and is never purged |
| D2 | The payload key is `workspace.setup` | `AccountPayload` nests the workspace under `tenant` | The key is `tenant.setup` |
| D3 | Whether the country was detected is known later | Nothing is stored about how the country was chosen | New nullable column `tenants.country_source` (`header`, `language`, `default`, `chosen`), beside `setup_confirmed_at` |
| D4 | Hook points chosen in the plan | Laravel fires a login event when a remember-me cookie restores a session, so an event listener would record noise | Web successes are written from Fortify's response contracts (`LoginResponse`, `RegisterResponse`, `TwoFactorLoginResponse`), failures from `AuthenticateUser`, the phone app from `AuthController` |
| D5 | `forwarded_chain` is "more hops than the trusted CDN explains" | The number of hops depends on the server's proxy set-up and is not known here | `qistas.proxy_chain_allowed` (env `QISTAS_PROXY_CHAIN_ALLOWED`, default 2); Task 12 has the owner check a normal sign-in reads "not detected" and adjust the number if not |
| D6 | The person reads sign-ins "from the API" | No such route exists | `GET /api/v1/account/sign-ins` (last 20, own only) |
| D7 | Remove the demo banners and the sign-in page's demo blocks, but keep the container's built-in demo mode | The container demo mode (`qistas.demo`) is what shows the banner and the demo credentials, and `scripts/e2e-demo.sh` and the CI job "Start with no configuration (demo mode)" depend on them | Remove only the **throw-away public demo** ("Try the demo" buttons, its API, `DemoAccess`, `DemoBusy`, prune, `qistas.demo_login`, the `demo-account-banner`). **Keep** `qistas.demo`, `x-demo-banner`, the credentials block and the CI job. `qistas:go-live-check` fails when `qistas.demo` is on |
| D8 | The setup route accepts name and region | A card that resends unchanged values must not fail once the region is locked | `PUT .../setup` ignores `country` and `timezone` equal to the current values; a changed region while locked is refused as a whole (nothing saved) |

## Global Constraints

- **Commands** (from `web/CLAUDE.md`): `php vendor/bin/pest`, `php vendor/bin/pint --dirty` (never bare `pint`), `php vendor/bin/phpstan analyse --memory-limit=1G --no-progress` (level 6); in `app/`: `flutter analyze` (**zero** issues, infos included), `flutter test`. Pest prints huge HTML on failure: run one file at a time and summarise.
- **Write the failing test first**, see it fail for the right reason, then implement. Real code over mocks.
- **Tenancy.** Every tenant-owned model uses `App\Tenancy\BelongsToTenant`. **One deliberate exception:** `SignInEvent` is owned by a *person* (who can belong to several workspaces), so it has no tenant scope and is only read through `$user->signInEvents()` and the staff pages.
- **The ledger is untouched.** Nothing in this release writes or edits `transactions`.
- **Secrets and PII.** Never store the e-mail or phone typed in a failed sign-in for an unknown account; never log or audit passwords, tokens or national IDs. IP addresses live only in `sign_in_events` (90 days).
- **Old clients.** `POST /api/v1/auth/register` keeps accepting `business_name` and `country` (app 1.28.x and older send them).
- **Demo.** The `tenants.is_demo` and `users.demo_expires_at` columns and the "skip demo tenants" filters in scheduled commands stay. The developer seeder (`DemoSeeder`, `qistas:setup --demo`) stays and keeps refusing to run in production.
- **Migrations** are additive and must run on SQLite (tests) **and PostgreSQL** (live: PostgreSQL 18). The controller runs `php artisan migrate:fresh --force` and the new tests on real PostgreSQL (PGlite: `node node_modules/@electric-sql/pglite-socket/dist/scripts/server.cjs --port=5433 --max-connections=4`, env `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=postgres DB_USERNAME=postgres DB_PASSWORD=postgres DB_SSLMODE=disable DB_URL= DB_EMULATE_PREPARES=true`, `--exclude-group=db-errors`) before pushing a task that has a migration. Keep SQL portable (no SQLite-only functions).
- **Strings.** Web: every new `__('...')` exists in `web/lang/app/{ar,fr,es,ur}.json` (`python scripts/merge_translations.py batch.json` from `web/`; `TranslationsTest` fails on a missing or stale string); Arabic wording count-neutral. App: `context.t('...')`, then `python tool/i18n.py --missing`, a batch JSON, `python tool/i18n.py batch.json`, `python tool/i18n.py --sync`.
- **Every API route** is described in `docs/api/openapi.yaml` (`tests/Feature/Api/OpenApiTest.php` compares the file with the routes).
- **Pest helper functions are global across files:** give every file-level helper a prefix of its own.
- **App rules.** Never run `dart format` on existing files (this repo writes long lines by hand); app tests use `test/support/harness.dart` and `FakeClock`; add each new screen to `test/visual/screens_test.dart` and look at the pictures once.
- **The live site is https://www.qistasme.com.** Live checks are read-only GETs; never create an account or press anything there. Never use the hosting-panel MCP tools without the owner choosing them (one of them belongs to another business's server).
- **Deploy order.** Web tasks (1-9) are pushed and live **before** any app task (10-11) is pushed: app 1.29 sends no business name, which an older server refuses. After the last web push, confirm with a read-only GET that the live `/register` page no longer has `business_name`.
- **Commits** end with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. The controller pushes each reviewed task by exact commit (`git push origin <sha>:main`) through the owner's terminal tool (the shell's own git credentials are the wrong account).

## Review Focus

The ways this work is most likely to hurt a person, and where a test pins each.

1. **A wrong country guess.** A visitor on a VPN, travelling, or on an unlisted country must not get the wrong currency or time zone silently, and a later mistake must not change currency under recorded money. (Tasks 2, 3, 4: detection order, the card, the region lock.)
2. **A placeholder name reaching a customer.** Every kind of PDF must wait for the confirmed name, on the web and in the API; nothing else may be blocked; staff test workspaces, the seeder and old workspaces are never asked. (Tasks 1, 3, 4.)
3. **Sign-in records that lie or leak.** One record per real sign-in, none for a remember-me restoration or an ordinary request, failed attempts throttled and only for existing accounts, no typed identifier stored, a person sees only their own, 90-day removal. (Tasks 5, 6, 7.)
4. **Old and new clients disagreeing.** An old app registering with a business name and country, a new app registering without; the deploy order. (Tasks 2, 10, 12.)
5. **Demo removal that breaks something else or deletes a real account.** Scheduled commands, staff test workspaces, the seeder and the container's demo mode keep working; the purge is a dry run by default, narrow in what it matches, and deletes only what was printed. (Tasks 8, 9.)

---

### Task 1: The setup columns, and the spec's corrections

**Files:**
- Create: `web/database/migrations/2026_11_01_000100_add_setup_to_tenants.php`
- Modify: `web/app/Models/Tenant.php`, `web/database/factories/TenantFactory.php`, `web/app/Sandbox/TestWorkspace.php`, `docs/superpowers/specs/2026-11-01-go-live-basics-design.md`
- Test: `web/tests/Feature/Tenancy/WorkspaceSetupColumnsTest.php`

**Interfaces:**
- Produces: columns `tenants.setup_confirmed_at` (timestamp, nullable) and `tenants.country_source` (string 12, nullable; `header`, `language`, `default` or `chosen`); `Tenant::isSetUp(): bool` (true when `setup_confirmed_at` is not null); `setup_confirmed_at` is cast to a date-time and **not fillable** (trusted code only).

- [ ] **Step 1: Write the failing tests** in `WorkspaceSetupColumnsTest.php`:
  - `a workspace made by the factory is confirmed` (so every existing test that makes a tenant keeps working).
  - `isSetUp is false only while setup_confirmed_at is null`.
  - `the migration confirms every workspace that exists when it runs`: roll this one migration back (`migrate:rollback --path=... --realpath` or the equivalent), insert two tenants, run it again, and assert each tenant's `setup_confirmed_at` equals its own `created_at` and `country_source` is still null.
  - `a staff test workspace is confirmed when it is made` (through `TestWorkspace`).
  - `setup_confirmed_at cannot be mass assigned`.
- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/Tenancy/WorkspaceSetupColumnsTest.php`. Expected: FAIL (no column).
- [ ] **Step 3: Implement** the migration (add both columns, then backfill with one SQL update `setup_confirmed_at = created_at`; `down()` drops them), the model cast and `isSetUp()`, the factory default (`setup_confirmed_at` = now), and `TestWorkspace` marking its workspace confirmed.
- [ ] **Step 4: Write the plan's corrections into the spec:** add a `### Plan deviations` subsection at the end of section 10 listing D1 to D8 above, one line each, and fix section 6.1/6.2 to say what D7 says (the container demo mode, its banner and credentials block stay; only the throw-away demo goes). Do not change anything else in the spec.
- [ ] **Step 5: Run** the new test file and `tests/Feature/Tenancy`. Pint, PHPStan. Expected: PASS.
- [ ] **Step 6: Commit** `Workspaces know whether their setup is confirmed` (code) and `Spec: deviations found while planning Release 1` (spec), as two commits.

---

### Task 2: Detecting the place, and a sign-up without business name or country

**Files:**
- Create: `web/app/Support/SignupPlace.php`
- Modify: `web/app/Actions/RegisterTenantOwner.php`, `web/app/Actions/Fortify/CreateNewUser.php`, `web/config/qistas.php`, `web/lang/app/{ar,fr,es,ur}.json`
- Test: `web/tests/Feature/Tenancy/SignupPlaceTest.php`; extend `web/tests/Feature/Tenancy/RegistrationTest.php` and `TimezoneTest.php` (they must still pass unchanged where they send a business name and country)

**Interfaces:**
- Consumes: `qistas.geo_header`, `qistas.countries`, `qistas.timezones`, `qistas.currency_default`; the CDN-header safety rule of `App\Theme\Visitor` (only the configured header, never one a browser could send itself; non-countries `XX T1 A1 A2 O1 EU AP` ignored). Reuse its parsing: make `Visitor::code()` reachable (public static or a shared helper) rather than copying it.
- Produces:
```php
final class SignupPlace {
    /** @return array{country: string, source: 'header'|'language'|'default'} the first match wins; 'default' returns config('qistas.default_country') */
    public static function detect(Request $request): array;
    /** The IANA zone a new workspace starts with. */
    public static function timezone(string $country, ?string $iana, ?int $utcOffsetMinutes, ?CarbonInterface $now = null): string;
}
```
`config('qistas.default_country')` = env `QISTAS_DEFAULT_COUNTRY`, default `SA`. `RegisterTenantOwner::handle(array $data): User` where `$data` is `name, email, password` plus optional `locale, business_name, country, country_source, timezone`: with `business_name` the tenant is named from it and `setup_confirmed_at = now()`; without it the name is the translated placeholder `Business of :name` (`:name` is the person's first word, at most 40 characters), `setup_confirmed_at` stays null; `country` defaults to the configured default with `country_source = 'default'`; `timezone` defaults to the country's table zone (else `UTC`). `CreateNewUser` validates `business_name` (nullable, string, max 255), `country` (nullable, two letters), `timezone` (nullable, string, max 64), `utc_offset` (nullable, integer from -840 to 840), then calls `SignupPlace::detect(request())` and `SignupPlace::timezone(...)` and passes `country`, `country_source` (`chosen` when the client sent a valid country), `timezone` on.

`timezone()` rule, in order: a valid IANA name (in `DateTimeZone::listIdentifiers()`); else, when `$utcOffsetMinutes` is given, the first zone of `DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $country)` whose offset at `$now` (default now) equals it; else `config('qistas.timezones')[$country]`; else `UTC`.

- [ ] **Step 1: Write the failing tests.** `SignupPlaceTest.php` (set `qistas.geo_header` to `CF-IPCountry` where needed):
  - `detect`: the header wins over the language; lower-case and padded header values are normalised; `XX` and `T1` are ignored and the language is tried next; with `geo_header` empty a request carrying `CF-IPCountry: QA` is ignored; `Accept-Language: ar-QA,ar;q=0.9` gives `QA` with source `language`; nothing at all gives the default with source `default`; an unlisted code such as `PS` is returned as is with source `header`.
  - `timezone`: `Asia/Qatar` wins; an invalid name is ignored; offset `180` with `SA` gives `Asia/Riyadh`; `240` with `AE` gives `Asia/Dubai`; offset `-300` with `US` in January and `-240` in July (pass `$now`) both give a United States zone; an offset no zone of the country has falls back to the table zone; no hints gives the table zone; an unknown country gives `UTC`.
  - `RegistrationTest`/new `SignUpFormTest.php` (web `POST /register` and `POST /api/v1/auth/register`): a request with only name, email, password, confirmation and terms creates the account; with `CF-IPCountry: QA` the tenant is `QA`, `QAR`, `Asia/Qatar`, `country_source = header`; with only `Accept-Language: ar-SA` it is `SA`, `language`; with nothing it is the default, `default`; a posted `timezone=Asia/Riyadh` is stored; `utc_offset=180` on the API maps to the country's zone; the placeholder is `Business of Layla` in English and `نشاط ليلى` in Arabic and the workspace is **not** confirmed; two people named Layla get different slugs; a 255-character name gives a placeholder no longer than the limit; a client that still sends `business_name` and `country` gets that name, that country, `country_source = chosen` and a **confirmed** workspace; `country=ZZZ` and `country=12` are refused with a `country` error; `utc_offset=9999` is refused.
- [ ] **Step 2: Run** the files. Expected: FAIL (class not found, fields still required).
- [ ] **Step 3: Implement** `SignupPlace`, the `RegisterTenantOwner` and `CreateNewUser` changes, the config setting and the `Business of :name` strings in the four languages.
- [ ] **Step 4: Run** `tests/Feature/Tenancy`, `tests/Feature/Auth`, `tests/Feature/Api` (sign-up and sign-in files), `TranslationsTest`, `OpenApiTest`; Pint; PHPStan. Expected: PASS. (`docs/api/openapi.yaml`: `business_name` and `country` become optional on the register body; `timezone`, `utc_offset` are added.)
- [ ] **Step 5: Commit** `Sign-up needs no business name or country: the place is detected`.

---

### Task 3: Confirming the setup, and documents that wait for it (server)

**Files:**
- Create: `web/app/Actions/ConfirmWorkspaceSetup.php`, `web/app/Http/Requests/WorkspaceSetupRequest.php`, `web/app/Http/Controllers/Api/V1/WorkspaceSetupController.php`, `web/app/Documents/WorkspaceSetupRequired.php`
- Modify: `web/app/Documents/DocumentService.php`, `web/app/Http/Api/AccountPayload.php`, `web/routes/api.php`, `docs/api/openapi.yaml`, `web/lang/app/{ar,fr,es,ur}.json`
- Test: `web/tests/Feature/Api/WorkspaceSetupTest.php`, `web/tests/Feature/Documents/SetupGateTest.php`

**Interfaces:**
- Consumes: `Tenant::isSetUp()`, `SignupPlace::timezone()`, `qistas.countries`, `qistas.timezones`, `Audit::record`, the owner check (`TenantRole`).
- Produces:
```php
final class ConfirmWorkspaceSetup {
    /** @param array{name: string, country?: string, timezone?: string} $data
     *  @throws ValidationException on `country` or `timezone` when the region is locked and the value differs */
    public function handle(Tenant $tenant, User $by, array $data): Tenant;
    /** True while the workspace has no contract and no transaction (read inside its tenant context). */
    public function regionIsOpen(Tenant $tenant): bool;
}
final class WorkspaceSetupRequired extends RuntimeException {} // renders: API 409 `workspace_setup_required`; web: redirect to app.dashboard with the warning flash
```
`PUT /api/v1/workspace/setup` (`workspace.setup`, owner only: others 403, throttled like the other settings routes) takes `name` (required, trimmed, 1 to 255 characters, not only whitespace), optional `country` (two letters in `qistas.countries`) and `timezone` (valid IANA); answers `{data: {tenant: {...AccountPayload tenant part}}}`. `AccountPayload` gains `tenant.setup`: `{confirmed: bool, country: string, currency: string, timezone: string, country_detected: bool, can_change_region: bool}` where `country_detected` is true only when `country_source` is `header` or `language` and the country is listed. `DocumentService::issue()` throws `WorkspaceSetupRequired` first thing when the workspace is not confirmed.

- [ ] **Step 1: Write the failing tests.**
  - `WorkspaceSetupTest`: the owner confirms (name saved, `setup_confirmed_at` set, audit row `workspace.setup_confirmed` whose `changes` hold only name, country, currency and time zone); an allowed region change is applied (currency from the table, zone from the table or the one given) and `country_source` becomes `chosen`; **with a contract in the workspace, and separately with only a transaction,** a different country is refused with a `country` error and **nothing** is saved (name included); sending the same country and zone while locked is fine and saves the name; a manager, a collector and a viewer get 403; an invalid name (empty, spaces, 256 characters) and an unlisted country are refused; the payload keys and types of `tenant.setup`, including `country_detected` for each `country_source`, an unlisted country and `can_change_region` before and after a contract; throttling.
  - `SetupGateTest`: with the workspace unconfirmed, every kind of PDF is refused with `WorkspaceSetupRequired` (build a dataset from the real `Template` classes: receipt, statement, contract, transactions report, investor report, day-close summary) and nothing is written to `documents`; the API routes answer 409 `workspace_setup_required` (two of them) and two web routes redirect to `app.dashboard` with the warning; after confirming, the same requests produce the PDF; customers, contracts and payments are never blocked while unconfirmed; an old workspace (migrated) and a staff test workspace are never refused.
- [ ] **Step 2: Run** both files. Expected: FAIL.
- [ ] **Step 3: Implement** the action, request, controller, route, exception (with its `render()`), the gate in `DocumentService`, the payload keys, the OpenAPI path and schemas, and the translations (the warning "Name your business first. It is printed on every receipt." and the validation messages).
- [ ] **Step 4: Run** the new files, `tests/Feature/Documents`, `tests/Feature/Api`, `OpenApiTest`, `TranslationsTest`. Pint, PHPStan. Expected: PASS.
- [ ] **Step 5: Commit** `The owner confirms the workspace's name and place once, and documents wait for it`.

---

### Task 4: The web: five-field sign-up, the first-run card, the time-zone hint

**Files:**
- Create: `web/resources/views/components/setup-card.blade.php`, `web/app/Http/Controllers/Workspace/SetupController.php`, `web/resources/js/timezone.js`
- Modify: `web/resources/views/auth/register.blade.php`, `web/resources/views/auth/login.blade.php` (the hidden time-zone field only; demo removal is Task 8), `web/resources/views/app/dashboard.blade.php`, `web/routes/app.php`, `web/resources/js/app.js` (import the script on every layout that has these forms), the app CSS for the card, `web/lang/app/{ar,fr,es,ur}.json`
- Test: `web/tests/Feature/App/SetupCardTest.php`; update the existing tests that post the old sign-up form

**Interfaces:**
- Consumes: Task 3's `ConfirmWorkspaceSetup`, `tenant.setup` data, `Entitlements`-free (the card has no feature switch).
- Produces: web route `PUT /app/setup` named `app.setup.update` (owner only, throttled `20,1`); component `<x-setup-card :tenant="$tenant" />`; `timezone.js` fills every `input[name=timezone]` inside a form marked `data-timezone` with `Intl.DateTimeFormat().resolvedOptions().timeZone` (no inline expressions: the CSP build).

- [ ] **Step 1: Write the failing tests** (`SetupCardTest.php` plus form tests):
  - `the sign-up page has name, email, password, confirmation and terms, and no business name or country field`; it has a hidden `timezone` input in a `data-timezone` form, and so does the sign-in page.
  - A posted five-field form creates an account whose first dashboard shows the card.
  - The card: shown to the owner while unconfirmed; not shown once confirmed; not shown to an invited member of an unconfirmed workspace; shows "We think you are in Qatar: QAR, Doha time" (the country's name, currency and zone) with a Change control when the country was detected and the region is open; shows a country chooser when the source is `default`; shows the name field only (no region control, with the explanation) when a contract exists.
  - `PUT /app/setup`: saves, redirects to the dashboard with a status flash, hides the card; errors appear next to the field; a manager gets 403; Arabic renders right to left.
  - A document route while unconfirmed redirects to the dashboard and the card is visible with the warning.
  - Escaping: a name containing markup is shown escaped.
- [ ] **Step 2: Run** the files. Expected: FAIL.
- [ ] **Step 3: Implement** the Blade, controller, route, script and styles. Reuse the existing form components (`x-field`, `x-button`) and the app's card styles; mobile-first; no new fonts.
- [ ] **Step 4: Run** the new tests, `tests/Feature/App`, `tests/Feature/Auth`, `tests/Feature/Tenancy`, `TranslationsTest`. Pint, PHPStan. Then the controller builds the assets and looks at `/register`, `/login` and the dashboard card in the built-in browser against a local server (English and Arabic, light and dark, 375 px wide), as in wave A (local demo database, `web/.env` and `public/build` are git-ignored).
- [ ] **Step 5: Commit** `Sign-up has five fields, and a first-run card asks for the business name once`.

---

### Task 5: The sign-in record: table, device and proxy recognition, retention

**Files:**
- Create: `web/database/migrations/2026_11_01_000200_create_sign_in_events.php`, `web/app/Models/SignInEvent.php`, `web/app/Auth/DeviceProfile.php`, `web/app/Auth/ProxyCheck.php`, `web/app/Auth/SignInRecorder.php`, `web/app/Console/Commands/PruneSignInsCommand.php`
- Modify: `web/composer.json` and `composer.lock` (`composer require matomo/device-detector`; confirm Composer accepts it under PHP 8.5, the live server's version, and report the version chosen), `web/app/Models/User.php` (`signInEvents(): HasMany`), `web/config/qistas.php` (`sign_in_days` = env `QISTAS_SIGN_IN_DAYS` default 90; `proxy_chain_allowed` = env `QISTAS_PROXY_CHAIN_ALLOWED` default 2), `web/routes/console.php`
- Test: `web/tests/Feature/Auth/DeviceProfileTest.php`, `ProxyCheckTest.php`, `SignInRecorderTest.php`, `PruneSignInsTest.php`

**Interfaces:**
- Produces:
```php
final class DeviceProfile {
    /** @param array{type?: string, os?: string, model?: string, app_version?: string}|null $reported what the phone app says about itself (a hint)
     *  @return array{device_type: 'desktop'|'mobile'|'tablet'|'unknown', os: ?string, browser: ?string, app_version: ?string, device_name: ?string, user_agent: ?string} */
    public static function describe(Request $request, string $channel, ?array $reported = null): array;
}
final class ProxyCheck {
    /** @return array{verdict: 'not_detected'|'possible'|'detected', signals: list<string>, ip_country: ?string}
     *  signals: tor | via_header | forwarded_chain | timezone_country_mismatch */
    public static function inspect(Request $request, ?string $reportedZone, ?int $reportedUtcOffset): array;
}
final class SignInRecorder {
    public function success(User $user, Request $request, string $method = 'password', string $channel = 'web'): SignInEvent;
    /** Null when throttled: at most one failed record per account and address per minute. */
    public function failed(User $user, Request $request, string $method = 'password', string $channel = 'web'): ?SignInEvent;
}
```
Table `sign_in_events`: `id` uuid, `user_id` (foreign key to users, cascade on delete), `method` string 16, `channel` string 8 (`web` or `app`), `outcome` string 8 (`success` or `failed`), `device_type` string 8, `os` string 64 null, `browser` string 64 null, `app_version` string 16 null, `device_name` string 100 null, `ip` string 45, `ip_country` char 2 null, `proxy` string 12, `proxy_signals` json, `user_agent` string 255 null, `created_at`; indexes `(user_id, created_at)` and `(created_at)`; **no** `updated_at`. The model refuses updates and deletes (copy how `AuditLog` is made append-only); only `PruneSignInsCommand` (`qistas:sign-ins:prune`, daily, `withoutOverlapping`, checked at run time) removes rows older than `qistas.sign_in_days`. The reported zone and offset reach the recorder as the request inputs `timezone` and `utc_offset`; the phone app's hint is the input `device` (an object).

Proxy rule: `tor` when the CDN country code is `T1` **and** `qistas.geo_header` is configured; `via_header` when the request has a `Via` header; `forwarded_chain` when `X-Forwarded-For` lists more addresses than `qistas.proxy_chain_allowed`; `timezone_country_mismatch` when the IP's country is known and the reported IANA zone is not one of that country's zones (or, for an offset, no zone of that country has that offset now). Verdict: `detected` when `tor`, `via_header` or `forwarded_chain` is present; `possible` when only the mismatch is; otherwise `not_detected`.

- [ ] **Step 1: Write the failing tests.**
  - `DeviceProfileTest` with real user-agent strings: iPhone Safari, Android Chrome phone, an Android tablet (Chrome without the `Mobile` token), iPad Safari on a mobile user agent, iPadOS in desktop mode (documented: recorded as `desktop`), Windows Chrome, macOS Safari, Firefox on Linux, Samsung Internet, an empty user agent (`unknown`); the app channel with a reported `{type: 'tablet', os: 'android', model: 'SM-X200', app_version: '1.29.0'}` gives `tablet` and the app version; an invalid reported type is ignored; the user agent is cut to 255 characters; `os` and `browser` are bounded.
  - `ProxyCheckTest`: every signal alone and the verdict it gives; two signals; `T1` with the header not configured gives nothing; an `X-Forwarded-For` of exactly the allowed length gives nothing and one more gives `forwarded_chain`; the mismatch needs a known IP country; an offset case.
  - `SignInRecorderTest`: `success` writes one row with every field; `failed` writes one; a second `failed` for the same account and address within a minute returns null and writes nothing, a different address does write, and after 61 seconds (travel the clock) it writes again; the table has no e-mail or phone column and the typed e-mail never appears in any stored value; `update` and `delete` on a model throw; `$user->signInEvents()` returns only that person's rows newest first; deleting the user removes the rows.
  - `PruneSignInsTest`: rows older than 90 days go, rows at 89 days stay, the days setting is honoured, and the command is on the schedule.
- [ ] **Step 2: Run** the files. Expected: FAIL.
- [ ] **Step 3: Implement** all of the above. Keep the three classes free of framework globals beyond the request they are given so they are easy to test.
- [ ] **Step 4: Run** the new files and `tests/Feature/Auth`; Pint; PHPStan. The controller runs the migration and these tests on PostgreSQL (PGlite).
- [ ] **Step 5: Commit** `Record every sign-in: device, network and whether a proxy shows`.

---

### Task 6: Writing the record when a person really signs in

**Files:**
- Create: `web/app/Http/Responses/RecordsSignIn.php` (Fortify `LoginResponse`), `web/app/Http/Responses/RecordsRegistration.php` (Fortify `RegisterResponse`)
- Modify: `web/app/Providers/FortifyServiceProvider.php` (bind both), `web/app/Http/Responses/RemembersTrustedDevice.php` (record in the login branch only), `web/app/Actions/Auth/AuthenticateUser.php` (the failed record), `web/app/Http/Controllers/Api/V1/AuthController.php` (login and register), `docs/api/openapi.yaml` (the optional `device`, `timezone`, `utc_offset` request fields)
- Test: `web/tests/Feature/Auth/SignInHooksTest.php`

**Interfaces:**
- Consumes: Task 5's `SignInRecorder`. The responses delegate to Fortify's own default response classes (`Laravel\Fortify\Http\Responses\LoginResponse` and `RegisterResponse`) after recording, so redirects and JSON answers are unchanged.
- Produces: exactly one `success` row per real sign-in or sign-up (D4); one `failed` row when `AuthenticateUser` finds the account but the password is wrong; the phone app's rows have `channel = app` and use the request's `device` hint.

- [ ] **Step 1: Write the failing tests.**
  - Web password sign-in writes one `success` (method `password`, channel `web`, the device parsed from the user agent, the IP, the verdict from a posted `timezone`).
  - A later request restored from a remember-me cookie writes **none**; fifty authenticated page requests write none.
  - A workspace owner with two-factor: after the password step no row; after a correct code one row; the "trusted device skips the code" sign-in writes one at the login; a wrong code writes none.
  - Web registration writes one `success`.
  - A wrong password for an existing account writes one `failed`; for an unknown e-mail none; a suspended account with the right password writes none; repeated wrong passwords from one address within a minute write one.
  - `POST /api/v1/auth/login` writes `success` with channel `app`, the posted `device` hint and `utc_offset`; with a two-factor code it writes once; a wrong password writes `failed`; `POST /api/v1/auth/register` writes one.
  - The recorder failing (make it throw) never fails the sign-in: the person is signed in and the exception is reported (wrap the call so a record problem cannot lock anybody out).
  - Existing sign-in and sign-up tests still pass.
- [ ] **Step 2: Run** the file. Expected: FAIL.
- [ ] **Step 3: Implement** the responses, bindings, the failure hook and the API calls. Mutation check: delete the remember-me guard in your design (record from a login event listener instead) and confirm the remember-me test fails; restore.
- [ ] **Step 4: Run** `tests/Feature/Auth`, `tests/Feature/Api`, `OpenApiTest`, `tests/Feature/Tenancy`; Pint; PHPStan. Expected: PASS.
- [ ] **Step 5: Commit** `Write one sign-in record per real sign-in, and one per wrong password`.

---

### Task 7: Where the record is shown: the person and staff

**Files:**
- Modify: `web/app/Http/Controllers/SecurityController.php`, `web/resources/views/account/security.blade.php`, the API account controller that serves `/me` (find it; add the route beside it) and `web/routes/api.php`, `web/app/Http/Controllers/Admin/BusinessController.php`, `web/resources/views/admin/businesses/show.blade.php`, `docs/api/openapi.yaml`, `web/lang/app/{ar,fr,es,ur}.json`
- Create: `web/app/Http/Resources/SignInEventResource.php` (or an array mapper in the controller if resources are not used there)
- Test: `web/tests/Feature/Auth/SignInSurfacesTest.php`

**Interfaces:**
- Produces: `GET /api/v1/account/sign-ins` (`account.sign-ins`, signed-in person, throttled like `/me`) answering `{data: [{id, method, channel, outcome, device_type, os, browser, app_version, device_name, ip, ip_country, proxy, proxy_signals, created_at}]}`, the person's last 20, newest first, `created_at` as UTC ISO 8601; a "Recent sign-ins" section on the web security page (which already asks for the password again); a "Recent sign-ins" table per member on the admin business page. Wording for the proxy verdict: "Proxy not detected", "Proxy possible", "Proxy detected".

- [ ] **Step 1: Write the failing tests:** the person sees their own last 20, newest first, never another person's; the web section shows the device line (type, system, browser, country, verdict wording, relative time) and `failed` rows are marked; an escaped user agent; the API shape and types, 401 without a token; staff see each member's rows on the business page and a customer (not staff) gets the usual refusal; the page for a business whose members have no records says so in a sentence; Arabic renders right to left.
- [ ] **Step 2: Run** the file. Expected: FAIL.
- [ ] **Step 3: Implement** the three surfaces and the OpenAPI path and schema.
- [ ] **Step 4: Run** the file, `tests/Feature/Admin`, `OpenApiTest`, `TranslationsTest`; Pint; PHPStan. The controller looks at the security page and the admin page once in the browser.
- [ ] **Step 5: Commit** `People and staff can see the recent sign-ins`.

---

### Task 8: Remove the throw-away public demo (web)

**Files:**
- Delete: `web/app/Http/Controllers/DemoLoginController.php`, `web/app/Http/Controllers/Api/V1/DemoController.php`, `web/app/Http/Controllers/Admin/DemoAccountsController.php`, `web/app/Sandbox/DemoBusy.php`, `web/app/Console/Commands/PruneDemoCommand.php`, `web/resources/views/components/demo-account-banner.blade.php`, `web/tests/Feature/Demo/DemoLoginTest.php`
- Create: `web/app/Sandbox/DemoCleanup.php`
- Modify: delete `web/app/Sandbox/DemoAccess.php` after moving its deletion routine into `DemoCleanup`; `web/routes/web.php`, `web/routes/api.php`, `web/routes/admin.php`, `web/routes/console.php` (remove `qistas:prune-demo`), `web/app/Providers/FortifyServiceProvider.php` (the `demo` and `api-demo` limiters), `web/config/qistas.php` (remove `demo_login`; **keep** `demo`), `web/resources/views/auth/login.blade.php` (remove the "Just looking around?" section; **keep** the `config('qistas.demo')` credentials block), `web/resources/views/components/layouts/app.blade.php` (the demo-account banner and the demo e-mail line), `web/resources/views/admin/home.blade.php` (the prune button), `web/app/Reports/PlatformOverview.php` (the "Demo sign-in" check), `web/app/Http/Api/AccountPayload.php` (`tenant.is_demo`), `docs/api/openapi.yaml`, `web/lang/app/*.json` (remove the strings only the demo used), tests that referenced the removed pieces (`AdminOverviewTest`, and any others a search for `DemoAccess`, `demo.start`, `demo.leave`, `demo_login`, `qistas:prune-demo` shows)
- Test: `web/tests/Feature/Demo/DemoRemovedTest.php`, `web/tests/Feature/Demo/DemoCleanupTest.php`

**Interfaces:**
- Produces:
```php
final class DemoCleanup {
    /** @return array{accounts: list<array{user_id: string, email: string, workspaces: list<array{id: string, name: string, rows: int}>}>, test_workspaces: list<array{id: string, name: string, owner_email: string}>}
     *  accounts: users with demo_expires_at set, or whose e-mail is DemoSeeder::EMAIL (D1); their is_demo workspaces; test_workspaces: is_test workspaces, never deleted without $includeTest */
    public function plan(bool $includeTest = false): array;
    /** Deletes exactly what $plan lists, each account in one transaction: its sessions and tokens, its owned is_demo workspaces (Tenant::delete(), rows go through the database's cascading foreign keys), then the user. Never touches an account that is not in the plan. Returns the number of accounts removed. */
    public function purge(array $plan): int;
}
```
Kept untouched: `Tenant.is_demo`, `User.demo_expires_at`, the `is_demo` filters in `SendDigestsCommand`, `InstalmentAlertsCommand`, `ChargeLateFeesCommand`, `SendGlanceRefreshCommand`, `SendDayCloseRemindersCommand`, `LearnPaydaysCommand`, `ChangePlan`, `BillingState`, `ExportService`, `ActivityRecorder`, `BusinessController`, the container's `qistas.demo` mode and `x-demo-banner`, `DemoSeeder`, `SampleBusiness`, `TestWorkspace`.

- [ ] **Step 1: Write the failing tests.** `DemoRemovedTest`: `POST /demo/admin`, `POST /demo/user`, `POST /demo-leave`, `GET /api/v1/demo`, `POST /api/v1/demo/admin` answer 404 (even with the old setting switched on); the sign-in page has no "Just looking around?" section; with `qistas.demo` true it still shows the credentials block and the banner (the container demo mode is intact); the account payload has no `is_demo`; `qistas:prune-demo` no longer exists; the scheduler has no demo line; the developer seeder still runs and still refuses in production. `DemoCleanupTest`: a demo account (with `demo_expires_at`) with an `is_demo` workspace holding a contract, instalments, a payment, a document and a day close is listed with its row count and removed by `purge`, including its sessions and tokens; a **real** account whose address ends `@demo.qistas.test` but has no `demo_expires_at` is not in the plan and survives `purge`; `purge` of a plan never deletes an account that was not listed (change the data between `plan` and `purge`: the extra account survives); staff test workspaces are in `test_workspaces` and only removed with `$includeTest`; a workspace shared with a real member is not removed.
- [ ] **Step 2: Run** both files. Expected: FAIL.
- [ ] **Step 3: Implement** the removal and `DemoCleanup`, carrying the old routine's rule "only ever a demo account". Search for dead strings and imports afterwards.
- [ ] **Step 4: Run** the whole `tests/Feature/Demo`, `Admin`, `Deployment`, `Auth`, `Billing`, `Backups`, `Notifications`, `PayPattern`, `DayClose` directories and `TranslationsTest`, `OpenApiTest`; Pint; PHPStan. The controller also runs the full web suite once at the end of the web tasks (Task 12), but run the full suite here too if time allows, because this task touches many files.
- [ ] **Step 5: Commit** `Remove the public demo: no Try-the-demo buttons, API or throw-away accounts`.

---

### Task 9: `qistas:go-live-check` and `qistas:purge-demo`

**Files:**
- Create: `web/app/Support/GoLiveCheck.php`, `web/app/Console/Commands/GoLiveCheckCommand.php`, `web/app/Console/Commands/PurgeDemoCommand.php`
- Modify: `web/lang/app/*.json` only if the output uses `__()` (command output is operator-facing English; keep it plain and untranslated, like the other commands)
- Test: `web/tests/Feature/Deployment/GoLiveCheckTest.php`, `web/tests/Feature/Deployment/PurgeDemoCommandTest.php`

**Interfaces:**
- Consumes: `DemoCleanup` (Task 8), `cron_runs`, `config`, the platform mail settings, `User` and `Tenant`.
- Produces:
```php
final class GoLiveCheck {
    /** @return list<array{key: string, status: 'pass'|'warn'|'fail', title: string, detail: string}> */
    public static function run(): array;
}
```
`qistas:go-live-check` prints one line per check and a summary, exits 1 when any check fails, and changes nothing. Checks (key: rule): `env`: `APP_ENV` is production (fail otherwise); `debug`: `APP_DEBUG` is off (fail); `url`: `APP_URL` starts with `https://` (fail) and the session cookie is secure (warn); `demo`: `qistas.demo` is off (fail); `demo-data`: no user with `demo_expires_at`, no account with the seeder's address (fail, with the count and "run qistas:purge-demo"); `demo-domain`: any account at `@demo.qistas.test` without `demo_expires_at` (warn: D1); `test-workspaces`: staff test workspaces exist (warn, count only); `mail`: a mailer other than `log`/`array` and a from address are set (fail); `cron`: `cron_runs` has a run in the last 15 minutes (fail; warn if the table is empty); `geo`: `qistas.geo_header` is set (warn); `billing`: the card gateway is not `fake` or card billing is off (warn); `admin`: at least one active super-admin exists (fail); `support`: `qistas.support_email` is set (warn); `legal`: `/terms` and `/privacy` pages answer (fail; use an internal request through the kernel, no network); `database`: the driver is PostgreSQL (warn otherwise); `storage`: `storage/` is writable (fail).
`qistas:purge-demo {--confirm} {--include-test}`: without `--confirm` it prints the plan (each account, its workspaces and row counts, then the test workspaces under their own heading) and says nothing was deleted; with `--confirm` it **recomputes** the plan at that moment, deletes exactly that, and prints what it removed.

- [ ] **Step 1: Write the failing tests:** a clean test database passes every deterministic check it can (set the config and fixtures in the test), and each failing condition makes its own check `fail` or `warn` with the stated status and the command exits 1 only on `fail`; the dry run deletes nothing and its printed list names the accounts; `--confirm` deletes exactly the listed demo accounts and leaves real ones; a real account at the demo domain is only a `warn` and survives; `--include-test` is needed for test workspaces; running `--confirm` twice is harmless; `qistas:go-live-check` after a purge shows `demo-data` as `pass`.
- [ ] **Step 2: Run** the files. Expected: FAIL.
- [ ] **Step 3: Implement** the check class and both commands. Output: aligned `PASS`/`WARN`/`FAIL` markers using the console's components, no colour-only meaning.
- [ ] **Step 4: Run** the files, `tests/Feature/Deployment`; Pint; PHPStan. Expected: PASS.
- [ ] **Step 5: Commit** `A go-live check, and a demo purge that shows its list first`.

---

### Task 10: The phone app: five-field sign-up, device hints, no demo

**Files:**
- Delete: `app/lib/features/auth/demo_choices.dart`, `app/test/features/demo_test.dart`
- Modify: `app/lib/features/auth/register_screen.dart` (remove business name, country and the picker), `app/lib/features/auth/login_screen.dart` (remove `DemoChoices`), `app/lib/data/qistas_api.dart` (`register` without the two fields and with `utc_offset` and `device`; `login` with `utc_offset` and `device`; remove `demoOffer`, `startDemo`), `app/lib/data/models.dart` (remove `DemoOffer`, `DemoPersona`, `Account.isDemo` and its serialisation), `app/lib/app/providers.dart` (remove `signInDemo` and `demoOfferProvider`), `app/lib/app/shell.dart` (the demo notice and strip), `app/lib/core/ui/errors.dart` (the comment), `app/lib/features/auth/countries.dart` (delete only if nothing else uses `countryName`; otherwise keep), `app/test/support/{harness,samples}.dart`, `app/test/core/errors_test.dart`, `app/test/visual/screens_test.dart`, `app/assets/i18n/*.json`
- Create: `app/lib/core/device_hint.dart`
- Test: update `app/test/features/auth_test.dart` (or the file that tests sign-up) and add `app/test/core/device_hint_test.dart`

**Interfaces:**
- Produces: `Map<String, dynamic> deviceHint({required double shortestSide})` returning `{type: 'tablet' | 'mobile', os: <dart:io operatingSystem>, model: <the existing deviceName()>, app_version: AppConfig.appVersion}` (type is `tablet` when the shortest side is at least 600 logical pixels); the app sends it as `device`, and `DateTime.now().timeZoneOffset.inMinutes` as `utc_offset`, on sign-in and sign-up. No new plugin. Sign-up screen fields: name, email, password, confirmation, terms.

- [ ] **Step 1: Write the failing tests:** the sign-up screen has exactly the five fields and no country picker; submitting posts no `business_name` or `country` and does post `utc_offset` and `device` (use the fake server's recorded body); a server `422` on `email` shows next to the field; the sign-in screen has no demo choices and posts the hints; `deviceHint` returns `tablet` at 600 and `mobile` at 599; the account model parses a payload without `is_demo`; 200% text in English and Arabic on the sign-up screen without overflow.
- [ ] **Step 2: Run** `flutter test` on the touched files. Expected: FAIL.
- [ ] **Step 3: Implement**; run `python tool/i18n.py --missing` and clean the strings only the demo and the removed fields used (`--sync` reports no stale strings).
- [ ] **Step 4: Run** `flutter analyze` (zero issues), the whole `flutter test`, `python tool/i18n.py --sync`; regenerate the visual pictures (`QISTAS_SCREENSHOTS=build/shots/golive flutter test test/visual`) and look at the sign-up and sign-in screens in English and Arabic.
- [ ] **Step 5: Commit** `App: sign-up with five fields, device hints on sign-in, no demo`.

---

### Task 11: The phone app: the first-run card and the recent sign-ins

**Files:**
- Create: `app/lib/features/setup/setup_card.dart`, `app/lib/features/setup/setup_state.dart`, `app/lib/data/sign_in_event.dart`
- Modify: `app/lib/data/models.dart` (`Account.setup`: `confirmed`, `country`, `currency`, `timezone`, `countryDetected`, `canChangeRegion`; the key is optional and absent on old servers), `app/lib/data/qistas_api.dart` (`confirmSetup({name, country, timezone})`, `signIns()`), `app/lib/features/dashboard/dashboard_screen.dart` (the card at the top of Today), `app/lib/features/settings/devices_screen.dart` (a "Recent sign-ins" section), `app/lib/core/ui/errors.dart` (map the 409 `workspace_setup_required` to opening the card), the screens that request documents (find them: they already catch API errors), `app/assets/i18n/*.json`, `app/test/visual/screens_test.dart`
- Test: `app/test/features/setup_card_test.dart`, `app/test/features/sign_ins_test.dart`

**Interfaces:**
- Consumes: Task 3's `tenant.setup` and `PUT /workspace/setup`; Task 7's `GET /account/sign-ins`.
- Produces: models `WorkspaceSetup` and `SignInEvent`; the card shows only for the owner while `confirmed` is false; one tap on "Save" sends only the name and, if the person changed them, the country and zone; the region controls are hidden when `canChangeRegion` is false; a 409 `workspace_setup_required` on a document request opens the same card instead of an error; the device screen lists the last 20 sign-ins in plain words ("Mobile, Chrome on Android, Qatar, proxy not detected, 5 minutes ago") and marks failed ones.

- [ ] **Step 1: Write the failing tests:** the card shows for an unconfirmed owner and not for a member, a confirmed owner, or a server that omits `setup`; it shows the detected country line with Change, or the chooser when not detected, or the name only when the region is locked; saving sends only what changed and then hides the card; a refused region change shows the server's text next to the field; double taps send one request; a document request that answers 409 opens the card; the recent sign-ins list renders newest first with the verdict words, marks failed rows, shows an empty state, and an old server without the endpoint (404) shows nothing and no error; 200% text and Arabic without overflow; nothing is requested for a viewer where the card does not apply.
- [ ] **Step 2: Run** the files. Expected: FAIL.
- [ ] **Step 3: Implement** and add the strings (Arabic count-neutral).
- [ ] **Step 4: Run** `flutter analyze` (zero), the whole `flutter test`, `python tool/i18n.py --sync`, the visual run (add the card, English and Arabic, and the recent sign-ins list) and look at the pictures once.
- [ ] **Step 5: Commit** `App: the first-run card and the recent sign-ins`.

---

### Task 12: Release: documents, version, verification, push, live checks

**Files:**
- Create: `docs/features/sign-up.md`, `docs/features/go-live.md`
- Modify: `app/pubspec.yaml` (version `1.29.0+33`), `app/lib/core/config.dart` (both places that hold the version), `app/test/features/settings_test.dart` (the version text), `docs/api/openapi.yaml` (a final prose pass: the new routes, the optional register fields, the 409 code), `docs/DEPLOY.md` (the Option A demo sentences, the SixPanel settings: list `QISTAS_DEFAULT_COUNTRY`, `QISTAS_SIGN_IN_DAYS`, `QISTAS_PROXY_CHAIN_ALLOWED`; the demo-mode paragraph in Option B stays), `README.md` only where it names removed demo buttons

**Interfaces:**
- Consumes: everything above.
- Produces: a released, pushed Release 1 and the owner's go-live steps.

- [ ] **Step 1: Operator notes.** `sign-up.md`: the five fields, what is detected and in what order, the first-run card and the region lock, what is recorded at each sign-in and why, the 90-day retention, the proposed privacy sentence (marked "proposal: not published until the owner approves it"), the proxy limits stated honestly. `go-live.md`: the checklist, the two commands with sample output, and the owner steps below.
- [ ] **Step 2: Full verification.** In `web/`: `vendor/bin/pest` (summarise), `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`. In `app/`: `flutter analyze` (zero), `flutter test`, `python tool/i18n.py --sync`. The controller runs `migrate:fresh` and the new test files on PostgreSQL (PGlite) for both migrations.
- [ ] **Step 3: Final review.** One whole-branch review on the most capable model against the spec, this plan and its Review Focus, then one fix dispatch and one scoped re-review if it finds anything.
- [ ] **Step 4: Push and watch.** Web tasks were pushed as they were approved; push the app tasks only after confirming, with a read-only GET, that the live `/register` page no longer contains `business_name`. Watch every workflow of the final push (`Web app` incl. the PostgreSQL job and the production-image check, `Mobile app` incl. the Android build, `iPhone app`, `CI`) until green.
- [ ] **Step 5: Read-only live checks** (never create an account or press anything on https://www.qistasme.com): `/register` has no `business_name` or `country`; `/login` has no "Just looking around?"; `/api/v1/demo` answers 404; `/api/v1/account/sign-ins` and `/api/v1/workspace/setup` answer 401; `/up` answers 200.
- [ ] **Step 6: Report honestly,** including what only the owner can do: run `php artisan qistas:go-live-check` on the server and fix every `FAIL`; run `php artisan qistas:purge-demo` (dry run), read the list, and only then run it with `--confirm`; sign in once from their own phone and computer and open the Security page: "Proxy not detected" is expected for a normal connection, and if it says "detected", set `QISTAS_PROXY_CHAIN_ALLOWED` one higher in the website's Environment page and sign in again; approve the privacy sentence; set the `MAIL_*` keys if the check says mail is not configured; and know the open items (Releases 2 and 3, the paid proxy service later).

---

## Self-review

**Spec coverage.** Section 3 (form, workspace, card, document gate): Tasks 1, 2, 3, 4, 10, 11. Section 4 (detection): Task 2. Section 5 (sign-in record, hooks, device, proxy, visibility, retention, privacy text): Tasks 5, 6, 7, 12. Section 6 (demo removal, go-live check, purge): Tasks 8, 9. Section 7 (old clients, version): Tasks 2, 10, 12. Section 8 (tests, PostgreSQL, rollout, documents): every task, Task 12. The spec's errors found while planning are D1 to D8, written into the spec by Task 1.

**Type consistency.** `SignupPlace::detect` / `timezone` (Task 2) feed `RegisterTenantOwner` (Task 2) and `ConfirmWorkspaceSetup` (Task 3). `tenant.setup` keys (`confirmed`, `country`, `currency`, `timezone`, `country_detected`, `can_change_region`) are the same in Tasks 3, 4 and 11. `SignInRecorder::success/failed`, `DeviceProfile::describe`, `ProxyCheck::inspect` (Task 5) are the calls Tasks 6 and 7 use; the request inputs `timezone`, `utc_offset`, `device` are the same in Tasks 4, 5, 6 and 10. `DemoCleanup::plan/purge` (Task 8) are what Task 9's command calls. Config keys: `default_country`, `sign_in_days`, `proxy_chain_allowed`.

**Review Focus.** Item 1: Tasks 2, 3, 4. Item 2: Tasks 1, 3, 4. Item 3: Tasks 5, 6, 7. Item 4: Tasks 2, 10, 12. Item 5: Tasks 8, 9.

**Proportion.** The tasks name interfaces, rules and test cases and leave bodies to the implementer; only the proxy rule and the zone rule are spelled out because the tests must be exact.

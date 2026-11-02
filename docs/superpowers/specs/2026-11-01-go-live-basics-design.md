# Go-live basics: a simpler sign-up, automatic place and device, no demo (Release 1 of 3)

**Status:** design, awaiting the owner's review. **Date:** 2026-11-01.
**Author:** Claude, from the owner's request of 2026-11-01 and the approved in-chat design.

## 1. Why, and what "done" looks like

The owner wants to take Qistas live at https://www.qistasme.com (its own server, PostgreSQL 18, Cloudflare in front) and
asked for three things that belong together, because they decide what a stranger meets on the first minute and what the
owner can trust afterwards:

1. **A shorter sign-up.** No business name and no country on the form. The place comes from the network and the
   browser; the device a person signs in from is recorded automatically (desktop, mobile or tablet, and whether a proxy
   is used).
2. **No demo.** Nothing that offers a demo, or can create demo data, reaches a real visitor; any demo or test leftovers
   on the live database are found and removed with the owner's approval.
3. **A way to know it is ready.** A read-only check that says in plain words whether the site is set up to take real
   customers.

The owner's wider request has two more parts that are **not** in this release and get their own spec, plan and release
(section 9): an error center on the admin dashboard (Release 2), and sign-up with a phone number and a one-time code,
Google and Outlook (Release 3).

Done means:

- The sign-up form on the website and in the phone app has **name, email, password, confirm password and the terms
  checkbox**, nothing else. Old app versions that still send a business name and a country keep working.
- A new workspace starts with a placeholder name and a detected country, currency and time zone. A **first-run card**
  asks the owner once to confirm them. No receipt, statement or contract PDF is produced under a placeholder name.
- Every real sign-in writes one **sign-in record** (device type, system, browser, IP, IP country, proxy verdict). The
  person sees their recent sign-ins; staff see them on the business page. Records are removed after 90 days.
- The public demo is gone from the website, the API and the phone app. `qistas:go-live-check` reports the state of the
  site; `qistas:purge-demo` lists, and with a confirm flag deletes, demo and test leftovers.
- All of it has tests that fail when the behaviour is wrong, runs on PostgreSQL, and is documented for the owner.

## 2. What exists today (verified in the code)

- `register.blade.php` and the app's `register_screen.dart` ask for name, business name, country, email, password,
  confirmation and terms. `CreateNewUser` validates them; `RegisterTenantOwner` creates the user, the workspace
  (name and slug from the business name; currency from `config('qistas.countries')`; time zone from
  `config('qistas.timezones')`), the owner membership and the default plan, and writes the audit row
  `account.registered`. The same action is used by `POST /api/v1/auth/register`, the developer seeder (`DemoSeeder`) and
  the public demo (`DemoAccess`). Staff test workspaces (`Sandbox\TestWorkspace`) create their tenant directly.
- Nothing in the web or API code lets an owner rename the workspace or change its country, currency or time zone after
  sign-up. The business profile page edits what documents print (names in Arabic and English, phone, address, CR/VAT);
  documents fall back to the workspace name when those are empty.
- `config('qistas.geo_header')` already names the CDN country header (`CF-IPCountry` on the live site), and
  `App\Theme\Visitor` already reads it safely (only the configured header, never one a browser could send itself).
- `users.email` is required and unique. `users.phone` exists (profile, nullable, unverified). Personal access tokens keep
  `last_used_ip`; the `sessions` table keeps IP and user agent. There is no history of sign-ins.
- There is no SMS or social sign-in package, and no error monitor.
- The public demo: `DemoLoginController` (`/demo/{persona}`, `/demo-leave`), `Api\V1\DemoController` (`/api/v1/demo`),
  `Sandbox\DemoAccess` and `DemoBusy`, `PruneDemoCommand`, the admin `DemoAccountsController` and its prune button, the
  demo banners and blocks in the layouts and on the sign-in page, the `qistas.demo` and `qistas.demo_login` settings,
  their rate limiters, and the phone app's `demo_choices.dart`. On the live site it is already switched off
  (`QISTAS_DEMO_LOGIN=false`; verified: no demo block on `/login`, `/api/v1/demo` answers `enabled:false`).

## 3. The sign-up form and the first-run card

### 3.1 The form

Fields: `name`, `email`, `password`, `password_confirmation`, `terms`, plus hidden `locale` (as today) and a hidden
time-zone hint (section 4). The phone app sends the same, with `utc_offset` (minutes) instead of an IANA name (a phone
app cannot read its IANA zone without a new plugin; section 4.2 explains how the server uses the offset).

`CreateNewUser` no longer requires `business_name` or `country`. If a client sends them (app versions up to 1.28.x do):

- `business_name`, when valid, becomes the workspace name and the workspace is **marked confirmed**;
- `country`, when a valid two-letter code, wins over detection.

### 3.2 The workspace on creation

`RegisterTenantOwner::handle()` takes `name`, `email`, `password`, `locale`, and optional `business_name`, `country`,
`timezone`, `utc_offset`, `detected_country`. It sets:

- `tenants.name`: `business_name` when given, otherwise the translated placeholder `Business of :name`
  (`:name` is the person's first word; e.g. "Business of Layla", "نشاط ليلى"). The slug is made from it as today.
- `country`, `currency`, `timezone` from section 4.
- `tenants.setup_confirmed_at`: `now()` when `business_name` was given, otherwise `null`.

New column `tenants.setup_confirmed_at` (timestamp, nullable). The migration is additive and **backfills every existing
workspace with its own `created_at`**, so nobody who is already using Qistas is asked anything. Staff test workspaces
and the developer seeder create their workspace as confirmed.

### 3.3 The first-run card

While `setup_confirmed_at` is null, the owner sees one card, at the top of the web dashboard and of the app's Today
screen, not blocking anything else:

> **Name your business.** *[ field, prefilled with the placeholder ]*
> **We think you are in Qatar: QAR, Doha time.** *[ Change ]*  **[ Save ]**

- **Who:** the owner (the person with `owner` role). Invited members never see it.
- **The country line** shows a one-tap confirmation when the country was detected from the network or browser. When
  nothing was detected it shows a chooser instead. "Change" opens the chooser and the time-zone picker.
- **The lock.** Country, currency and time zone can change only while the workspace has **no contract and no
  transaction** (they decide currency and every due date). After that the card shows the name field only, and a change
  of region is refused with a clear message pointing to support. The server enforces it; the screen only reflects it
  (`can_change_region` in the payload).
- **Saving** sets the name, applies any allowed region change (currency from the country table, time zone from the
  picker or the country), sets `setup_confirmed_at`, and writes the audit row `workspace.setup_confirmed` (name, country,
  currency, time zone; no other data).
- **Routes:** web `PUT /app/setup` (`app.setup.update`), API `PUT /api/v1/workspace/setup`. Both are owner-only,
  rate-limited like the other settings routes, and described in `docs/api/openapi.yaml`.
- **The account payload** (`AccountPayload`) gains `workspace.setup`: `confirmed` (bool), `country`, `currency`,
  `timezone`, `country_detected` (bool), `can_change_region` (bool). Old apps ignore it.

### 3.4 Documents wait for the name

`DocumentService::issue()` (the single entry point for receipts, statements, contract PDFs, the day-close summary and
every other PDF) refuses while the workspace is not confirmed:

- web: redirect to the dashboard with the first-run card and the message "Name your business first. It is printed on
  every receipt.";
- API: HTTP 409, error code `workspace_setup_required`, with the same message; the app opens the card.

Customers, contracts and payments are never blocked.

## 4. Country and time zone, detected

### 4.1 Country

`App\Support\SignupPlace::detect(Request): array{country: ?string, source: string}`, first match wins:

1. the CDN country header named in `qistas.geo_header` (the live site: `CF-IPCountry`), ignoring non-countries (`XX`,
   `T1` Tor, `A1`, ...); this reuses the code and the safety rule of `App\Theme\Visitor`;
2. the region of the browser's `Accept-Language` (`ar-QA` gives `QA`);
3. nothing: the workspace gets `qistas.default_country` (env `QISTAS_DEFAULT_COUNTRY`, default `SA`, what the form
   preselects today) and the card asks instead of confirming.

A detected country that is not in `config('qistas.countries')` (for example `PS`) is kept as the workspace country only
if the owner picks it; at creation the workspace gets the default currency (`qistas.currency_default`) and the card
asks.

### 4.2 Time zone

In order: the `timezone` the browser sends (IANA; validated with PHP's timezone list); else, for the phone app, the
`utc_offset` combined with the country (the first zone in `DateTimeZone::listIdentifiers(PER_COUNTRY, $country)` whose
current offset equals the reported one; the Gulf has no daylight saving so this is exact there); else
`config('qistas.timezones')[country]`; else `UTC`.

### 4.3 Currency

From the country, with the existing table. The documented limitation about 3-decimal currencies (KWD, BHD, OMR) is
unchanged and out of scope.

## 5. The sign-in record

### 5.1 Table `sign_in_events` (append-only, user-owned)

| Column | Meaning |
|---|---|
| `id` uuid | key |
| `user_id` uuid | the person; cascade on delete |
| `method` | `password` now; `otp`, `google`, `microsoft` arrive in Release 3 |
| `channel` | `web`, or `app` (the phone app's sign-in); using an integration token is not a sign-in and is not recorded |
| `outcome` | `success`, or `failed` (wrong password for an existing account) |
| `device_type` | `desktop`, `mobile`, `tablet` or `unknown` |
| `os`, `browser` | short names from the user agent; null when unknown |
| `app_version`, `device_name` | from the phone app; null on the web |
| `ip` | the visitor's real address (as the app already sees it behind Cloudflare) |
| `ip_country` | two letters from the CDN header; null when unknown |
| `proxy` | `not_detected`, `possible` or `detected` |
| `proxy_signals` json | the reasons: `tor`, `via_header`, `forwarded_chain`, `timezone_country_mismatch` |
| `user_agent` | truncated to 255 characters, for support |
| `created_at` | when |

Indexes `(user_id, created_at)` and `(created_at)`. The model refuses updates; rows leave only through the prune command.
It is **user-owned, not tenant-owned** (a person can belong to several workspaces), so it never uses the tenant scope and
is only read through `$user->signInEvents()` or the staff pages. Never stored: the typed e-mail or phone of a failed
attempt for an unknown account; passwords; tokens.

### 5.2 When a record is written

Exactly one `success` per real sign-in or sign-up: a password sign-in without two-factor, or the completion of the
two-factor step; a phone-app sign-in (`POST /api/v1/auth/login`); a registration on either channel. **Not** when a remember-me cookie restores a session (Laravel
fires a login event for that too), not per request. One `failed` record when the password is wrong for an account that
exists, at most one per account and IP per minute, so a stranger guessing at someone's account cannot fill their history.
The recorder is `App\Auth\SignInRecorder::record($user, $request, $method, $outcome)`; the hook points are chosen in the
plan (the password check in `AuthenticateUser` for failures; the completed login for successes) and tested against the
remember-me and two-factor pitfalls.

### 5.3 Device and proxy

- **Device type, system, browser:** `matomo/device-detector` (maintained, handles tablets and uncommon agents). The phone
  app also sends `device`: `{type, os, model, app_version}`, taken as a hint and shown as reported; its device class
  (shortest side of at least 600 logical pixels is a tablet) is used when the user agent says nothing useful. Honest
  limit: an iPad in desktop mode can look like a Mac and is recorded as desktop.
- **Proxy verdict (free checks only):**
  - `tor`: the CDN's country code `T1` (only trusted when the configured header is the CDN's own);
  - `via_header`: the request carries `Via` or `Forwarded` from a proxy that announces itself;
  - `forwarded_chain`: more forwarding hops than the trusted CDN explains;
  - `timezone_country_mismatch`: the browser or phone time zone does not belong to the IP's country (a weak signal,
    never alone more than `possible`).
  `detected` when `tor`, `via_header` or `forwarded_chain`; `possible` when only the mismatch; otherwise
  `not_detected`. The screens say "proxy detected / possible / not detected", never "certain". A paid IP-intelligence
  service can replace these checks later behind the same class (`ProxyCheck`), as decided with the owner.

### 5.4 Who sees it, and for how long

- **The person:** "Recent sign-ins" (last 20) on the web security page (which already asks for the password again) and
  in the app's devices screen, from `GET /api/v1/account/sign-ins`. Example line: *Mobile, Chrome on Android, Qatar,
  proxy not detected, 5 minutes ago.*
- **Staff:** the same list for the workspace's members on the admin business page.
- **Retention:** 90 days (`qistas.sign_in_days`), removed by `qistas:sign-ins:prune` (daily, in the existing
  five-minute scheduler run, checked at run time). Deleting an account removes its records.
- **Privacy text.** The owner owns the legal pages, so this sentence is a proposal, not published until approved: *"When
  you sign in we record the device type, browser, the country and address of your connection, and whether it appears to
  go through a proxy, to protect your account. You can see these on your Security page. We keep them for 90 days."*

## 6. Demo removal and the go-live check

### 6.1 Removed from the product

The throw-away public demo and everything that exists only for it: `DemoLoginController` and its two routes;
`Api\V1\DemoController` and its two routes; `Sandbox\DemoAccess` and `DemoBusy`; `PruneDemoCommand` and its schedule
line; the admin `DemoAccountsController`, its route and the prune button; the throw-away account's banner
(`demo-account-banner`) and the sign-in page's "Try the demo" buttons (and their use in the four layouts); the
`qistas.demo_login` setting and the `demo` and `api-demo` rate limiters; the demo flag in `AccountPayload`; the phone
app's `demo_choices.dart`, its sign-in-screen hooks, API methods and strings; and the tests of all of these.

### 6.2 Kept on purpose

- **The developer seeder** (`DemoSeeder`, `qistas:setup --demo`, Docker's `DEMO_DATA`): it refuses to run in production
  (`app()->isProduction()` throws) and is how everyone develops locally. It keeps working with the new
  `RegisterTenantOwner` (it passes a business name).
- **`SampleBusiness`** and **staff test workspaces** (`is_test`): a staff tool for trying features, excluded from
  customer figures. They are listed, never deleted, by the go-live tools.
- **The container's built-in demo mode** (the Docker or Vercel image when no database is configured): the `qistas.demo`
  setting, the `x-demo-banner` component and the credentials block on the sign-in page stay, because they are what the
  container shows and what `scripts/e2e-demo.sh` and the CI job "Start with no configuration (demo mode)" depend on. It
  is not part of the live site, and `qistas:go-live-check` fails when `qistas.demo` is on. Out of scope here; a follow-up
  can retire it with the Vercel copy.
- **The column `users.demo_expires_at`** stays so the tools can recognise old demo accounts; a later migration drops it
  after the live clean-up.

### 6.3 `qistas:go-live-check` (read-only)

Prints `PASS`, `WARN` or `FAIL` per check and a summary; exits non-zero on any `FAIL`. Checks: `APP_ENV` is production;
debug is off; `APP_URL` is https and the session cookie is secure; no demo setting is on; no demo or test-looking
account exists (see 6.4); mail can be sent (settings present; the admin's "send a test" stays the proof); the
scheduler has run in the last 15 minutes (`cron_runs`); the CDN geo header is configured; the billing gateway is not
`fake` (or card billing is switched off); at least one super-admin exists; the support e-mail is set; the privacy and
terms pages answer; the database is PostgreSQL; the storage folder is writable. It never changes anything.

### 6.4 `qistas:purge-demo`

Default is a **dry run**: it prints every account it would remove and the workspaces and row counts that go with it, then
stops. Deleting needs `--confirm`, and the command reads the list again at that moment. It matches:

- users with `demo_expires_at` set, or with an e-mail ending `@demo.qistas.test`, or equal to the developer seeder's
  address;
- workspaces flagged `is_demo` that they own, and any other workspace of such a user when no other person belongs to it.

Staff test workspaces (`is_test`) are listed under their own heading and are removed only with an explicit
`--include-test`.

**How it deletes** (verified in the code that is being removed): the old demo prune deleted the account's sessions and
tokens, deleted each owned `is_demo` workspace with `Tenant::delete()`, whose rows go with it through the database's
cascading foreign keys (the ledger is protected from edits by the model, not by blocking a whole-workspace delete), and
then deleted the user, all in one transaction per account. That routine moves into a small `Sandbox\DemoCleanup` class,
keeps its rule "only ever a demo account", and gains the dry run. The plan must prove it on real PostgreSQL with a
workspace that holds contracts, instalments, payments, documents, files and a closed day.

**Nothing is deleted on the live database until the owner has seen the printed list and said yes**; the command runs
where the owner runs it (the server's terminal), or through the hosting panel connection only if the owner asks for that.

## 7. Compatibility and old clients

- App 1.28.x and older send `business_name` and `country` to `POST /api/v1/auth/register`: still accepted (3.1).
- Old apps never send `device`, `timezone` or `utc_offset`: the record is made from the request alone.
- `AccountPayload` gains optional keys only. The new endpoints are new routes. No existing response loses a key,
  except the demo flag that no client reads once the demo is gone (the app's demo code goes in the same app release).
- App release: version **1.29.0+33**.

## 8. Testing, rollout, documents

**Tests first, each failing for the right reason before the code:**

- sign-up form (web) and API: only the five fields are required; a client that still sends business name and country
  keeps working; placeholder name per locale; slug uniqueness; `setup_confirmed_at` rules; backfill of existing rows;
- detection: header, language region, default; non-countries ignored; an unlisted country; offset-to-zone mapping for the
  Gulf and for a daylight-saving country; invalid time zone input; a browser-sent country header is never trusted unless
  it is the configured one;
- first-run card: owner only; save applies name and allowed region; the region lock once a contract or a transaction
  exists; audit row; web and API; the payload keys;
- document gate: every kind of PDF is refused (web redirect, API 409) while unconfirmed and works after; staff test
  workspaces and the seeder are confirmed;
- sign-in record: one `success` per real sign-in and sign-up on web, app and API; none for remember-me restoration; none
  per request; two-factor flow records once, after the code; `failed` only for an existing account and throttled per
  minute; no typed identifier stored; device classification table (phone, tablet, desktop, iPad desktop mode, the app's
  own report); each proxy signal and the verdict rule; retention prune; the person sees only their own; staff see the
  business's; user deletion removes rows;
- demo removal: the routes answer 404, the layouts and sign-in page contain no demo block, the seeder still runs
  locally and refuses in production, `go-live-check` results for a clean and a dirty database, `purge-demo` dry run
  deletes nothing, `--confirm` deletes exactly the listed rows, test workspaces survive without `--include-test`;
- app: sign-up screen has five fields, sends the offset, handles the card and the 409 `workspace_setup_required`;
  recent sign-ins list; 200% text and Arabic; four-language strings (`python tool/i18n.py`, and
  `scripts/merge_translations.py` for the web).

**PostgreSQL:** the two migrations (`setup_confirmed_at` with its backfill, `sign_in_events`) run through
`migrate:fresh` on real PostgreSQL (PGlite recipe in the deployment notes) before anything is pushed.

**Rollout:** the web release deploys like every push to `main` (the website pulls and builds). The app ships as 1.29.0.
After the deploy the owner runs `php artisan qistas:go-live-check`, then `php artisan qistas:purge-demo` (dry run), reads
the list, and approves or not.

**Documents:** `docs/features/sign-up.md` (what is recorded and why, detection order, the card), `docs/features/go-live.md`
(the checklist and the two commands), the privacy sentence proposal, the OpenAPI changes, and the `DEPLOY.md` notes that
mention the demo.

## 9. Not in this release (own specs)

- **Release 2, the error center.** An admin page that records errors from the website, the API, the database, scheduled
  jobs, 404 and 500 pages, and crashes sent by the phone app (without personal data); same error grouped, with counts,
  first and last seen, where it happened, a status (new, seen, resolved), and an e-mail on a new error.
- **Release 3, phone, Google and Outlook sign-in.** Phone number plus password, verified by a code sent by SMS first with
  WhatsApp as the backup; Google and Microsoft accounts; `users.email` becomes optional; account linking rules; the
  owner creates the SMS, Google and Microsoft accounts (steps will be written; keys go into the server's environment
  page, never into chat). **This release keeps the door open:** nothing here assumes an account has an email, and
  `sign_in_events.method` already has room for the new methods.

## 10. Decisions made for the owner (change any of them)

- A placeholder name, a first-run card and documents that wait for the name, rather than a compulsory screen.
- Country, currency and time zone are changeable only while the workspace has no contract and no transaction.
- `matomo/device-detector` is added as a dependency for device recognition.
- The public demo is deleted, the local developer seeder stays, the container's built-in demo mode is out of scope.
- Sign-in records live 90 days; failed attempts are recorded at most once a minute per account and IP.
- Default country when nothing can be detected: `SA` (one environment setting, `QISTAS_DEFAULT_COUNTRY`).
- The phone app reports a UTC offset instead of a zone name, to avoid a new plugin.

### Plan deviations

Found while reading the code to write the plan (2026-11-02). Where they differ from the sections above, these win.

- **D1.** `purge-demo` matches only users with `demo_expires_at` set or the developer seeder's address
  (`DemoSeeder::EMAIL`), not the `@demo.qistas.test` domain: a real person could sign up with an address there. Such an
  account without `demo_expires_at` is a `WARN` in the go-live check and is never purged.
- **D2.** The payload key is `tenant.setup`, not `workspace.setup`: `AccountPayload` nests the workspace under `tenant`.
- **D3.** A new nullable column `tenants.country_source` (`header`, `language`, `default`, `chosen`) sits beside
  `setup_confirmed_at`, because nothing stored how the country was chosen.
- **D4.** Web successes are written from Fortify's response contracts (`LoginResponse`, `RegisterResponse`,
  `TwoFactorLoginResponse`), failures from `AuthenticateUser`, the phone app from `AuthController`: a login event
  listener would also record every remember-me restoration.
- **D5.** `forwarded_chain` means more hops than `qistas.proxy_chain_allowed` (env `QISTAS_PROXY_CHAIN_ALLOWED`, default
  2), because the hop count depends on the server's proxy set-up; the owner checks that a normal sign-in reads "not
  detected" and adjusts the number if not.
- **D6.** The person reads their sign-ins at `GET /api/v1/account/sign-ins` (last 20, own only), a new route.
- **D7.** Only the throw-away public demo goes. The container's built-in demo mode (`qistas.demo`, `x-demo-banner`, the
  sign-in credentials block), `scripts/e2e-demo.sh` and the CI job "Start with no configuration (demo mode)" stay;
  `qistas:go-live-check` fails when `qistas.demo` is on.
- **D8.** `PUT .../setup` ignores `country` and `timezone` equal to the current values, so a card that resends unchanged
  values does not fail once the region is locked; a changed region while locked is refused as a whole (nothing saved).

## 11. Risks and honest limits

- A visitor on a VPN or travelling can be detected in the wrong country; the card exists for that, and the lock keeps a
  later mistake from changing currency under recorded money.
- Proxy detection is a best effort; a normal VPN often looks like a normal visitor until a paid service is added.
- Device detection cannot see an iPad in desktop mode.
- IP addresses are personal data: they are kept 90 days, shown only to the person and to staff, and the privacy text
  must be approved by the owner before it is published.
- The first-run card is a new step for new owners; it is one card with one required field, and nothing else waits for it
  except documents.

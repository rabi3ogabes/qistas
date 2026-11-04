# Error Center (Release 2 of 3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One admin screen that shows what is breaking on the website, the API, the database and the phone app (grouped, counted, with versions and a status), with a scrubber that keeps personal data out, an instant e-mail for a new kind of server error, a daily digest, and automatic app crash reports with an off switch.

**Architecture:** Platform-wide tables (`error_issues`, `error_occurrences`, `error_issue_days`, `error_issue_people`, `error_noise_days`) written only by `ErrorRecorder`, after the response is sent and behind a guard, with a file spool for when the database is down. Server capture hooks (exception report, a terminating middleware for error pages, a slow-query listener) and two untrusted reporters (a script on the site, the phone app) all feed the same recorder through one scrubber. Admin pages read the tables; alerts are a listener on "issue created" plus a daily command.

**Tech Stack:** Laravel 13 / PHP 8.4, Blade, plain JS bundled by Vite (no inline script), Pest, Pint, Larastan level 6; Flutter 3.41 (Riverpod, dio, shared_preferences); PostgreSQL in production, SQLite in tests.

**Spec:** `docs/superpowers/specs/2026-11-04-error-center-design.md` (binding; section numbers below point into it).

## Global Constraints

Every task's requirements include this section. Values are copied from the spec.

- **Platform tables, not tenant tables.** No `tenant_id`, no business name or id, no IP, no user agent, no cookie, no request body stored (spec 5, 6). Models use explicit `#[Fillable]`; status and counters change only through `ErrorRecorder` and `ErrorTriage`. Migrations run on SQLite and PostgreSQL; UUID primary keys.
- **Config** (`config/qistas.php` key `errors`, listed in `.env.example`): `enabled` ← `QISTAS_ERRORS` (default `true`); `slow_query_ms` ← `QISTAS_SLOW_QUERY_MS` (3000); `occurrence_days` ← `QISTAS_ERROR_OCCURRENCE_DAYS` (30); `issue_days` ← `QISTAS_ERROR_ISSUE_DAYS` (90). Fixed limits (constants in code, not env): samples per issue 5; people per issue 500; message 500 characters; trace 8192 bytes; title 200; new issues 60 a minute and 500 an hour for the platform, client-reported sources 20 a minute; spool file at most 2 MB (`storage/logs/error-spool.ndjson`); instant e-mails at most 3 an hour and 10 a day.
- **Vocabulary.** sources `site`, `api`, `database`, `app`; kinds `exception`, `http`, `slow_query`, `script`, `crash`; statuses `open`, `resolved`, `ignored`; platforms `server`, `web`, `android`, `ios`; devices `desktop`, `mobile`, `tablet` or null.
- **Recording never breaks a request** (spec 8): it runs through `defer(...)->always()` after the answer, every `Throwable` inside it is swallowed, it never records its own failures, and a re-entrancy flag stops loops.
- **Client reports are untrusted** (spec 9): never an instant e-mail, labelled "reported by a browser" / "reported by a phone", strict limits.
- **Strings** live in `lang/` (English and Arabic complete; `tests/Feature/TranslationsTest.php` enforces it). No inline `<script>`; the site's strict CSP and Alpine CSP build forbid it.
- **Project rules** in `web/CLAUDE.md` still bind (audit rows for staff actions, generic auth errors, money rules do not apply here). Windows: write files with the editor tools, not shell heredocs with nested quotes. Pest helper functions are global across files: prefix every helper with `errors`.
- **Phone app:** the reporter sends only the class, a scrubbed trace, the app version, the Android or iOS version and phone or tablet; skipped when the Settings switch is off (on by default); at most 20 queued reports, at most 6 hours old; a 404 or 405 answer stops sending for the session; version bump in Task 11 only.

## Review Focus

The ways this work is most likely to hurt a person, and where a test pins each (spec 13).

1. **Personal data in an error record** (a phone number in an exception message, a token in a trace, SQL bindings in a query error, an e-mail in a validation text). Pinned in Task 2 (table-driven scrubber test), Task 5 (a thrown `QueryException` and a message carrying every kind of value are stored clean), Task 6 (reports from browsers and phones).
2. **The recorder breaks or slows a request, or loops on its own failure.** Task 4 (database write throws, so the spool receives it; a spool failure is swallowed), Task 5 (the response is unchanged when the recorder throws; no recursion).
3. **An alert flood or a silent miss.** Task 8 (hourly and daily caps, new issues only, no instant e-mail for client reports or error pages below 500, ignored issues silent, a reopened issue is in the digest and sends no instant e-mail).
4. **Fake or hostile reports** (thousands of crashes, giant bodies, forged origins, markup in a message). Task 6 (size, rate, origin and new-issues-per-minute caps, `dropped` counting) and Task 7 (escaped output in the admin views).
5. **A customer reaching the screens, or staff actions unaudited.** Task 7 (a non-staff user and a guest get 404 on every route; each status change writes an audit row).

---

## File structure

Web, under `web/`:

- `app/Errors/ErrorEvent.php` value object; `ErrorScrubber.php`; `ErrorFingerprint.php`; `ErrorRecorder.php`; `Recorded.php` (enum); `ErrorSpool.php`; `ErrorCapture.php`; `ErrorTriage.php`; `ErrorAlerts.php`; `ClientErrorIntake.php`; `Events/ErrorIssueCreated.php`
- `app/Models/ErrorIssue.php`, `app/Models/ErrorOccurrence.php`
- `app/Providers/ErrorServiceProvider.php` (slow-query listener, rate limiters), registered in `bootstrap/providers.php`
- `app/Http/Middleware/RecordErrorPages.php`
- `app/Http/Controllers/ClientErrorController.php` (site), `app/Http/Controllers/Api/V1/ClientErrorController.php` (app), `app/Http/Controllers/Admin/ErrorsController.php`
- `app/Console/Commands/ErrorsImportSpoolCommand.php`, `ErrorsPruneCommand.php`, `ErrorsDigestCommand.php`
- `app/Mail/ErrorAlertMail.php`, `app/Mail/ErrorDigestMail.php`, `resources/views/mail/error-alert.blade.php`, `error-digest.blade.php`
- `resources/views/admin/errors/index.blade.php`, `show.blade.php`; `resources/js/error-report.js`
- `database/migrations/2026_11_05_000100_create_error_center.php`
- Tests in `tests/Feature/Errors/`

Phone app, under `app/`: `lib/core/errors/` (`error_reporter.dart`, `error_queue.dart`, `trace_scrubber.dart`), `lib/data/qistas_api.dart` (`reportError`), `lib/main.dart`, `lib/features/settings/settings_screen.dart`, `assets/i18n/*.json`, tests in `test/core/errors/` and `test/features/`.

---

### Task 1: Tables, models, configuration

**Files:**
- Create: `web/database/migrations/2026_11_05_000100_create_error_center.php`, `web/app/Models/ErrorIssue.php`, `web/app/Models/ErrorOccurrence.php`
- Modify: `web/config/qistas.php` (key `errors`, including `scanner_paths`: a list of case-insensitive regular expressions for the scanner paths in spec 3: `/.env`, `/.git/`, `/wp-*`, `/xmlrpc.php`, `/phpmyadmin`, `/vendor/`, `/admin.php`, anything ending `.php`, `.sql`, `.bak`, `.zip`, `.env`), `web/.env.example`
- Test: `web/tests/Feature/Errors/ErrorTablesTest.php`

**Interfaces:**
- Produces: the five tables with exactly the columns of spec 5 (`error_issues`, `error_occurrences`, `error_issue_days`, `error_issue_people`, `error_noise_days`); `App\Models\ErrorIssue` with constants `SOURCES`, `KINDS`, `STATUSES`, relation `occurrences()`, scope `open()`, `static function openCount(): int`; `App\Models\ErrorOccurrence` (belongs to issue); `config('qistas.errors.enabled'|'slow_query_ms'|'occurrence_days'|'issue_days'|'scanner_paths')`.

- [ ] **Step 1: Write the failing tests** in `ErrorTablesTest.php`: `the five tables exist with the columns of the spec` (assert every column name per table with `Schema::hasColumns`); `no error table has a tenant_id`; `the fingerprint is unique`; `deleting an issue removes its occurrences, days and people` (cascade); `status defaults to open and openCount counts only open issues`; `the models list #[Fillable] explicitly` (status, occurrences, resolved_by are not mass assignable); `config defaults` (enabled true, 3000, 30, 90 and a non-empty scanner list).
- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/Errors/ErrorTablesTest.php`. Expected: FAIL (no tables).
- [ ] **Step 3: Implement** the migration (all five tables in one migration, indexes `(status, last_seen_at)` and `(source, status)` on issues, `issue_id` foreign keys with cascade, composite primary keys on days, people and noise as in spec 5, `down()` drops them in reverse order), the two models with `HasUuids` and `#[Fillable]`, `ErrorIssue::openCount()`, the config block and `.env.example` lines with one comment each.
- [ ] **Step 4: Run** the test file; then `vendor/bin/pest tests/Feature/ConfigTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `Error center: the tables, models and settings`.

### Task 2: The event and the scrubber

**Files:**
- Create: `web/app/Errors/ErrorEvent.php`, `web/app/Errors/ErrorScrubber.php`
- Test: `web/tests/Feature/Errors/ErrorScrubberTest.php`

**Interfaces:**
- Produces: `final class ErrorEvent` (readonly properties `source`, `kind`, `class` (string, '' when none), `message`, `trace`, `status` (?int), `method` (?string), `route` (?string), `version` (?string), `platform` (default `server`), `device` (?string), `userId` (?string), `at` (?string, ISO 8601, default now); `toArray(): array`, `static fromArray(array): self`). `final class ErrorScrubber` with static methods `text(string $text, int $max = 500): string`, `trace(string $trace, int $max = 8192): string` (also cuts file paths to the part under the project root, handling Windows backslashes), `route(?string $route): ?string` (drops the query string and replaces id-like segments), `event(ErrorEvent $event): ErrorEvent` (cleans class, message, trace, route, title inputs and caps lengths).

- [ ] **Step 1: Write the failing tests:** a dataset test (`it('removes ...')->with([...])`) with one row per pattern of spec 6, each with a realistic input and the expected masked output: e-mail address; Saudi, Qatari and international phone numbers including Arabic-Indic digits; a 10-digit national ID; an IBAN; a 16-digit card number; a bearer token; a Sanctum token (`12|` plus 40 characters); a JWT-like value; a 40-character hex run; `password=hunter2`, `token=abc`, `secret=`, `key=`, `Authorization: Bearer x`, `Cookie: a=b` in any case; `https://user:pass@host/x?token=1` (user-info and query removed); an SQL statement containing literals (only `?` remains in a statement passed through `text`); absolute paths on Linux and Windows (`C:\Users\x\proj\web\app\A.php` becomes `app/A.php`). Plus: `it is idempotent` (scrubbing twice equals once); `it cuts at the limit on a character boundary` (Arabic text); `it survives invalid UTF-8`; `a trace over 8192 bytes is cut`; `event() cleans every string field and leaves numbers and enums alone`; `ErrorEvent round-trips through toArray/fromArray`.
- [ ] **Step 2: Run** the file. Expected: FAIL (class missing).
- [ ] **Step 3: Implement** both classes. One ordered list of named regular expressions in the scrubber (a private constant), applied in a fixed order; masks look like `[email]`, `[phone]`, `[id]`, `[token]`, `[secret]`; the project root comes from `base_path()` and is also tried with `/` and `\` separators.
- [ ] **Step 4: Run** the file and `vendor/bin/pint --test app/Errors`. Expected: PASS.
- [ ] **Step 5: Commit** `Error center: the event and the scrubber that keeps personal data out`.

### Task 3: Fingerprints, titles, route patterns, scanner paths

**Files:**
- Create: `web/app/Errors/ErrorFingerprint.php`
- Test: `web/tests/Feature/Errors/ErrorFingerprintTest.php`

**Interfaces:**
- Consumes: `ErrorEvent`, `ErrorScrubber`, `config('qistas.errors.scanner_paths')`.
- Produces: `final class ErrorFingerprint` with static `normalise(string $message): string` (spec 4: UUID → `<id>`, digit runs → `<n>`, 16+ hex → `<hex>`, quoted strings and SQL literals → `?`, whitespace collapsed, cut at 200); `topFrame(string $trace, string $platform): string` (PHP: first `app/` frame as `file::function` without line; JavaScript: file name without its hash plus function; Dart: library path under `qistas` plus function; none: `-`); `of(ErrorEvent $scrubbed): string` (`sha1(source|kind|class|normalised message|top frame)`; for `http` kind the class is `http.<status>` and the message is the route pattern); `title(ErrorEvent $scrubbed): string` (at most 200); `routePattern(string $path): string` (ids and numbers become `{id}` / `{n}`); `isScannerPath(string $path): bool`.

- [ ] **Step 1: Write the failing tests:** normalisation cases from spec 4 (two messages that differ only in ids or numbers get the same fingerprint; a different class or a different top frame differs); a deploy-stable case (the same exception at two different line numbers is one fingerprint); `http` fingerprints group `/app/customers/<uuid>` and `/app/customers/<other uuid>` together; slow-query fingerprint ignores literals; `isScannerPath` true for `/.env`, `/wp-login.php`, `/xmlrpc.php`, `/phpmyadmin/index.php`, `/backup.sql`, `/vendor/phpunit/x`, `/x.zip`, false for `/app/customers`, `/pricing`, `/ar/pricing`; `title` is plain words and capped.
- [ ] **Step 2: Run** the file. Expected: FAIL.
- [ ] **Step 3: Implement** the class (pure, no database).
- [ ] **Step 4: Run** the file. Expected: PASS.
- [ ] **Step 5: Commit** `Error center: how failures are grouped`.

### Task 4: The recorder, the spool and flood control

**Files:**
- Create: `web/app/Errors/Recorded.php` (enum), `web/app/Errors/ErrorRecorder.php`, `web/app/Errors/ErrorSpool.php`, `web/app/Errors/Events/ErrorIssueCreated.php`, `web/app/Console/Commands/ErrorsImportSpoolCommand.php` (`qistas:errors:import-spool`)
- Test: `web/tests/Feature/Errors/ErrorRecorderTest.php`, `web/tests/Feature/Errors/ErrorSpoolTest.php`

**Interfaces:**
- Consumes: Tasks 1 to 3.
- Produces: `enum Recorded: string { Off, Ignored, Dropped, Spooled, Counted, Created, Reopened }`; `ErrorRecorder::record(ErrorEvent $event): Recorded` (scrubs, fingerprints, then in one transaction upserts the issue, adds the occurrence and keeps the newest five, increments `error_issue_days`, inserts the person hash `substr(hash_hmac('sha256', $userId, config('app.key')), 0, 16)` while under 500); `ErrorRecorder::noise(string $kind, ?CarbonInterface $day = null): void` (adds to `error_noise_days`); `ErrorSpool::append(ErrorEvent $scrubbed): bool`, `ErrorSpool::import(ErrorRecorder $recorder): int`, `ErrorSpool::path(): string`; event `ErrorIssueCreated(ErrorIssue $issue)` fired once when a new issue is created. Rules: `enabled=false` → `Off` and nothing written; an `ignored` issue → `Ignored`, nothing counted; a `resolved` issue → set open, `reopened_at`, `reopened_version` = the event's version, return `Reopened` (no `ErrorIssueCreated`); new issue → `Created` and the event; over a cap → `Dropped` and `noise('dropped')`; any database failure → the already-scrubbed event is appended to the spool and `Spooled` is returned.

- [ ] **Step 1: Write the failing tests** (recorder): `a first failure creates an issue with one occurrence, one day row and one person`; `the same failure again only increments` (same issue id, count 2, still one issue); `a sixth occurrence deletes the oldest sample`; `the person count stops at 500 and reads as 500+` (unit-level on the model accessor or the stored cap); `ids and numbers in the message do not split the issue`; `two different classes make two issues`; `an ignored issue stays silent and uncounted`; `a resolved issue reopens with the version it came back in and fires no ErrorIssueCreated`; `ErrorIssueCreated fires once per new issue`; `the 61st new issue in a minute is dropped and counted as dropped`, `the 501st in an hour`, `client-reported sources (script, crash) stop at 20 new issues a minute`; `an existing issue still counts when new-issue caps are reached`; `QISTAS_ERRORS=false writes nothing and returns Off`; `first and last version track the min and newest event version`; `a stored occurrence holds no personal data` (event carrying an e-mail, a token and a phone number in its message and trace is stored masked).
- [ ] **Step 2: Write the failing tests** (spool): `when the database write throws, the scrubbed event is appended to the spool and Spooled is returned` (force the failure by making the issues table unreachable inside the test, for example renaming the table in a transaction or binding a recorder whose repository throws); `a failing spool never throws`; `the spool stops growing at 2 MB`; `import() records every line, truncates what it imported and returns the count`; `import() stops at the first line the database still refuses and keeps the rest`; `the command qistas:errors:import-spool prints how many it imported and exits 0`.
- [ ] **Step 3: Run** both files. Expected: FAIL.
- [ ] **Step 4: Implement** the classes. Caps use `Cache::add`/`Cache::increment` with keys that include the minute or hour; the spool uses `LOCK_EX` appends and a lock around `import`; the recorder never calls the capture layer (no recursion).
- [ ] **Step 5: Run** both files plus `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`. Expected: PASS.
- [ ] **Step 6: Commit** `Error center: the recorder, with a spool for when the database is down`.

### Task 5: Capturing what breaks on the server

**Files:**
- Create: `web/app/Errors/ErrorCapture.php`, `web/app/Http/Middleware/RecordErrorPages.php`, `web/app/Providers/ErrorServiceProvider.php`
- Modify: `web/bootstrap/app.php` (the `report` callback in `withExceptions`; the middleware in the global stack), `web/bootstrap/providers.php`
- Test: `web/tests/Feature/Errors/ErrorCaptureTest.php`

**Interfaces:**
- Consumes: Task 4.
- Produces: `final class ErrorCapture` with `exception(Throwable $e, ?Request $request = null): void`, `errorPage(Request $request, int $status): void`, `slowQuery(QueryExecuted $query): void`, and `static guarded(Closure $work): void` (runs `$work` through `defer(...)->always()` after the answer, swallows every `Throwable`, sets a re-entrancy flag so nothing recorded inside is recorded again). Classification: a `QueryException`, `PDOException`, lost connection or deadlock is source `database` on any request or command; otherwise `api` for paths under `/api`, else `site`. Database messages are built from the class, the SQLSTATE and `getSql()` (placeholders), never `getMessage()` (it holds the bindings). `RecordErrorPages::terminate(Request, Response)` records `site/http` for 403, 404, 419, 429, 500, 503 and `api/http` for 5xx, skips a 500 when an exception was already recorded for the request (`errors.recorded` request attribute), counts scanner paths through `ErrorRecorder::noise('scanner')` instead of recording them, and groups unmatched 404s by `ErrorFingerprint::routePattern`. The provider registers the `QueryExecuted` listener (a query at or above `qistas.errors.slow_query_ms` is recorded as `database/slow_query`, its message the SQL with placeholders) and does nothing while the re-entrancy flag is set. `ErrorCapture` records a given fingerprint at most once per request (spec 8).

- [ ] **Step 1: Write the failing tests** through the real kernel with test routes: a route that throws `RuntimeException` on the website is recorded as `site/exception` with the route pattern, method and status 500; the same under `/api/v1/...` is `api/exception`; the user's id is stored only as the 16-character hash; a thrown `QueryException` whose bindings include an e-mail address and a 10-digit ID stores neither (`database/exception`, message holds `SQLSTATE` and placeholders); a `ValidationException`, `AuthenticationException`, an `EntitlementException` and an API 401, 402, 422 are **not** recorded; an API reply of 503 is `api/http`; a 404 for `/pricing-typo` is one `site/http` issue and a second hit increments it; `/.env`, `/wp-login.php`, `/x.sql` increment `scanner` noise and create no issue; a 500 caused by a reported exception makes one issue, not two; a response of 429 and 419 on the website are `site/http`; **the same fingerprint reported twice within one request is counted once** (`ErrorCapture` keeps the fingerprints seen in a request-bound set); slow query with `slow_query_ms=0` is recorded without values; **the response is identical when the recorder throws** (bind a recorder that throws; assert status and body unchanged); **no recursion** (a failure inside the recorder creates no new issue); `QISTAS_ERRORS=false` records nothing; an artisan command that throws a `QueryException` is recorded as `database` with no request.
- [ ] **Step 2: Run** the file. Expected: FAIL.
- [ ] **Step 3: Implement** the classes and wire `bootstrap/app.php`: inside `withExceptions` add `$exceptions->report(fn (Throwable $e) => app(ErrorCapture::class)->exception($e))` (reportable exceptions only; keep the framework's own logging); add the middleware with `$middleware->append(RecordErrorPages::class)`; register the provider.
- [ ] **Step 4: Run** the file, the whole `tests/Feature/Errors` folder, and `vendor/bin/pest tests/Feature/Auth` (no sign-in test may change). Expected: PASS.
- [ ] **Step 5: Commit** `Error center: capture exceptions, error pages and slow queries`.

### Task 6: The two reporters (website script, phone endpoint)

**Files:**
- Create: `web/app/Errors/ClientErrorIntake.php`, `web/app/Http/Controllers/ClientErrorController.php`, `web/app/Http/Controllers/Api/V1/ClientErrorController.php`, `web/resources/js/error-report.js`
- Modify: `web/routes/web.php` (`POST /client-errors`, name `client-errors.store`), `web/routes/api.php` (`POST client-errors`, name `api.client-errors.store`, **outside** the `auth:sanctum` group), `web/bootstrap/app.php` (CSRF exemption for `client-errors`), `web/app/Providers/ErrorServiceProvider.php` (limiters `client-errors` 20 a minute by address, `client-errors-api` 30 a minute by token or address), `web/resources/js/app.js` (init)
- Test: `web/tests/Feature/Errors/ClientErrorsTest.php`, `web/tests/Feature/Errors/ErrorReportScriptTest.php`

**Interfaces:**
- Consumes: `ErrorRecorder`, `ErrorScrubber`.
- Produces: `ClientErrorIntake::accept(string $source, string $kind, array $data, ?string $userId, string $platform): void`; the website endpoint answers `204`; body at most 8 KB (413 above), JSON only (415 otherwise), a foreign `Origin` header is refused (403), every field length-limited, unknown fields ignored; the app endpoint answers `204`, body at most 16 KB, works with an app token (people hash) or none, fields `class` (160), `message` (500), `trace` (8000), `version` (16), `os` (`android` or `ios`), `os_version` (24, ignored by the recorder), `device` (`phone` → `mobile`, `tablet`). JavaScript exports `routePattern(pathname)`, `shouldReport(errorEvent, ownOrigin)`, `buildPayload(errorEvent, location)` and `initErrorReporting(win)`; at most 5 reports per page view; ignores errors whose file is not on the page's own origin and the cross-origin "Script error."

- [ ] **Step 1: Write the failing tests** (PHP): `a script report creates a site/script issue labelled as reported by a browser`; `a phone report creates an app/crash issue with platform android and device mobile`; `a report with a token stores only the person hash; without a token none`; `personal data in message and trace is masked` (e-mail, token, phone); `a body over the limit is refused with 413`; `a non-JSON content type is refused`; `a foreign Origin is refused`; `the 21st report in a minute from one address answers 429`; `the 21st NEW issue in a minute is dropped and counted as dropped while reports for existing issues still count`; `unknown fields are ignored`; `markup in a message is stored as text and never produces an ErrorIssueCreated alert listener call for client sources`; `the website endpoint needs no CSRF token and sets no cookie`; `kind and source cannot be chosen by the sender` (a body claiming `source: database` still stores `site/script`).
- [ ] **Step 2: Write the failing tests** (JS, run through Node the way the payday-vector test is run in `tests/Feature` (find it with `grep -rl payday-vectors tests`)): `routePattern` strips ids and the query string; `shouldReport` rejects extension and cross-origin errors and "Script error."; `buildPayload` contains only the allowed fields; the per-page cap of 5.
- [ ] **Step 3: Run** both files. Expected: FAIL.
- [ ] **Step 4: Implement** the intake, the controllers (thin: validate, call the intake, 204), routes, limiters, CSRF exemption, and `error-report.js` using `navigator.sendBeacon` (fallback `fetch` with `keepalive`), imported and started from `app.js`; no inline script anywhere.
- [ ] **Step 5: Run** both files, `npm run build`, and `vendor/bin/pest tests/Feature/Site`. Expected: PASS.
- [ ] **Step 6: Commit** `Error center: reports from the website's scripts and from the phone app`.

### Task 7: The admin screens

**Files:**
- Create: `web/app/Errors/ErrorTriage.php`, `web/app/Http/Controllers/Admin/ErrorsController.php`, `web/resources/views/admin/errors/index.blade.php`, `web/resources/views/admin/errors/show.blade.php`
- Modify: `web/routes/admin.php` (prefix `errors`, names `admin.errors.index`, `admin.errors.show`, `admin.errors.status`; `PUT {issue}/status` throttled 30,1; `{issue}` constrained to UUID), `web/resources/views/components/layouts/admin.blade.php` (nav entry `errors` with a badge of `ErrorIssue::openCount()`), `web/resources/views/admin/home.blade.php` and `web/app/Reports/PlatformOverview.php` (`health()` gains the check `errors`: `ok` when no open issue was seen in the last 24 hours, otherwise `warn` with "N open issues seen in the last 24 hours"), `web/lang/ar.json` (and the other language files as `TranslationsTest` requires), `web/resources/css/` (only if the admin stylesheet lacks the needed rules)
- Test: `web/tests/Feature/Errors/ErrorsAdminTest.php`

**Interfaces:**
- Consumes: Tasks 1 and 4.
- Produces: `ErrorTriage::resolve|ignore|reopen(ErrorIssue $issue, User $by): ErrorIssue` (each writes the audit row `error.resolved` / `error.ignored` / `error.reopened` through `Audit::record` with `['issue' => id, 'from' => previous status]`); the list (spec 7: source tag in text and colour, plain-words title, count with a 7-day sparkline drawn as inline SVG from `error_issue_days`, "new" / "came back" / "growing" marks, first and last seen with versions, filters source / status (default open) / new in 24 hours / came back, 25 per page, collapsed "Scanner noise" card with the last 7 days and the dropped count) and the detail page (scrubbed trace, five samples, 30-day timeline, who resolved or ignored and when, "reported by a browser" / "reported by a phone" label for client sources, the three buttons).

- [ ] **Step 1: Write the failing tests:** a guest is redirected to sign-in and a customer (business owner) gets 404 on `/admin/errors`, `/admin/errors/{id}` and the status route; both `admin` and `super_admin` can open the list and the detail and change status; each change writes the audit row with the actor and the previous status and an unknown status value is refused (422); filters narrow the list correctly (source, status, new in 24 hours, came back); `the list is paginated at 25`; the sidebar badge and the home line show the open count and the 24-hour warning; **an issue whose message and trace contain `<script>alert(1)</script>` and an `onerror=` attribute renders escaped (assert the raw markup is absent and the escaped text present)**; the page renders `dir="rtl"` in Arabic; scanner noise card shows the last 7 days; ignoring an issue removes it from the default list; the home `PlatformOverview::health()` has the new check (update `AdminOverviewTest` for the extra check).
- [ ] **Step 2: Run** the file. Expected: FAIL.
- [ ] **Step 3: Implement** the triage class, controller, views and nav entry. Use an existing icon from the admin icon set (look at how `life` and `bell` are defined) or add a simple one in the same style; keyboard-operable controls with visible focus; the source tag carries text, not colour alone. Add Arabic for every new `__()` string.
- [ ] **Step 4: Run** the file, `vendor/bin/pest tests/Feature/Admin tests/Feature/TranslationsTest.php`, `vendor/bin/pint --test`. Expected: PASS.
- [ ] **Step 5: Commit** `Error center: the admin screens`.

### Task 8: Alerts: the instant e-mail and the daily digest

**Files:**
- Create: `web/app/Errors/ErrorAlerts.php`, `web/app/Mail/ErrorAlertMail.php`, `web/app/Mail/ErrorDigestMail.php`, `web/resources/views/mail/error-alert.blade.php`, `web/resources/views/mail/error-digest.blade.php`, `web/app/Console/Commands/ErrorsDigestCommand.php` (`qistas:errors:digest`)
- Modify: `web/app/Providers/ErrorServiceProvider.php` (listen to `ErrorIssueCreated` with `ErrorAlerts`), `web/lang/*.json`
- Test: `web/tests/Feature/Errors/ErrorAlertsTest.php`

**Interfaces:**
- Consumes: `ErrorIssueCreated`, `Mail`, `RateLimiter`.
- Produces: `ErrorAlerts::instant(ErrorIssue $issue): bool` (sends one `ErrorAlertMail` to every active super admin when the issue is source `site`/`api`/`database`, kind `exception` or `http` with a status of 500 or above on its first occurrence, and neither the hour cap (3) nor the day cap (10) is reached; sets `alerted_at`; returns whether it sent); `ErrorAlerts::digest(CarbonInterface $now): bool` (one `ErrorDigestMail` only when at least one issue is new in the last 24 hours, came back in the last 24 hours, or is growing: today's count at least 3 and at least double the average of the previous 7 days; ignored issues excluded); `ErrorAlertMail(ErrorIssue)`, `ErrorDigestMail(array $new, array $cameBack, array $growing)`. Recipients: active (not suspended) super admins only. A mail failure is swallowed and never affects recording.

- [ ] **Step 1: Write the failing tests** (`Mail::fake()`, `travelTo`): instant mail for a new `site/exception`, `api/exception`, `database/exception`, `api/http` 503; **no** instant mail for `site/http` 404, 419 or 429, `slow_query`, `script`, `crash`; the 4th mail in an hour and the 11th in a day are not sent (and those issues appear in the next digest); an issue seen again does not mail again; a resolved issue that reopens sends no instant mail and appears in the digest as "came back"; an ignored issue never mails and never appears; mails go only to active super admins (not admins, not suspended); the mail names the source, the title and the count and links to `admin.errors.show`, contains no trace and no personal data; the digest is sent only when something is new, came back or growing (a quiet day sends nothing); the growth rule (today 3 and double the 7-day average; today 2 does not qualify); `a mail failure does not stop the recorder` (mailer throws; the issue is still stored); Arabic text for the mail (locale `ar` user).
- [ ] **Step 2: Run** the file. Expected: FAIL.
- [ ] **Step 3: Implement** with `RateLimiter::tooManyAttempts` / `hit` keys `error-alerts-hour` (3 per 3600 s) and `error-alerts-day` (10 per 86400 s); the listener calls `ErrorAlerts::instant` inside `ErrorCapture::guarded` semantics (swallows failures); the command prints "Digest sent." or "Nothing to send." and exits 0.
- [ ] **Step 4: Run** the file and `vendor/bin/pest tests/Feature/Errors tests/Feature/TranslationsTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `Error center: the instant e-mail and the daily digest`.

### Task 9: Retention, schedule, the go-live check

**Files:**
- Create: `web/app/Console/Commands/ErrorsPruneCommand.php` (`qistas:errors:prune`)
- Modify: `web/routes/console.php` (schedule `qistas:errors:prune` daily, `qistas:errors:import-spool` every five minutes, `qistas:errors:digest` daily at 07:00 in `app.timezone`, all `withoutOverlapping`), `web/app/Support/GoLiveCheck.php` (17th check `errors` titled "Error center"; update the docblock and the word "sixteen"), `web/app/Console/Commands/GoLiveCheckCommand.php` only if it names the count, `web/tests/Feature/Deployment/GoLiveCheckTest.php` (count, the new cases), `docs/features/go-live.md` and `docs/DEPLOY.md` (sixteen → seventeen)
- Test: `web/tests/Feature/Errors/ErrorsPruneTest.php`; additions to `GoLiveCheckTest.php`

**Interfaces:**
- Produces: `qistas:errors:prune` deletes occurrences older than `occurrence_days`, issues (cascading days and people) whose `last_seen_at` is older than `issue_days`, **resolved** issues 30 days after `resolved_at`, **ignored** issues 30 days after `last_seen_at`, and `error_noise_days` older than 30 days; prints the counts. The go-live check `errors`: PASS when `qistas.errors.enabled` is true and the spool folder (`dirname(ErrorSpool::path())`) is writable; WARN when `QISTAS_ERRORS` is off; FAIL when the folder cannot be written. Detail lines never print a path value beyond the folder name `storage/logs`.

- [ ] **Step 1: Write the failing tests:** prune boundaries at exactly the retention day (kept) and one day over (removed) for occurrences, issues, resolved issues, ignored issues and noise; an open issue seen recently is never removed; cascades remove days and people; the three commands appear in `Schedule` with the right frequencies (follow how the existing schedule tests read `app(Schedule::class)->events()`); go-live: PASS, WARN (disabled) and FAIL (unwritable folder: point `ErrorSpool::path()` through a test config override) cases, and the total is seventeen.
- [ ] **Step 2: Run** the files. Expected: FAIL.
- [ ] **Step 3: Implement** the command, the schedule lines (each with a one-line comment), the check, and the doc number changes.
- [ ] **Step 4: Run** `vendor/bin/pest tests/Feature/Errors tests/Feature/Deployment`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`. Expected: PASS.
- [ ] **Step 5: Commit** `Error center: retention, the schedule and a 17th go-live check`.

### Task 10: The phone app: reporter, queue, Settings switch

**Files:**
- Create: `app/lib/core/errors/error_reporter.dart`, `app/lib/core/errors/error_queue.dart`, `app/lib/core/errors/trace_scrubber.dart`
- Modify: `app/lib/data/qistas_api.dart` (`reportError(Map<String, dynamic> report)`, an unauthenticated-or-token POST to `/client-errors`), `app/lib/main.dart` (install `runZonedGuarded`, `FlutterError.onError`, `PlatformDispatcher.instance.onError`), `app/lib/app/providers.dart` (the `crashReportsProvider`, a bool in `shared_preferences` under `crash_reports`, default `true`), `app/lib/features/settings/settings_screen.dart` (a switch row "Send crash reports" with a one-line explanation), `app/assets/i18n/*.json` (five languages; Arabic count-neutral)
- Test: `app/test/core/errors/trace_scrubber_test.dart`, `app/test/core/errors/error_reporter_test.dart`, `app/test/features/crash_reports_setting_test.dart`

**Interfaces:**
- Consumes: Task 6's `POST /api/v1/client-errors`; `deviceHint`/`AppConfig.appVersion` for the version; `defaultTargetPlatform` for `android`/`ios`.
- Produces: `TraceScrubber.clean(String)` (same patterns as the server's scrubber: e-mails, phone numbers, long digit runs, tokens, query strings, absolute paths cut to `package:` form; cap 8000); `ErrorQueue` (`add(report)`, `drain()`, at most 20 reports, drops anything older than 6 hours, persisted in `shared_preferences`); `ErrorReporter.report(Object error, StackTrace stack)` (does nothing when the switch is off; builds exactly `{class, message, trace, version, os, os_version, device}`; sends, queues on failure; a 404 or 405 answer stops sending for the session and clears the queue; never throws and never reports its own failure); `installErrorReporting({...})` returning the guarded runner `main.dart` uses; `crashReportsProvider` (`StateNotifierProvider<…, bool>` or the project's existing pattern).

- [ ] **Step 1: Write the failing tests:** scrubber table (e-mail, `+974 5555 1234`, `0501234567`, a 10-digit ID, `Bearer abc`, `token=xyz`, `?access_token=1`, an absolute path); the report holds exactly the allowed keys and no name, number, amount or account id; the switch off sends nothing and queues nothing; a failed send queues and the next successful flush drains in order; the 21st queued report drops the oldest; a report older than 6 hours is dropped; a 404 stops further sends in the session and clears the queue; an exception thrown by the sender is swallowed and produces no second report; `FlutterError.onError` and the platform error hook call the reporter (use `installErrorReporting` with an injected sender); the Settings row toggles the preference and persists it, shows the explanation, and holds together in Arabic at 200 % text on a small phone.
- [ ] **Step 2: Run** the three files (`flutter test --no-pub test/core/errors test/features/crash_reports_setting_test.dart`). Expected: FAIL.
- [ ] **Step 3: Implement.** Keep `main.dart` change small: `runZonedGuarded(() async { ...existing body... }, reporter.onZoneError)` and the two hooks installed before `runApp`; add the five-language strings and run `python tool/i18n.py --sync`.
- [ ] **Step 4: Run** `flutter analyze --no-pub` (zero issues), the whole `flutter test --no-pub`, `python tool/i18n.py --sync`; look at the Settings picture once if the visual test list covers it. Expected: PASS. (Use `--no-pub`: `pub get` can hang on this machine.)
- [ ] **Step 5: Commit** `App: crash reports, with a switch in Settings`.

### Task 11: Documentation, release, verification

**Files:**
- Create: `docs/features/error-center.md`
- Modify: `docs/DEPLOY.md` (the four settings rows and a short "Errors" paragraph; the scheduled commands), `web/.env.example` (check Task 1 left it complete), `docs/api/openapi.yaml` (`POST /client-errors` with the request body, 204/413/415/422/429, "no token needed"), `docs/features/go-live.md` (seventeen checks, one line for the new one), `app/pubspec.yaml`, `app/lib/core/config.dart`, `app/test/features/settings_test.dart` (version 1.30.0+34)
- Verify only: the whole branch

- [ ] **Step 1: Write `error-center.md`** in the style of the other feature notes (dated, owner-facing): what is recorded and what never is, the four sources, grouping and statuses, the screens, the alerts and their caps, the app switch, retention, the operator commands with sample output from a developer machine (run `php artisan qistas:errors:prune`, `qistas:errors:digest`, `qistas:errors:import-spool` and paste the real lines), known limits (native crashes that never reach Dart are not seen; the app does not report network failures; client reports are unverifiable and never mail), and the **proposals, not published until the owner approves them**: the privacy sentence of spec 11, the Google Play Data safety change (crash logs and diagnostics become collected, optional) and the Apple privacy label ("Crash Data", not linked to identity), each marked as a proposal.
- [ ] **Step 2: Update the other documents** listed above; bump the version in the three app places.
- [ ] **Step 3: Verify the whole branch** from `web/`: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`, the whole `vendor/bin/pest --compact` (about 15 minutes, once), `npm run build`; against the PostgreSQL stand-in already running on port 5433 (never start or stop it): `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=postgres DB_USERNAME=postgres DB_PASSWORD=postgres DB_SSLMODE=disable DB_URL= DB_EMULATE_PREPARES=true php artisan migrate:fresh --force` then `php vendor/bin/pest --compact --exclude-group=db-errors tests/Feature/Errors tests/Feature/Admin tests/Feature/Deployment tests/Feature/Auth`; from `app/`: `flutter analyze --no-pub`, `flutter test --no-pub`, `python tool/i18n.py --sync`. Expected: all green; record the numbers.
- [ ] **Step 4: Commit** the documents and the version separately: `Error center: the owner's note, the settings and the API entry`, then `App 1.30.0: crash reports with a switch`.

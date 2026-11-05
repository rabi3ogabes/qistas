# Error center (Release 2 of 3 of the go-live package)

Date: 4 November 2026. Asked by the owner on 1 November 2026: "a dashboard recording bugs, issues and error pages of the
site, the API, the database and the app". Release 1 (go-live basics) is built; Release 3 (phone number and code, Google,
Microsoft) is a separate cycle. This document is the design for Release 2 only.

## 1. Decisions already made by the owner

| Question | Answer |
|---|---|
| Build it or buy it | **Build it inside Qistas' own admin console.** Nothing is sent to a third party. |
| How you are told | **Instant e-mail for a brand-new kind of server error, plus a daily digest.** A badge on the admin home always shows the open count. |
| The phone app | **Sends its crashes and unhandled errors automatically, with a "Send crash reports" switch in Settings to turn it off.** |

Approved in chat on 4 November 2026: the four sources and what they record (section 3), the grouping (4) and what is
kept or never kept (6); the screens, alerts, failure rules, reporters and retention (7 to 11).

## 2. Goal and what success looks like

The owner opens one screen in the admin console and sees what is breaking on the website, the API, the database and
the phone app: what, how often, since when, on which version, whether it is new or came back, and whether anyone has
dealt with it. A new kind of server error reaches the owner by e-mail within minutes. No customer data is stored or
shown. Recording never slows or breaks a request.

**Not in this release:** performance monitoring beyond slow queries, session replay, a "report a problem" form for
customers, source maps or release tracking tools, anything for Release 3.

## 3. What is recorded

Every record has a **source** (`site`, `api`, `database`, `app`) and a **kind**.

| Source | Kind | Recorded when |
|---|---|---|
| `site` | `exception` | an exception the framework reports on a request that is not under `/api` |
| `site` | `http` | an error page is shown to a visitor: 403, 404, 419, 429, 500, 503 |
| `site` | `script` | the browser reports a script error from our own scripts (section 9) |
| `api` | `exception` | an exception reported on a request under `/api` |
| `api` | `http` | the API answers 5xx (a 401 sign-in, 402 plan limit, 403, 404, 405, 409, 422 or 429 is an expected answer and is **not** an error) |
| `database` | `exception` | a `QueryException` or `PDOException` (any request, command or job), a lost connection, a deadlock |
| `database` | `slow_query` | a query takes longer than `QISTAS_SLOW_QUERY_MS` (default 3000) |
| `app` | `crash` | the phone app reports an uncaught Dart error or a Flutter framework error (a crash inside Android or iOS code that never reaches Dart is not visible without a native plugin and is out of scope) |

Nothing else. In particular the app does **not** report failed network calls (offline is normal; a 5xx is already seen by
the server) and does not report validation errors.

**Scanner noise.** A 404, 403 or 405 for a path that only a scanner asks for (`/.env`, `/.git/`, `/wp-*`, `/xmlrpc.php`,
`/phpmyadmin`, `/vendor/`, `/admin.php`, anything ending `.php` or `.sql`, `.bak`, `.zip`, `.env`; the list lives in one
config array) is counted per day in `error_noise_days` and never becomes an issue, a sample or an e-mail. Every other
404 on a path our routes do not serve is recorded as an issue grouped by path pattern (ids and numbers replaced), so a
broken link on the site shows up.

**Never recorded as errors:** `EntitlementException` (already `dontReport`), validation failures, authentication
failures, `HttpException` below 500 from the API, and anything the owner has set to *ignored*.

## 4. Grouping, and the life of an issue

An **issue** is a set of occurrences with the same **fingerprint**: `sha1(source | kind | class | normalised message |
top frame)`.

- *Normalised message:* UUIDs become `<id>`, runs of digits become `<n>`, hex strings of 16 or more characters become
  `<hex>`, quoted strings and SQL literals become `?`, whitespace is collapsed, cut at 200 characters.
- *Top frame:* the first frame of our own code: PHP `app/...` (file and function, no line number, so a deploy does not
  split an issue), JavaScript (file name without its hash, and function), Dart (library path under `qistas` and
  function). None found: the literal `-`.
- *HTTP error pages:* the class is `http.<status>` and the message is the route pattern (`/app/customers/{id}`), or the
  path with ids replaced when no route matched.
- *Slow query:* the SQL with every literal and binding replaced by `?`, cut at 300 characters; no values, ever.

**Status:** `open` (new and untouched), `resolved`, `ignored`. A staff member can resolve, ignore or reopen an issue
(audited). An occurrence for a **resolved** issue reopens it and records `reopened_at` and the version it came back in
("came back in 1.30.0"). Occurrences for an **ignored** issue are dropped silently, not counted and never alerted.

An issue shows: its source and kind, a title in plain words (the error class and the normalised message), the count, the
number of different people it touched (a count only, never who), first seen and last seen with their versions, a
7-day count per day, and five sample occurrences.

## 5. Data model

All tables are **platform-wide, not tenant-scoped** (like `sign_in_events` and `AuditLog`: platform code, no
`tenant_id`, no business name or id stored anywhere). Migrations run on SQLite and PostgreSQL. UUID primary keys.

- `error_issues`: `id`, `fingerprint` (char 40, unique), `source`, `kind`, `title` (200), `class` (160, null), `status`,
  `occurrences` (bigint), `people` (int, capped at 500), `first_seen_at`, `last_seen_at`, `first_version`, `last_version`
  (16, null), `resolved_at`, `resolved_by` (user id, null), `ignored_at`, `ignored_by`, `reopened_at`, `reopened_version`,
  `alerted_at` (when the instant e-mail went), `created_at`, `updated_at`. Index on `(status, last_seen_at)` and
  `(source, status)`.
- `error_occurrences`: the **last five** per issue only: `id`, `issue_id` (cascade), `occurred_at`, `status` (int, null),
  `method` (8, null), `route` (160, null; the pattern, never the address with ids or a query string), `message` (500,
  scrubbed), `trace` (text, scrubbed, at most 8 KB), `version` (16, null), `platform` (`server`, `web`, `android`, `ios`),
  `device` (`desktop`, `mobile`, `tablet`, null), `created_at`. Writing a sixth deletes the oldest of that issue.
- `error_issue_days`: `issue_id` (cascade), `day` (date), `count`; primary key `(issue_id, day)`. Drives the sparkline and
  "growing".
- `error_issue_people`: `issue_id` (cascade), `person` (char 16: first 16 hex characters of an HMAC-SHA256 of the user id
  with the application key); primary key `(issue_id, person)`; at most 500 rows per issue, after which `people` stops
  growing and shows "500+". Removed with the issue.
- `error_noise_days`: `day` (date), `kind` (`scanner`, `dropped`), `count`; primary key `(day, kind)`. `dropped` is
  everything over a cap (section 8).

No model has mass-assignable status or counters: `ErrorRecorder` and `ErrorTriage` are the only writers, with explicit
`#[Fillable]` lists.

## 6. What is stored, and what never is

Before anything is stored, one `ErrorScrubber` cleans **every** string (message, trace, route, class, title) and is the
only way in. It removes or masks:

- e-mail addresses and phone numbers (including Arabic-Indic digits), national-ID-like runs of 9 or more digits,
  IBANs, card-like runs (13 to 19 digits);
- bearer tokens, Sanctum tokens (`\d+\|[A-Za-z0-9]{40,}`), JWT-like `xxx.yyy.zzz`, long hex or base64 runs (24 or more
  characters), `password=`/`token=`/`secret=`/`key=`/`authorization:`/`cookie:` values in any case, URL user-info, and any
  query string;
- SQL bindings: only the statement with `?` is kept;
- file paths are cut to the part under the project root; absolute server paths and environment values are removed.

Never stored, by construction (they never reach the recorder): request bodies and headers, cookies, session ids, the IP
address, the user agent, user ids (only the 16-character HMAC above), tenant ids or names, customer data. The phone's
report carries only the error, a scrubbed trace, the app version, the Android or iOS version, and phone or tablet.

## 7. The admin screens

A new **Errors** entry in the admin console (nav section `errors`), under `/admin/errors`, reachable by platform staff
only (the existing `admin` middleware group: a customer gets 404). Both admin roles can read and change status.

- **List** (`GET /admin/errors`): one row per issue: a coloured source tag (text as well as colour), the plain-words
  title, count with the 7-day sparkline, "new" / "came back" / "growing" marks, first and last seen, last version.
  Filters: source, status (default *open*), "new in 24 hours", "came back". Newest last-seen first, 25 per page. A
  collapsed **Scanner noise** card shows the last 7 days of counts and the dropped count.
- **Detail** (`GET /admin/errors/{issue}`): the scrubbed trace, the five samples, the 30-day timeline, who resolved or
  ignored it and when, and the buttons **Resolve**, **Ignore** and **Reopen** (`PUT /admin/errors/{issue}/status`,
  throttled; each change writes the audit row `error.resolved`, `error.ignored` or `error.reopened` with the issue id
  and the status it came from).
- **Badge:** the open-issue count (excluding scanner noise) on the sidebar entry and on the admin home, which also gains
  the line "Errors in the last 24 hours" in its health block (`PlatformOverview::health()`).
- Every screen is keyboard-operable, RTL-correct, works at 200 % text, and meets the admin console's existing design
  system (`x-layouts.admin`). English and Arabic complete; French, Spanish and Urdu fall back per `web/CLAUDE.md`.

## 8. Alerts, safety rules, flood control

**Instant e-mail.** When an issue is **created** with source `site`, `api` or `database` and kind `exception` or `http`
with a status of 500 or above, an e-mail goes to every active super admin: the source, the title, the count so far and a
link. Error pages below 500 (a 404, a 419) and slow queries never alert instantly; they appear in the digest. Caps: at most **3 per hour and 10 per day** over all issues
(`Cache::add` counters); an issue that would exceed a cap is simply included in the next digest. `alerted_at` records it.
`script` and `crash` (client-reported, unverifiable) issues **never** trigger an instant e-mail.

**Daily digest** (`qistas:errors:digest`, 07:00 in `app.timezone`): to the same people, only on days when at least one
issue is new, growing (today at least 3 times and at least double the 7-day daily average) or came back, listing them
with their counts. Sent through the existing mailer (`MailSettings`); with the mailer set to `log` nothing special
happens (the go-live check already fails mail).

**Recording can never break a request.** Server capture runs through `defer(...)->always()` after the response is sent
(as `SignInLog` does) and is wrapped so that any `Throwable` inside the recorder is swallowed. The recorder never
records its own failures and sets a re-entrancy flag, so an error inside it cannot loop.

**The database is down.** If the write fails, the already-scrubbed event is appended as one JSON line to
`storage/logs/error-spool.ndjson` (a lock, a 2 MB cap: when full, further events are only counted in memory for that
process and dropped). `qistas:errors:import-spool` (scheduled every five minutes) imports the file when the database
answers, then truncates it. The admin home's "database" line comes from its own check, not from this table.

**Floods.** Per fingerprint, a counter only increments; samples are capped at five; new issues are capped at **60 per
minute** and **500 per hour** for the whole platform (client-reported sources at 20 per minute); everything over a cap
increments `error_noise_days.dropped` and is shown as "dropped" so nothing is invisible. A repeat of the same
fingerprint within the same request is counted once.

## 9. The two reporters

Both are authored **without inline script** (the site's strict content-security policy and its Alpine CSP build forbid
it) and send only what section 6 allows. Both are untrusted input: any visitor can post to them, so they get strict
validation, size and rate limits, never raise instant alerts (section 8), and their issues are labelled "reported by a
browser" or "reported by a phone" on the detail page.

**Website script** (`resources/js/error-report.js`, imported by `app.js`): listens to `window` `error` and
`unhandledrejection`; ignores errors whose source is not our own origin (browser extensions, third-party scripts) and
cross-origin "Script error."; sends at most 5 per page view with `navigator.sendBeacon` (JSON, same origin) to
`POST /client-errors` (route name `client-errors.store`, throttled 20 per minute per address, **no cookie and no CSRF
token needed**, body at most 8 KB, JSON content type only, an `Origin` header that is not our own is refused, every
field length-limited, unknown fields ignored). It sends the message, a trace of our own frames, the page route
pattern (the script strips ids and the query string before sending) and nothing else; the server scrubs everything before
storing it.

**Phone app** (`POST /api/v1/client-errors`, an app token or none: a crash on the sign-in screen matters; throttled
30 per minute per address and per token; body at most 16 KB): `main.dart` installs `runZonedGuarded`, `FlutterError.onError`
and `PlatformDispatcher.instance.onError`. Each report is the error class, a scrubbed trace, the app version, the Android
or iOS version and phone or tablet (only Dart-level errors; see section 3). Rules:

- Skipped entirely when **Settings > Send crash reports** is off (a new switch, **on by default**, stored on the phone).
- The device keeps unsent reports (at most 20, at most 6 hours old) and retries; none survives longer. A `404` or `405`
  answer from an older server stops sending for that session and drops the queue (no retrying forever).
- A reporter failure is swallowed and never produces a second report.
- The app never sends a name, a number, an amount, a phone number, what was typed, or the account id.

## 10. Retention and commands

- Occurrences: 30 days. Issues (with their days and people): 90 days after `last_seen_at`; a **resolved** issue 30 days
  after `resolved_at`; an ignored issue 30 days after its last sighting (dropped occurrences do not renew it).
  `error_noise_days`: 30 days.
- `qistas:errors:prune` (daily), `qistas:errors:import-spool` (every five minutes), `qistas:errors:digest` (daily 07:00),
  all registered in `routes/console.php` with `withoutOverlapping`.
- Configuration (all with safe defaults, listed in `.env.example` and `docs/DEPLOY.md`): `QISTAS_ERRORS` (default
  `true`; `false` turns capture off and hides nothing already recorded), `QISTAS_SLOW_QUERY_MS` (3000),
  `QISTAS_ERROR_OCCURRENCE_DAYS` (30), `QISTAS_ERROR_ISSUE_DAYS` (90).

## 11. Privacy, store forms, go-live

- A new feature note `docs/features/error-center.md` for the owner (what is recorded, what never is, the screens, how
  to resolve, what the alerts are).
- **Proposals, not published until the owner approves:** one sentence for the privacy page ("When the app or the website
  hits an error we keep the kind of error, a technical trace with personal details removed, the version and the kind
  of device, for up to 90 days, to fix it. You can turn this off for the app in Settings.") and the Google Play Data
  safety changes (crash logs and diagnostics become **collected, optional, for app functionality / analytics**; the
  current answer is "Not collected" in `docs/store/GOOGLE_PLAY_LAUNCH.md`). The Apple privacy label needs the same
  change ("Crash Data", not linked to identity). These are listed in the release checklist, not edited by the code.
- `qistas:go-live-check` gains a 17th check, **Error center**: PASS when capture is on and the spool folder is
  writable; WARN when `QISTAS_ERRORS` is off; FAIL when the folder cannot be written. `docs/features/go-live.md` is
  updated.
- Deploy order, as in Release 1: **website first, app after**. The website tolerates an app that does not report; the
  new app tolerates a server without the endpoint (section 9).

## 12. Where it lives

Web (`web/`): `App\Errors\ErrorRecorder` (the one entry point: scrub, fingerprint, upsert, sample, count, flood caps,
spool fallback), `ErrorScrubber`, `ErrorFingerprint`, `ErrorTriage` (resolve, ignore, reopen), `ErrorAlerts` (instant and
digest), `ErrorSpool`, `ErrorIssue`/`ErrorOccurrence` models, `ErrorCapture` (hooks: `bootstrap/app.php`
`$exceptions->report`, a terminating middleware for error pages, `DB::listen` in a service provider), `Admin\ErrorsController`,
`Http\Controllers\ClientErrorController` (site) and `Api\V1\ClientErrorController` (app), Blade views under
`resources/views/admin/errors/`, the commands above, `resources/js/error-report.js`, `lang/` strings.
App (`app/`): `lib/core/errors/` (reporter, queue, scrubber for the trace), the Settings switch, `main.dart` wiring,
i18n in five languages.

## 13. How it can hurt a person, and where a test pins it

1. **Personal data in an error record** (a phone number in an exception message, a token in a trace, bindings in a SQL
   error, an e-mail in a validation text). The scrubber has a table-driven test per pattern in section 6, and an
   end-to-end test throws exceptions carrying each kind of value and asserts the stored text contains none.
2. **The recorder breaks or slows a request**, or loops on its own failure. Tests: a recorder whose database write
   throws still returns the response; the spool receives the event; a failure inside the spool is swallowed; no
   recursion.
3. **An alert flood or a silent miss.** Tests for the hourly and daily caps, "new issue only", no instant e-mail for
   client-reported issues, ignored issues silent, resolved issues reopening and alerting once.
4. **Fake or hostile reports** (a stranger posting thousands of crashes, giant bodies, forged origins, markup in the
   message). Tests: size, rate and per-minute-new-issue caps, `dropped` counting, escaped output in the admin views.
5. **A customer reaching the screens, or staff actions unaudited.** Tests: a non-staff user gets 404 on every
   route, status changes write audit rows, the pages are RTL and keyboard-safe.

## 14. Plan deviations

What the build does differently from sections 1 to 13 above (checked against the code on 5 November 2026; the rest of this
document stands as written). Each item names the section it changes.

**Grouping and titles (section 4)**

- D1. SQLSTATE codes are kept in fingerprints (`SQLSTATE[23505]` is not turned into `SQLSTATE[<n>]`), and double-quoted
  identifiers stay inside a SQL statement (tables and columns), so queries on different tables do not merge.
- D2. A slow query's statement is cut at 300 characters for its fingerprint and every other normalised message at 200
  (`normalise()` takes the width); a list of the same mark collapses (`in (?, ?)` is `in (?)`, and `values (?), (?)` is `values (?)`).
- D3. A frame counts as "our code" when its file is under `app/` or its function starts with `App\` (controllers, jobs and
  commands called from vendor code), and a masked file does not hide it.
- D4. Database failures are stored as the class, the SQLSTATE with a short plain description ("Unique violation") and the
  statement with placeholders, never the driver's own message; `mask_bindings_in_exception_messages` is on for all five connections and
  `App\Errors\SqlMasker` removes every value from a statement. (Sections 3 and 6.)

**What is recorded (section 3)**

- D5. Besides `EntitlementException`, an `ApiException` below 500 and the "not on your plan" or "switched off" page are not recorded; the
  503 shown in maintenance mode is not recorded; a request that reports an exception and then answers 5xx is one issue, not two.
- D6. A 5xx on a path no route matches (and the web fallback route counts as no route) uses one fixed pattern, `/{unmatched}`; a 404 on
  such a path keeps the path with ids hidden.
- D7. The noise list is two config lists, not one: `errors.scanner_paths` (widened: any dot file at any depth, `/cgi-bin/`, `/actuator`,
  `.map`, `.gz`, `.yml` and more) and `errors.browser_noise_paths` (`/apple-touch-icon*.png`, `/sw.js`, `/manifest.webmanifest`, everything under `/.well-known/`).
- D8. Repeats of a hot error page (the same fingerprint below 500 within 5 seconds) are counted in `dropped`; there is no separate
  noise kind. `dropped` also counts reports lost on the way (unreadable or refused spool lines, a request with more than 100 events).
- D9. The two reporters' own refusals (403, 413, 415, 422, 429) are never recorded as error pages and not counted anywhere.
- D10. A failed queued job and a failed scheduled task are recorded through the worker's and the scheduler's events (flushed at the
  worker's next loop or stop), as the job's own failure with no route, method or status.
- D11. `RecordErrorPages` is **prepended** to the global middleware, not appended, because terminating middleware ends in list order
  and an appended one would end after `InvokeDeferredCallbacks`, which would then never run its deferred recording.
- D12. An exception the code reported and recovered from is stored with status 500 (the answer is not known when it is reported).

**Floods, spool, alerts (section 8)**

- D13. New issues draw on **four** budgets, not one: `alert` (exceptions and error pages of 500 or more) 60 a minute and 500 an hour, `quiet`
  (error pages below 500 and slow queries) 30 and 200, `script` and `crash` 20 and 100 each; a known issue is never capped; a cap counter
  that cannot be read keeps the gate shut.
- D14. On top of the budgets one client sender may open 3 new client issues an hour, keyed by the person for a signed-in phone (every sign-in
  makes a new token) and by the address otherwise; the key is an HMAC and is never stored.
- D15. The recorder runs its writes in transactions of its own and, on PostgreSQL, sets `lock_timeout = 1s` (`SET LOCAL`, at the outermost level
  only); counters are added to in the database (`occurrences = occurrences + 1`).
- D16. A spool line keeps its stored fingerprint only when an issue with that fingerprint exists and has the same source and kind; the import
  has a 20-second budget, a line the database refuses three times while its tables answer is dropped, and a refusal for a lock (55P03)
  is treated as "the database is away".
- D17. The 3-an-hour and 10-a-day caps use Laravel's rate limiter (`error-alerts-hour`, `error-alerts-day`), not raw `Cache::add`: the hour
  counter is taken atomically before sending, the day counter moves only after a mail went, and a dead mail server uses the hour's three attempts.
- D18. A resolved issue that comes back sends no instant e-mail (the event fires only when an issue is created); it is announced by the digest
  as "came back".
- D19. The digest runs at **04:00 UTC, which is 07:00 in Qatar**, not "07:00 in `app.timezone`", because `app.timezone` is hard-coded to UTC in
  `config/app.php`. It lists open issues only, each in one list (came back, then new, then growing), at most 25 per list with "And N more", shows
  client-reported issues by their kind and not their text, and judges "growing" on the application's day so far.
- D20. The mails are plain HTML with a text part (not Markdown, so a title can never become a link), carry an `Auto-Submitted` header, and link to
  `APP_URL` plus the relative route (never the request's host). A digest that could not be sent to anyone fails the command (exit 1).
- D21. `qistas:errors:prune` also removes the expired error-center rows from the cache table (`errors:*`, `error-alerts-*`) when the cache is in the database.

**The two reporters (section 9)**

- D22. The phone endpoint is throttled 30 a minute per **app token** (per address when there is none), not "per address and per token"; only a token with
  the `app` ability is a person, any other is nobody; and a request carrying `Origin` or `Sec-Fetch-Site` is refused with an empty 403, because a
  browser can post to it cross-site without a preflight.
- D23. The stored route of a client report is never the page's own text: it is matched against the router (`/verify/K7MPQ2RTXW` is `/verify/{code}`),
  or hidden as a pattern, and any segment that is markup once its percent escapes are read becomes `{x}` (`ErrorScrubber::route`, one rule for
  every route). A refusal is a bare status with no body (the phone's 429 keeps the API's usual error body).
- D24. The phone sends the system's version (`os_version`) and the server reads and does not keep it (no column).

**The phone app (section 9)**

- D25. A 403, 404 or 405 answer (not only 404 and 405) stops reporting for the session and drops the queue; 413 and 422 drop that report; 429 waits `Retry-After`.
- D26. The reporter sends only the first line of an error's message, does not report network, API or time-out errors or framework errors marked
  silent, counts the same error within a minute once, uses an API client of its own (a stale token cannot sign anyone out), and cuts the encoded body to 15 KB.
- D27. The web build never reports, and the Settings switch is hidden there (a browser's reports always carry an `Origin` and would all be refused).

**The screens (section 7)**

- D28. The home health line "Errors in the last 24 hours" counts only server errors (open exceptions and error pages of 500 or more); the sidebar
  badge still counts every open issue. When recording is off the line is a warning that says so, and the list shows the same notice.
- D29. The source tag for `site` reads "Website" (a string called "Site" collides with `lang/en/site.php`), the status reads "Open issue", the noise card is
  called "Background noise (bots and browsers)" and shows the dropped count in its summary, Ignore asks for a second press (a `<details>`, no script),
  a staff resolve or ignore clears `reopened_at` and `reopened_version` (so "came back" means it came back after the last decision), and the detail
  page's back link keeps the list's filters.
- D30. Translations: the strings live in `lang/app/{ar,fr,es,ur}.json`; French, Spanish and Urdu are real translations (the translation test demands
  them) and, like the Arabic, were not read by a native speaker.

**Elsewhere**

- D31. `error_occurrences` has one extra index, `(issue_id, occurred_at)`, and the issue's samples relation is `samples()` (the `occurrences` property is the count column).
- D32. A bearer token whose id no bigint can hold (`99999999999999999999|x`) gave a 500 on PostgreSQL on every `auth:sanctum` route and on `/plans`;
  `App\Models\PersonalAccessToken` (Sanctum's model, registered in `AppServiceProvider`) now answers it as an ordinary 401.

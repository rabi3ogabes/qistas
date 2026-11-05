# Error center: see what breaks before a customer tells you

Asked by the owner on 1 November 2026: "a dashboard recording bugs, issues and error pages of the site, the API, the
database and the app". This is **Release 2** of the go-live package. Release 1 (sign-up with five fields, the sign-in
record, the go-live check) is `sign-up.md` and `go-live.md`; Release 3 (phone number and code, Google, Microsoft) is a
separate piece of work. Designed and approved on 4 November 2026, built on 4 and 5 November. The design is
`docs/superpowers/specs/2026-11-04-error-center-design.md`; its section 14 lists where the build differs from it.

The one-minute version: when something breaks on the website, in the API, in the database or in the phone app, it
appears on one admin screen, grouped so that a thousand repeats are one line; a brand-new server error also reaches
the super admins by e-mail within moments; a daily summary tells them what is new, came back or is growing. Nothing
about a customer or a business is stored, and nothing goes to a third party.

## The three decisions you made

| Question | Your answer, and what was built |
|---|---|
| Build it or buy it | **Built inside Qistas' own admin console.** No Sentry, no outside service: nothing is sent anywhere. |
| How you are told | **An instant e-mail for a brand-new kind of server error, plus a daily digest at 07:00 Qatar time.** A badge on the admin menu always shows how many issues are open. |
| The phone app | **It sends its crashes and unhandled errors by itself, with a "Send crash reports" switch in Settings** that turns it off (on by default). |

## What is recorded

Every record has a **source** (Website, API, Database, App) and a **kind**.

| Source | Kind | Recorded when |
|---|---|---|
| Website | Exception | the framework reports an exception on a request that is not under `/api` |
| Website | Error page | a visitor is shown a 403, 404, 419, 429, 500 or 503 page |
| Website | Script error | a visitor's browser reports an error in one of our own scripts |
| API | Exception | the framework reports an exception on a request under `/api` |
| API | Error page | the API answers 500 or above |
| Database | Exception | a `QueryException` or `PDOException` (on any request, command or queued job), a lost connection, a deadlock |
| Database | Slow query | a query takes longer than `QISTAS_SLOW_QUERY_MS` (3000 milliseconds unless you change it) |
| App | Crash | the phone app reports an uncaught Dart error or a Flutter framework error |

A queued job or a scheduled task that fails is recorded too, as its own failure (it has no page, so no address, method
or status is stored for it).

**Not recorded, on purpose.** Answers that are the product doing its job: a sign-in refused, a plan limit (402), a
validation message, a 401, 403, 404, 405, 409, 410, 422 or 429 from the API, and the "not on your plan" or "switched
off" page. Also: anything you set to *Ignored*, the 503 page shown while the site is in maintenance mode, and the error
center's own failures (it never records itself). The phone does not report failed network calls: offline is normal, and a
real 5xx is already seen by the server.

**Background noise.** Bots probe every site for files it does not have (`/.env`, `/.git/`, `/wp-login.php`,
`/xmlrpc.php`, `/phpmyadmin`, `/vendor/`, anything ending `.php`, `.sql`, `.bak`, `.zip`, `.map` and the like), and browsers
ask by themselves for icons and manifests (`/apple-touch-icon.png`, `/sw.js`, `/manifest.webmanifest`, anything under
`/.well-known/`). When such a path answers 404, 403 or 405 it is only **counted per day** in the "Background noise" card:
never an issue, never a sample, never an e-mail. Every other 404 is recorded and grouped by page address pattern, so
a broken link on the site shows up as an issue. The two lists are in `config/qistas.php` (`errors.scanner_paths` and
`errors.browser_noise_paths`).

## How failures are grouped, and the life of an issue

An **issue** is every occurrence with the same **fingerprint**: the source, the kind, the error class, the message with
its ids, numbers and quoted values hidden, and the first line of our own code in the trace. Line numbers and build
hashes are not part of it, so a deploy does not split an issue in two. An error page is grouped by the page's route
pattern (`/app/customers/{id}`), or by the path with ids hidden when no route matched; a slow query by its SQL with every
value replaced by `?`.

| Status | Meaning |
|---|---|
| **Open** | New and not yet dealt with. |
| **Resolved** | You fixed it. If it happens again it **reopens itself** and is marked *Came back*, with the time and the app version it came back in. |
| **Ignored** | You do not want to hear about it. New occurrences are dropped silently: not counted, never alerted. You can reopen it any time. |

Three marks on open issues: **New** (first seen in the last 24 hours), **Came back** (it was resolved and happened again
in the last 7 days) and **Growing** (today at least 3 times, and at least double the daily average of the 7 days before).

Resolving, ignoring or reopening is open to both admin roles and is written to the audit log (`error.resolved`,
`error.ignored`, `error.reopened`, with the issue's id and the status it came from). Pressing a button for the status an
issue already has changes nothing and writes nothing. Resolving or ignoring by hand clears *Came back*; reopening by
hand does not add it.

## What is stored, and what never is

Per issue: the source and kind, a title in plain words (the error class and its message), the count, how many different
people it touched (a number only, "500+" at the cap), when it was first and last seen, a count per day, and the **last
five occurrences**. Per occurrence: the time, the message (500 characters at most), a trace (8 KB at most), the route
**pattern** (never the real address), the method and status, the app version, the platform (server, web, Android, iOS)
and phone or tablet. Versions come from the phone only; a server error has none.

Everything is cleaned **before** it is stored, by one class that is the only way in (`App\Errors\ErrorScrubber`). It
removes or masks e-mail addresses, phone numbers (also in Arabic-Indic digits), national-ID-like runs of 9 digits or
more, IBANs and card numbers, bearer and Sanctum tokens, JWTs, long random strings, `password=`, `token=`, `secret=`,
`key=`, `authorization:` and `cookie:` values, URL credentials and every query string, every value in a SQL statement
(only the statement with `?` is kept), and server paths outside the project. A database error is not stored as the
driver wrote it either: it is kept as the class, the SQLSTATE code with a plain description ("Unique violation") and the
statement with placeholders.

**Never stored, by construction:** request bodies and headers, cookies, session ids, the visitor's address, their
browser's user-agent text, user ids, tenant ids or names, customer data. The only trace of a person is a **code**: the
first 16 characters of an HMAC-SHA256 of the user id with the application key. It cannot be turned back into a person; it
exists so the same person is not counted twice. These tables belong to the platform, not to a business: they have no
`tenant_id`, and a customer can never reach them.

## The admin screens

**Errors** in the admin menu (`/admin/errors`), for platform staff only (admins and super admins; a customer gets a
404).

- **The list.** One row per issue: a coloured source tag (with the word, not only the colour), the title, the count with a
  7-day bar chart, the New / Came back / Growing marks, first and last seen, the last version. Filters: source, status
  (open unless you pick another), "New in 24 hours", "Came back". Newest first, 25 to a page. A closed **Background noise
  (bots and browsers)** card shows the last 7 days of counted noise, and how many reports were *dropped* (over a limit,
  a repeat of a busy error page, or refused), so nothing disappears unseen.
- **The detail.** The scrubbed trace, the five latest occurrences, a 30-day timeline, who resolved or ignored it and when,
  and the buttons **Resolve**, **Ignore** (it asks for a second press) and **Reopen**. A page that came from a browser or
  a phone says "Reported by a browser" or "Reported by a phone" and that it never sends an instant e-mail.
- **The badge.** The count of open issues on the menu entry (hidden at 0, "99+" above).
- **The admin home** has a line "Errors in the last 24 hours" in its health block. It counts **server errors only**
  (open exceptions and error pages of 500 or more), so a broken link or an expired form does not keep it yellow for ever.
  It turns to a warning with a "See the errors" button; it also warns when recording is switched off.

Every screen works with the keyboard, mirrors correctly in Arabic, and holds at 200 % text. Whatever a stranger can
write (a message, a route) is shown as text and never becomes a link.

## The alerts

**Who:** every active (not suspended) **super admin**, in the language of their own account. An `admin` is not told.

**Instant e-mail.** When a **new issue** is opened from the website, the API or the database, and it is an exception or
an error page of 500 or more. Never for a 404, 403, 419 or 429 page, never for a slow query, and **never for what a
browser or a phone reports** (it cannot be checked, so a stranger could otherwise make your inbox ring). At most **3 an
hour and 10 a day** over all issues; an issue a limit holds back is not lost, it is in the next digest. The mail says
the source, the title, the count so far, the first version and gives a link; nothing else leaves the database. The link is
built on `APP_URL`, never on the address of the request that failed. A resolved issue that comes back is not mailed at
once; it appears in the digest as "came back". The instant mail needs no scheduler: it goes out right after the failing
answer.

**Daily digest.** At **04:00 UTC, which is 07:00 in Qatar**, only on a day when at least one open issue is new (first seen
in the last 24 hours), came back (in the last 24 hours) or is growing. Three lists, each issue in one list only, up to 25
in each and "And N more". Phone and browser reports appear with their kind ("Crash", "Script error") and not with their
text, which a stranger wrote.

Mail goes through the SMTP server saved in the admin E-mail page, like every other mail. With the mailer set to `log`
nothing real is sent (the go-live check already fails that). A mail that cannot be sent is skipped without noise; a digest
that could not be sent to anyone makes `qistas:errors:digest` print "The digest could not be sent." and exit 1.

## The safety rules

**Recording can never break a request.** It happens after the answer has been sent, and every failure inside it is swallowed.
It never records its own failures and it stops itself from looping. At most 100 events wait per request, command or
job; the rest are counted as dropped. The same failure twice in one request is counted once.

**When the database is down,** the cleaned event is appended as one line to `storage/logs/error-spool.ndjson` (2 MB at most;
a line holds the person's code, never the user id). `qistas:errors:import-spool`, every five minutes, records the lines
once the database answers, spending at most 20 seconds a run; a line the database refuses three times while its tables
answer is dropped and counted. With `QISTAS_ERRORS=false` the spool is neither written nor read.

**Floods.** Only a *new* issue is capped; a known issue only counts one more. New issues draw on four budgets of their own,
so a flood of one kind can never stop another from being recorded:

| Budget | Draws on it | New issues a minute | New issues an hour |
|---|---|---|---|
| alert | exceptions, error pages of 500 or more | 60 | 500 |
| quiet | error pages below 500, slow queries | 30 | 200 |
| script | what browsers report | 20 | 100 |
| crash | what phones report | 20 | 100 |

On top of that, one sender may open **3 new client issues an hour**: a signed-in phone is counted as the person (not the
token, because every sign-in makes a new one), anything else by its address. Everything over a limit is counted as
*dropped*. An error page below 500 that is hit again within 5 seconds (a made-up address asked a hundred times) is recorded
once and the repeats are counted as dropped. A cache
that cannot be read keeps the gate shut, so a failing cache can never let a flood through. On PostgreSQL the recorder
never waits more than a second for a lock.

## The phone app

Nothing to set up. **Settings > Preferences > Send crash reports** is on by default and kept on the phone; the line under it
says "When the app hits an error it sends the kind of error and a technical trace, never your data or your customers', so we
can fix it." Turning it off stops reports at once and empties what was waiting.

**What a report holds:** the error's class, the first line of its message, a trace, the app version, Android or iOS (and the
system's version, which the server reads and does not keep), and phone or tablet. The phone cleans all of it first, with the
same kind of rules as the server, and cuts it to 500, 160 and 8000 characters. **It never sends** a name, a number, an amount,
a phone number, what was typed, or the account.

**When it is sent:** straight away, 3 seconds after the app starts, and when the app comes back to the screen. A report that
cannot go waits on the phone (20 at most, 6 hours at most). The same error within a minute is one report. The server's
answer decides the rest: 403, 404 or 405 (a server that does not have the place yet) stops reporting for the session and
empties the queue, 413 or 422 drops that report, 429 waits as long as asked, anything else keeps it for the next try. The
reporter has an API client of its own, so a stale sign-in can never sign anyone out.

**Not reported:** failed network calls (`ApiException`, no connection, a time-out, a refused certificate), errors the
framework marks as handled, and anything on the web build, which has no reporter and no switch.

**The server side** is `POST /api/v1/client-errors` (`api.client-errors.store`): no token needed (a crash on the sign-in
screen matters); a phone's app token counts one more person on the issue; any other token is nobody. A request that comes
from a web browser (it carries an `Origin` or `Sec-Fetch-Site` header; the app's HTTP library sends neither) is refused
with 403, so another site's page cannot post to it through a visitor's browser. 30 reports a minute per token (per address
without one), 16 KB at most. A refusal is a bare status with no words; a report of the right shape is always answered `204`.

**The website's script** (`resources/js/error-report.js`) reports only errors in our own scripts, at most 5 per page view and each
only once, to `POST /client-errors` (`client-errors.store`): no cookie and no CSRF token, JSON only, 8 KB at most, an `Origin` that
is not ours is refused, 20 a minute per address. It sends the class, the message, a trace of our own frames and the page's
route pattern, with ids and the query string already removed, and nothing else.

## How long it is kept

| What | Kept |
|---|---|
| The last five occurrences of an issue | 30 days (`QISTAS_ERROR_OCCURRENCE_DAYS`) |
| An open issue, with its days and people | 90 days after it was last seen (`QISTAS_ERROR_ISSUE_DAYS`) |
| A resolved issue | 30 days after it was resolved |
| An ignored issue | 30 days after its last sighting |
| The counted background noise | 30 days |

`qistas:errors:prune` runs every night and also removes the expired error-center rows from the cache table (the
recorder's counters, the repeat markers, the alert limits). Nothing else touches them.

## The commands, and the settings

All three are scheduled (`routes/console.php`, each `withoutOverlapping`) and need the website's cron to call `/internal/cron`
every five minutes. They take no options and are safe to run by hand, except that **running the digest again sends it again**.

| Command | Runs | What it does |
|---|---|---|
| `php artisan qistas:errors:prune` | every night at 00:00 UTC | Removes what the table above says, and the expired cache rows. Refuses to run (and removes nothing) when either retention setting is below 1. |
| `php artisan qistas:errors:import-spool` | every 5 minutes | Records the errors that waited in `storage/logs/error-spool.ndjson` while the database was away. |
| `php artisan qistas:errors:digest` | every day at 04:00 UTC (07:00 Qatar) | E-mails the super admins the summary, when there is something to say. |

Real output on a developer machine (SQLite, mail goes to the log). The first run of the import there printed
`Imported 116, dropped 0.`, because errors from earlier work had waited in the spool while the tables did not exist yet:

```text
$ php artisan qistas:errors:prune
 INFO Removed 0 occurrences older than 30 days.
 INFO Removed 0 open issues not seen for 90 days.
 INFO Removed 0 resolved issues resolved more than 30 days ago.
 INFO Removed 0 ignored issues not seen for 30 days.
 INFO Removed 0 noise rows older than 30 days.
 INFO Removed 0 expired error-center rows from the cache.

$ php artisan qistas:errors:import-spool
 INFO Imported 0, dropped 0.

$ php artisan qistas:errors:digest
 INFO Digest sent.
```

On a quiet day the last one prints ` INFO Nothing to send.`. With `QISTAS_ERRORS=false` the import prints
` INFO Error capture is off (QISTAS_ERRORS=false): the spool was left as it is.`.

| Setting | Default | Meaning |
|---|---|---|
| `QISTAS_ERRORS` | `true` | `false` stops recording new errors. What is already recorded stays visible, the list says recording is off, and the go-live check warns. |
| `QISTAS_SLOW_QUERY_MS` | `3000` | A query slower than this many milliseconds is recorded as a slow query. A value below 1 means 3000. |
| `QISTAS_ERROR_OCCURRENCE_DAYS` | `30` | How long an issue's sample occurrences are kept. |
| `QISTAS_ERROR_ISSUE_DAYS` | `90` | How long an open issue is kept after it was last seen. |

They are listed in `web/.env.example` and in `docs/DEPLOY.md`. The limits in the tables above (five samples, 500 people, the
four budgets, 3 mails an hour and 10 a day, the 2 MB spool) are rules of the design, not settings.

## The 17th go-live check

`php artisan qistas:go-live-check` now has an **Error center** line: PASS when recording is on and `storage/logs` is
writable; WARN when `QISTAS_ERRORS` is off; FAIL when `storage/logs` cannot be written (an error that happens while the
database is away would be lost). See `go-live.md`.

## How to use it on a normal day

1. Open **Errors**. The default list is the open issues, newest first.
2. A **New** issue from the Website, API or Database with a server title is the one to read first; open it, read the
   title and the trace (the first `app/` line is where in our code it happens).
3. When it is fixed and deployed, press **Resolve**. If it comes back you will see *Came back* and the version.
4. When there is nothing to do about one (an old bot habit, a harmless browser error) press **Ignore**.
5. A 404 that keeps growing on a real page is a broken link on the site: fix the link, then resolve.

## Known limits

- **A crash inside Android or iOS code that never reaches Dart is not seen.** That would need a native plugin; it was left out
  of this release.
- **The phone does not report network failures, errors in background isolates (the push handler, the home-widget refresh), or
  anything from the web build.** A debug build run from a developer's machine (`flutter run`) *does* report, to whatever address
  it was built with, which is the live site unless changed, so developer noise can reach the live error center
  (compare the version and the platform). The reporter has run in the test suite only: no real phone and no live server have
  seen it yet, so look at the first real reports after the app is released.
- **Client reports cannot be verified.** Anyone can post a fake one, so they never send an instant e-mail, they have budgets of
  their own, one sender can open only 3 new issues an hour, and a flood of fake reports can use up a client budget and
  make a real new crash *dropped* (counted, not shown).
- **Names typed into free text cannot always be recognised.** The scrubber finds shapes (e-mails, phones, numbers, tokens,
  SQL values), not people: `Customer "Ahmed Ali" not found` would be stored as written. So **an exception message must never
  be built from a customer's name or any customer field**, in the website and in the app; make it a review rule.
- **A few long class names are hidden as `[token]` in traces** (for example `KeepStaffOutOfTwoFactor`). A frame hidden that
  way cannot be the one the grouping uses, so such an issue groups by the next frame of ours further down. A failure thrown
  by a route closure or by a Blade view itself has no frame of ours at the top either, so it groups by that next frame or,
  with none, by class and message only.
- **An exception that the code reported and then recovered from is stored with status 500**, because the answer is not known
  yet when the exception is reported.
- **A database outage cannot alert while it lasts.** Recording needs the database, and so does the mail. Events wait in the spool
  and are imported when it returns (the digest then lists them). Real outage alerting needs an **external uptime monitor**
  watching `/up`. If an outage outlasts the 2 MB spool the rest are lost, and only the process that lost them counts them.
- **With emulated prepared statements (`DB_EMULATE_PREPARES=true`: the Vercel container and Supabase's transaction pooler),
  PostgreSQL's own error text echoes the first characters of a value** (`LINE 1: ... email = 'ahmed@exam..`) in a
  `QueryException`'s message; Laravel's masking of bindings only covers the `SQL:` part. The error center is not affected,
  because it never stores a database exception's message (only the class, the SQLSTATE and the statement with
  placeholders), but Laravel's own log in `storage/logs` can hold those characters. SixPanel uses native prepares and is not
  affected. This was found because one test (`ErrorScrubberTest`: Laravel keeps the bindings out of the exception message on
  every connection) fails against such a connection; it passes on SQLite.
- **The container hosting target (Vercel) spools per running instance** and throws that disk away when the instance stops, so
  a spooled event can be lost there. SixPanel is one server with one spool file that the five-minute import reads.
- **Real addresses that now count as noise:** old `.php`, `.zip`, `.map` and similar URLs, `/sw.js`, `/manifest.webmanifest`
  and anything under `/.well-known/`. If one of them is a link you really use, it is counted but never shown as a broken link.
- **The digest judges growth on the day so far.** "Today" is the UTC day, and at 04:00 UTC it is four hours old, so an issue that
  surged yesterday and is quiet so far today is not listed as growing (it is still in *New* or *Came back* when it qualifies).
- **The alert caps count attempts and can be passed by a little under a race**: the hour never exceeds 3, the day can reach 12
  when workers race at its edge. A dead mail server uses the hour's three attempts; the day's count only moves when a mail went.
- **Arabic, French, Spanish and Urdu wording of the new screens, mails and phone strings is not native-reviewed.** English and
  Arabic are complete, the others are real translations written without a native reader.
- **The detail page says "Sightings are kept for 30 days"** whatever `QISTAS_ERROR_OCCURRENCE_DAYS` says.

## Proposals (not published until you approve them)

These three are the changes to the privacy page and to the store forms that crash reporting needs. Nothing here has been
published, filed or edited in the repository: `docs/store/GOOGLE_PLAY_LAUNCH.md` still says "Not collected" for crash logs
and diagnostics.

### A sentence for the privacy page

> When the app or the website hits an error we keep the kind of error, a technical trace with personal details removed, the
> version and the kind of device, for up to 90 days, to fix it. You can turn this off for the app in Settings.

**Proposal: not published until the owner approves it.** The privacy page is the owner's text; this file only holds the
sentence ready.

### Google Play, Data safety form

In `docs/store/GOOGLE_PLAY_LAUNCH.md`, section 5, the last row of the table says that location, contacts list, messages,
audio, calendar, health, web history, app activity, **crash logs** and **diagnostics** are "Not collected". With this
release, crash logs and diagnostics become **collected, optional** (the person can turn them off in Settings), for **app
functionality** and **analytics**, not shared with third parties (our own server, no crash-reporting kit). The sentence "There is no
ad, analytics or crash-reporting kit" stays true: the reporter is our own code.

**Proposal: not applied until the owner approves it.** Fill the form in on the Play Console only when you decide; the
document has not been changed.

### Apple privacy label

In App Store Connect, App Privacy: add **Crash Data** (under Diagnostics), for **App Functionality**, **not linked to the
user's identity**, and not used for tracking. The reports carry no name and no account id; the server keeps only a one-way
code per issue, to count different people, which nobody can read back. Read Apple's current wording of "linked" before you
answer, since that code is the one thing that comes close.

**Proposal: not applied until the owner approves it.**

## Code and tests

Code (web): `App\Errors\ErrorRecorder` (the one entry point: scrub, fingerprint, upsert, sample, count, flood caps, spool
fallback), `ErrorScrubber` and `SqlMasker` (cleaning), `ErrorFingerprint` (grouping, titles, noise paths), `ErrorCapture`
and `DatabaseFailure` (the hooks and what counts as a failure), `ErrorSpool`, `ErrorTrend` (7-day counts, "growing"),
`ErrorTriage` (resolve, ignore, reopen), `ErrorAlerts` with `App\Mail\ErrorAlertMail` and `ErrorDigestMail` (the e-mails),
`ClientErrorIntake` and `ClientSender` (the two reporters), `App\Http\Middleware\RecordErrorPages` and `GuardClientReport`,
`App\Providers\ErrorServiceProvider`, `App\Http\Controllers\Admin\ErrorsController`, `ClientErrorController` and
`Api\V1\ClientErrorController`, `App\Models\ErrorIssue` and `ErrorOccurrence` (migration `create_error_center`), the three
`Errors*Command` classes, `App\Models\PersonalAccessToken` (a malformed bearer token is a plain 401), `App\Support\GoLiveCheck`,
`resources/js/error-report.js`, `resources/views/admin/errors/`. Code (app): `app/lib/core/errors/` (reporter, queue, trace
scrubber), the Settings switch, `main.dart`.

Tests: `tests/Feature/Errors/` (tables, scrubber, `SqlMasker`, fingerprints, recorder, spool, capture, the two reporters,
alerts, admin screens, trend, prune), `tests/Unit/DatabaseFailureTest.php`, `tests/Feature/Api/MalformedBearerTokenTest.php`,
`tests/Feature/Deployment/GoLiveCheckTest.php`, `app/test/core/errors/`, `app/test/features/crash_reports_setting_test.dart`.

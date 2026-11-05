# Going live: the check and the clean-up

Asked by the owner on 1 November 2026 ("remove any demo data so the system can go live"). Two commands, both run on the
server, both safe to run as often as wanted: one says whether the site is ready, the other lists and (only with your
yes) removes what the old public demo and the developer seeder left behind.

```sh
php artisan qistas:go-live-check          # reads only; changes no data; exits 1 when anything fails
php artisan qistas:purge-demo             # the list, nothing deleted
php artisan qistas:purge-demo --confirm   # the list worked out again now, then removed
```

## The go-live check

Seventeen checks, each **PASS**, **WARN** or **FAIL** with one plain line saying what to do next. A FAIL stops the launch
(the command exits 1, so a script can stop on it); a WARN does not, but each one deserves a decision.
It never prints a secret, a customer's address or the value of a setting: a setting is named, not quoted, and a check
that cannot run says which kind of fault stopped it (the reason could hold a connection string).

| Check | FAIL when | WARN when |
|---|---|---|
| Production mode | `APP_ENV` is not `production` | |
| Debug mode off | `APP_DEBUG` is on | |
| HTTPS address | `APP_URL` does not start with `https://` | the session cookie is not secure (`SESSION_SECURE_COOKIE=true`) |
| Built-in demo mode off | `QISTAS_DEMO` is on | |
| No old demo accounts | any old demo account or the seeder's account is left (removable or held back) | |
| No look-alike demo addresses | | a real person's account uses the demo address domain (it is never purged; make sure it is a person) |
| Staff test workspaces | | staff test workspaces exist (`qistas:purge-demo --include-test` removes them) |
| E-mail can be sent | e-mail only goes to the log, the mailer is misspelt, or the From address is invalid | |
| Scheduler is running | the last scheduled run was more than 15 minutes ago | none ever ran, or the last run failed |
| Visitor country header | | `QISTAS_GEO_HEADER` is not set, so sign-up guesses the country from the browser |
| Card billing gateway | | card billing is on with the fake gateway, missing Stripe keys, or test keys (PASS when off or live) |
| A super admin exists | no active super admin | |
| Support e-mail | | `QISTAS_SUPPORT_EMAIL` is not set |
| Terms and privacy pages | `/terms` or `/privacy` does not answer 200 | |
| PostgreSQL database | | the database is not PostgreSQL |
| Storage is writable | `storage`, `storage/logs` or `storage/framework` cannot be written | |
| Error center | the folder where errors wait while the database is away (`storage/logs`) cannot be written | error recording is switched off (`QISTAS_ERRORS`) |

What it does that looks like a write: it asks the application itself for the terms and privacy pages (through its own
HTTP kernel, no network, with the session kept in memory), which can warm a cache entry. Nothing else is stored.

Code: `App\Support\GoLiveCheck`, `App\Console\Commands\GoLiveCheckCommand`.
Tests: `tests/Feature/Deployment/GoLiveCheckTest.php`.

### Sample output

This is the real output on a developer machine (SQLite, local mode, the seeder's account present), so it fails on
purpose. On the live server the goal is the last line reading *Ready to go live.*

```text
Qistas go-live check

 FAIL Production mode .. APP_ENV is not production. Set APP_ENV=production on the server.
 FAIL Debug mode off .. APP_DEBUG is on and would show internal errors to visitors. Set APP_DEBUG=false.
 FAIL HTTPS address .. APP_URL does not start with https://. Set it to the https address of the site.
 PASS Built-in demo mode off .. The built-in demo mode is off.
 FAIL No old demo accounts 2 can be removed, 0 are held back (the list says why). Run php artisan qistas:purge-demo, read the list, then add --confirm.
 PASS No look-alike demo addresses .. No account outside the old demo uses the demo domain.
 WARN Staff test workspaces Staff test workspaces exist: 1. Remove them when done: php artisan qistas:purge-demo --include-test.
 FAIL E-mail can be sent E-mail only goes to the log. Save the SMTP server in the admin E-mail page, or set MAIL_MAILER.
 WARN Scheduler is running No scheduled run recorded yet. Set CRON_SECRET and have a pinger call /internal/cron every 5 minutes.
 WARN Visitor country header QISTAS_GEO_HEADER is not set, so sign-up guesses the visitor's country. Set it to your CDN's country header.
 PASS Card billing gateway .. Card billing is switched off, so no card gateway is needed.
 PASS A super admin exists .. An active super admin exists.
 WARN Support e-mail .. QISTAS_SUPPORT_EMAIL is not set, so suspended people are not told where to write. Set it.
 PASS Terms and privacy pages .. /terms and /privacy both answer 200.
 WARN PostgreSQL database .. The database is sqlite, not PostgreSQL. Point the live site at PostgreSQL (DB_URL).
 PASS Storage is writable .. storage, storage/logs and storage/framework are writable.
 PASS Error center Errors are recorded, and storage/logs is writable for the ones that happen while the database is away.

 ERROR Summary: 7 PASS, 5 WARN, 5 FAIL. Fix every FAIL, then run this check again.
```

## Removing the demo leftovers

**Who counts as a demo account.** A person with no platform role (never an admin) **and** either a demo expiry date (what
the old "Try the demo" button set) or exactly the developer seeder's e-mail address. It is never decided by the e-mail
domain alone, so a real person who happens to write an `@demo.qistas.test` address is not touched (the check above warns
about them instead).

**What a run does.**

1. `qistas:purge-demo` with no option only prints the list: each account, how it matched, when it was made, and each
   workspace it owns with its number of records. Nothing is deleted. Accounts that are *held back* (for example, someone
   else has joined the workspace) are listed with the reason.
2. `qistas:purge-demo --confirm` works the list out **again at that moment**, re-checks every account in its own
   transaction just before removing it, and removes exactly that: the workspace and everything in it, and the login. A
   login that owns no workspace is removed (with any membership) while a business it only belongs to, and that
   business's records, stay. An account that changed since the list was made is left alone and said so.
3. Staff test workspaces (made from the admin console) are only listed unless `--include-test` is added.
4. Every removal writes a row to the audit log.

What the command prints about accounts is demo and seeder addresses and workspace names only, as plain text.

Code: `App\Sandbox\DemoCleanup` (every rule), `App\Console\Commands\PurgeDemoCommand` (a thin wrapper).
Tests: `tests/Feature/Deployment/PurgeDemoCommandTest.php`, `tests/Feature/Demo/DemoCleanupTest.php`.

### Sample output (the dry run)

Real output on the same developer machine:

```text
Demo accounts that would be removed: 2

 demo-62bn5ez1twl2zh@demo.qistas.test matched by demo_expires_at (the old "Try the demo" button), made 2026-10-29T15:50:07Z
 workspace "Al-Fares Electronics" .. 33 records
 demo@qistas.test matched by seeder_address only (no demo expiry: check it is not a real person), made 2026-10-07T20:30:23Z
 owns no workspace its login (and any membership) will be removed; a business it only belongs to, and its records, are kept

Staff test workspaces: 1 (only removed with --include-test)

 "Al-Fares Electronics (test)" .. owner formcheck@example.test

To remove the staff test workspaces too, add the option --include-test.

 INFO Nothing was deleted. This was only the list.

To remove exactly what is listed above, run: php artisan qistas:purge-demo --confirm
```

## The owner's checklist

In this order, on the server after the new version is deployed:

1. **Deploy.** The hosting panel pulls `main` and runs "Commands after each deploy"; the new tables and columns arrive
   with `php artisan qistas:setup`.
2. **`php artisan qistas:go-live-check`.** Fix every FAIL (the line says how). Read every WARN. The settings it names are
   on the website's Environment page; see `docs/DEPLOY.md`.
3. **`php artisan qistas:purge-demo`.** Read the list; make sure every account on it is a demo or the seeder's. Only then
   `php artisan qistas:purge-demo --confirm`. Run the go-live check again.
4. **Sign in once from your own phone and your own computer**, then open Security: both rows should read *proxy not
   detected*, with your country. If one reads *detected*, raise `QISTAS_PROXY_CHAIN_ALLOWED` by one (see `sign-up.md`).
5. **Create one real account** with a real e-mail and walk through the card, then a first contract and its PDF, on the
   live site.
6. **Approve the privacy sentence** in `sign-up.md`, or write your own, and publish it on the privacy page.
7. **Before the next Google Play release, re-check the Data safety form** (`docs/store/GOOGLE_PLAY_LAUNCH.md`, section 5):
   it says location is not collected and lists only the push token under device IDs, and every sign-in now records the
   connection's address, its country and the device's kind, operating system and app version. Whether Google counts those is for you to decide
   when you fill the form in; the document has not been changed.

8. **Decide the three error-center proposals** in `error-center.md` (the privacy sentence, the Google Play Data safety change and
   the Apple privacy label for crash reports). Nothing is published or filed until you approve them; do it before the app
   with crash reports reaches the stores.

Release 3 (phone number and code, Google, Microsoft) needs accounts and keys only you can create (an SMS provider, a Google
project, a Microsoft app registration); they go on the server's Environment page, never in a chat or the repository, when
that release starts. The error center (Release 2) needs none: see `error-center.md`.

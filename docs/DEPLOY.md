# Running and hosting the Qistas web app

The web app is one Laravel application: the public website, the signed-in app (`/app`), the admin console and the
REST API. It ships as **one container image** (`web/Dockerfile.vercel`) that runs anywhere. The database is
PostgreSQL (Supabase in production).

| You want to… | Use |
|---|---|
| Look around on your own computer | [Option A: Docker](#option-a--your-computer-with-docker) |
| See how the live site (https://www.qistasme.com) runs | [Option D: SixPanel](#option-d--production-on-sixpanel-wwwqistasmecom) |
| Put it online at a public link | [Option B: Vercel + Supabase](#option-b--online-with-vercel-and-supabase) |
| Develop or change it | [Option C: without Docker](#option-c--develop-without-docker) |

---

## Option A: your computer, with Docker

You need [Docker Desktop](https://www.docker.com/products/docker-desktop/) (or any Docker with Compose) and Git.

```bash
git clone https://github.com/rabi3ogabes/qistas.git
cd qistas
docker compose up --build
```

The first build takes a few minutes. Then open **http://localhost:8080**.

* Sign in with `demo@qistas.test` / `Demo!Passw0rd2026` to explore a demo business (customers, contracts, payments), or
  press **Start free** and create your own account.
* `docker compose down` stops it; `docker compose down -v` also erases the data.

This setup uses a throw-away database password and a published demo login. **Never expose it to the internet.**

---

## Option B: online, with Vercel and Supabase

Vercel runs the container; Supabase keeps the data. The repository's `vercel.json` already tells Vercel to build the
app in `web/` as a container. Vercel's own deploy on every push is switched off (`"git": {"deploymentEnabled": false}`):
the **Deploy** workflow (`.github/workflows/deploy.yml`) redeploys the Vercel copy (https://qistas-puce.vercel.app/; the live
site moved to SixPanel, see [Option D](#option-d--production-on-sixpanel-wwwqistasmecom))
whenever a push to `main` changes `web/`, `vercel.json` or `.vercelignore`, by calling a Vercel Deploy Hook. It needs
the repository secret `VERCEL_DEPLOY_HOOK` (Vercel project → Settings → Git → Deploy Hooks, branch `main`). To redeploy
without a change, run the workflow by hand (Actions → Deploy → Run workflow).

### Step 0: it works at once, as a demo

With nothing configured, the container starts in **demo mode**: a demo business with a published sign-in (shown on the
sign-in page), a banner on every page, and temporary data that each running copy keeps for itself and loses when it
stops. Anyone can look around; nothing real should be entered. To go live for real, do steps 1 to 3.

### 1. Create the database (Supabase)

1. Sign in at <https://supabase.com> and choose **New project**. Pick a region close to your customers and set a
   database password (save it).
2. Open the project and press **Connect**. Choose **Transaction pooler** and copy the connection string. It looks like
   `postgresql://postgres.abcdefgh:[YOUR-PASSWORD]@aws-0-eu-central-1.pooler.supabase.com:6543/postgres`.

### 2. Connect it: one script, on your own computer

The script asks for that string and your database password, checks the connection, creates the Qistas tables, locks
Supabase's public API out of them, and then walks you through pasting the two secrets into Vercel through your
clipboard. The password is never printed, saved or sent anywhere except to your own database. In a terminal, in the
repository folder:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\connect-supabase.ps1
```

(It needs PHP and `composer install` done in `web/`, as in [Option C](#option-c--develop-without-docker).)

What the script does to the database, in the order it does it:

1. `php artisan qistas:db-check` says in plain words whether the connection works and why not: wrong password, wrong
   user name, IPv6-only direct connection, paused project, missing SSL.
2. `php artisan qistas:setup` creates the tables in **one transaction** (all or nothing) and runs
   `php artisan qistas:secure-database`: **row-level security on every table, and no access for Supabase's `anon` and
   `authenticated` roles**, including on tables created later. This matters: Supabase publishes the `public` schema
   through its data API and the project's public key is easy to find, so without this the customers' data would be
   readable by anyone. The app itself connects as the table owner (`postgres`, or a dedicated login role that set the
   schema up, such as `qistas_app`), which row-level security does not apply to, so it is unaffected. A dedicated role
   is the tidier choice: it is not a superuser and cannot touch Supabase's own schemas, e.g.
   `create role qistas_app login password '…'; grant usage, create on schema public to qistas_app;` and then use
   `qistas_app.<project-ref>` as the user name in the pooler string. If you want to be thorough, also switch off the
   Data API in the Supabase dashboard (Project Settings → Data API): the app never uses it.
3. It generates the application key (**keep a private copy**: if it is lost, encrypted national IDs cannot be read
   again), then copies `APP_KEY` and `DB_URL` to your clipboard one after the other for you to paste into
   **Vercel → Settings → Environment Variables → Add New (Production)**.

**Without the script** you can do the same by hand: create a key (`"base64:" + [Convert]::ToBase64String([System.Security.Cryptography.RandomNumberGenerator]::GetBytes(32))`
in PowerShell, or `echo "base64:$(openssl rand -base64 32)"` in a shell), add `APP_KEY` and `DB_URL` in Vercel, and
redeploy: on its first start the app creates its tables and secures them by itself.

*Optional shortcut:* connect the **Supabase** connector in Claude Code and ask it to create the project and apply the
schema; you still paste the database password and the two variables yourself.

### 3. Redeploy

In Vercel open **Deployments → ⋯ → Redeploy**. The demo banner disappears and the site is real: **Start free**
creates a real account in your Supabase database. Setting only one of `APP_KEY` and `DB_URL` stops the app with a
clear message in the Vercel logs instead of quietly running as a demo.

| Name | Value |
|---|---|
| `APP_KEY` | the key the script generated |
| `DB_URL` | the Supabase transaction-pooler string with your password filled in (percent-encoded) |

Everything else has a safe default (production mode, secure cookies, trusted proxy, automatic database set-up).

### Optional settings

| Name | Default | Meaning |
|---|---|---|
| `APP_URL` | detected | The public address, used in e-mails and links created outside a request. |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | `log` | Send real e-mail (verification, password reset). Until set, e-mails are written to the log only. |
| `AUTO_MIGRATE` | `true` | Bring the database up to date on every start. Set `false` to run `php artisan migrate --force` yourself. |
| `TRUSTED_PROXIES` | `*` | Which proxies' `X-Forwarded-*` headers are believed. `*` is right behind Vercel. |
| `STRIPE_SECRET`, `STRIPE_PUBLISHABLE_KEY`, `STRIPE_WEBHOOK_SECRET` | none | Normally left unset: Pro by card keeps its Stripe keys in the admin Billing page (`docs/features/billing.md`). A value here wins over the saved one, for testing on your own machine with your own test keys. With none saved, no card is ever charged. (`BILLING_GATEWAY` is only the development default for the fake checkout; the live gateway is decided by the saved keys.) |
| `QISTAS_DEFAULT_CURRENCY` | `USD` | Currency for new workspaces whose country is not recognised. |
| `QISTAS_DEFAULT_COUNTRY` | `SA` | The country a new workspace gets when sign-up can find none (no CDN header, no time zone, no language region). Two letters, one the product knows. See `docs/features/sign-up.md`. |
| `QISTAS_SIGN_IN_DAYS` | `90` | How many days each person's sign-in record (device, address, country, proxy verdict) is kept; a daily job removes older rows. |
| `QISTAS_PROXY_CHAIN_ALLOWED` | `2` | How many addresses `X-Forwarded-For` may list before a sign-in reads "proxy detected" (a normal visit already passes the CDN and the host's own proxy). After going live, sign in once from your own phone and computer: both should read "not detected"; if not, raise this by one. |
| `CRON_SECRET` | none | Switches on scheduled work (see below). Any long random string. With none, `/internal/cron` answers 503 and nothing runs. |
| `CRON_MAX_SECONDS` | `50` | How long one scheduler call works through waiting jobs before it stops and leaves the rest for the next call. |
| `FILESYSTEM_DISK` | none | Where customers' files (contract and ID photos, proofs of payment) are kept. **Unset (Vercel): in the database** (`file_blobs`, decision D5 of Sprint 2), which lasts and is backed up with everything else. `s3` keeps them in an S3-compatible bucket instead (better once there are many photos); `local` in a private folder on the server, for development only (Vercel throws its disk away). Every kind is only ever served through links that expire within five minutes. Moving from the database to a bucket keeps the same paths. |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET` | none | The bucket's keys, for `FILESYSTEM_DISK=s3`. Supabase Storage works: create a **private** bucket and use its S3 keys. |
| `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT` | none, `false` | For an S3-compatible service other than Amazon (Supabase: `https://<project>.supabase.co/storage/v1/s3` and `true`). |
| `QISTAS_GEO_HEADER` | none | The header the CDN puts the visitor's country in, so an event theme meant for some countries (Appearance → Seasonal events) reaches visitors who are not signed in. On Vercel: `X-Vercel-IP-Country`; behind Cloudflare: `CF-IPCountry`. Without it, a visitor's country comes from their browser's language region (ar-SA is Saudi Arabia), and a signed-in person always counts as their business's country. Set it only to a header the CDN itself writes on every request. |

> **Containers on Vercel.** This uses Vercel's container Functions (`web/Dockerfile.vercel`). The same image runs on
> Render, Fly.io, Railway, Google Cloud Run or any server with Docker: give it the same two variables.

### Before real customers come

On the server, `php artisan qistas:go-live-check` says in sixteen plain lines whether the site is ready (production mode,
https, e-mail, scheduler, a super admin, the terms and privacy pages, PostgreSQL, writable storage, no leftover demo
accounts); it changes nothing and exits 1 while any line is FAIL. `php artisan qistas:purge-demo` lists the old demo
accounts and removes them only with `--confirm`. Both are explained, with their output, in `docs/features/go-live.md`.

### Scheduled work

The site has no scheduler and no queue worker of its own. Instead, something outside calls `/internal/cron` every few
minutes; each call does one bounded slice of work (whatever is due in the Laravel scheduler, then the jobs waiting on
the database queue). Without it nothing scheduled runs: not the daily removal of sign-in records older than 90 days, not the removal of
accounts people asked to delete, not the alerts, digests, late fees and billing notices.

1. Choose a long random string and set it as `CRON_SECRET` in Vercel. Redeploy.
2. In the GitHub repository, open *Settings, Secrets and variables, Actions* and add two **repository secrets**:
   `CRON_URL` (the full address, for example `https://qistas-puce.vercel.app/internal/cron`) and `CRON_SECRET` (the same
   string as in Vercel). The *Scheduler* workflow (`.github/workflows/cron.yml`) then calls it every five minutes. Until
   both exist it does nothing and shows a grey notice.
3. Check it: the admin overview's "Is everything set up?" shows *Scheduled work* as good after the first call, or run
   `php artisan qistas:cron-status`.

The endpoint takes `GET` (what Vercel Cron sends) or `POST`, and only with `Authorization: Bearer <CRON_SECRET>`: a
wrong or missing secret is a 401 and does nothing, two calls at once give the second a 409, and it is rate-limited. If
the owner prefers Vercel Cron, point it at the same address; GitHub's schedule can run a few minutes late, which is why
a gap of up to 15 minutes is not reported as a problem.

### Files and PDFs (optional)

Private file storage (`App\Support\Files`) and the PDF renderer (`App\Documents\PdfRenderer`) are built and tested, and
no feature uses them yet. A file is kept per workspace, checked by what its bytes are, stripped of hidden location data (a
picture) and only ever handed out through a link that expires within five minutes. The image needs the `gd` extension (it
is installed in `web/Dockerfile.vercel`). The PDF renderer carries its own fonts (`web/resources/fonts`, with their licences),
so it needs nothing from the host. To look at a sample: `php artisan qistas:pdf-preview ar` writes
`storage/app/previews/sample-ar.pdf`.

### What happened to the brand prototype

The static brand prototype (intro, Theme Studio, brand system) is no longer served at the live address, which now
belongs to the app. It stays in the repository (`brand/`, `index.html`): run it with `npm run dev`
(<http://127.0.0.1:5274>). Its Vercel settings are kept in `vercel.static.json` and `.vercelignore.static` should it
ever get a project of its own.

---

## Option D: production on SixPanel (www.qistasme.com)

The live site, **https://www.qistasme.com** (`qistasme.com` redirects to it), runs on the Athar Qatar server, managed
with SixPanel, as the PHP website **qistasme**. No container: nginx and PHP-FPM 8.5 serve `web/public`, and the data is
in a **PostgreSQL 18** database on the same server (`site_qistasme_pg`, reachable from the server only). Cloudflare is in
front; the panel passes each visitor's real address to the app, so rate limits work per person.

**Deploys.** The website pulls `main` from GitHub. Because the Laravel app lives in `web/`, the panel's own Composer step
does not apply; instead the website's **Commands after each deploy** do the build, as the site's own account:

1. link `web/.env` to the panel-managed `.env` one folder up;
2. `composer install --no-dev --optimize-autoloader`, then `npm ci` and `npm run build` (Node 26 is on the server);
3. the first time only: copy the panel's PostgreSQL password into `DB_PASSWORD`, generate `APP_KEY`, create `CRON_SECRET`;
4. `php artisan qistas:setup` (migrations, feature switches, row-level security), then rebuild the caches.

Kept between deploys: `web/vendor`, `web/node_modules`, `web/public/build`, `web/storage`, `web/bootstrap/cache`. A
deploy whose commands fail puts the previous version back.

**Settings (the website's Environment page).** `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://www.qistasme.com`,
`DB_CONNECTION=pgsql` with `DB_HOST=127.0.0.1`, `DB_PORT=5432`, `DB_DATABASE`/`DB_USERNAME=site_qistasme_pg`,
`DB_SSLMODE=disable`, `SESSION_DRIVER=database`, `SESSION_SECURE_COOKIE=true`, `CACHE_STORE=database`,
`QUEUE_CONNECTION=sync`, `LOG_CHANNEL=daily`, `QISTAS_GEO_HEADER=CF-IPCountry`. (`QISTAS_DEMO_LOGIN` is no longer read:
the public demo was removed; the line can be deleted from the Environment page.) Secrets
(`APP_KEY`, `DB_PASSWORD`, `CRON_SECRET`) are made on the server and never leave it. Set the `MAIL_*` keys there to send
real e-mail.

**Scheduled work.** A cron job of the website, every five minutes, calls `/internal/cron` with the `CRON_SECRET` it reads
from `.env`; check it with `php artisan qistas:cron-status`. The scheduler runs each task as its own process, so this
website's PHP keeps `proc_open` allowed (the other process functions stay disabled).

**MariaDB is not supported**: the migrations use PostgreSQL features (partial unique indexes, for example one main
investor per business). The website's unused MariaDB database can be removed on its Database page.

---

## Option C: develop without Docker

You need PHP 8.4 with `intl`, `bcmath`, `pdo_sqlite`, Composer and Node 22.

```bash
cd web
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan db:seed --class=DemoSeeder     # optional demo business
npm install && npm run build
php artisan serve                          # http://127.0.0.1:8000
```

Checks to run before every commit: `php vendor/bin/pest`, `php vendor/bin/pint`, `php vendor/bin/phpstan analyse`.
Continuous integration (`.github/workflows/web.yml`) runs all of them, runs the whole suite again on a real PostgreSQL,
and builds and starts the production container.

## What this does not include yet

* Real e-mail delivery needs an SMTP account (see the table above).
* Security headers and a Content-Security-Policy for the app are planned (task 15 in the plan).
* Pro by card is built and switched off on the live site: it needs a Stripe account, its keys saved in the admin Billing
  page, and one end-to-end run in Stripe's test mode before the switch is turned on (`docs/features/billing.md`).
* Sign-up with a phone number and a code, Google and Microsoft accounts, and the error center are planned as the next two
  releases; they need an SMS provider, a Google project and a Microsoft app registration that only the owner can create.
* The Android app is a test build signed with a debug key (see `app/README.md`); store releases need a release key.
* The admin overview (`/admin`) counts workspaces, plans, people and new sign-ups (never customer data), and shows
  whether the database, e-mail, the environment and scheduled work are in order. It is where an admin opens a test
  workspace. The public demo ("Try the demo") was removed before going live; accounts it left behind are listed and,
  with the owner's yes, removed by `php artisan qistas:purge-demo`.

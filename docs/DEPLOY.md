# Running and hosting the Qistas web app

The web app is one Laravel application: the public website, the signed-in app (`/app`), the admin console and the
REST API. It ships as **one container image** (`web/Dockerfile.vercel`) that runs anywhere. The database is
PostgreSQL (Supabase in production).

| You want to… | Use |
|---|---|
| Look around on your own computer | [Option A: Docker](#option-a--your-computer-with-docker) |
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
app in `web/` as a container, so **every push to `main` redeploys the live site** (https://qistas-puce.vercel.app/).

### Step 0: it works at once, as a demo

With nothing configured, the container starts in **demo mode**: a demo business with a published sign-in (shown on the
sign-in page), a banner on every page, and temporary data that each running copy keeps for itself and loses when it
stops. Anyone can look around; nothing real should be entered. To go live for real, do steps 1 to 3.

### 1. Create the database (Supabase)

1. Sign in at <https://supabase.com> and choose **New project**. Pick a region close to your customers and set a
   database password (save it).
2. Open the project, press **Connect**, and copy the **Transaction pooler** connection string. It looks like
   `postgresql://postgres.abcdefgh:[YOUR-PASSWORD]@aws-0-eu-central-1.pooler.supabase.com:6543/postgres`.
   Replace `[YOUR-PASSWORD]` with your database password.

### 2. Create the key that protects your data

The application key encrypts sessions and customers' national IDs. **Keep it; if you lose it, encrypted data cannot be
read again.** Create one (any one of these):

```powershell
# Windows PowerShell
"base64:" + [Convert]::ToBase64String([System.Security.Cryptography.RandomNumberGenerator]::GetBytes(32))
```
```bash
# macOS / Linux / Git Bash
echo "base64:$(openssl rand -base64 32)"
```

### 3. Add both to Vercel and redeploy

In Vercel open the project, **Settings → Environment Variables**, add the two below (for Production), then
**Deployments → ⋯ → Redeploy**:

| Name | Value |
|---|---|
| `APP_KEY` | the key from step 2 |
| `DB_URL` | the Supabase connection string from step 1 |

Everything else has a safe default (production mode, secure cookies, trusted proxy, automatic database set-up). On its
first start the app creates its tables in your Supabase database, the demo banner disappears, and the site is real:
**Start free** creates a real account. Setting only one of the two variables stops the app with a clear message in
the Vercel logs instead of quietly running as a demo.

### Optional settings

| Name | Default | Meaning |
|---|---|---|
| `APP_URL` | detected | The public address, used in e-mails and links created outside a request. |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | `log` | Send real e-mail (verification, password reset). Until set, e-mails are written to the log only. |
| `AUTO_MIGRATE` | `true` | Bring the database up to date on every start. Set `false` to run `php artisan migrate --force` yourself. |
| `TRUSTED_PROXIES` | `*` | Which proxies' `X-Forwarded-*` headers are believed. `*` is right behind Vercel. |
| `BILLING_GATEWAY` | `fake` | `fake` shows a pretend checkout; `stripe` needs `STRIPE_SECRET` and `STRIPE_WEBHOOK_SECRET`. |
| `QISTAS_DEFAULT_CURRENCY` | `USD` | Currency for new workspaces whose country is not recognised. |

> **Containers on Vercel.** This uses Vercel's container Functions (`web/Dockerfile.vercel`). The same image runs on
> Render, Fly.io, Railway, Google Cloud Run or any server with Docker: give it the same two variables.

### What happened to the brand prototype

The static brand prototype (intro, Theme Studio, brand system) is no longer served at the live address, which now
belongs to the app. It stays in the repository (`brand/`, `index.html`): run it with `npm run dev`
(<http://127.0.0.1:5274>). Its Vercel settings are kept in `vercel.static.json` and `.vercelignore.static` should it
ever get a project of its own.

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
* The admin console, the REST API, billing through Stripe and the Flutter mobile app are still being built.

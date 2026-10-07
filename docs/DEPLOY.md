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

Vercel runs the container; Supabase keeps the data. About ten minutes, and you never touch a server.

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

### 3. Create the Vercel project

1. In Vercel choose **Add New → Project** and import the GitHub repository `rabi3ogabes/qistas`.
2. Set **Root Directory** to `web`. Leave the framework preset on **Other**.
3. Add these **Environment Variables**:

   | Name | Value |
   |---|---|
   | `APP_KEY` | the key from step 2 |
   | `DB_URL` | the Supabase connection string from step 1 |
   | `APP_URL` | the project's public address, e.g. `https://qistas.vercel.app` (you can add it after the first deploy) |

   Everything else has a safe default for this setup (production mode, secure cookies, trusted proxy, automatic
   database set-up). Optional settings are in the table below.
4. Press **Deploy**.

On its first start the app creates its tables in your Supabase database by itself. Open the address Vercel gives
you, press **Start free**, create an account, and you are in the dashboard.

> **Containers on Vercel.** This uses Vercel's container Functions (the `Dockerfile.vercel` file). If your Vercel plan
> does not offer them, the same image runs on Render, Fly.io, Railway, Google Cloud Run or any server with Docker:
> point it at the same two variables (`APP_KEY`, `DB_URL`).

### Optional settings

| Name | Default | Meaning |
|---|---|---|
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | `log` | Send real e-mail (verification, password reset). Until set, e-mails are written to the log only. |
| `AUTO_MIGRATE` | `true` | Bring the database up to date on every start. Set `false` to run `php artisan migrate --force` yourself. |
| `TRUSTED_PROXIES` | `*` | Which proxies' `X-Forwarded-*` headers are believed. `*` is right behind Vercel. |
| `BILLING_GATEWAY` | `fake` | `fake` shows a pretend checkout; `stripe` needs `STRIPE_SECRET` and `STRIPE_WEBHOOK_SECRET`. |
| `QISTAS_DEFAULT_CURRENCY` | `USD` | Currency for new workspaces whose country is not recognised. |

### Making the public site the main Vercel link

The existing `qistas-puce` Vercel project serves only the static brand prototype from the repository root. To make that
same address show the real website, open that project's **Settings → General → Root Directory**, set it to `web`, add
the variables above, and redeploy. The prototype pages remain in the repository (`brand/`) and run locally with
`npm run dev`.

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

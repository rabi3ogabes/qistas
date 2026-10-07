# Qistas web app

The Laravel application behind qistas: public website, signed-in app (`/app`), admin console and REST API.

* **Run it / host it:** see [../docs/DEPLOY.md](../docs/DEPLOY.md) (Docker on your computer, or Vercel + Supabase online).
* **Working on it:** read [CLAUDE.md](CLAUDE.md) for the rules the code must keep (tenancy, money, entitlements, audit).
* **Design and plan:** [../docs/superpowers/specs](../docs/superpowers/specs) and [../docs/superpowers/plans](../docs/superpowers/plans).

```bash
composer install && cp .env.example .env && php artisan key:generate
php artisan migrate && php artisan db:seed --class=DemoSeeder
npm install && npm run build && php artisan serve
```

```bash
php vendor/bin/pest                 # tests (SQLite in memory)
php vendor/bin/pint                 # code style
php vendor/bin/phpstan analyse      # static analysis, level 6
```

Stack: Laravel 13, PHP 8.4, PostgreSQL (SQLite for local development and tests), Tailwind 4 + Vite, Alpine (CSP build).

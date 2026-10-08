# Qistas · قسطاس

*The just balance.* A premium instalment, lending and accounting platform for merchants and lenders, Arabic-first and
available in Arabic, English, French, Spanish and Urdu.

**Live:** https://qistas-puce.vercel.app/ — the real website with a sign-in to the dashboard, running on a Supabase (PostgreSQL) database. Create a free account, or sign in.

**The mobile app:** download the Android build from the [latest release](https://github.com/rabi3ogabes/qistas/releases/latest) (built by GitHub Actions from `app/`; see [app/README.md](app/README.md)). It uses the same account as the website.

## The app: website, dashboard and API

One Laravel application in [`web/`](web/): the public website (home with a live instalment calculator, pricing, legal pages
in five languages), the signed-in app (dashboard, customers, contracts with a live schedule preview, payments with an
immutable ledger, plan limits and upgrade prompts), the REST API under `/api/v1`, and the first part of the admin area:
platform admins get a one-click **test workspace** (sample data, Free or Pro) to try the dashboard and the app without
touching real data (`php artisan qistas:make-admin <email>` makes the first admin). Free accounts have
a minimal feature set; Pro unlocks everything; an admin chooses which feature belongs to which plan.

**Run it on your computer** (needs Docker and Git):

```bash
git clone https://github.com/rabi3ogabes/qistas.git
cd qistas
docker compose up --build        # then open http://localhost:8080  (demo login is printed in docs/DEPLOY.md)
```

**The REST API** (what the mobile app uses): [docs/api/openapi.yaml](docs/api/openapi.yaml), under `/api/v1`.

**Put it online for real** with Supabase: [docs/DEPLOY.md](docs/DEPLOY.md). **The mobile app** (Flutter, Android/iOS/web) is in [`app/`](app/).

### Brand prototypes (run locally with `npm run dev`; no longer hosted)

| Prototype | Local address | What to try |
|---|---|---|
| Intro: splash + onboarding | [/brand/intro/](http://127.0.0.1:5274/brand/intro/) | switch to Arabic or Urdu, jump to Ramadan for Saudi Arabia, try dark mode |
| Theme Studio (admin) | [/brand/theme-studio/](http://127.0.0.1:5274/brand/theme-studio/) | change a colour, break the contrast, publish, auto-fix, roll back |
| Brand system | [/brand/](http://127.0.0.1:5274/brand/) | the name, the Plumb q logo, colour, type, voice, motion |

## Status

| Done and verified | Not built yet |
|---|---|
| Name, Plumb q logo kit, app icons and favicon set | Admin console (users, plans, themes), Stripe billing, reports and exports |
| Laravel web app: website, sign-up and sign-in with 2FA, customers, contracts, payments, plans and limits, five languages, REST API, admin test workspace; 1,100 tests, also green on PostgreSQL | Online payment for Pro (today the team turns Pro on), e-mail sending (needs SMTP) |
| Flutter app: sign-in with 2FA, dashboard, customers, contracts with a live schedule preview, payments (safe to retry), plans, five languages with right-to-left, 220+ tests | iOS and store builds, push reminders, offline queue |
| Theme engine: base → country → event → merchant accent, Hijri schedules, WCAG gate (24 tests) | Real database behind the live site: Supabase, connected |
| Theme Studio prototype with preview, versions, rollback | Reminders, backups, AI assistant |
| Production deployment: security headers + CSP, redirects, 404, CI, smoke tests | Native-speaker review of Arabic/Urdu/French/Spanish copy; trademark search |

## Repository map

```
web/          the Laravel app (website, /app, admin, API) · Dockerfile.vercel · tests · lang/ (five languages)
docker-compose.yml                                                    run the whole app locally with one command
vercel.json · .vercelignore                                           deploy web/ as a container on Vercel
index.html · 404.html · robots.txt · vercel.static.json              the static brand prototype (not deployed)
brand/        index.html (brand system) · intro/ · theme-studio/ · landing/ · shared/ (theme resolver, logo, preview, icons)
              logo/ (SVG masters, web icons) · tokens/ (themes.seed.json is the source of truth) · tools/ (build + tests)
supabase/     migrations/ · seed/ (generated from the theme seed)
docs/         THEME_ENGINE.md (algorithm, API contract, guard-rails) · DEPLOYMENT.md (Vercel runbook)
scripts/      serve-vercel.cjs · smoke.cjs · check-links.cjs · verify-local.cjs · validate-assets.py
.github/      workflows: ci.yml (every push/PR) · post-deploy.yml (smoke test of production)
```

## Develop

Requires Node 20+ and Python 3.10+ (Chrome only for the link check). No dependencies to install.

```bash
npm run dev            # local server that applies vercel.json: http://127.0.0.1:5274
npm test               # theme engine tests
npm run build:assets   # regenerate logos, theme-data.js and the SQL seed after editing the logo or tokens/themes.seed.json
npm run verify         # tests + 26-check smoke test + rendered-DOM link check
python scripts/validate-assets.py
```

CI fails if generated files are out of date, so run `npm run build:assets` and commit the result after changing
`brand/tools/build_logo.py` or `brand/tokens/themes.seed.json`.

## Deploy

Push to `main`: Vercel deploys it and `post-deploy.yml` smoke-tests production. Settings, rollback and the
launch checklist are in [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

## Docs

* [Brand README](brand/README.md): the system, decisions and rebuild commands
* [Theme engine](docs/THEME_ENGINE.md): how an admin recolours the product per country and event
* [Deployment](docs/DEPLOYMENT.md)

© Qistas. All rights reserved. Fonts are SIL OFL; icons and logos are original drawings.

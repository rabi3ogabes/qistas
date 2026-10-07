# Qistas · قسطاس

*The just balance.* A premium instalment, lending and accounting platform for merchants and lenders, Arabic-first and
available in Arabic, English, French, Spanish and Urdu.

**Brand prototype (static):** https://qistas-puce.vercel.app/ — the animated intro, Theme Studio and brand system. It is not the app.

## The app: website, dashboard and API

One Laravel application in [`web/`](web/): the public website (home with a live instalment calculator, pricing, legal pages
in five languages), the signed-in app (dashboard, customers, contracts with a live schedule preview, payments with an
immutable ledger, plan limits and upgrade prompts), and, in progress, the admin console and REST API. Free accounts have
a minimal feature set; Pro unlocks everything; an admin chooses which feature belongs to which plan.

**Run it on your computer** (needs Docker and Git):

```bash
git clone https://github.com/rabi3ogabes/qistas.git
cd qistas
docker compose up --build        # then open http://localhost:8080  (demo login is printed in docs/DEPLOY.md)
```

**Put it online** with Vercel + Supabase: [docs/DEPLOY.md](docs/DEPLOY.md). The mobile app (Flutter) is not built yet.

### Brand prototypes

| Prototype | Live | What to try |
|---|---|---|
| Intro: splash + onboarding | [/brand/intro/](https://qistas-puce.vercel.app/brand/intro/) | switch to Arabic or Urdu, jump to Ramadan for Saudi Arabia, try dark mode |
| Theme Studio (admin) | [/brand/theme-studio/](https://qistas-puce.vercel.app/brand/theme-studio/) | change a colour, break the contrast, publish, auto-fix, roll back |
| Brand system | [/brand/](https://qistas-puce.vercel.app/brand/) | the name, the Plumb q logo, colour, type, voice, motion |

## Status

| Done and verified | Not built yet |
|---|---|
| Name, Plumb q logo kit, app icons and favicon set | Flutter mobile app |
| Laravel web app: website, sign-up and sign-in with 2FA, customers, contracts, payments, plans and limits, five languages, 895 tests also green on PostgreSQL | Admin console, REST API, Stripe billing, reports and exports |
| Theme engine: base → country → event → merchant accent, Hijri schedules, WCAG gate (24 tests) | Hosted app on Vercel + Supabase (ready to deploy: see docs/DEPLOY.md) |
| Theme Studio prototype with preview, versions, rollback | Reminders, backups, AI assistant |
| Production deployment: security headers + CSP, redirects, 404, CI, smoke tests | Native-speaker review of Arabic/Urdu/French/Spanish copy; trademark search |

## Repository map

```
web/          the Laravel app (website, /app, admin, API) · Dockerfile.vercel · tests · lang/ (five languages)
docker-compose.yml                                                    run the whole app locally with one command
index.html · 404.html · robots.txt · vercel.json · .vercelignore      the deployed site's root
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

# Qistas · قسطاس

*The just balance.* A premium instalment, lending and accounting platform for merchants and lenders, Arabic-first and
available in Arabic, English, French, Spanish and Urdu.

**Live test site:** https://qistas-puce.vercel.app/ (static, no sign-in, nothing leaves your browser)

| Prototype | Live | What to try |
|---|---|---|
| Intro: splash + onboarding | [/brand/intro/](https://qistas-puce.vercel.app/brand/intro/) | switch to Arabic or Urdu, jump to Ramadan for Saudi Arabia, try dark mode |
| Theme Studio (admin) | [/brand/theme-studio/](https://qistas-puce.vercel.app/brand/theme-studio/) | change a colour, break the contrast, publish, auto-fix, roll back |
| Brand system | [/brand/](https://qistas-puce.vercel.app/brand/) | the name, the Plumb q logo, colour, type, voice, motion |

## Status

| Done and verified | Not built yet |
|---|---|
| Name, Plumb q logo kit, app icons and favicon set | Flutter app (customers, contracts, schedules, payments, reports) |
| Animated intro and onboarding in five languages, RTL | Laravel API |
| Theme engine: base → country → event → merchant accent, Hijri schedules, WCAG gate (24 tests) | Supabase database (migration written, **not executed**) |
| Theme Studio prototype with preview, versions, rollback | Auth, billing, reminders, backups, AI assistant |
| Production deployment: security headers + CSP, redirects, 404, CI, smoke tests | Native-speaker review of Arabic/Urdu/French/Spanish copy; trademark search |

## Repository map

```
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

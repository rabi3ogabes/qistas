# Deployment (Vercel)

Live test site: **https://qistas-puce.vercel.app/** · Source: https://github.com/rabi3ogabes/qistas · Branch: `main`

The site is **static**: no build step, no server, no environment variables, no secrets. Vercel serves the repository
root (minus what `.vercelignore` excludes) straight from the `main` branch. Every push to `main` is a production deploy;
every other branch or pull request gets a preview URL.

## What is served

| URL | Source |
|---|---|
| `/` | `index.html` — landing page with the three prototypes |
| `/brand/` | brand system |
| `/brand/intro/` | animated splash and onboarding (also `/intro`) |
| `/brand/theme-studio/` | admin Theme Studio (also `/studio`) |
| `/favicon.ico` `/favicon.svg` `/apple-touch-icon.png` | rewritten to `brand/logo/…` |
| anything else | `404.html` (branded) |

Not served (listed in `.vercelignore`, still in git): `docs/`, `supabase/`, `scripts/`, `brand/tools/`, `.github/`,
original-size preview PNGs, the logo presentation board. The brand page links to those on GitHub instead.

## Vercel project settings to verify once

Dashboard → Project → Settings. `vercel.json` already carries the behaviour; these only need to not fight it.

| Setting | Value |
|---|---|
| Framework Preset | **Other** |
| Build Command | *(empty / override off)* |
| Install Command | *(empty / override off)* |
| Output Directory | *(empty)* — the repository root |
| Root Directory | `./` |
| Production Branch | `main` |
| Node.js Version | any (not used) |
| Deployment Protection | *Standard* is fine: the production domain stays public |

## What `vercel.json` does

* `trailingSlash: true` so relative asset URLs resolve (`/brand` → `/brand/`).
* Short links: `/intro`, `/studio`, `/theme-studio` redirect to the real pages.
* **Security headers on every response:** Content-Security-Policy (no inline or third-party scripts; styles from self,
  inline attributes and Google Fonts; fonts from Google; images self/data; no frames, no plugins, no external
  connections), `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`
  (camera, microphone, geolocation, payment, USB off), `Cross-Origin-Opener-Policy: same-origin`. HSTS is added by Vercel.
* `X-Robots-Tag: noindex, nofollow` plus `robots.txt` `Disallow: /` so the test site stays out of search results.
* Logos, previews and concepts are cached for 1 hour with stale-while-revalidate; HTML, CSS and JS revalidate on every load.

**If you add analytics, a chat widget, a payment form or any other third-party script, the CSP must be widened for it**
(`script-src`, `connect-src`, `frame-src`). Do it in `vercel.json`, never with `unsafe-inline` for scripts.

## Verifying a change before it ships

```bash
npm run dev        # http://127.0.0.1:5274 — local server that applies vercel.json (redirects, rewrites, headers, 404)
npm run verify     # tests + smoke test (26 checks) + rendered-DOM link check, all against that local server
python scripts/validate-assets.py   # 559 static checks: JSON, SVG, vercel.json, seed schema, ignore rules
```

GitHub Actions repeats all of this on every push and pull request (`.github/workflows/ci.yml`), and after each
production deploy runs the smoke test and link check against the **live** URL (`post-deploy.yml`), including a check
that private files (SQL, docs, scripts, `.git`) return 404. Set a repository variable `PRODUCTION_URL` if the domain changes.

Manual post-deploy check:

```bash
node scripts/smoke.cjs https://qistas-puce.vercel.app --deployed
node scripts/check-links.cjs https://qistas-puce.vercel.app
```

## Rolling back

Dashboard → Deployments → pick the last good one → **Promote to Production** (instant). Or `git revert <sha>` and push.

## Going from "test" to "launch"

1. Add the custom domain in Vercel (Settings → Domains) and update the absolute URLs in the `canonical`/`og:` meta tags
   (`index.html`, `brand/index.html`, `brand/intro/index.html`, `brand/theme-studio/index.html`) and `PRODUCTION_URL`.
2. Remove `X-Robots-Tag` from `vercel.json`, the `noindex` meta tags, and `Disallow: /` in `robots.txt`
   (`validate-assets.py` currently *requires* the meta tag; relax that check at the same time). Add a `sitemap.xml`.
3. Replace the placeholder trademark status with the result of a real search (see the brand page, section Name).
4. Decide where the real application will live (see below) and point the landing page's buttons at it.

## Known limits of this deployment

* **It is the design system and theming prototypes, not the product.** There is no sign-in, no database, and nothing is
  sent anywhere. The Theme Studio stores edits in the visitor's own browser (`localStorage`).
* Fonts load from Google Fonts; without a network they fall back to system fonts (layout is unaffected).
* Right-to-left layouts, five languages and dark mode are verified in Chrome; Safari and Firefox have not been exercised.
* The Supabase migration in `supabase/` has never been executed against a live database.

## Where the real application would run

The product stack in the master prompt is Flutter + Laravel + Supabase. On Vercel the Flutter **web build** can be served
as static files (commit `build/web` or build it in CI); Laravel cannot run on Vercel's runtime, so the API needs a PHP host
(or Supabase edge functions / Next.js route handlers replace it). That decision is the next milestone.

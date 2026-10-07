# Qistas · قسطاس — brand system

*The just balance.* Name, logo, colour, type, voice, motion, the animated intro, and the **Theme Studio** that lets an
admin recolour the product for every country and every event.

## Open it (no build step)

Double-click any of these; they also work from `file://`:

| Page | What it is |
|---|---|
| [`index.html`](index.html) | The brand system: name, Plumb q logo, colour, type, voice, motion, theming demo, files |
| [`intro/index.html`](intro/index.html) | Splash (plumb-bob drop) + 3 onboarding slides in AR / EN / FR / ES / UR, themeable by country and date |
| [`theme-studio/index.html`](theme-studio/index.html) | Admin prototype: edit colours per country/event, schedule (incl. Hijri), WCAG gate, versions, rollback |

(Google Fonts load from the network; offline they fall back to system fonts.)

## Layout

```
brand/
  index.html · guidelines.css · guidelines.js      brand system page
  intro/        index.html · intro.css · intro.js · copy.js (5-language copy)
  theme-studio/ index.html · studio.css · studio.js · theme-data.js (generated)
  shared/       qistas-theme.js (resolver) · qistas-logo.js (generated) · qistas-preview.js/.css · qistas-base.css · icons.js
  logo/         SVG masters (wordmark, Arabic, dual lockups, symbol, small cut, app icons, favicon, OG card)
                web/ (favicon.ico, PNG set, manifest, <head> snippet) · presentation/ (board + mockups)
  tokens/       themes.seed.json (source of truth) · design-tokens.json (fixed tokens)
  tools/        build_logo.py · sync-brand-data.py · test-theme-runtime.cjs · arabic-outline.html · shot.sh
  concepts/     the three concepts that were explored (A Stepped Q, B Equilibrium Coin, C Plumb q ← chosen)
  previews/     screenshots of the finished pages
../supabase/migrations/20261007000100_theme_engine.sql   tables, RLS, versioning, rollback (UNEXECUTED)
../supabase/seed/theme_seed.sql                          generated from tokens/themes.seed.json
../docs/THEME_ENGINE.md                                  algorithm, API contract, guard-rails, client notes
```

## Rebuild

```bash
python brand/tools/build_logo.py          # regenerates every SVG in brand/logo from geometry
python brand/tools/sync-brand-data.py     # regenerates theme-data.js, qistas-logo.js and the SQL seed
node   brand/tools/test-theme-runtime.cjs # 22 tests: layering, Hijri/Gregorian schedules, locked campaigns, contrast
```

PNG/ICO exports (favicon set, app icon, OG card) are produced with the `logo-design` skill's `render_png.py`; the commands
are in `logo/web/` history and `brand/index.html#assets`.

## Decisions worth knowing

* **Name:** *Qistas* (قسطاس), "the just balance". "Qist" alone is taken by Qist Bazaar (PK); no exact "Qistas" brand found in a
  7 Oct 2026 web search. **Not a trademark clearance.**
* **Logo:** *Plumb q*, a monoline geometric wordmark whose q ends in a gold plumb-bob; the Arabic wordmark is Cairo Regular,
  outlined, with its two qaf dots redrawn as gold coins.
* **Colour:** Midnight Navy `#0B1F44`, Champagne Gold `#C9A25B`, Ivory `#F7F3EA`, plus the reference UI's Sapphire
  `#1C6BA4` and pastel tiles. All text pairs clear WCAG AA.
* **Theming:** base → country → event → merchant accent. Themes store only what they change; the engine derives the rest
  and re-checks contrast at runtime.
* **Fonts (all SIL OFL):** Cormorant Garamond, Geist, IBM Plex Sans Arabic, Noto Nastaliq Urdu.

## Status and next steps

Done and verified in a browser: logo kit, brand page, intro, Theme Studio, resolver tests.
Not built yet (specified in `docs/THEME_ENGINE.md`): the Laravel endpoints, the Flutter `ThemeExtension` and the splash
widget, and executing the SQL migration against Supabase. Needs from you: a trademark search, native-speaker review of the
Arabic / Urdu / French / Spanish copy, and the default country for the pre-login intro.

# Admin shell and Appearance studio Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** A right-hand admin navigation, and an Appearance studio where a super admin controls colours, pictures and welcome banners of the website, the web app and the Android app.

**Architecture:** A PHP port of the theme engine resolves four brand colours into the full 26-token light and dark palette with a contrast gate. An `Appearance` service is the only writer of immutable versions (draft, publish, restore, reset) and of database-stored brand pictures. Public routes serve `/theme.css` and `/brand-assets/{id}`, layouts link them, and `GET /api/v1/appearance` gives the Android app the same resolved palette and banner.

**Tech Stack:** Laravel 13, Blade, vanilla CSS and JS (CSP-safe, no inline script), GD, Pest; Flutter (Riverpod).

**Spec:** `docs/superpowers/specs/2026-10-09-admin-shell-and-appearance-design.md`

## Global Constraints

- Laravel 13, PHP 8.4; tests on SQLite, production on PostgreSQL: migrations additive, reversible, UUID keys, no tenant data.
- Every user-visible string in five languages: web `lang/app/{ar,fr,es,ur}.json` via `scripts/merge_translations.py`; app via `python tool/i18n.py`. Arabic wording is count-neutral.
- The admin console keeps the Qistas look (decision D10); only previews show the chosen brand.
- Colours: derivation and contrast rules are those of `brand/shared/qistas-theme.js`; PHP must match it on shared vectors.
- Images: JPEG and PNG only, re-encoded (no metadata), resized, never SVG; stored in the database; served immutable.
- Gates per task: `pint --dirty`, `phpstan analyse`, `pest`; app: `flutter analyze` zero issues, `flutter test`. `docs/api/openapi.yaml` is drift-tested.
- One task = one commit, pushed through the owner's terminal tool. Bump `app/pubspec.yaml` when the app changes.

## Review Focus

1. **A bad palette must not ship.** Black on black, white on white, a near-grey accent: publish repairs foregrounds and reports it; the served CSS always passes the gate.
2. **Hostile input.** `javascript:` and `data:` call-to-action links, script in banner text, an SVG renamed `.png`, a huge picture, a CSS injection through a "colour" (`red;} body{display:none`).
3. **A published look must never be lost.** Restore and reset publish new versions; history is append-only; two admins publishing at once do not corrupt the live pointer.
4. **Caching.** `/theme.css` and pictures are versioned URLs and long-cached; a new publish changes every URL, so nobody sees a stale look.
5. **Right-to-left.** The menu is on the right in Arabic and English; the banner and the studio mirror correctly.

---
### Task 1: The admin shell with the navigation on the right

**Files:** Modify `web/resources/views/components/layouts/admin.blade.php`, `web/resources/css/admin.css`, `web/resources/js/app.js` (init), Create `web/resources/js/admin-nav.js`; Test `web/tests/Feature/Admin/AdminShellTest.php`.

**Interfaces:**
- Produces: `x-layouts.admin` keeps its props (`title`, `section`); sections known: `overview`, `features`, `appearance`, `security`. A nav group is a `<nav aria-label>`; the current page carries `aria-current="page"`; the panel is `#admin-nav`; its open button is `a[href="#admin-nav"]`.

- [ ] **Step 1: Failing tests:** every admin page has one `#admin-nav` with the links Overview, Feature control, Appearance (only when its route exists), Open app (when the account has a workspace), Website, Security; the current section is marked; the account name, e-mail, language button, theme toggle and sign-out form are inside the panel; the old top bar is gone; Arabic renders `dir="rtl"` with the same panel.
- [ ] **Step 2: Implement** the right-hand panel (physical `right`), mobile slide-in via `:target`, `admin-nav.js` for focus return, Escape and scrim click; luxury treatment: midnight panel, ivory text, gold current-page marker, quiet separators, no eyebrow labels.
- [ ] **Step 3:** screenshots at 1440 and 390 px in light, dark and Arabic; fix what shows; gates; **Commit** `Move the admin menu to the right and redesign it (admin shell)`; push.

---
### Task 2: The colour engine in PHP

**Files:** Create `web/app/Theme/{Color,ThemeEngine}.php`, `shared/theme-vectors.json`, `web/tests/Feature/Theme/ThemeEngineTest.php`; a Node script `brand/tools/make-theme-vectors.cjs` that writes the vectors from the reference.

**Interfaces:**
- Produces: `Color::{hexToRgb,rgbToHex,hexToHsl,hslToHex,mix,luminance,contrast,lighten,darken,readableOn,readableOnAll,ensureContrast}`; `ThemeEngine::BASE` (the factory light and dark tokens), `ThemeEngine::resolve(array $pins): array{light: array<string,string>, dark: array<string,string>}` (pins are `['light' => [...], 'dark' => [...]]` of changed tokens only), `ThemeEngine::validate(array $tokens): list<array>` (mode, fg, bg, min, ratio, pass, blocking, label), `ThemeEngine::autoFix(array $tokens): array{tokens: array, changed: list<array>}`.

- [ ] **Step 1: Failing tests:** the factory tokens resolve to themselves and pass every check; 12 vectors made by the reference JS (different primaries, accents, infos, pinned tokens) match PHP token for token; a pinned black-on-black palette is repaired and every check then passes; a dark primary yields a dark bg derived from its hue; `contrast` of black and white is 21; malformed hex is rejected.
- [ ] **Step 2-4:** red, port, green; mutation check on derivation and the repair. **Commit** `Port the theme engine's colour rules to PHP`; push.

---
### Task 3: The Appearance store

**Files:** Create `web/database/migrations/2026_10_09_000800_create_appearance_tables.php`, `web/app/Models/{AppearanceVersion,AppearanceAsset}.php`, `web/app/Theme/{Appearance,BrandImages,Banner}.php`; Modify `AppServiceProvider` (gate `manage-appearance`); Test `web/tests/Feature/Theme/AppearanceTest.php`.

**Interfaces:**
- Produces: `Appearance::live(): AppearanceView` (never null: factory look when nothing is published) with `version(): int`, `tokens(): array`, `hasCustomColours(): bool`, `imageUrl(string $slot): ?string`, `banner(string $surface, string $language): ?array`; `Appearance::draft(): AppearanceVersion` (created from live on first use), `saveDraft(array $input, User $by): AppearanceVersion` (validates), `publish(User $by, ?string $note): AppearanceVersion` (runs the gate, repairs, bumps the version, audits), `restore(AppearanceVersion, User)`, `resetToFactory(User)`, `history(int $limit)`. `BrandImages::store(UploadedFile, string $slot, User): AppearanceAsset` (slots `logo`, `logo_dark`, `hero`, `banner`).

- [ ] **Step 1: Failing tests:** factory look when empty; save-publish-live round trip; publish repairs a bad palette and lists what changed; versions are immutable and numbered; restore and reset create new versions; two publishes in a row do not collide; CSS-injection colours, `javascript:`/`data:` links, over-long and markup-laden text, a bad surface or language and an unknown slot are refused; image: SVG renamed `.png`, a PDF, an over-size and an over-pixel picture are refused, EXIF is stripped, the picture is resized, the stored bytes round-trip; only `super_admin` passes the gate; every change audited.
- [ ] **Step 2-4:** red, implement, green, mutation checks. **Commit** `Add the Appearance store with versions and brand pictures`; push.

---
### Task 4: Delivery: theme.css, pictures and the API

**Files:** Create `web/app/Http/Controllers/{ThemeCssController,BrandAssetController}.php`, `web/app/Http/Controllers/Api/V1/AppearanceController.php`, `web/app/View/Components/BrandHead.php` and view; Modify the four layouts, `routes/web.php`, `routes/api.php`, `docs/api/openapi.yaml`; Test `web/tests/Feature/Theme/DeliveryTest.php`.

**Interfaces:**
- Produces: `GET /theme.css?v={version}` (the engine's CSS variables for both modes, empty when nothing is customised; `Cache-Control: public, max-age=31536000, immutable`); `GET /brand-assets/{asset}` (the stored bytes, immutable); `GET /api/v1/appearance` -> `{version, tokens:{light,dark}, logo_url, banner|null}` with `ETag` and `304`; a Blade component `<x-brand-head />` that links the CSS, sets `theme-color` and the favicon.

- [ ] **Step 1: Failing tests:** CSS has every token for both modes and the `data-q-mode` and `prefers-color-scheme` forms, and nothing else (no injection); empty file for the factory look; the layouts (site, auth, app) link it and the admin layout does not; asset route serves bytes with the right type, immutable cache, `nosniff`, and 404 for unknown ids; API returns the resolved palette, 304 on a matching ETag, and the banner in the requested language with English fallback.
- [ ] **Step 2-4:** red, implement, green. **Commit** `Serve the chosen look: theme.css, brand pictures and the appearance API`; push.

---
### Task 5: Welcome banners on the website and the web app

**Files:** Create `web/resources/views/components/welcome-banner.blade.php`, `web/resources/css/banner.css`, `web/resources/js/banner.js`; Modify site and app layouts and the dashboard view; Test `web/tests/Feature/Theme/BannerTest.php`.

- [ ] **Step 1: Failing tests:** a published website banner shows under the header on public pages in the page's language (English fallback), not when disabled, before its start or after its end; the web-app banner shows on `/app` only; the website banner is absent from `/app` and the reverse; text is escaped; a call-to-action renders only for a safe link; dismissible banners carry their version for the script; right-to-left renders.
- [ ] **Step 2-4:** red, implement (tones: gold, navy, sand, sky; optional picture), green, screenshots. **Commit** `Show the welcome banner on the website and in the web app`; push.

---
### Task 6: The Appearance studio in the admin

**Files:** Create `web/app/Http/Controllers/Admin/AppearanceController.php`, `web/resources/views/admin/appearance/index.blade.php`, `web/resources/js/admin-appearance.js`, studio styles in `admin.css`; Modify `routes/admin.php`; Test `web/tests/Feature/Admin/AppearancePageTest.php`.

- [ ] **Step 1: Failing tests:** page needs admin and 2FA; other staff see it read-only; saving a draft persists colours, pictures and banners; invalid input shows field errors and keeps what was typed; publish shows what the gate repaired; history lists versions with who and when; restore and reset work; the page never applies the brand to itself.
- [ ] **Step 2-4:** red, implement (presets, four pickers with live contrast, picture slots with preview, per-surface banner editor with language tabs, live preview of website, web app and phone, sticky publish bar), green, browser checks desktop and phone, Arabic. **Commit** `Add the Appearance studio to the admin`; push.

---
### Task 7: The Android app follows the brand

**Files:** Create `app/lib/data/appearance.dart`, `app/lib/features/appearance/welcome_banner.dart`; Modify `app/lib/app/providers.dart`, `app/lib/core/design/qistas_theme.dart`, the dashboard, `qistas_api.dart`; Test `app/test/features/appearance_test.dart`; bump `pubspec.yaml` to 1.3.0+5.

- [ ] **Step 1: Failing tests:** palette parsed into `QistasColors` (all 26 tokens, light and dark); the last answer is kept and applied on a cold start with no network; a failed fetch keeps the old look; banner shows in the app's language with English fallback, can be dismissed per version, hides after its end date; no banner when none is published.
- [ ] **Step 2-4:** red, implement, green, screenshots. **Commit** `Let the Android app follow the chosen colours and show the welcome banner (v1.3.0)`; push; CI builds the APK.

---
### Task 8: Docs, final gates and the live check

- [ ] Update `docs/features/m0-foundation.md` neighbours with `docs/features/appearance.md`, `docs/DEPLOY.md` (nothing to configure), memory notes; full suites; push; watch CI; read-only live checks (`/theme.css`, `/brand-assets/x`, `/api/v1/appearance`, `/admin` 302); report what is not provable.

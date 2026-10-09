# App refresh (v1.3.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The Android app gets its real icon and launch screen, loses the test-workspace strip, gains a finer language picker and a reorganised Settings, and follows the colours and welcome banner chosen in the admin's Appearance page.

**Architecture:** Flutter (Riverpod, go_router, dio). The palette comes from `GET /api/v1/appearance` (ETag, kept in SharedPreferences, applied on a cold start before the network answers) and is turned into `QistasColors` for light and dark; `QistasTheme.of` takes the colours instead of the constants. Launcher icon and splash are generated files from the brand art.

**Tech Stack:** Flutter 3.41, flutter_launcher_icons, headless Chrome for SVG→PNG, Laravel for the one API addition.

**Spec:** `docs/superpowers/specs/2026-10-10-app-billing-notifications-design.md` (D13, D14, D15) and `docs/superpowers/specs/2026-10-09-admin-shell-and-appearance-design.md` (D11).

## Global Constraints

- `flutter analyze` zero issues; `flutter test` green; never run `dart format`.
- Every new string in five languages via `python tool/i18n.py` (batch file), Arabic count-neutral.
- Colours only from `context.qc`; no literals in screens.
- Touch targets ≥ 48 dp; text scales to 2×; right-to-left mirrors.
- Version `1.3.0+5` in `app/pubspec.yaml` and `AppConfig.appVersion` default; CI publishes release "Qistas app 1.3.0".
- One task = one commit, pushed through the owner's terminal tool.

## Review Focus

1. **A bad or missing palette never breaks the app.** Malformed JSON, a missing token, a non-hex value, a 500, no network: the app keeps the last good palette or the built-in one.
2. **Cold start offline** shows the last chosen colours, not a flash of the factory look.
3. **A dismissed banner stays dismissed** across restarts, and a changed banner (new key) shows again.
4. **Right-to-left**: the language cards, settings rows and banner card mirror; Urdu's Nastaliq line height does not clip.
5. **Large text (2×)**: settings rows and language cards wrap instead of overflowing.

---

### Task 1: The real app icon and launch screen

**Files:** Create `app/tool/icons.py` (renders the brand SVGs to PNGs with headless Chrome), `app/assets/icon/{foreground,monochrome}.png`, `android/app/src/main/res/values-ar/strings.xml`, `values-v31/styles.xml`, `drawable/launch_background.xml` (navy + mark); Modify `pubspec.yaml` (`flutter_launcher_icons`: `adaptive_icon_background: "#0B1F44"`, `adaptive_icon_foreground`, `adaptive_icon_monochrome`), `AndroidManifest.xml` (`android:label="@string/app_name"`), `values/strings.xml`, `values/styles.xml`, `values-night/styles.xml`.

- [ ] Render foreground (transparent, mark inside the 66 % safe zone) and monochrome (white mark) at 1024 px from `brand/logo/qistas-app-icon-android-foreground.svg`.
- [ ] `dart run flutter_launcher_icons`; check every `mipmap-*/ic_launcher.png` is the q (not Flutter's), `mipmap-anydpi-v26/ic_launcher.xml` has background, foreground and monochrome.
- [ ] Launch theme: `windowBackground` navy with the centred mark; Android 12+: `windowSplashScreenBackground` navy, `windowSplashScreenAnimatedIcon` the foreground.
- [ ] Label: `Qistas`; `values-ar`: `قسطاس`.
- [ ] Commit `Give the Android app its icon, name and launch screen`.

### Task 2: No test-workspace strip

**Files:** Modify `lib/app/shell.dart` (drop the `isTest` strip from `_Notices` and from `hasNotices`), `lib/features/settings/settings_screen.dart` (a `QBadge(context.t('Test workspace'))` on the account card); Test `test/features/workspace_test.dart`.

- [ ] Failing tests: a test workspace's dashboard has no `Sample data. Nothing here is real` text and no strip; Settings shows the *Test workspace* badge; offline and unverified-e-mail strips still show.
- [ ] Implement; green; commit `Remove the test-workspace strip from the app`.

### Task 3: A finer language picker

**Files:** Modify `lib/app/language_button.dart`; Test `test/features/language_test.dart`.

**Interfaces:** `LanguageButton({bool compact})` keeps its name and tooltip *Language*; compact shows a 40-dp circle with the language's monogram (`En`, `ع`, `Fr`, `Es`, `ار`) with a gold hairline, matching `AccountButton`; full shows globe + native name. `showLanguageSheet(context)` unchanged. `LanguageSheet` shows five `_LanguageCard`s: native name in its own typeface (Cormorant for Latin, IBM Plex Sans Arabic, Noto Nastaliq Urdu), a greeting in it (`Welcome`, `أهلًا بك`, `Bienvenue`, `Bienvenido`, `خوش آمدید`), selected = gold ring + check, `Semantics(selected:)`.

- [ ] Failing tests: the compact button shows `En`; after choosing Arabic it shows `ع` and the app is right-to-left; the sheet lists five cards each with its greeting; the current one is selected in semantics; choosing saves `language` and closes the sheet; at 2× text the sheet does not overflow.
- [ ] Implement; green; commit `Make the language picker a set of five language cards`.

### Task 4: Settings, reorganised

**Files:** Modify `lib/features/settings/settings_screen.dart` (split into `_ProfileHeader`, `_PlanCard`, `_Group`, `_Row`); Test `test/features/settings_test.dart` (new).

**Interfaces:** Groups in order: profile header (gradient initials, name, e-mail, business, role, plan badge, Test badge); **Your plan** (Free: two meters + *Upgrade to Pro*; Pro: "Pro is on" + renewal date when known); **Preferences** (Language row → sheet, value = native name; Appearance = segmented *System / Light / Dark*); **Workspace** (Instalment tools when present); **Security** (two-step status, Sign out, Sign out everywhere, both confirmed); **Help** (website, privacy, terms, version). Each row: icon tile, title, value or chevron, 56 dp min height.

- [ ] Failing tests: groups appear in that order; the Language row shows *English* and opens the sheet; the appearance control switches `themeModeProvider` and saves it; Sign out asks first; a Pro account shows no meters; at 2× text and in Arabic nothing overflows.
- [ ] Implement; green; visual check (light, dark, Arabic); commit `Reorganise the app's settings`.

### Task 5: The app follows the chosen look and shows the banner

**Files:** Web: Modify `app/Theme/AppearanceView.php` (banner payload adds `ends_on`), `docs/api/openapi.yaml`, `tests/Feature/Theme/DeliveryTest.php`. App: Create `lib/data/appearance.dart`, `lib/features/appearance/welcome_banner.dart`; Modify `lib/app/providers.dart`, `lib/app/app.dart`, `lib/core/design/qistas_theme.dart` (`of(..., {QistasColors? colors})`), `lib/core/design/tokens.dart` (`QistasColors.fromTokens(Map<String,String>, {required QistasColors fallback})`), `lib/data/qistas_api.dart` (`appearance({String? etag})`), `lib/features/dashboard/dashboard_screen.dart`; Test `test/features/appearance_test.dart`.

**Interfaces:**
- `class Look { final int version; final QistasColors light, dark; final WelcomeBanner? banner; final String? etag; String toJson(); static Look? fromJson(String) }`
- `class WelcomeBanner { title, message, ctaLabel, ctaUrl, tone ('gold'|'navy'|'sand'|'sky'), dismissible, imageUrl, key, endsOn }`
- `final lookProvider = NotifierProvider<LookController, Look?>` — `build()` returns the saved look (SharedPreferences `look.<language>`) synchronously; `refresh()` asks the server with `If-None-Match`, keeps the old look on 304, error, or a malformed answer.
- `dismissedBannersProvider` — a `Set<String>` of keys in SharedPreferences.

- [ ] Failing tests: all 24 app tokens parse from a 26-token answer for both modes; a missing or non-hex token falls back to the built-in colour; the saved look is applied on a cold start with the network down (no factory flash); 304 keeps the look; a 500 keeps the look; the dashboard shows the banner in the app's language; closing it hides it and survives a restart; a new key shows again; a banner past `ends_on` is not shown even from the cache; a `/pricing` link opens the website; no banner when none is published.
- [ ] Implement; green; commit `Let the Android app follow the chosen colours and show the welcome banner`.

### Task 6: Release 1.3.0

- [ ] Bump to `1.3.0+5`; translations complete; `flutter analyze`; full `flutter test`; visual screenshots of dashboard, settings, language sheet (light, dark, Arabic); web suite for the API change; push; watch CI build the release *Qistas app 1.3.0*; report the APK link.

# Flutter App Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (native) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** One Flutter codebase (Android, iOS, Web) where a user creates a free account (or signs in to the one made on the website), uses the free feature set, and upgrades to Pro, driven entirely by the entitlements the Laravel API returns.

**Architecture:** Feature-first folders under `app/lib`, Riverpod for state, go_router for navigation, Dio for HTTP, `flutter_secure_storage` for the token. No code generation (plain immutable models) to keep builds fast. Visual identity comes from the Qistas tokens (`ThemeExtension<QistasColors>`), light and dark, English and Arabic with RTL.

**Tech Stack:** Flutter 3.41 / Dart 3.11, flutter_riverpod, go_router, dio, flutter_secure_storage, intl + flutter_localizations, shared_preferences, google_fonts (or bundled fonts for offline).

**Spec:** `docs/superpowers/specs/2026-10-07-qistas-platform-design.md` · API: `docs/api/openapi.yaml`

## Global Constraints

- Targets: Android, iOS, Web; min Android API 24; `flutter analyze` must report **zero** issues; tests run with `flutter test`.
- The app never contains secrets; the API base URL is a `--dart-define=API_BASE_URL=…` with a safe default for local development.
- Tokens only in secure storage (Keychain/Keystore; encrypted storage on web is best-effort and documented); never logged.
- Feature gating is read from `GET /api/v1/me` → `entitlements`; the UI **never** hard-codes which feature is Free or Pro.
- A `402` from the API must always open the upgrade sheet, never a generic error.
- Money is parsed from decimal **strings**, computed with integer minor units or `Decimal`, never `double`.
- The schedule preview must reproduce `shared/schedule-vectors.json` exactly (parity with PHP).
- Arabic (`ar`) and English complete; layouts mirror in RTL; minimum touch target 48 dp; text scales to 200%.
- Store rule: unlocking Pro on iOS/Android must use store billing. Until store accounts exist the mobile paywall shows plans and opens web checkout **only on Web**; on iOS/Android it says Pro can be activated from the website account (no purchase link) — documented in the app and in `docs/BILLING.md`.

## Review Focus

- Token expired mid-session → one silent sign-out to the login screen, no error loop (Task 3).
- Offline on launch with a stored session → shows cached dashboard banner, not a blank screen (Task 5).
- Free user at the customer limit taps "Add customer" → upgrade sheet shows used/limit, no form opens (Task 6).
- Arabic locale: numerals, chevrons, back button and swipe directions mirror; amounts stay left-to-right isolated (Task 2).
- Large font (200%) on the contract wizard → no clipped buttons (Task 7).
- Double tap on "Record payment" → one request (idempotency key reused) (Task 8).

---

### Task 1: Scaffold and tooling
**Files:** `app/` via `flutter create --org com.qistas --platforms=android,ios,web app`; `app/analysis_options.yaml` (strict), `app/pubspec.yaml`. Test `app/test/smoke_test.dart::boots the root widget`.
- [ ] Failing smoke test → scaffold → pass → commit.

### Task 2: Design system, theme and localisation
**Files:** `lib/core/design/tokens.dart` (generated from `brand/tokens/*.json` values), `qistas_theme.dart`, `lib/core/l10n/*.arb` (`en`,`ar`), widgets `QButton`, `QField`, `QCard`, `QBadge`, `QMeter`, `QSkeleton`. Test `test/design/theme_test.dart` (contrast of every token pair ≥ 4.5 in light and dark, RTL direction for `ar`).
- [ ] Failing test → implement → pass → commit.

### Task 3: API client, auth, secure storage
**Files:** `lib/core/api/api_client.dart` (Dio, bearer, 401 → sign-out, 402 → `UpgradeRequired`), `lib/features/auth/*` (register, login, logout), `lib/core/storage/token_store.dart`. Tests with a fake adapter: 401 handling, 402 mapping, token never in logs.
- [ ] Failing tests → implement → pass → commit.

### Task 4: Splash, intro, onboarding
**Files:** `lib/features/intro/splash_screen.dart` (plumb-bob spring animation with `CustomPainter`, reduced-motion aware), `onboarding_screen.dart` (3 pages from the web intro copy, `en`/`ar`). Widget tests for reduced motion and RTL.

### Task 5: Dashboard
**Files:** `lib/features/dashboard/*` consuming `GET /dashboard` and `/me`; usage meter; offline banner. Widget test with fake API.

### Task 6: Customers
**Files:** `lib/features/customers/*` (list, search, detail, form); the add action consults `entitlements.customers`. Tests for limit gating and validation.

### Task 7: Contracts wizard + schedule parity
**Files:** `lib/domain/schedule_generator.dart` (port of the PHP generator), `lib/features/contracts/*`. Test `test/domain/schedule_generator_test.dart` runs every vector in `shared/schedule-vectors.json`.

### Task 8: Payments
**Files:** `lib/features/payments/record_payment_sheet.dart` with idempotency key per attempt. Tests: double tap → one call; invalid amounts rejected client-side and server-side messages shown.

### Task 9: Paywall and billing
**Files:** `lib/features/billing/paywall_sheet.dart`, `plans_screen.dart` (plans and feature matrix from `GET /plans`, the admin's choices), web checkout redirect, mobile policy text per Global Constraints.

### Task 10: Settings, security, CI, web build
**Files:** settings (language, theme mode, sign out everywhere), `.github/workflows/ci.yml` Flutter job (`flutter analyze`, `flutter test`, `flutter build web`), `app/README.md`. Verify against a locally running Laravel API.

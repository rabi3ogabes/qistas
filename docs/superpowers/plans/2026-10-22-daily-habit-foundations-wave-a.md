# Daily-Habit Features, Foundations and Wave A: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the home-screen widget (H3), the end-of-day close (H4) and payday learning (H6), each behind an admin switch that starts off, with the one shared helper they need.

**Architecture:** Each feature is one new case in the existing `Feature` catalogue (so the admin cockpit, plan matrix, "off means off" rules and translations apply unchanged), a small domain class with the rules, thin API controllers, a Blade page where the web needs one, and Flutter screens. The three features are independent of each other after Task 2, so they can be built in parallel. Nothing writes the ledger except the existing actions.

**Tech Stack:** Laravel 13 (PHP 8.4), Pest, Pint, Larastan; Blade with Alpine; Flutter 3.41.2 (Riverpod, go_router, dio); the `home_widget` plugin (0.10.0 resolves with the app's dependencies; checked 22 Oct 2026); Kotlin and XML for the Android widget; mPDF for the closing summary.

**Spec:** `docs/superpowers/specs/2026-10-22-daily-habit-features-design.md` (sections 4 H3, H4, H6 and 5). Approved by the owner on 22 Oct 2026.

## Corrections to the spec, found while reading the code

These are decisions made while planning. Task 1 writes them into the spec so the two documents agree.

| # | Spec said | What the code showed | Plan |
|---|---|---|---|
| C1 | H3 and H4 are "group Core" | `Core` means *always on, switch locked* (`Feature::isCore()`, `launchState()`), so a new feature there could never be switched off | `home_widget` goes in **Collecting**, `day_close` in **Team**, `payday_learning` in **Collecting** |
| C2 | Foundations register eight cases, the AI gateway, deep links for `/receipt`, `/coach`, `/sale`, and instrumentation | A switch for an unbuilt feature invites turning it on; wave A calls no AI; `FeatureUsage::hit()` already exists | Each case is registered **in the task that builds it**. The AI gateway and the other deep links move to the wave B plan. Foundations here is the workspace-day helper (Task 2) |
| C3 | Day close card 2 shows "has proof" | Payment proofs cannot be attached yet (`FileKind::PaymentProof` exists, nothing uses it) | Card 2 lists non-cash payments (method, customer, amount, note). The proof marker arrives with H1 in wave B |
| C4 | A closing time field | Settings have types `switch`, `int`, `select`, `amount` and no time | `day_close.closing_time` is a `select` of half-hour options, default 20:00 |
| C5 | The widget refreshes on a data push "after a payment from another device" | A background Dart handler and a silent push do not exist; every push today carries a notification | Wave A sends **one silent push a day at each person's digest time**, plus refresh on open, resume and payment. Other-device refresh is not built |
| C6 | H6 times reminders the day before the real payday | Reminders are the Coach's job, which is wave B | Wave A ships the pattern, the chip and the suggestions. Timing alerts by payday is in the wave B Coach plan |
| C7 | Day close is "web and app" only in the experience text | The web workspace is a first-class surface | Task 11 adds a small web page |

## Global Constraints

- **Commands** (from `web/CLAUDE.md`): `php vendor/bin/pest`, `php vendor/bin/pint --dirty` (never bare `pint`), `php vendor/bin/phpstan analyse` (level 6); in `app/`: `flutter analyze` (**zero** issues, infos included), `flutter test`. Failures print huge HTML in Pest: run one file at a time, and summarise.
- **Write the failing test first**, see it fail for the right reason, then implement. Real code over mocks.
- **Tenancy:** every tenant-owned model uses `App\Tenancy\BelongsToTenant`; never read or write one without a tenant context; another workspace's record is a 404. Test helpers: `apiOwner(array $tenant = [], array $abilities = ['app']): array{User, Tenant}`, `apiMember(string $role, Tenant $tenant)`, `workspaceOn(string $plan)`, `asTenant($tenant, fn)`, `openContract($tenant)`, `installmentsOfContract($contract)`, `expectConsistentLedger($contract)`, `switchOn(Feature)` / `switchOff(Feature)` (new features ship off, so every test that uses one calls `switchOn` first), `$this->travelTo('2026-10-07 12:00:00')`.
- **Money:** never a float. PHP: `App\Support\Money` (bcmath strings, two decimals). Dart: `lib/core/money.dart` (`Money`, BigInt cents).
- **The ledger is immutable.** Only `RecordPayment`, `VoidTransaction` and `CreateContract` write it. Money-in rows are `Transaction::MONEY_IN = ['payment', 'down_payment', 'reversal']`, selected with the `Transaction::moneyIn()` scope; a void is a `reversal` row with a negative amount.
- **A new `Feature` case** must be added to every exhaustive `match` in `app/Entitlements/Feature.php` (`type`, `label`, `group`, `description`, `offBehaviour`, `dependsOn`, `defaultFor` for `free`), and its `description()` and `offBehaviour()` sentences, and every other new `__('…')` string, must exist in `web/lang/app/{ar,fr,es,ur}.json` (add them, from `web/`, with `python scripts/merge_translations.py batch.json`; `TranslationsTest` fails on any missing or stale string). Never use `FeatureGroup::Core` for a new feature.
- **Every new API route** is described in `docs/api/openapi.yaml` (`tests/Feature/Api/OpenApiTest.php` compares the file with the routes). A feature's routes sit behind the `feature:<key>` middleware, which answers 403 `feature_unavailable` when the platform has it off and 402 when the plan lacks it. **One exception is deliberate:** `GET /api/v1/glance` answers `200 {enabled:false}` instead, so an installed widget can degrade gracefully (Task 3).
- **Settings** are `App\Settings\SettingDefinition` objects registered on the `SettingsRegistry` in `AppServiceProvider::boot()`, either inline (as the morning summary's late limit is) or through a small static `register(SettingsRegistry)` on the feature's own class (as `LateFeePolicy` and `Dates` do); the app's Tools screen and the web Tools page draw their controls from them, so a setting needs no screen of its own.
- **Never** put national IDs, tokens or customer names in logs, audit `changes`, or the widget by default. Use `App\Support\Audit::record(action, subject, changes)`.
- **App strings** are keyed by the English text: `context.t('…')`; after adding any, run `python tool/i18n.py --missing`, write a batch JSON for ar, fr, es, ur, and `python tool/i18n.py batch.json`. Arabic wording must be count-neutral ("الأقساط المستحقة اليوم: :count"); English needs separate singular and plural strings.
- **App tests:** `test/support/harness.dart` (`pumpApp`, `workspaceServer`, `tapText`, `tapButton`, `tapTooltip`, `typeInto`, `openSettings`, `settle`), `test/support/samples.dart`. Use `SingleChildScrollView` for long pages (lazy list children are not in the tree), never `Flexible(flex: 0)`, and re-run a single test with `--plain-name` before believing a cascade of failures. Add each new screen to `test/visual/screens_test.dart` and look at the pictures: `QISTAS_SCREENSHOTS=build/shots/x flutter test test/visual`.
- **Every commit** ends with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`, and is pushed after it is made, through the owner's terminal tool (the shell's own git credentials are the wrong account). Commit after each task.

## Review Focus

The five ways this work is most likely to hurt a person, and where each is pinned by a test.

1. **A day's money in the wrong day.** A payment at 23:50 Riyadh time belongs to that local day even though it is the next UTC day; daylight-saving days are 23 or 25 hours; a void of an old cash payment lowers *today's* expected cash while also touching a *closed* day. (Task 2, Task 7, Task 8)
2. **The widget leaks on a locked screen.** Names never appear unless the owner allowed them; with the app lock on only counts appear; sign-out clears the stored snapshot; a switched-off feature shows "Open Qistas" and reads nothing. (Task 3, Task 5)
3. **Closing a day twice, or editing a closed day quietly.** A double tap or two phones must produce one close; a collector cannot void or back-date into a closed day, and an owner who does leaves an audit row. (Task 8, Task 9)
4. **A payday guess from too little or messy data.** Fewer than three months shows nothing; voided payments, weekly and open contracts, and several payments in one month must not skew it; a failure in the calculation must never fail the payment that triggered it. (Task 13, Task 14)
5. **"Off" that is not off.** With a feature off, its endpoints refuse, its scheduled jobs do nothing (checked at run time, not when queued), the app hides it, and existing records stay. (Tasks 3, 4, 9, 10, 14, every app task)

---

### Task 1: Write the corrections into the spec

**Files:**
- Modify: `docs/superpowers/specs/2026-10-22-daily-habit-features-design.md`

**Interfaces:**
- Produces: the agreed groups (`home_widget` Collecting, `day_close` Team, `payday_learning` Collecting) that Tasks 3, 7 and 13 use.

- [ ] **Step 1: Edit the spec** so it matches section "Corrections to the spec" above: replace "group Core" in H3 and H4 and "group Collecting" stays for H6; add a short **Plan deviations** subsection to section 8 listing C2 to C7 in one line each; change H4's card 2 sentence per C3.
- [ ] **Step 2: Check for contradictions**: `rg "group Core|Core" docs/superpowers/specs/2026-10-22-daily-habit-features-design.md` shows no feature in Core.
- [ ] **Step 3: Commit** `Spec: groups and deviations found while planning wave A`.

---

### Task 2: The workspace-day helper

**Files:**
- Create: `web/app/Support/WorkspaceDay.php`
- Test: `web/tests/Feature/Support/WorkspaceDayTest.php`

**Interfaces:**
- Consumes: `Tenant::localTimezone(): string`, `Tenant::localTime(CarbonInterface): CarbonInterface`.
- Produces (used by Tasks 7, 8, 10, 14):
```php
final readonly class WorkspaceDay
{
    public function __construct(public string $date, public CarbonImmutable $start, public CarbonImmutable $end, public string $timezone) {}
    public static function of(Tenant $tenant, ?CarbonInterface $now = null): self;   // the local day that contains $now
    public static function on(Tenant $tenant, string $date): self;                    // 'Y-m-d' in the workspace's clock
    public function contains(CarbonInterface $moment): bool;                          // start <= moment < end
    public function previous(): self;
    public function atTime(string $hhmm): CarbonImmutable;                            // that wall-clock time on this day, as an instant
}
```
`start` and `end` are UTC instants; `end` is exclusive.

- [ ] **Step 1: Write the failing tests** in `WorkspaceDayTest.php`:
  - `it finds the local day a moment belongs to`: a `Asia/Riyadh` workspace, 20:30 UTC on 21 Oct is 23:30 local on the 21st; 21:30 UTC is 00:30 local on the 22nd (assert `->date`).
  - `it spans 23 hours when the clocks go forward and 25 when they go back`: `America/New_York`, 8 Mar 2026 and 1 Nov 2026 (assert `$start->diffInHours($end)`).
  - `it treats the end as exclusive`: `contains($end)` is false, `contains($start)` true.
  - `it steps back across a month boundary`: `previous()` of 1 Nov is 31 Oct.
  - `it turns a wall-clock time into an instant`: `atTime('20:00')` in Riyadh is 17:00 UTC.
  - `it follows the country's zone when the workspace has none stored`.
- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/Support/WorkspaceDayTest.php`. Expected: FAIL, class not found.
- [ ] **Step 3: Implement `WorkspaceDay`**: build the local start with `CarbonImmutable::parse($date, $tz)->startOfDay()` and the end as the next local midnight, both converted to UTC; `of()` uses `$tenant->localTime($now)` for the date.
- [ ] **Step 4: Run the tests.** Expected: PASS. Run `vendor/bin/pint --dirty` and `vendor/bin/phpstan analyse`.
- [ ] **Step 5: Commit** `Add WorkspaceDay: the workspace's own calendar day as UTC bounds`.

---

## Wave A, H3: the home-screen widget

### Task 3: The `home_widget` feature and `GET /api/v1/glance`

**Files:**
- Modify: `web/app/Entitlements/Feature.php`, `web/routes/api.php`, `docs/api/openapi.yaml`
- Create: `web/app/Glance/GlanceSnapshot.php`, `web/app/Http/Controllers/Api/V1/GlanceController.php`
- Modify: `web/lang/app/{ar,fr,es,ur}.json` (via `scripts/merge_translations.py`)
- Test: `web/tests/Feature/Api/GlanceTest.php`

**Interfaces:**
- Consumes: `App\Notifications\Alerts\AlertQueries::totals(CarbonInterface $today, int $lateAfterDays)` (keys `due_count`, `due_amount`, `late_count`, `late_amount`), `DashboardMetrics::dueTodayList(Tenant, CarbonInterface)` and `briefing(Tenant)['upcoming']` for the next instalments, `FeatureUsage::hit()`.
- Produces: `Feature::HomeWidget` (`'home_widget'`, Toggle, group Collecting; Free enabled, Pro enabled), and
```php
final class GlanceSnapshot {
    /** @return array{enabled: true, currency: string, due_count: int, due_amount: string, late_count: int, late_amount: string,
     *     next: list<array{reference: string, amount: string, due_on: string, name?: string}>, generated_at: string} */
    public static function for(Tenant $tenant, bool $names, ?CarbonInterface $now = null): array;
}
```
`next` is the first three of: due today (oldest first), then coming up this week; `name` appears only when `$names` is true. The endpoint is `GET /api/v1/glance?names=0|1`, outside the `feature:` middleware, answering `{"data": {"enabled": false}}` when the feature is not enabled for the workspace (platform off or plan lacks it).

- [ ] **Step 1: Write the failing tests** in `GlanceTest.php` (all use `switchOn(Feature::HomeWidget)` except where stated):
  - `it returns today's counts and amounts exactly as the morning summary counts them` (fixture with due and late instalments; compare with `AlertQueries::totals`).
  - `it never includes a customer's name unless asked`, and `it includes names when names=1`.
  - `it answers enabled false and nothing else when the platform switch is off` (no `switchOn`).
  - `it answers enabled false when the plan lacks it` (`Plan::setFeature(HomeWidget, false)` on Free).
  - `it only counts this workspace's instalments` (second tenant with a due instalment).
  - `it reads in a handful of queries` (assert query count ≤ 8 with `DB::enableQueryLog()`).
  - `it needs a token` (401) and `it works for a viewer`.
  - `it counts one use per read` (`feature_usage_daily` hits for the day).
- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/Api/GlanceTest.php`. Expected: FAIL, route not found.
- [ ] **Step 3: Add the case** to every match in `Feature.php`: Toggle; label `__('Home-screen widget')`; description (one sentence: today's money on the phone's home screen, counts and amounts only unless the owner allows names); offBehaviour (an installed widget shows "Open Qistas" and stops reading; nothing is deleted); `dependsOn` `[]`; Free default enabled. Write the three new strings into the four language files.
- [ ] **Step 4: Implement `GlanceSnapshot` and `GlanceController`** (invokable); register `Route::get('glance', GlanceController::class)->name('glance')` inside the authenticated API group, not under `feature:`; describe it in `openapi.yaml`.
- [ ] **Step 5: Run** the glance tests, `tests/Feature/Api/OpenApiTest.php`, `tests/Feature/TranslationsTest.php`, `tests/Feature/Entitlements/FeatureCatalogueTest.php`. Expected: PASS. Then pint and phpstan.
- [ ] **Step 6: Commit** `Add the home-screen widget feature and its glance endpoint`.

---

### Task 4: The silent morning refresh push

**Files:**
- Modify: `web/app/Notifications/Push/PushMessage.php`, `web/app/Notifications/Push/FcmPushSender.php`, `web/app/Notifications/Push/LogPushSender.php`, `web/routes/console.php`
- Create: `web/app/Notifications/Alerts/GlanceRefresh.php`, `web/app/Console/Commands/SendGlanceRefreshCommand.php`
- Test: `web/tests/Feature/Notifications/GlanceRefreshTest.php`

**Interfaces:**
- Consumes: `Recipients::of(Tenant)`, `Recipients::isTime(string $time, CarbonInterface $local)`, `Preferences::forUsers()` (the person's `daily_digest.time`), `PushToken` (active tokens: `user_id`, not revoked), `Entitlements::for($tenant)->check(Feature::HomeWidget)->enabled()`.
- Produces: `PushMessage::silent(string $type, array $data = []): PushMessage` (public readonly `bool $silent`), and `GlanceRefresh::run(Tenant $tenant, CarbonInterface $now): int` (people told); command `qistas:send-glance-refresh`, scheduled every five minutes with `withoutOverlapping(10)`.

- [ ] **Step 1: Write the failing tests** in `GlanceRefreshTest.php` (`Http::fake()` for FCM; `travelTo`):
  - `it sends one silent push to each phone at the person's digest time and not before`.
  - `it never sends twice on the same day` (use `Cache::add('glance:{user}:{date}', …, 36h)` as the guard; assert the second run returns 0).
  - `it does nothing when the feature is off` (checked at run time: queue nothing, send nothing).
  - `it sends no notification block` (the FCM payload for a silent message has `data.type = glance.refresh`, `android.priority = high`, and **no** `notification` key).
  - `it leaves no inbox entry` (`AppNotification` count unchanged).
  - `it skips revoked phones and other workspaces' people`.
- [ ] **Step 2: Run** the file. Expected: FAIL, class not found.
- [ ] **Step 3: Implement.** `PushMessage::silent` sets `silent: true` with empty title and body; `FcmPushSender` omits `notification` and adds `apns.payload.aps.content-available = 1` for silent messages; `LogPushSender` logs the type only. `GlanceRefresh` mirrors `DailyDigest::run` (per workspace clock, per person time, `Cache::add` guard) but sends straight through `PushSender` to each active token. The command mirrors `SendDigestsCommand` (active, non-demo tenants). Add the schedule line with a one-line comment.
- [ ] **Step 4: Run** the new tests and `tests/Feature/Notifications`. Expected: PASS. Pint, phpstan.
- [ ] **Step 5: Commit** `Send one silent refresh push a day so the widget is fresh before the owner opens the app`.

---

### Task 5: The app side of the widget: snapshot, privacy, bridge

**Files:**
- Modify: `app/pubspec.yaml` (add `home_widget: ^0.10.0`), `app/lib/data/qistas_api.dart`, `app/lib/app/shell.dart` (refresh hooks), `app/lib/app/router.dart` (route `/settings/widget`), `app/lib/features/settings/settings_screen.dart` (a row, shown only when the feature is on), `app/assets/i18n/*.json`
- Create: `app/lib/features/glance/glance_data.dart`, `glance_encoder.dart`, `glance_store.dart`, `glance_service.dart`, `glance_privacy.dart`, `app/lib/features/settings/widget_settings_screen.dart`
- Test: `app/test/features/glance_test.dart`

**Interfaces:**
- Consumes: `QistasApi` (add `Future<GlanceData> glance({required bool names})` for `GET /glance`), `AppLockState` / the app-lock controller in `lib/features/security/app_lock.dart` (`enabled`, `required`), `accountProvider` (`entitlements['home_widget']?.isOn`), `sharedPreferencesProvider`, the language provider and `Translations`.
- Produces:
```dart
class GlanceData { final bool enabled; final String currency; final int dueCount; final Money dueAmount; final int lateCount; final Money lateAmount; final List<GlanceItem> next; final DateTime generatedAt; static GlanceData fromJson(Map<String, dynamic>); }
class GlancePrivacy { final bool showAmounts; final bool showNames; }          // stored in prefs: glance.show_amounts (default true), glance.show_names (default false)
Map<String, String> encodeGlance(GlanceData data, {required GlancePrivacy privacy, required bool appLockOn, required String language, required bool rtl, required Translations words, required DateTime now});
abstract class GlanceStore { Future<void> write(Map<String, String> values); Future<void> clear(); Future<void> redraw(); }   // HomeWidgetStore for the phone, a fake in tests
class GlanceService { Future<void> refresh(); Future<void> clear(); }          // via glanceServiceProvider
```
**The contract with the Kotlin widget (Task 6)** is exactly these keys, all strings, already formatted in the app's language so the native code needs no translations: `enabled` (`"1"`/`"0"`), `rtl` (`"1"`/`"0"`), `due_line`, `late_line`, `amount_line` (empty when amounts are hidden), `next_1`, `next_2`, `next_3` (empty when names and amounts are hidden or there is none), `updated` (a time such as "09:05"), `updated_epoch_ms`.

Privacy rules (the encoder is the only place they are applied): **app lock on ⇒ counts only** (no amounts, no names); otherwise amounts show unless `showAmounts` is false; names show only when `showNames` is true *and* amounts show *and* the app lock is off; the request to the server asks for `names` only in that same case.

- [ ] **Step 1: Write the failing tests** in `glance_test.dart`:
  - encoder matrix: `shows counts and amounts by default and no names`; `shows counts only when the app lock is on`; `hides amounts when the owner chose to`; `shows names only when allowed, amounts shown, lock off`; `marks right-to-left for Arabic and Urdu`; `writes the updated time in the app's language`.
  - service: `writes the snapshot after a successful read`; `asks the server for names only when the privacy rules allow`; `writes the disabled state when the server says enabled false`; `keeps the last snapshot when offline` (no write, no clear); `clears everything on sign-out`.
  - triggers: `refreshes after the dashboard loads, after a payment is recorded and on resume`.
  - settings: `the widget row appears only when the feature is on`; `the two switches save to this phone`; `the names switch is disabled with a reason while the app lock is on`.
- [ ] **Step 2: Run** `flutter test test/features/glance_test.dart`. Expected: FAIL.
- [ ] **Step 3: Implement** the files above. `HomeWidgetStore` uses `HomeWidget.saveWidgetData` and `HomeWidget.updateWidget` with the provider's Android name `GlanceWidgetProvider` (Task 6). Hook `refresh()` where `dashboardProvider` completes, in `payment_success.dart`'s success path, and in the resume branch of `AppShell`; call `clear()` where sign-out clears the token. Add the strings (for example "Home-screen widget", "Show amounts", "Show customer names", "Names stay hidden while the app lock is on", "Open Qistas to refresh", "Due today: :count", "Late: :count") and translate them.
- [ ] **Step 4: Run** the new tests, `flutter analyze` (zero issues), and `python tool/i18n.py --sync`. Expected: PASS and clean.
- [ ] **Step 5: Commit** `App: the widget's snapshot, its privacy rules and the settings to change them`.

---

### Task 6: The Android widget and the background refresh

**Files:**
- Create: `app/android/app/src/main/kotlin/com/qistas/qistas/GlanceWidgetProvider.kt`; `app/android/app/src/main/res/layout/glance_small.xml`, `glance_small_rtl.xml`, `glance_medium.xml`, `glance_medium_rtl.xml`; `res/xml/glance_small_info.xml`, `glance_medium_info.xml`; `res/drawable/glance_background.xml`; widget strings and brand colours in `res/values/` and `res/values-night/`; `app/lib/core/push/background.dart`, `app/lib/features/glance/glance_refresher.dart`; `docs/features/home-widget.md`
- Modify: `app/android/app/src/main/AndroidManifest.xml` (two receivers), `app/lib/main.dart` (register the background handler), `app/lib/app/router.dart` or the shell (open Today when launched from the widget)
- Test: `app/test/features/glance_refresher_test.dart`

**Interfaces:**
- Consumes: the Task 5 key contract; `HomeWidgetProvider` from the plugin; the app's secure token store and the same Dio client the providers build (read how `apiProvider` constructs it in `lib/app/providers.dart` and `lib/core/api`, and build it without Riverpod).
- Produces: `class GlanceRefresher { Future<bool> run(); }` (reads the token, calls `GET /glance`, writes with the same encoder and privacy as the app, redraws; returns whether it wrote) and a top-level `@pragma('vm:entry-point') Future<void> qistasBackgroundMessage(RemoteMessage message)` that runs the refresher only when `message.data['type'] == 'glance.refresh'`.

Design notes the implementer must follow: native views are `RemoteViews` fed only from the stored strings (no network and no translations in Kotlin); two layouts per size chosen by `rtl`; states are *normal*, *disabled* ("Open Qistas"), and *stale* (updated more than 12 hours ago: show the muted line "Open Qistas to refresh"); light and dark colours from the brand tokens (navy, ivory, gold); amounts in the generic `serif` family to echo the brand; the whole widget is one tap target that opens Today through the plugin's launch intent (`HomeWidget.initiallyLaunchedFromHomeWidget()` and `widgetClicked`, routing to `/`); a content description reads the lines aloud; `updatePeriodMillis` is 30 minutes (Android may delay it; the "updated" time is the honest signal).

- [ ] **Step 1: Write the failing tests** in `glance_refresher_test.dart`: `it fetches, encodes with the saved privacy and writes`; `it does nothing without a token`; `it writes the disabled state when the server says so`; `it never logs the token or any name`; `it ignores a message that is not glance.refresh` (the background function); `it survives a network error without clearing the last snapshot`.
- [ ] **Step 2: Run** them. Expected: FAIL.
- [ ] **Step 3: Implement** the refresher and `background.dart`; register the handler in `main.dart` with `FirebaseMessaging.onBackgroundMessage(qistasBackgroundMessage)` (guard for no-Firebase builds exactly as `FirebasePushService` does, and call `DartPluginRegistrant.ensureInitialized()` first).
- [ ] **Step 4: Write the native widget** (Kotlin, layouts, info files, manifest receivers) against the key contract. Keep the Kotlin small: read the keys, pick the layout, set text, set the click intent.
- [ ] **Step 5: Write `docs/features/home-widget.md`**: what the admin switch does, what the widget shows and never shows, the privacy rules, and a **manual device checklist** (add small and medium; resize; dark mode; Arabic; app lock on then off; airplane mode; sign out; switch the feature off in the cockpit and see "Open Qistas"; wait for the morning push).
- [ ] **Step 6: Verify what can be verified here.** Run the new Dart tests and `flutter analyze`. The Kotlin and XML cannot be compiled locally (Android licences are not accepted on this machine): **push and watch the `Mobile app` workflow's Android job** until it is green, and report that the widget itself still needs the owner's phone.
- [ ] **Step 7: Commit** `Android: the home-screen widget and its silent background refresh`.

---

## Wave A, H4: end-of-day close

### Task 7: The `day_close` feature, its table and the day's totals

**Files:**
- Create: `web/database/migrations/2026_10_22_000100_create_day_closes.php`, `web/app/Models/DayClose.php`, `web/app/Domain/DayClose/DayTotals.php`
- Modify: `web/app/Entitlements/Feature.php`, `web/app/Providers/AppServiceProvider.php` (register the setting), `web/lang/app/*.json`
- Test: `web/tests/Feature/DayClose/DayTotalsTest.php`

**Interfaces:**
- Consumes: `WorkspaceDay` (Task 2), `Transaction::MONEY_IN` and the `moneyIn()` scope, `App\Models\User`, `App\Models\LateFee` (`status`, `waived_at`, `waived_by_user_id`), `Contract` (`created_at`, `cancelled_at`).
- Produces: `Feature::DayClose` (`'day_close'`, Toggle, group **Team**, Free and Pro enabled); setting `day_close.closing_time` (`select`, options `17:00` … `23:30` in half hours, default `'20:00'`, feature `DayClose`); table `day_closes`; `DayClose` model; and
```php
final class DayTotals {
    /** @return array{day: string, expected_cash: string, by_method: array<string,string>, count: int,
     *     per_member: array<string, array{name: string, cash: string, count: int}>,
     *     non_cash: list<array{id: string, customer: string, amount: string, method: string, note: ?string}>,
     *     watch: list<array{kind: 'void'|'waived_fee'|'back_dated'|'opened'|'cancelled', id: string, label: string, amount: ?string}>} */
    public static function for(Tenant $tenant, WorkspaceDay $day): array;
}
```
`day_closes` columns: `id` uuid, `tenant_id`, `day` date, `version` unsigned small integer default 1, `status` string `closed|reopened`, `expected_cash`, `counted_cash`, `difference` (decimal 18,4; difference = counted − expected), `totals` json (by_method, count, per_member), `note` text null, `closed_by_user_id`, `closed_at`, `reopened_by_user_id` null, `reopened_at` null, `reopen_reason` text null, `snapshot_hash` char(64), timestamps; unique `(tenant_id, day, version)`; index `(tenant_id, day)`. The model refuses to update any column except the three `reopen_*` columns and `status`.

- [ ] **Step 1: Write the failing tests** in `DayTotalsTest.php` (`travelTo`; a Riyadh workspace and, for DST, a New York one):
  - `expected cash is the cash taken today minus the cash voided today`.
  - `it ignores card and bank transfers in the cash figure but lists them` (`non_cash`).
  - `it counts down payments as money in` and `it excludes an open contract's charges`.
  - `it uses the workspace's day, not UTC` (23:50 local on the 21st counts for the 21st).
  - `a void today of yesterday's cash lowers today's expected cash`.
  - `per-member totals add up to the day's cash`.
  - `it lists voids, waived fees, back-dated payments, contracts opened and contracts cancelled in watch`.
  - `an empty day returns zeros and empty lists` and `another workspace's money is never counted`.
  - a model test: `the closing row cannot be edited except to reopen it`.
- [ ] **Step 2: Run** the file. Expected: FAIL.
- [ ] **Step 3: Implement.** Add the case to every match (label `__('End-of-day close')`; description: a short evening ritual that compares counted cash with what was recorded and keeps a signed-off summary; offBehaviour: no new closes or reminders, closed days and their summaries stay readable); register the setting (inline in `AppServiceProvider::boot()` or through a small static `register()`); write the migration (runs on SQLite and PostgreSQL, additive); implement `DayTotals` with one query per section, grouped in SQL.
- [ ] **Step 4: Run** the tests, `FeatureCatalogueTest`, `TranslationsTest`; add the new strings to the four languages. Pint, phpstan.
- [ ] **Step 5: Commit** `Add end-of-day close: the table, the setting and the day's totals`.

---

### Task 8: Closing, reopening and the after-close rule

**Files:**
- Create: `web/app/Actions/CloseDay.php`, `web/app/Actions/ReopenDay.php`, `web/app/Domain/DayClose/DayCloseGuard.php`, `web/app/Policies/DayClosePolicy.php`
- Modify: `web/app/Actions/RecordPayment.php` (`handle` and `settle`), `web/app/Actions/VoidTransaction.php`, and the action that takes one payment across a customer's contracts (find it with `rg "batchId" app`)
- Test: `web/tests/Feature/DayClose/CloseDayTest.php`, `web/tests/Feature/DayClose/AfterCloseTest.php`

**Interfaces:**
- Consumes: `DayTotals`, `WorkspaceDay`, `TenantRole::canWrite()` / `canDelete()`, `Audit::record`.
- Produces:
```php
CloseDay::handle(Tenant $tenant, WorkspaceDay $day, string $countedCash, ?string $note, User $by): DayClose
ReopenDay::handle(DayClose $close, string $reason, User $by): DayClose
DayCloseGuard::isClosed(Tenant $tenant, WorkspaceDay $day): bool
DayCloseGuard::assertMayChange(Tenant $tenant, CarbonInterface $moment, ?User $by): void
```
Rules: `CloseDay` accepts today or any of the previous seven days that is not already closed; a future day, a second close of the same day, and a negative or over-precise counted amount are `ValidationException` (field `day` or `counted_cash`); it locks the latest version row so two simultaneous closes produce one; `snapshot_hash` is the SHA-256 of the canonical JSON of day, version, expected, counted and totals; audit `day_close.closed`. `ReopenDay` needs `canDelete()` (owner or manager), a reason of at least 5 characters, sets `status = reopened` and the three `reopen_*` columns, audit `day_close.reopened`; a new close of that day then gets `version + 1`. `assertMayChange` does nothing when the day containing `$moment` is not closed; when it is closed, a user without `canDelete()` gets a `ValidationException` (field `paid_at` or `transaction`, message `__('That day is closed. Ask the owner or a manager.')`), and an owner or manager is allowed and an audit row `day_close.change_after_close` records the day and what was done. Every entry point that writes money calls it with the payment's `paid_at` (`RecordPayment::handle`, `::settle`, the across-contracts action) or the original payment's `paid_at` (`VoidTransaction::handle`).

- [ ] **Step 1: Write the failing tests.** `CloseDayTest.php`: `it closes today with counted cash and records the difference`; `it refuses a second close of the same day`; `two simultaneous closes make one`; `it refuses a future day and one more than seven days back`; `it rejects a negative or three-decimal amount`; `a collector may close, a viewer may not`; `only an owner or manager may reopen, with a reason`; `a reopened day can be closed again as version 2`; `the hash changes when any figure changes`; `it writes audit rows`. `AfterCloseTest.php`: `a collector cannot record a payment dated on a closed day`; `an owner can, and an audit row is written`; `a collector cannot void a payment from a closed day`; `voiding today's payment on an open day is unaffected`; `early settlement and the across-contracts payment obey the same rule`; `reopening the day allows the change again`; `expectConsistentLedger still holds after an owner's after-close entry`.
- [ ] **Step 2: Run** both files. Expected: FAIL.
- [ ] **Step 3: Implement** the actions, the guard and the policy; add the guard calls in the four entry points **before any write**, inside the existing transaction where one is open.
- [ ] **Step 4: Run** the new tests and the whole `tests/Feature/Payments`, `tests/Feature/Contracts`, `tests/Feature/Api/PaymentsTest.php` (the guard must change nothing for an open day). Pint, phpstan.
- [ ] **Step 5: Mutation check:** change the guard's `canDelete()` test to `true` and confirm `a collector cannot void a payment from a closed day` fails; restore.
- [ ] **Step 6: Commit** `Close and reopen a day, and guard changes to a closed day`.

---

### Task 9: The day-close API and the summary PDF

**Files:**
- Create: `web/app/Http/Controllers/Api/V1/DayCloseController.php`, `web/app/Http/Resources/DayCloseResource.php`, `web/app/Http/Requests/CloseDayRequest.php`, `web/app/Documents/Templates/DayCloseSummary.php`, `web/resources/views/documents/day-close-summary.blade.php`
- Modify: `web/routes/api.php`, `docs/api/openapi.yaml`, `web/resources/views/site/verify.blade.php` (add the kind `day_close` => `__('Day summary')`), the kind lists in the docblocks of `Template.php` and `Models/Document.php`, `web/lang/app/*.json`
- Test: `web/tests/Feature/Api/DayCloseTest.php`

**Interfaces:**
- Consumes: Tasks 7 and 8, `DocumentService::issue(Template, DocumentOptions, Tenant, User)` (model the controller on `SendsDocuments::transactions()`), `DocumentOptions`.
- Produces (all under `feature:day_close`, throttled like the other money routes):
  - `GET /api/v1/day-close/today` → `{data: {day, closing_time, closed, close: DayCloseResource|null, expected_cash, by_method, count, per_member, non_cash, watch, can_close, can_reopen}}`
  - `POST /api/v1/day-close` body `{counted_cash, note?, day?}` → 201 `DayCloseResource` with `summary_text` (one localized sentence the person can share, for example "Al-Fares, 21 Oct: collected SAR 8,420 (cash 3,100, other 5,320), 14 payments, difference 0.")
  - `POST /api/v1/day-close/{close}/reopen` body `{reason}`
  - `GET /api/v1/day-close` (history, newest first, paginated like the other lists)
  - `GET /api/v1/day-close/{close}/summary.pdf` (counts against the PDF allowance through `DocumentService`)
  - `DayCloseSummary` implements `Template` with `kind() = 'day_close'`, `reference()` the day, `total()` the day's total money in.

- [ ] **Step 1: Write the failing tests** in `DayCloseTest.php`: `today shows expected cash, the other payments and what to look at`; `a collector closes the day and gets a shareable sentence`; `a viewer is refused`; `only an owner or manager can reopen`; `closing twice is a validation error, not a second row`; `history is newest first and paginated`; `it answers 403 feature_unavailable when the platform switch is off`; `it answers 402 when the plan lacks it`; `the summary PDF opens, shows the day's figures, and counts one unit of the PDF allowance`; `asking for the same PDF again within ten minutes does not count twice`; `the verify page names it a day summary`; `summary_text is in Arabic when the request asks for Arabic`; `one workspace never sees another's closes`.
- [ ] **Step 2: Run** it. Expected: FAIL.
- [ ] **Step 3: Implement** controller, resource, request and template; routes; OpenAPI paths and schemas; translations.
- [ ] **Step 4: Run** `DayCloseTest.php`, `OpenApiTest.php`, `TranslationsTest.php`, `tests/Feature/Documents`. Pint, phpstan.
- [ ] **Step 5: Commit** `Day close: the API and the signed summary PDF`.

---

### Task 10: The day-close notifications

**Files:**
- Modify: `web/app/Notifications/Catalog.php`, `web/app/Notifications/Preferences.php`, `web/app/Actions/CloseDay.php`, `web/routes/console.php`, `web/lang/app/*.json`
- Create: `web/app/Notifications/Alerts/DayCloseReminders.php`, `web/app/Console/Commands/SendDayCloseRemindersCommand.php`
- Test: `web/tests/Feature/Notifications/DayCloseNotificationsTest.php`

**Interfaces:**
- Consumes: `Notifier::notify(User, PushMessage, array $covers)` and `Notifier::decided()`, `Recipients::of()`, `Preferences::forUsers()`, `Preferences::isQuiet()`, `WorkspaceDay`, `TenantSettings::for($tenant)->get('day_close.closing_time')`, `DayCloseGuard::isClosed`, `DayTotals::for`.
- Produces: two kinds in `Catalog::KINDS`, `day_close_reminder` and `day_close_difference` (group `alerts`, default channels in the app only), their `Preferences::DEFAULTS` (enabled) and labels and descriptions; `DayCloseReminders::run(Tenant $tenant, CarbonInterface $now): int`; command `qistas:send-day-close-reminders` scheduled every five minutes; push `data.route = '/day-close'`.

Rules: a reminder goes to each recipient who may close, from the workspace's closing time for two hours, once a day per person (dedupe key `day_close_reminder:{user}:{date}`), only when the feature is on **at run time**, the day is not closed, and the day has any money in or anything to look at (a quiet day says nothing; record the skip as the digest does); the person's quiet hours and their switch are respected. When `CloseDay` records a non-zero difference and the closer is not an owner, each owner gets `day_close_difference` once (key `day_close_difference:{close id}`).

- [ ] **Step 1: Write the failing tests:** `it reminds each person who can close at the closing time and not before`; `it does not remind after two hours`; `it does not remind twice`; `it says nothing on a day with no money and nothing to look at`; `it does nothing when the feature is off`; `it does not remind once the day is closed`; `it respects a person's switch and quiet hours`; `it uses the workspace's closing time setting`; `a close with a difference by a collector notifies the owner once`; `a balanced close notifies nobody`; `the push opens /day-close`; `a viewer is never reminded`.
- [ ] **Step 2: Run** the file. Expected: FAIL.
- [ ] **Step 3: Implement**, add the strings to the four languages, add the schedule line.
- [ ] **Step 4: Run** the new tests, `tests/Feature/Notifications`, `TranslationsTest`. Pint, phpstan.
- [ ] **Step 5: Commit** `Remind the team to close the day, and tell the owner about a difference`.

---

### Task 11: The day-close page on the web

**Files:**
- Create: `web/app/Http/Controllers/Workspace/DayCloseController.php`, `web/resources/views/app/day-close/show.blade.php`
- Modify: `web/routes/app.php`, `web/app/Support/AppNav.php`, `web/resources/views/app/dashboard.blade.php` (a "Close the day" link), `web/lang/app/*.json`
- Test: `web/tests/Feature/App/DayCloseWebTest.php`

**Interfaces:**
- Consumes: Tasks 7 to 9 (call the same actions and `DayTotals`; do not duplicate rules).
- Produces: routes `app.day-close.show` (GET `day-close`: today's figures and the history), `app.day-close.store` (POST), `app.day-close.reopen` (POST `day-close/{close}/reopen`), `app.day-close.summary` (GET `day-close/{close}/summary.pdf`), all inside the `feature:day_close` group; a navigation entry that appears only when the feature is on for the workspace.

Page shape (follow the workspace's existing components and styling): a heading with the date and a closed seal when closed; three sections (Cash with a counted-amount field and the difference in words; Other payments; Worth a look); a "Close the day" button; after closing, the shareable sentence with a copy button and the PDF link; history below. Money in the page comes from the shared server data, never recomputed in the view.

- [ ] **Step 1: Write the failing tests:** `it shows today's expected cash, the other payments and what to look at`; `it closes the day with a counted amount and then shows it closed`; `a second submit does not close it twice`; `a viewer is refused`; `the navigation entry appears only when the feature is on`; `it is not reachable when the feature is off`; `an owner can reopen with a reason`; `the page is the workspace's own` (isolation).
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement**, add strings and translations.
- [ ] **Step 4: Run** the new tests and `tests/Feature/App`. Look at the page in the built-in browser against the local server (`.claude/launch.json`, `npm run build` first), in English and Arabic, light and dark, and at phone width; fix what looks wrong in one batch.
- [ ] **Step 5: Commit** `Day close on the web`.

---

### Task 12: Day close in the app

**Files:**
- Create: `app/lib/data/day_close.dart`, `app/lib/features/day_close/day_close_screen.dart`, `day_close_history_screen.dart`, `day_close_state.dart`
- Modify: `app/lib/data/qistas_api.dart`, `app/lib/app/router.dart` (`/day-close`, `/day-close/history`), `app/lib/features/dashboard/dashboard_screen.dart` (a tile when the feature is on), `app/assets/i18n/*.json`, `app/test/visual/screens_test.dart`
- Test: `app/test/features/day_close_test.dart`

**Interfaces:**
- Consumes: Task 9's endpoints; `Account.entitlements['day_close']?.isOn`; `Account.role` (`'owner'`, `'manager'` may reopen); `Money`; the luxe widgets (`HeroPanel`, `CountUpMoney`, `SoftChip`); `showPaymentSuccess`'s haptics pattern.
- Produces: models `DayCloseToday`, `DayCloseRecord`; API methods `dayCloseToday()`, `closeDay({countedCash, note})`, `reopenDay(id, reason)`, `dayCloseHistory()`; the screens.

Experience to build: three calm cards (Cash with an on-screen number pad and the difference in a plain tone: balanced, short by, over by; Other payments; Worth a look), a sticky **Close the day** button enabled once a counted amount is entered (or "No cash today"); a success moment with a seal and `HapticFeedback.mediumImpact()`; the share sheet with `summary_text`; a closed day shows the seal and no number pad, and owners and managers see **Reopen** (a reason sheet); a history list. Offline shows the standard offline message; nothing is queued. A tile on Today says "Close the day" and is quietly emphasised after the closing time.

- [ ] **Step 1: Write the failing tests:** `it shows the expected cash and the other payments`; `it shows the difference with the right wording as digits are typed` (balanced, short, over); `Close the day calls the server once even on a double tap`; `a closed day shows the seal and no number pad`; `reopen asks for a reason and is offered only to owners and managers`; `the tile is hidden when the feature is off and for a viewer`; `the screen lays out at 200% text in English and Arabic without overflow`; `the tap on a push opens /day-close`.
- [ ] **Step 2: Run** `flutter test test/features/day_close_test.dart`. Expected: FAIL.
- [ ] **Step 3: Implement**; add strings and translate (count-neutral Arabic); add the screens to the visual set (English and Arabic, balanced and short).
- [ ] **Step 4: Run** the tests, `flutter analyze` (zero issues), `python tool/i18n.py --sync`, and the visual capture; look at the pictures once, fix as a batch, confirm once.
- [ ] **Step 5: Commit** `App: close the day`.

---

## Wave A, H6: learn the real payday

### Task 13: The `payday_learning` feature and the calculator

**Files:**
- Create: `web/database/migrations/2026_10_22_000200_create_customer_pay_patterns.php`, `web/app/Models/CustomerPayPattern.php`, `web/app/Domain/PayPattern/PayPattern.php`, `web/app/Domain/PayPattern/PayPatternCalculator.php`
- Modify: `web/app/Entitlements/Feature.php`, `web/lang/app/*.json`
- Test: `web/tests/Feature/PayPattern/PayPatternCalculatorTest.php`

**Interfaces:**
- Produces: `Feature::PaydayLearning` (`'payday_learning'`, Toggle, group **Collecting**, Free **off**, Pro on); table `customer_pay_patterns` (`id` uuid, `tenant_id`, `customer_id` unique per tenant, `day` tinyint, `spread` tinyint, `months` tinyint, `within` tinyint (percent), `confidence` string `high|medium`, `computed_at`); and
```php
final readonly class PayPattern { public function __construct(public int $day, public int $spread, public int $months, public int $within, public string $confidence) {} public function endOfMonth(): bool; }
final class PayPatternCalculator {
    /** @param list<CarbonInterface> $paidOn the local dates of a customer's instalment payments @return ?PayPattern null when there is not enough to say */
    public static function calculate(array $paidOn): ?PayPattern;
}
```
Algorithm (fixed so the tests can be exact): keep the **first** payment of each calendar month; use the last 12 months that have one; need **at least 3 months**; map each day to `min(day, 28)` (so 28 to 31 all mean "the end of the month"); `day` = the median (for an even count the lower middle); `spread` = Q3 − Q1 by the nearest-rank method; `within` = the percent of months whose day is within 3 of the median; **high** = months ≥ 4 and spread ≤ 3 and within ≥ 75; **medium** = months ≥ 3 and spread ≤ 5 and within ≥ 60; otherwise null. `endOfMonth()` is true when `day` is 28.

- [ ] **Step 1: Write the failing tests:** `a tight cluster on the 27th is high confidence, day 27`; `two months say nothing`; `three consistent months are medium`; `scattered payments say nothing`; `payments on the 29th, 30th, 31st and 28th mean the end of the month`; `partial early payments count by the first payment in a month`; `several payments in one month count once`; `one outlier does not move the median`; `only the last twelve months count`; `an even count takes the lower middle`; plus a catalogue case: the new feature passes `FeatureCatalogueTest`.
- [ ] **Step 2: Run** the file. Expected: FAIL.
- [ ] **Step 3: Implement** the case (label `__('Payday learning')`; description: notices when each customer actually pays and suggests dates and nudges around it, never changing anything by itself; offBehaviour: suggestions disappear and patterns stop updating, stored patterns stay), the migration, the model, the value object and the calculator. Translate the new strings.
- [ ] **Step 4: Run** the tests, `FeatureCatalogueTest`, `TranslationsTest`. Pint, phpstan.
- [ ] **Step 5: Commit** `Payday learning: the feature, its table and the calculator`.

---

### Task 14: Keeping the patterns current

**Files:**
- Create: `web/app/Domain/PayPattern/RecomputePayPattern.php`, `web/app/Console/Commands/LearnPaydaysCommand.php`
- Modify: `web/app/Actions/RecordPayment.php`, `web/app/Actions/VoidTransaction.php`, `web/routes/console.php`
- Test: `web/tests/Feature/PayPattern/RecomputeTest.php`

**Interfaces:**
- Consumes: `PayPatternCalculator`, `WorkspaceDay`/`Tenant::localTime`, `Entitlements::for($tenant)->check(Feature::PaydayLearning)->enabled()`.
- Produces: `RecomputePayPattern::handle(Customer $customer): void` (loads the customer's payment dates: `Transaction` type `payment`, **not reversed by a later reversal row**, on contracts that are not open and whose frequency is monthly, `paid_at` converted to the workspace's local date; runs the calculator; upserts the row or deletes it when the result is null; does nothing when the feature is off); command `qistas:learn-paydays` (daily, active non-demo tenants, chunked, idempotent).

The hooks in `RecordPayment` and `VoidTransaction` call it through `DB::afterCommit()` and **wrap it so any exception is reported and swallowed** (`report($e)`): a calculation problem must never fail or delay a payment.

- [ ] **Step 1: Write the failing tests:** `a payment recomputes the customer's pattern`; `a void recomputes it, and the pattern disappears when fewer than three months remain`; `voided payments are excluded`; `weekly and open contracts are ignored`; `a payment at 23:30 UTC on the 26th counts as the 27th in Riyadh`; `with the feature off nothing is written, even by the nightly command`; `an exception in the recompute never fails the payment`; `the nightly command is idempotent`; `one workspace's payments never shape another's pattern`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement**; add the schedule line (`daily()->withoutOverlapping(30)`).
- [ ] **Step 4: Run** the new tests and `tests/Feature/Payments`, `tests/Feature/Api/PaymentsTest.php`. Pint, phpstan.
- [ ] **Step 5: Mutation check:** remove the `swallow` wrapper and confirm `an exception in the recompute never fails the payment` fails; restore.
- [ ] **Step 6: Commit** `Keep each customer's payday pattern up to date`.

---

### Task 15: The pattern in the API

**Files:**
- Modify: `web/app/Http/Resources/CustomerResource.php`, `web/app/Http/Controllers/Api/V1/CustomerController.php` (`show`), `docs/api/openapi.yaml`, `web/lang/app/*.json`
- Test: `web/tests/Feature/Api/PayPatternApiTest.php`

**Interfaces:**
- Consumes: `CustomerPayPattern`, `Feature::PaydayLearning`.
- Produces: on `GET /api/v1/customers/{customer}` (only; not the list) a `pay_pattern` key: `null`, or `{day, spread, months, within, confidence, end_of_month, label}`. `label` is localized and number-neutral: `__('Usually pays around day :day of the month')`, or `__('Usually pays at the end of the month')`, and the basis line `__('Seen in :percent% of recent months')` as `basis`. The key is present only when the feature is on for the workspace.

- [ ] **Step 1: Write the failing tests:** `it shows the pattern for a customer who has one`; `it shows null when the customer has none`; `it is absent when the feature is off`; `the list does not carry it` (and adds no queries per row); `the label is localized in Arabic`; `another workspace's customer is a 404`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement**, update the OpenAPI Customer schema, translate.
- [ ] **Step 4: Run** the new tests, `OpenApiTest`, `TranslationsTest`, `tests/Feature/Api/CustomersTest.php`. Pint, phpstan.
- [ ] **Step 5: Commit** `Show a customer's payday pattern in the API`.

---

### Task 16: The pattern on the web

**Files:**
- Modify: `web/resources/views/app/customers/show.blade.php`, `web/app/Http/Controllers/Workspace/CustomerController.php` (`show`), `web/resources/views/app/contracts/create.blade.php`, `web/app/Http/Controllers/Workspace/ContractController.php` (`create`), `web/lang/app/*.json`
- Test: `web/tests/Feature/App/PayPatternWebTest.php`

**Interfaces:**
- Consumes: Task 13 and 15's pattern and label.
- Produces: a chip on the customer page (the label and basis); in the new-contract form (it uses Alpine with `x-model="firstDue"`), a map `payPatterns` of customer id to `{day, label}` rendered as JSON for the customers in the select, and, under the first-due field, a suggestion that appears when the selected customer has one: *"Ahmad usually pays around day 27. Use the 27th?"* with a **Use it** button that sets `firstDue` to the next occurrence of that day on or after the date currently entered (for the end of the month, the last day of that month). **Nothing changes unless the button is pressed.**

- [ ] **Step 1: Write the failing tests:** `the customer page shows the chip when there is a pattern`; `it shows nothing without one or with the feature off`; `the contract form carries the patterns of the selectable customers and only theirs`; `the suggestion markup is present, and the date field is unchanged by default`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.** Keep the Alpine function small and mirror exactly the rule tested in Task 17's Dart `nextOccurrence`.
- [ ] **Step 4: Run** the new tests and `tests/Feature/Contracts`. **Check the Alpine behaviour in the built-in browser** against the local server (select a customer with a pattern, press Use it, see the date change; select another, see the suggestion go), since Pest cannot run JavaScript.
- [ ] **Step 5: Commit** `Payday learning on the web: the chip and the suggestion`.

---

### Task 17: The pattern in the app

**Files:**
- Create: `app/lib/domain/payday.dart`
- Modify: `app/lib/data/models.dart` (`Customer.payPattern`), `app/lib/features/customers/customer_detail_screen.dart`, `app/lib/features/contracts/contract_form_screen.dart`, `app/lib/features/contracts/change_plan_screen.dart`, `app/assets/i18n/*.json`, `app/test/visual/screens_test.dart`
- Test: `app/test/domain/payday_test.dart`, `app/test/features/pay_pattern_test.dart`

**Interfaces:**
- Consumes: `pay_pattern` from Task 15 (`day`, `confidence`, `end_of_month`, `label`, `basis`).
- Produces:
```dart
class PayPattern { final int day; final int spread; final int months; final int within; final String confidence; final bool endOfMonth; final String label; final String basis; static PayPattern? fromJson(Object? json); }
DateTime nextOccurrence(DateTime from, PayPattern pattern);   // the first date on or after `from` that falls on the pattern's day (the month's last day for an end-of-month pattern)
```

Behaviour: a chip on the customer screen; in the contract form a row under the first due date, *"Ahmad usually pays around day 27. Use the 27th?"*, whose button sets the first due date to `nextOccurrence(currentDate, pattern)`; in the change-plan screen the same row under the date field of the "move a date" option. Shown only when the pattern is not null **and** the feature is on; **never applied without a tap**.

- [ ] **Step 1: Write the failing tests.** `payday_test.dart`: `a day later in this month`; `a day already passed rolls to next month`; `the 31st clamps to a short month`; `February and leap years`; `end of month means the last day of the month`; `the same date is returned when it already falls on the day`. `pay_pattern_test.dart`: `the customer screen shows the chip`; `the contract form suggests the day and changes the date only when the button is pressed`; `nothing shows without a pattern or with the feature off`; `the change-plan screen offers the same`; `Arabic layout has no overflow at 200% text`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement**; add strings (count-neutral Arabic) and translate; add the chip and the suggestion row to the visual set.
- [ ] **Step 4: Run** the tests, `flutter analyze` (zero issues), `python tool/i18n.py --sync`, the visual capture; look once, fix as a batch, confirm once.
- [ ] **Step 5: Commit** `App: the payday chip and the date suggestion`.

---

### Task 18: Release wave A

**Files:**
- Create: `docs/features/day-close.md`, `docs/features/payday-learning.md` (and `home-widget.md` from Task 6 is finished here)
- Modify: `app/pubspec.yaml` (raise the version, for example from 1.x.y+N to the next minor), `docs/api/openapi.yaml` (final pass), `docs/features/QISTAS_FEATURES_BRIEF.md` only if it lists these features

**Interfaces:**
- Consumes: everything above.
- Produces: a released, pushed wave A with three new cards, off, in the admin cockpit.

- [ ] **Step 1: Operator notes.** For each feature: what the admin switch does and its off-behaviour, the plan defaults (Free: widget and day close; Pro: all three), the settings, the privacy rules, how to roll it out (Off → Beta for the owner's own workspace → On for one friendly shop → On for everyone), and what the owner must check on a phone.
- [ ] **Step 2: Full verification.** In `web/`: `vendor/bin/pest` (summarise), `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`. In `app/`: `flutter analyze` (zero), `flutter test`, `python tool/i18n.py --sync`. Run `php artisan qistas:sync-features` locally and confirm three new switch rows at their launch state (off).
- [ ] **Step 3: Pictures.** `QISTAS_SCREENSHOTS=build/shots/waveA flutter test test/visual`; look at the new screens in English and Arabic once.
- [ ] **Step 4: Commit and push** (through the owner's terminal tool), then **watch both workflows**: `Mobile app` (analyse and test, web build, Android build) and the web deploy until green.
- [ ] **Step 5: Verify the live site with read-only requests only** (never create accounts or press demo buttons there): `GET /api/v1/glance` without a token answers 401; `GET /api/v1/day-close/today` answers 401; the deploy log shows the two migrations applied and `qistas:sync-features` adding three rows.
- [ ] **Step 6: Report honestly:** what was verified by tests; that the widget's look and behaviour, the silent morning push, and the Android build's runtime behaviour need the owner's phone (list the manual checklist); the APK link; and the three cockpit steps the owner takes to try the features.

---

## Self-review

**Spec coverage.** H3: Tasks 3 (feature, endpoint, privacy of names, disabled state), 4 (silent push), 5 (privacy, snapshot, settings), 6 (native widget, background refresh, QA). H4: Tasks 7 (totals, setting), 8 (close, reopen, after-close), 9 (API, summary, sentence), 10 (reminders, difference push), 11 (web), 12 (app). H6: Tasks 13 (calculator), 14 (keeping current, nightly), 15 (API), 16 (web), 17 (app). Foundations: Task 2, and Task 1 for the corrections. Release: Task 18. The spec's foundation items that do not appear (AI gateway, `/receipt`, `/coach`, `/sale` links, instrumentation) are recorded in corrections C2 and C6 with where they go.

**Type consistency.** `WorkspaceDay` (Task 2) is used unchanged in Tasks 7, 8, 10. `DayTotals::for` (Task 7) is used by Tasks 8, 9, 10, 11. `PayPatternCalculator::calculate` and `PayPattern` (Task 13) are used by Task 14; `pay_pattern` fields (`day, spread, months, within, confidence, end_of_month, label, basis`) are the same in Tasks 15, 16, 17. The widget key contract is defined once (Task 5) and consumed by Task 6. Feature keys: `home_widget`, `day_close`, `payday_learning`.

**Review Focus.** Each of the five items is pinned: 1 by Tasks 2, 7, 8; 2 by Tasks 3 and 5; 3 by Tasks 8 and 9; 4 by Tasks 13 and 14; 5 by the "off" tests in Tasks 3, 4, 9, 10, 14 and the app tests.

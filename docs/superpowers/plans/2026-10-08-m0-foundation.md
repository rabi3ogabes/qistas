# M0 Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the switch system and shared infrastructure every later feature stands on: platform Off/Beta/On control with an admin cockpit, run-time enforcement, usage counts, owner settings, private file storage, a PDF renderer, and a scheduler that works on a serverless host.

**Architecture:** `Entitlements` gains a platform layer in front of today's override-then-plan resolution and returns a `status` (`on | plan_locked | platform_off`). One service (`FeatureControl`) is the only writer of switches, used by the admin screen, the CLI and the presets. Infrastructure (files, PDF, settings, cron) is small, separately testable units behind interfaces, defaulting to local fakes.

**Tech Stack:** Laravel 13 / PHP 8.4, Pest, Pint, Larastan; Blade + small vanilla JS for the cockpit; `league/flysystem-aws-s3-v3`, `mpdf/mpdf`, `smalot/pdfparser` (dev); Flutter (Riverpod, go_router, dio).

**Spec:** [`docs/features/QISTAS_FEATURES_BRIEF.md`](../../features/QISTAS_FEATURES_BRIEF.md) (Parts 3, 0.1 to 0.6, 7, 8) and the decisions in [`docs/superpowers/specs/2026-10-08-m0-foundation-design.md`](../specs/2026-10-08-m0-foundation-design.md) (D1 to D12). Where they differ, the design note wins.

## Global Constraints

- Laravel 13, PHP 8.4; tests on SQLite in memory, production on Postgres: every migration runs on both, is additive and reversible.
- UUID keys (`HasUuids`) except `platform_features.feature_key` (string PK). Tenant-owned tables use `App\Tenancy\BelongsToTenant`, `tenant_id` first in every index.
- Money is never a float. Nothing in M0 writes the ledger.
- Never trust a tenant id from a client. Never put secrets, tokens or national IDs in logs or `Audit` `changes`.
- Existing behaviour must not change on deploy: the 7 core features stay `on`; the existing 1,177 web tests stay green after every task.
- Five languages for every user-visible string: web `lang/app/{ar,fr,es,ur}.json` via `scripts/merge_translations.py`; app via `python tool/i18n.py`. Arabic wording is count-neutral; English has separate 1-vs-many strings.
- Web gates per task: `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`, `vendor/bin/pest` (pipe through `pestsum.py`). App gates: `flutter analyze` at zero issues, `flutter test` green. `docs/api/openapi.yaml` is drift-tested: update it with every endpoint.
- Secrets live in Vercel/GitHub settings set by the owner; never ask for them in chat. Never create accounts on, or press demo buttons of, the live site.
- One task = one commit, pushed (`git push origin main` through the owner's terminal tool). The APK is rebuilt by CI whenever `app/**` changes; bump `app/pubspec.yaml` version in Task 10.

## Review Focus

Failure modes the brief implies but no task states; each has its test in the named task.

1. **Deploy must change nothing.** With an empty `platform_features` table, `customers`, `active_contracts`, `api_tokens`, `pdf_statements` and the toggles resolve exactly as before, and a request mid-deploy never sees "denied" (Tasks 1, 2).
2. **A switch flipped between queueing and running.** A job queued while a feature was on, run after it was turned off, does nothing and logs `skipped: feature_off`; turning it on again lets a new run work (Task 3).
3. **Cron abuse.** No secret, a wrong secret of the same length, GET versus POST, two ticks overlapping, a run that would exceed its time cap (Task 7).
4. **Beta edges.** An override expiring exactly now; a *denied* override while in beta; an override for workspace A never applying to B; a dependency that is in beta without access (Task 2).
5. **Upload attacks.** `../` in `kind`, a double extension, a `.jpg` that is really a PDF, an oversized file, guessing another workspace's file id, an expired signed URL (Task 8).

## File structure

```
web/app/Entitlements/  PlatformState, FeatureStatus, FeatureGroup, PlatformFeatures, FeatureControl, Presets,
                       FeatureGate, FeatureUsage, FeatureUnavailable        (new);  Feature, Entitlement,
                       Entitlements, EntitlementException                   (changed)
web/app/Jobs/FeatureJob.php                                                  re-checks the switch when it runs
web/app/Settings/      TenantSettings, SettingDefinition, SettingsRegistry
web/app/Cron/CronRunner.php; routes/internal.php; app/Http/Controllers/CronController.php
web/app/Support/       Files, FileKinds, ImageSanitizer
web/app/Documents/     PdfRenderer, QrCode
web/app/Http/Controllers/Admin/FeaturesController.php; resources/views/admin/features/*; resources/js/admin-features.js
app/lib/features/settings/tools_screen.dart; app/lib/data/models.dart (status)
```

---

### Task 1: The catalogue contract and where platform state lives

**Files:**
- Create: `web/app/Entitlements/{PlatformState,FeatureStatus,FeatureGroup,PlatformFeatures}.php`, `web/app/Models/PlatformFeature.php`, `web/app/Console/Commands/SyncFeaturesCommand.php`, `web/database/migrations/2026_10_09_000100_create_platform_features.php`
- Modify: `web/app/Entitlements/Feature.php`, `web/docker/entrypoint.sh` (run `php artisan qistas:sync-features` after migrating), `web/lang/app/*.json`
- Test: `web/tests/Feature/Entitlements/FeatureCatalogueTest.php`

**Interfaces:**
- Produces: the enums and the `Feature` methods and `PlatformFeatures` API listed in the design note, section 3. `PlatformFeatures::state(Feature): PlatformState` returns the row's state, or `launchState()` when no row exists. `PlatformFeatures::sync(): int` inserts a row for every case that lacks one and returns how many.

- [ ] **Step 1: Write the failing tests** (`FeatureCatalogueTest.php`), a Pest dataset over `Feature::cases()`:
  - every case has non-empty `description()` and `offBehaviour()`; both keys exist in `ar`, `fr`, `es`, `ur` of `lang/app/*.json`;
  - every case has a `platform_features` row after migrating; `sync()` run twice returns `0` the second time;
  - the 7 existing cases are core: `launchState() === PlatformState::On`, `isCore()`, `scope() === 'workspace'`;
  - `dependsOn()` is acyclic across all cases (depth-first search) and never names itself;
  - `PlatformFeatures::state(Feature::Customers)` is `On` when the table is empty (`PlatformFeature::query()->delete()` first).
- [ ] **Step 2: Run** `vendor/bin/pest tests/Feature/Entitlements/FeatureCatalogueTest.php` and see it fail because the methods do not exist.
- [ ] **Step 3: Implement** the migration (`platform_features`: `feature_key` string PK, `state` string default `'off'`, `reason` text null, `changed_by_user_id` uuid null, `changed_at` timestamp null, timestamps; seed rows for the 7 core cases as `on` in the migration's `up()` by calling `PlatformFeatures::sync()`), the model (`$incrementing = false`, `$keyType = 'string'`, `#[Fillable]`), and the `Feature` methods. Core cases: `group() = FeatureGroup::Core`, `scope() = 'workspace'`, `dependsOn() = []`. Write English `description()` and `offBehaviour()` sentences for the 7 cases and translate them in all four other languages.
- [ ] **Step 4: Run** the new file and then the whole suite (`vendor/bin/pest | python pestsum.py`): all green.
- [ ] **Step 5: Commit** `Add the platform-state table and the catalogue contract (M0 task 1)`; push.

---

### Task 2: One resolution, two kinds of "no"

**Files:**
- Create: `web/app/Entitlements/FeatureUnavailable.php`
- Modify: `web/app/Entitlements/{Entitlement,Entitlements,EntitlementException}.php`, `web/app/Providers/AppServiceProvider.php` (Blade `@feature('key')` directive), `docs/api/openapi.yaml`, `web/lang/app/*.json`
- Test: `web/tests/Feature/Entitlements/ResolutionMatrixTest.php`

**Interfaces:**
- Consumes: Task 1 (`PlatformFeatures`, `FeatureStatus`).
- Produces: `Entitlement::status(): FeatureStatus`, `Entitlement::detail(): ?string`; `Entitlement::toArray()` adds `status` and `detail`, `enabled` remains and equals `status === On`. `FeatureUnavailable` answers HTTP **403** `{"error":{"code":"feature_unavailable",...}}`; `FeatureLocked` and `LimitReached` stay 402. `EntitlementException` gains `abstract status(): int`. `Entitlements::assertEnabled / assertCanCreate / consume` throw `FeatureUnavailable` when the status is `platform_off`, otherwise `FeatureLocked`.

- [ ] **Step 1: Write the failing tests.** The matrix is a dataset (18 rows), asserted on `Entitlements::for($tenant)->check(Feature::AdvancedReports)->status()`:

| platform | plan includes | override | expected |
|---|---|---|---|
| off | any | any | `platform_off` |
| beta | any | none or denied | `platform_off` |
| beta | any | granted | `on` |
| on | yes | none or granted | `on` |
| on | no | none | `plan_locked` |
| on | no | granted | `on` |
| on | yes or no | denied | `plan_locked` |

  Further tests: (a) **empty `platform_features` changes nothing**: delete all rows, then `customers` limit, `api_tokens`, `pdf_statements` quota and the three toggles equal their pre-change values for a Free and a Pro workspace (Review Focus 1); (b) an override expiring at exactly `now()` no longer lets a beta workspace in, one expiring a second later does (Review Focus 4); (c) a granted override in workspace A does not change B; (d) dependency: a dependent whose dependency is platform `off` is `platform_off` with `detail === 'dependency:<key>'`, a dependent whose dependency is plan-locked is `plan_locked`, and a dependency that is in beta without access makes the dependent `platform_off` (Review Focus 4). An enum cannot be stubbed, so `Entitlements::resolveStatus(...)` is `public static` and takes the dependency graph as an optional closure `?Closure $dependsOn` (default `fn (Feature $f) => $f->dependsOn()`); the tests pass their own graph over existing cases; (e) HTTP: a test route `Route::middleware(['auth:sanctum','tenant','feature:advanced_reports'])->get('/api/v1/_probe')` answers 402 `feature_locked` when plan-locked, **403 `feature_unavailable`** when platform off, 200 when on, and the 403 body has no `upgrade_url`; (f) `/api/v1/me` carries `status` for every feature; (g) `@feature('advanced_reports')` renders its slot only for status `on`.
- [ ] **Step 2: Run** and see them fail for the right reason (no `status`).
- [ ] **Step 3: Implement.** `Entitlements::load()` also loads `PlatformFeatures::all()` once. The pure function `public static resolveStatus(Feature, array $platform, array $rows, array $overrides, ?Closure $dependsOn = null): array{status: FeatureStatus, detail: ?string}` applies, in order: platform `off` (or `beta` without an active *enabled* override) gives `platform_off`; an unmet dependency (recursive on the same loaded maps) gives `platform_off` + `dependency:<key>` or `plan_locked`; then override, plan, denied. `entitlement()` builds `Entitlement` from it; a non-`on` status has no limit. Update OpenAPI: `Entitlement` schema gains `status` (enum) and `detail`, add the `feature_unavailable` 403 error, keep the drift test green.
- [ ] **Step 4: Mutation checks** (each must make a named test fail, then restore): swap the platform step and the override step in `resolveStatus`; delete the `feature:` middleware from the probe route; make `assertEnabled` always throw `FeatureLocked`.
- [ ] **Step 5: Run** full suite, Pint, Larastan. **Commit** `Resolve platform state before plan and override; answer 403 feature_unavailable (M0 task 2)`; push.

---

### Task 3: Running work re-checks the switch; usage is counted

**Files:**
- Create: `web/app/Entitlements/{FeatureGate,FeatureUsage}.php`, `web/app/Jobs/FeatureJob.php`, `web/database/migrations/2026_10_09_000200_create_feature_usage_daily.php`
- Test: `web/tests/Feature/Entitlements/FeatureOffMeansOffTest.php`, `web/tests/Feature/Entitlements/FeatureUsageTest.php`

**Interfaces:**
- Produces: `FeatureGate::allows(Tenant, Feature): bool`; `abstract class FeatureJob implements ShouldQueue` with `abstract feature(): Feature`, `abstract tenantId(): string`, `abstract work(): void`, and a final `handle()` that returns early (logging `skipped: feature_off` with the feature key and tenant id, no personal data) when `FeatureGate::allows` is false. `FeatureUsage::hit(Feature, ?Tenant = null): void` upserts one `(tenant_id, feature_key, day)` row atomically (insert-or-ignore, then `hits = hits + 1`); `FeatureUsage::workspacesInLast30Days(Feature): int`.

- [ ] **Step 1: Write the failing tests.** (a) a test job (anonymous subclass of `FeatureJob` for `advanced_reports`) queued while on, then `PlatformFeature` set to `off`, then `Queue::runNextJob`/`dispatchSync`: `work()` never ran and the log has `skipped: feature_off`; set `on` again and a fresh dispatch runs (Review Focus 2); (b) data created while on is still readable after off; (c) `hit` twice on one day yields one row with `hits = 2`, a second tenant has its own row, a hit stores only counts (the table has no content columns); (d) `workspacesInLast30Days` counts distinct tenants with a row in the last 30 days and ignores older rows; (e) `hit` never throws when the row already exists (concurrent insert).
- [ ] **Step 2: Run, see them fail. Step 3: Implement.** Migration `feature_usage_daily` (`tenant_id` uuid, `feature_key` string, `day` date, `hits` unsigned int default 0, unique `(tenant_id, feature_key, day)`, index `(feature_key, day)`).
- [ ] **Step 4: Run** full suite and gates. **Step 5: Commit** `Make queued work re-check its switch; count feature usage (M0 task 3)`; push.

---

### Task 4: Owner settings

**Files:**
- Create: `web/app/Settings/{TenantSettings,SettingDefinition,SettingsRegistry}.php`, `web/app/Models/TenantSetting.php`, `web/database/migrations/2026_10_09_000300_create_tenant_settings.php`, `web/app/Http/Controllers/Workspace/ToolsController.php`, `web/app/Http/Controllers/Api/V1/ToolsController.php`, `web/resources/views/app/tools.blade.php`
- Modify: `web/routes/app.php` (`/app/settings/tools`), `web/routes/api.php` (`GET /settings/tools`, `PUT /settings/tools/{key}`), `docs/api/openapi.yaml`, `web/app/Support/AppNav.php`, `web/lang/app/*.json`
- Test: `web/tests/Feature/Settings/TenantSettingsTest.php`

**Interfaces:**
- Produces: `SettingDefinition(key, feature: Feature, type: 'switch'|'int'|'select', default, rules: array, label, help, options?)`; `SettingsRegistry::register(SettingDefinition)`, `::definitionsFor(Tenant): list<SettingDefinition>` (only those whose feature has status `on` for the workspace); `TenantSettings::for(Tenant)->get(key)` (typed, default when unset) and `->set(key, value)` (validates by the definition's rules, audits `settings.changed` with `before`/`after`, throws `FeatureUnavailable` when the feature is not `on`). API `GET /api/v1/settings/tools` returns `{data: [{key, feature, type, label, help, value, options?}]}`, empty today.

- [ ] **Step 1: Failing tests** (register a definition for `advanced_reports` in a `beforeEach`, clear in `afterEach`): lists nothing when the feature is off, lists it when on; `get` returns the default when unset; `set` rejects an out-of-range int with a 422 field error, stores a valid one, writes an audit row with before and after; a viewer cannot `PUT` (403), owner and manager can; workspace A's setting never appears in B; with the feature turned off `PUT` answers 403 `feature_unavailable` and the stored value is kept; the web page `/app/settings/tools` shows the empty state ("No instalment tools are available yet.") when nothing is on, and the tool's control when on.
- [ ] **Step 2 to 5:** run red, implement (value stored as JSON; validation through `Validator::make`), run full suite and gates, commit `Add per-workspace settings and the Instalment tools screen (M0 task 4)`; push.

---

### Task 5: The feature-control service, its endpoints and the CLI

**Files:**
- Create: `web/app/Entitlements/{FeatureControl,Presets}.php`, `web/app/Http/Controllers/Admin/FeaturesController.php`, `web/app/Console/Commands/FeaturesCommand.php`, `web/database/migrations/2026_10_09_000400_create_feature_snapshots.php`
- Modify: `web/routes/admin.php`, `web/app/Providers/AppServiceProvider.php` (Gate `manage-platform-features`: `platform_role === 'super_admin'`), `web/app/Entitlements/Feature.php` (`touchesCustomers(): bool`, default false)
- Test: `web/tests/Feature/Admin/FeatureControlTest.php`

**Interfaces:**
- Produces: `FeatureControl` exactly as in design note section 3. Endpoints (admin group, CSRF, `throttle:60,1`, each also answers JSON when `Accept: application/json`): `GET /admin/features`, `PUT /admin/features/{key}/state`, `PUT /admin/features/{key}/plans/{plan}`, `POST /admin/features/{key}/beta`, `DELETE /admin/features/{key}/beta/{override}`, `GET /admin/features/presets/{preset}/preview`, `POST /admin/features/presets/{preset}/apply`, `POST /admin/features/pause-automation`. CLI: `qistas:features list | state <key> <off|beta|on> --reason= | plan <key> <plan> <on|off> [--limit=] | sync`.
- Rules: core features refuse a state change (422 `core_feature`); turning **off** a feature with `FeatureUsage::workspacesInLast30Days > 0` requires `reason`; turning on is one call; `setState` never deletes data; every change writes `Audit::record('feature.state_changed' | 'feature.plan_changed' | 'feature.beta_granted' | 'feature.beta_revoked' | 'feature.preset_applied', null, [...from, to, reason, undo])` (subject null, design D3); `applyPreset` first stores a row in `feature_snapshots` so `restore_previous` can return to it; presets: `dark_launch` (all non-core off), `essentials` (Epics B and C basics and F; empty set today), `full` (all non-core on), `restore_previous`.

- [ ] **Step 1: Failing tests** (brief 3.6, Admin bullets, using `overviewAdmin()`-style staff factories): guests redirect to login; a non-staff user gets 404; staff without a confirmed second factor is challenged; an `admin` can open the page but every mutation is 403, a `super_admin` can; each mutation writes exactly one audit row with before and after; a core feature's state change is refused; off-with-usage without a reason is 422, with a reason is 200 and the reason is stored; `setState` on a feature with dependents reports them in the JSON (`dependents`) without blocking; `previewPreset('full')` returns the diff and changes nothing, `applyPreset` then `restore_previous` returns to the earlier states; `setPlan` upserts the `plan_features` row and the entitlement for a workspace on that plan changes at the next call; `grantBeta` creates a `tenant_overrides` row with reason, expiry and creator and revoke removes it; the CLI `list`, `state`, `plan`, `sync` work and `state` without `--reason` on an in-use feature fails; usage badge numbers equal `workspacesInLast30Days`; **Mutation:** make `setState` skip the audit call and remove the Gate check, and see tests fail.
- [ ] **Step 2 to 5:** red, implement, full suite and gates, commit `Add FeatureControl, its admin endpoints and qistas:features (M0 task 5)`; push.

---

### Task 6: The Feature-control cockpit

**Files:**
- Create: `web/resources/views/admin/features/{index,card,drawer}.blade.php`, `web/app/Admin/FeatureCard.php` (a readonly view-model built from `FeatureControl`), `web/resources/js/admin-features.js`
- Modify: `web/resources/css/admin.css`, `web/vite.config.js` (entry), the admin layout (nav link), `web/lang/app/*.json` (five languages)
- Test: `web/tests/Feature/Admin/FeaturesPageTest.php`

Use the `impeccable` and `frontend-design` skills for this task (`emil-design-eng` for the interaction details). Tokens: navy, gold, ivory; Cormorant headings, Geist body; dark mode; `prefers-reduced-motion`.

- [ ] **Step 1: Failing tests:** the page renders a sticky summary bar with counts (`N on · N beta · N off`), collapsible sections per group, and one card per feature; each card has `role="radiogroup"` with three `role="radio"` buttons (Off, Beta, On), the core cards have the switch `disabled` with the text "Core"; plan chips for every plan, a usage badge, dependency badges, `offBehaviour()` in the drawer; a form works without JavaScript (the segmented control is three submit buttons inside a form posting to the state route); the page contains no hard-coded strings in any of the five languages (the translation test passes); `admin` (non-super) sees disabled controls with an explanation.
- [ ] **Step 2: Implement** the view-model, Blade and CSS first (works with no JS); then `admin-features.js`: arrow-key navigation inside the radiogroup, optimistic update through `fetch` with `Accept: application/json`, a 6-second **Undo** toast (calls the state route with `undo: true`), confirmation dialog when `reason` is required (shows `offBehaviour()`, usage count, dependents), presets menu with diff preview, search and filters, **Pause all automation** (hidden while `touchesCustomers()` has no cases).
- [ ] **Step 3: Verify visually, one bounded pass.** Render the view with a fabricated list of `FeatureCard`s covering every state (on, beta, off, locked core, dependency problem, in use) and capture desktop (1440) and phone (390) in light and dark and in Arabic; inspect once, fix as a batch, confirm once.
- [ ] **Step 4:** full suite and gates; Vite build passes. **Commit** `Add the Feature-control cockpit (M0 task 6)`; push.

---

### Task 7: A scheduler that works without a worker

**Files:**
- Create: `web/routes/internal.php`, `web/app/Http/Controllers/CronController.php`, `web/app/Cron/CronRunner.php`, `web/app/Console/Commands/CronStatusCommand.php`, `web/database/migrations/2026_10_09_000500_create_cron_runs.php`, `.github/workflows/cron.yml`
- Modify: `web/bootstrap/app.php` (load `routes/internal.php` with no session or CSRF middleware), `web/config/qistas.php` (`cron_secret`, `cron_max_seconds` default 50), `web/app/Reports/PlatformOverview.php` (`health()` gains `cron`), `web/resources/views/admin/home.blade.php`, `docs/DEPLOY.md`
- Test: `web/tests/Feature/Deployment/CronEndpointTest.php`

**Interfaces:**
- Produces: `GET|POST /internal/cron` guarded by `Authorization: Bearer <CRON_SECRET>`, compared with `hash_equals`; `503 {"error":"not_configured"}` when no secret is set; `401` without or with a wrong secret; `409` when another tick holds the cache lock (`cron:run`, ttl 60 s); rate-limited. `CronRunner::run(): CronRun` calls `schedule:run`, then `queue:work --stop-when-empty --max-time=<cron_max_seconds>` and records start, end, outcome and the number of jobs in `cron_runs`. `qistas:cron-status` prints last run, lag in minutes, last failures. Overview `cron` health is `info` ("not set up yet") before the first run or when no secret exists, `ok` when the last run is under 15 minutes old, `warn` otherwise.

- [ ] **Step 1: Failing tests:** 503 when unset; 401 with none, a wrong secret of equal length, a wrong length (Review Focus 3); 200 with the right secret on both GET and POST; a second call while the lock is held returns 409 and runs nothing; a queued probe job is executed through the endpoint; the run record is written; `max-time` is honoured by passing `cron_max_seconds=1` and queueing slow jobs (the run stops cleanly and the rest wait for the next tick); the scheduled `qistas:prune-demo` is registered in `routes/console.php` and runs from `schedule:run`; the overview shows `info`, `ok` and `warn` for the three situations (set `cron_runs.started_at` back 20 minutes).
- [ ] **Step 2 to 4:** red, implement, run; `.github/workflows/cron.yml`: `schedule: "*/5 * * * *"` and `workflow_dispatch`, one job that exits 0 with a notice when `CRON_URL` or `CRON_SECRET` is missing, else `curl -fsS -m 60 -H "Authorization: Bearer $CRON_SECRET" "$CRON_URL"`; validate its YAML with PyYAML before pushing (a plain `: ` inside a `run:` scalar broke a workflow once; use a block scalar). Document in `docs/DEPLOY.md`.
- [ ] **Step 5:** full suite and gates. **Commit** `Add the cron endpoint, its runner, status and the GitHub scheduler (M0 task 7)`; push.

---

### Task 8: Private file storage

**Files:**
- Create: `web/app/Support/{Files,FileKinds,ImageSanitizer}.php`, `web/app/Models/StoredFile.php`, `web/app/Http/Controllers/FileController.php`, `web/database/migrations/2026_10_09_000600_create_stored_files.php`
- Modify: `web/composer.json`/`composer.lock` (`league/flysystem-aws-s3-v3`), `web/config/filesystems.php` (disk `files`: S3 when `FILESYSTEM_DISK=s3`, else a private local disk with `serve` enabled), `web/Dockerfile.vercel` (`gd` in `install-php-extensions`), `web/routes/app.php`, `docs/DEPLOY.md`
- Test: `web/tests/Feature/Support/FilesTest.php`

**Interfaces:**
- Produces: `Files::put(UploadedFile|string $content, FileKind $kind, Model $subject, User $by): StoredFile` stores at `tenants/{tenant_id}/{kind}/{uuid}.{ext}` where the extension comes from the **sniffed** MIME, never from the client name; `Files::temporaryUrl(StoredFile): string` with an expiry of **at most 5 minutes**; `Files::delete(StoredFile)` soft-deletes the row and removes the object. `FileKind` enum (`id_front, id_back, payment_proof, cheque_front, cheque_back, contract_pdf, signature, logo, statement`) with allowed MIME types, size cap (images 6 MB, PDFs 10 MB) and `audited()` (true for the two ID kinds). `GET /app/files/{file}` checks tenant membership and role, audits an audited kind, then redirects to the temporary URL.

- [ ] **Step 1: Failing tests** (`Storage::fake('files')` and a local fake S3 filesystem): put stores under the exact path pattern with a sniffed extension; **wrong MIME** (an HTML file named `x.jpg`, a PDF named `x.png`, double extension `invoice.pdf.php`) is rejected with a validation error and nothing stored (Review Focus 5); oversize rejected for image and PDF; a `kind` string containing `../` cannot be constructed (enum); an image with an injected `APP1 Exif` segment is stored **without** it (`'Exif'` absent from the stored bytes, present in the input); the temporary URL's expiry is at most 300 seconds even if the caller asks for more; another workspace's file id answers 404; an expired signed URL on the local disk is refused; downloading an `id_front` writes an audit row, a `payment_proof` does not; soft delete keeps the row.
- [ ] **Step 2 to 5:** red, `composer require league/flysystem-aws-s3-v3`, implement (`ImageSanitizer` re-encodes JPEG/PNG through GD), full suite and gates, check `docker build` through CI's container job, commit `Add private file storage (M0 task 8)`; push.

---

### Task 9: The PDF renderer

**Files:**
- Create: `web/app/Documents/{PdfRenderer,QrCode}.php`, `web/resources/views/documents/{layout,sample}.blade.php`, `web/resources/fonts/*` (Geist Regular/Bold, IBM Plex Sans Arabic Regular/Bold, with their OFL notices, copied from `app/assets/fonts`), `web/app/Console/Commands/PdfPreviewCommand.php`
- Modify: `web/composer.json`/`composer.lock` (`mpdf/mpdf`; dev `smalot/pdfparser`), `docs/DEPLOY.md`
- Test: `web/tests/Feature/Documents/PdfRendererTest.php`

**Interfaces:**
- Produces: `PdfRenderer::render(string $view, array $data, string $language, ?Feature $quota = null): string` returns PDF bytes. It sets RTL and the Arabic font for `ar`/`ur`, draws the brand header and footer, and when `$quota` is given calls `Entitlements::for(tenant)->consume($quota)` **after** the document rendered successfully (a failed render consumes nothing). `QrCode::svg(string $url): string` through `bacon/bacon-qr-code`. A `$branding` slot (logo path, accent) exists in the layout with Qistas defaults; filling it is Task F5's job.

- [ ] **Step 1: Failing tests:** English sample extracts its text and has the expected page count; the Arabic sample extracts correctly (compare after `Normalizer::normalize(..., NFKC)` and with the extracted string tried forward and reversed, which is how shaped RTL text comes back from a PDF parser); a bilingual layout contains both; quota: with `pdf_statements` as quota the Free workspace's `used` goes 0 to 1 on success and stays on a render that throws; the QR SVG is non-empty and decodes to the URL (render to the PDF and assert the URL string is not in the visible text but the SVG is embedded); the font files exist and are readable.
- [ ] **Step 2 to 5:** red, implement with mPDF (temp dir `storage/framework/mpdf`, created if missing), `qistas:pdf-preview {lang}` writes `storage/app/previews/sample-{lang}.pdf` (and a PNG when `pdftoppm` exists) for the one visual check, full suite and gates, commit `Add the PDF renderer with Arabic support (M0 task 9)`; push.

---

### Task 10: The app understands the switches

**Files:**
- Modify: `app/lib/data/models.dart` (`Entitlement.status`, `.detail`; `Account.featureStatus(key)`, `Account.shows(key)`), `app/lib/core/api/` error mapping (`feature_unavailable`), `app/lib/app/providers.dart`, `app/lib/app/router.dart`, `app/lib/features/settings/settings_screen.dart`, `app/assets/i18n/*.json`, `app/pubspec.yaml` (version `1.2.0+3`), `app/README.md`
- Create: `app/lib/features/settings/tools_screen.dart`
- Test: `app/test/features/switches_test.dart`; extend `app/test/visual/screens_test.dart`

**Interfaces:**
- Consumes: Task 2's `status`/`detail` and Task 4's `GET /settings/tools`, `PUT /settings/tools/{key}`.
- Produces: `Account.shows(String key)` is true only when `status == 'on'` (default true for an unknown key when the server sent no `status`, so an older server never hides things); a `feature_unavailable` error from any call refreshes the account once and the UI re-reads `shows`; `plan_locked` still opens the upgrade sheet. `ToolsScreen` renders a server-described list (`switch`, `int`, `select`) and saves each change; the Settings tile "Instalment tools" appears only when the list is non-empty.

- [ ] **Step 1: Failing tests:** models parse `status`/`detail`; an old payload without `status` is treated as `on`; a `403 feature_unavailable` response triggers one `/me` refetch (assert the call count) and the hidden widget disappears; a `402 feature_locked` still shows the upgrade sheet and does **not** hide the feature; the tools screen renders each type, saving sends the right `PUT` and shows the server's field error; with an empty list the tile is absent.
- [ ] **Step 2 to 3:** red, implement, translations (`python tool/i18n.py --missing`, batch merge), screenshot the tools screen once with the real fonts (add to `test/visual`), `flutter analyze` zero, `flutter test` green, mutation-check the `shows` rule.
- [ ] **Step 4:** **Commit** `Teach the app the platform switches and the Instalment tools screen (M0 task 10)`; push; wait for CI; confirm the rolling release `app-latest` holds the new APK (version 1.2.0).

---

### Task 11: Release notes, documentation and the live check

**Files:**
- Create: `docs/features/m0-foundation.md` (operator notes: what the cockpit does, how to read statuses, the CLI, what each env var is for, how to turn the scheduler on)
- Modify: `docs/DEPLOY.md` (env table: `CRON_SECRET`, the storage variables), `docs/features/QISTAS_FEATURES_BRIEF.md` (tick M0 in Part 9 only), memory notes

- [ ] **Step 1:** run all gates once more (`pest`, `pint --dirty`, `phpstan`, `flutter analyze`, `flutter test`).
- [ ] **Step 2:** push, watch both workflows (`Mobile app`, `web`) and the Vercel deployment to success.
- [ ] **Step 3:** live read-only checks: `/up`, `/login`, `/admin` (302 to login), `/internal/cron` without a secret (503 or 401, never 500), `/api/v1/me` unauthenticated (401). Confirm nothing the live site had before has changed.
- [ ] **Step 4:** report: what shipped, the links (live site, APK), what was **not** verifiable (real S3, a real scheduled tick, a phone), and the two owner actions that unlock more later (`CRON_SECRET` and its two GitHub secrets; storage variables). M0 has nothing for the owner to switch on yet, and says so.

---

## Self-review

**Spec coverage.** 0.1 Feature control: Tasks 1, 2, 5, 6 (3.1 resolution, 3.2 data, 3.3 contract, 3.4 enforcement points 1 to 6 through Tasks 2, 3 and 10, 3.5 cockpit, 3.6 tests). 0.2 Storage: Task 8. 0.3 PDF: Task 9. 0.4 Settings: Task 4 (web and API, app in Task 10). 0.5 Cron: Task 7. 0.6 Metering: Task 3 (the per-feature `hit` calls are added as each feature is built). Brief Part 8's checklist is applied per task through the Global Constraints. Deliberate differences from the brief are the design note's D1 to D12.

**Type consistency.** `FeatureStatus`, `PlatformState`, `Entitlement::status()`, `FeatureUnavailable`, `FeatureControl::{setState,setPlan,grantBeta,revokeBeta,previewPreset,applyPreset,pauseAutomation}`, `FeatureUsage::{hit,workspacesInLast30Days}`, `FeatureGate::allows`, `FeatureJob` are named identically wherever used.

**Proportion.** Eleven tasks for one milestone; steps name tests and signatures and leave bodies to the implementer.

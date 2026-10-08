# Milestone M0 (Foundation): design note

Supplements [`docs/features/QISTAS_FEATURES_BRIEF.md`](../../features/QISTAS_FEATURES_BRIEF.md), which is the specification. This
note covers only Milestone M0 (items 0.1 to 0.6 plus the app's awareness of the new switches). It records what was checked in
the code, where the code differs from what the brief assumes, and the decisions taken so the plan can be exact. Later
milestones get their own note and plan.

**Path:** architectural (a new subsystem that changes how entitlements resolve, which the API, the web app and the Flutter app all depend on).

## 1. Understanding

| | |
|---|---|
| **Outcome** | Qistas can ship dozens of features dark and let a platform admin turn each one Off, Beta or On from `/admin/features`, with no deploy. "Off" means off everywhere: routes, actions, queued jobs and both clients, while no data is ever deleted by a switch. The infrastructure the later features stand on exists: private file storage, a PDF renderer, per-workspace settings, a scheduler that works on a serverless host, and feature-usage counts. |
| **For** | The platform owner (admin cockpit) and, indirectly, every workspace owner (nothing changes for them until a feature is switched on). |
| **Success** | The tests of brief 3.6 pass, including a mutation check of the resolution order and of a route guard. A guarded probe route and a probe job behave correctly in every state. The live site behaves **exactly as before** after deploy (no feature the owner has today disappears). A new APK understands the new `status` field. |
| **Said by the owner** | Everything in the brief; "start working"; push the web version and a new APK after each completed piece. |
| **Assumed by me** | The Part 10 recommendations are accepted where they need no outside account (super-admin only; Supabase Storage; simple e-signature later). Anything needing an account (WhatsApp, gateway, S3 keys, SMTP) is built against a fake and left switched off. |

## 2. What the code showed (and what I decided)

| # | Finding | Decision |
|---|---|---|
| D1 | The catalogue has 7 features that **already work**: `customers`, `active_contracts`, `api_tokens` (limits), `pdf_statements` (quota), `export_csv`, `advanced_reports`, `custom_branding` (toggles; the last four are declared but not built). The brief says every feature seeds platform state `off`; doing that to these would lock every workspace out of adding customers. | Each case declares `launchState()`. The seven existing cases are **core**: `on`, and their platform switch is **not changeable** (the cockpit shows them under "Core", switch locked; their plan chips stay editable, which is today's behaviour). Every programme feature added later launches `off`. |
| D2 | A feature added in code but not yet in the table must not become "denied" or "allowed" by accident. | A missing `platform_features` row resolves to the case's `launchState()`. `qistas:sync-features` (idempotent) inserts missing rows; it runs after migrations in the container entrypoint and from a migration for today's cases. A test fails if any case lacks a row after migrating. |
| D3 | `audit_logs.subject_id` is a UUID column; platform features are keyed by a string. | `platform_features` keeps `feature_key` as its primary key. Audit rows for it use `subject = null` and carry `feature`, `from`, `to`, `reason` in `changes`. |
| D4 | The brief calls for a fake feature to prove the guard, the job skip and the client hiding. An enum cannot be extended in a test. | The proof uses an **existing declared toggle** (`advanced_reports`) with a test-only route and a test-only job. No test hook in production code. |
| D5 | `EntitlementException::render()` answers 402 only. | The class gains `status()`. New `FeatureUnavailable` answers **403 `feature_unavailable`**; `FeatureLocked` and `LimitReached` stay 402. `Entitlements` picks the exception from the resolved status. |
| D6 | Dependencies: "not enabled" is ambiguous. | A dependency that is switched off at the platform level (or in beta without access) makes the dependent `platform_off` with `detail: dependency:<key>`. A dependency that the plan does not include makes the dependent `plan_locked` (the upgrade path is the same). The dependency graph is declared in code and a test proves it has no cycles. |
| D7 | The brief says `POST /internal/cron`, with Vercel Cron. Vercel Cron sends **GET** with `Authorization: Bearer <CRON_SECRET>`; Hobby plans allow one run a day; `vercel.json` uses the newer `services` form and I cannot see the team's plan. A wrong cron entry could fail every deployment. | The endpoint accepts GET and POST and is host-agnostic. It is scheduled by a **GitHub Actions workflow every 5 minutes** (needs repo secrets `CRON_URL` and `CRON_SECRET`; the workflow does nothing until they exist). `vercel.json` is **not touched**. The admin overview shows a calm "not set up yet" until the first run, and a warning only when a configured cron goes stale (>15 min). |
| D8 | The brief adds `endroid/qr-code`. | Use the already installed `bacon/bacon-qr-code`. One dependency fewer. |
| D9 | The web image is built from `web/` only, so it cannot read `app/assets/fonts`; and it has no `gd` extension (needed to strip EXIF and by mPDF). | Copy the needed font files (OFL) into `web/resources/fonts`; add `gd` to `install-php-extensions` in `Dockerfile.vercel`. |
| D10 | Only `super_admin` may change platform state (brief decision 2); `admin` views. | Enforced by a policy gate on every mutating admin route and by `qistas:features` (a CLI run is the operator, so it is allowed and audited as such). |
| D11 | Undo after turning something Off. | Undo restores the previous state in one call, is exempt from the "reason required" rule (it is a reversal), and is audited as `feature.state_changed` with `undo: true`. |
| D12 | Owner settings screen in the app. | Schema-driven: the server describes each tool's fields (`switch`, `int`, `select`) and the app renders them, so no later feature needs its own settings screen. Hidden entirely while no tool is `on` (true throughout M0). |

## 3. Interfaces (the contract the plan builds)

```php
enum PlatformState: string { case Off='off'; case Beta='beta'; case On='on'; }
enum FeatureStatus: string { case On='on'; case PlanLocked='plan_locked'; case PlatformOff='platform_off'; }

// Feature (existing enum) gains:
public function group(): FeatureGroup;          // Core, A..I, Platform
public function description(): string;          // one sentence for the admin card
public function offBehaviour(): string;         // what happens to data and running work when off
public function dependsOn(): array;             // Feature[]
public function scope(): string;                // 'workspace' | 'platform'
public function launchState(): PlatformState;   // core => On, everything new => Off
public function isCore(): bool;

// Entitlement gains:
public function status(): FeatureStatus;
public function detail(): ?string;              // 'dependency:<key>' or null
// toArray() adds 'status' and 'detail'; 'enabled' stays and now means status === On.

final class PlatformFeatures {                  // reads/writes platform_features
    public static function state(Feature $f): PlatformState;      // missing row => launchState()
    public static function all(): array;                           // keyed by feature key
    public static function sync(): int;                            // inserts missing rows, returns count
}

final class FeatureControl {                    // the one place that changes switches
    public function setState(Feature $f, PlatformState $to, ?string $reason, User|null $by, bool $undo = false): void;
    public function setPlan(Feature $f, Plan $plan, bool $enabled, ?int $limit, ?User $by): void;
    public function grantBeta(Feature $f, Tenant $t, string $reason, ?CarbonInterface $until, ?User $by): TenantOverride;
    public function revokeBeta(TenantOverride $o, ?User $by): void;
    public function previewPreset(string $preset): array;  // [{feature, from, to}]
    public function applyPreset(string $preset, string $reason, ?User $by): void;  // snapshots first
    public function pauseAutomation(string $reason, ?User $by): void;
}

final class FeatureUsage { public static function hit(Feature $f, ?Tenant $t = null): void; }   // counts only
final class FeatureGate { public static function allows(Tenant $t, Feature $f): bool; }          // for jobs and commands
abstract class FeatureJob implements ShouldQueue { abstract public function feature(): Feature; ... }  // re-checks at run time

final class TenantSettings { public function get(string $key): mixed; public function set(string $key, mixed $value): void; }
final class Files { public function put(...): StoredFile; public function temporaryUrl(StoredFile $f): string; public function delete(StoredFile $f): void; }
final class PdfRenderer { public function render(string $view, array $data, Language $lang, ?Feature $quota = null): string; }
```

## 4. What the owner does later (nothing blocks M0)

| For | Variable | Until set |
|---|---|---|
| Scheduler | repo secrets `CRON_URL`, `CRON_SECRET`; and `CRON_SECRET` in Vercel | The cron endpoint answers 503 "not configured"; the workflow does nothing |
| Storage | `FILESYSTEM_DISK=s3`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT` | Uploads use the private local disk (fine for development, not durable on Vercel; nothing in M0 stores a file for a customer yet) |

## 5. What M0 cannot prove, and says so

- Real S3 / Supabase Storage behaviour (signed URLs against a real bucket, EXIF on a real phone photo): proven against the local disk and a fake S3 filesystem only.
- A real scheduled run on GitHub Actions or Vercel: the endpoint and the runner are tested; the first live tick needs the owner's secrets.
- PDFs in the production container: rendered and text-checked locally; the image build is checked by CI's container job.
- The app on a phone: screens are rendered with real fonts; haptics and real devices are not covered.

## 6. Risks and how the plan answers them

1. **Changing resolution can break what works today.** The existing 1,177 web tests must stay green after every task, and a test pins that an empty `platform_features` table changes nothing (D2).
2. **A switch that is not checked everywhere is not a switch.** Tests cover route, action, job and client for the probe, and a mutation check removes each guard in turn.
3. **The admin cockpit is the most visible new surface.** It is built after the logic is proven, checked with real screenshots at desktop and phone width, in both directions of text, and works without JavaScript (forms) with JavaScript only improving it.
4. **Heavy new dependencies** (S3 driver, mPDF) change the image: the Dockerfile and CI's container job are updated in the same task.

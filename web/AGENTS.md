# Qistas web (Laravel)

One Laravel app serves the marketing website, the customer web app (`/app`), the admin console (`/admin`) and the
REST API (`/api/v1`) that the Flutter app calls. Design: `../docs/superpowers/specs/2026-10-07-qistas-platform-design.md`.
Plans: `../docs/superpowers/plans/`.

## Commands

```sh
php vendor/bin/pest                 # tests (SQLite in memory)
php vendor/bin/pint                 # code style (run before every commit)
php vendor/bin/phpstan analyse      # Larastan, level 6
```

Write the failing test first, watch it fail for the right reason, then implement. Prefer real code over mocks.

## Rules that must not be broken

- **Tenancy.** Every tenant-owned model uses `App\Tenancy\BelongsToTenant`. Never read or write one without a
  tenant context, never trust a `tenant_id` from a request, and never use `withoutGlobalScope(TenantScope::class)`
  outside platform-admin code. Another workspace's record is a 404, never a 403.
- **Money.** Never `float`. Amounts are `decimal(18,4)` in the database and bcmath strings in PHP through
  `App\Support\Money`. Schedules come from `App\Domain\Schedule\ScheduleGenerator`, which is cross-checked against
  `../shared/schedule-vectors.json`; the Flutter app must pass the same vectors.
- **Entitlements.** Features are declared in code (enum `Feature`) and switched on/off per plan by an admin in the
  database. Check them through the entitlements service, never by plan name. Downgrades never delete data.
- **Mass assignment.** Models list `#[Fillable]` explicitly. Roles, status, tenant ids and plan fields are set by
  trusted code only.
- **Secrets and PII.** National IDs are encrypted at rest. Never log or audit passwords, tokens or national IDs.
- **Audit.** Security-relevant actions call `App\Support\Audit::record()`. The table is append-only.
- **Errors.** Authentication failures are generic (never reveal whether an email exists). API plan/limit failures
  are HTTP 402 with the documented JSON error body.

## Conventions

- UUID primary keys (`HasUuids`). Migrations must run on SQLite (tests) and PostgreSQL (Supabase).
- Business logic lives in `app/Actions` and `app/Domain`, not in controllers or models.
- Keep user-facing strings in `lang/` (English and Arabic complete; French, Spanish and Urdu fall back to English
  per key). Never hard-code a string in a Blade view.
- Windows development: write files with the editor tools, not shell heredocs with nested quotes.

# Qistas Theme Engine

How the **admin changes any colour by country and by event, from the dashboard**, without a release.

| | |
|---|---|
| Admin UI | `brand/theme-studio/index.html` (prototype of the Admin Console page, Phase 5) |
| Reference resolver | `brand/shared/qistas-theme.js` (24 tests: `node brand/tools/test-theme-runtime.cjs`) |
| Data | `supabase/migrations/20261007000100_theme_engine.sql` + `supabase/seed/theme_seed.sql` |
| Seed source of truth | `brand/tokens/themes.seed.json` → `python brand/tools/sync-brand-data.py` |

## 1. The idea in one picture

```
 base  ───────►  country  ───────►  event  ───────►  merchant accent
 Qistas          Saudi, UAE,        Ramadan, Eid,    the lender's own gold
 Signature       Egypt, Morocco…    National Day…    (unless the campaign is locked)
 (always)        (always, per       (scheduled:      
                  country)           date window,
                                     yearly, Hijri)
```

A theme stores **only the tokens it changes**. Everything that depends on them is derived, so an admin
who changes one primary colour gets a complete, accessible light *and* dark palette.

## 2. Tokens (26, per mode)

`primary onPrimary action onAction accent onAccent accentText info onInfo bg surface surfaceAlt ink inkMuted line positive warning danger tintSky tintBlush tintSand tintMint heroFrom heroTo logoInk logoAccent`

Derivation (when the theme does not pin the dependent token):

| Admin changes | Auto-derived |
|---|---|
| `primary` | `onPrimary` (best of ivory/navy/white/black across the hero gradient), `heroFrom`, `heroTo`, `action`, `onAction`, `logoInk`; **dark mode**: `bg`, `surface`, `surfaceAlt`, `line`, `primary`, hero stops, all re-hued from the new primary |
| `accent` | `onAccent`, `accentText` (darkened until ≥ 4.6:1 on the lightest surface it sits on), `logoAccent`; dark: `accent`, `action` |
| `info` | `onInfo`; dark: lightened `info` with contrast ensured |

## 3. Resolution algorithm (must be identical on every platform)

```
resolve(country, planId, tenantId, tenantAccent, now, tz, hijriOffsetDays):
  live = themes where status = published, not deleted,
                      (kind = base  OR  scope matches country/plan/tenant),
                      schedule is active at `now`
  order live by: layer (base < country < event), then priority ascending, then published_at ascending
                 (so the HIGHEST priority / newest wins when merged last)
  tokens = base tokens
  for each non-base theme in order:
      overlay its tokens (light and dark separately); remember which tokens were pinned
      merge copy per key per locale; union motifs
      if theme.allow_tenant_accent = false: lock = true
  if tenantAccent and not lock: overlay accent as a pinned light-mode token
  derive dependents for tokens that were not pinned   (§2)
  safeguard: re-validate WCAG AA on the resolved palette; repair failing foregrounds
  validUntil = next local midnight in `tz`, or the next one-off startsAt/endsAt, whichever is first
```

### Schedules

| Type | Fields | Notes |
|---|---|---|
| Always | none | base, country themes |
| Window | `starts_at`, `ends_at` | one-off campaigns; compared as instants |
| Gregorian yearly | `month`, `day`, `spanDays` | spans may cross the year end (29 Dec + 5 days) |
| Hijri yearly | `month`, `day`, `spanDays` | Umm al-Qura calendar (`Intl` `islamic-umalqura` on clients; `IntlCalendar` in PHP) |

*Moon-sighting offset:* `theme_country_settings.hijri_offset_days` (−2…+2). `+1` means the country's local
Ramadan starts one day after Umm al-Qura. The engine evaluates the Hijri date of `today − offset`.

*Time zone:* "today" is the civil date in the tenant country's zone, not the server's.

## 4. API contract (Laravel, `/api/v1`)

### Client

`GET /theme?country=SA&plan=pro` — authenticated users use their tenant's country; pre-login screens
(intro, sign-in) send the device region. Never IP-geolocate silently without a disclosure.

```jsonc
// 200
{
  "version": "2026-10-07T09:41:12Z#14",          // also sent as ETag
  "tokens": { "light": { "primary": "#0E3B43", … 26 keys }, "dark": { … } },
  "copy":   { "greeting": { "ar": "رمضان كريم", "en": "Ramadan Kareem" }, "tagline": { … } },
  "motifs": ["crescent"],
  "applied": [{ "id": "base", "kind": "base" }, { "id": "country-sa", "kind": "country" }, { "id": "event-ramadan", "kind": "event" }],
  "tenantAccentApplied": true,
  "validUntil": "2027-02-20T21:00:00Z",
  "context": { "country": "SA", "timeZone": "Asia/Riyadh", "hijriOffsetDays": 0 }
}
```

* `ETag` / `If-None-Match` → `304`. Cache-Control: `private, max-age=300, stale-while-revalidate=86400`.
* The server resolves; clients **may not** merge themes themselves except offline (see §6).
* Throttle: 60 req/min per device. The response is tiny (≈3 KB gzipped) and Redis-cached per
  `(country, plan, tenantAccent, local date)`.

### Admin (Super Admin / Admin staff with `themes.manage`, mandatory 2FA, audit-logged)

| Endpoint | Purpose |
|---|---|
| `GET /admin/themes` | list with status, scope, schedule, "live now in" |
| `POST /admin/themes` · `PUT /admin/themes/{id}` | create / edit (draft) |
| `POST /admin/themes/{id}/publish` | **gate**: schema + WCAG AA on resolved light & dark; `422` with the failing pairs otherwise |
| `POST /admin/themes/{id}/unpublish` · `DELETE …` | back to draft · archive (soft delete) |
| `POST /admin/themes/{id}/rollback` `{version}` | `rollback_theme()` |
| `GET /admin/themes/{id}/preview?country=&date=&source=` | the same resolver, for the Studio's live phone |
| `PUT /admin/theme-countries/{code}` | time zone, Hijri offset |

Publish effects: bump `version`, write `theme_versions`, bust the Redis cache, `pg_notify('theme_changed')`,
Supabase Realtime pushes to connected clients (they re-fetch immediately); everyone else picks it up at
`validUntil` or the next cold start.

## 5. Guard-rails (why an admin cannot break the product)

1. **Contrast gate** at publish: 25 pairs × 2 modes (text, muted text, status chips, text-on-primary/accent/action/interactive, accent text,
   links, status colours, tile text, hero text, logo ≥ 3:1). Failing pairs block publish; **Auto-fix** repairs only the
   foregrounds (neutral text snaps back to brand navy/ivory; semantic colours keep their hue).
2. **Client safeguard**: the same check runs on every resolved palette; if a bad palette ever slips through it is
   repaired at runtime and logged (`repaired[]`), never shown unreadable.
3. **Shape constraints in Postgres**: only the 26 known tokens, `#RRGGBB` only, valid scopes and recurrences, one
   published base theme, base cannot be scoped or scheduled.
4. **Versioning + rollback** for every change; the history is append-only.
5. **Locked campaigns**: `allow_tenant_accent = false` suspends merchant accents (National Day etc.) so a brand campaign
   is consistent; merchants regain their accent automatically afterwards.
6. **RLS**: anyone can read *published* themes (they are not secret); only platform admins write; merchants can only
   write their own `tenant_branding` row.

## 6. Client implementation notes (Flutter)

* Map the 26 tokens 1:1 onto a `ThemeExtension<QistasColors>` plus `ColorScheme.fromSeed` overrides; keep light and dark
  in one object and let `ThemeMode` choose. Fixed (non-themeable) tokens live in `brand/tokens/design-tokens.json`.
* Cache the last response (`shared_preferences`/Drift) with its `validUntil`; apply it synchronously on cold start so the
  splash is already in the right colours. Schedule one timer for `validUntil` and refresh on app resume.
* **Offline**: if the cache is older than `validUntil`, re-resolve locally with the cached `themes` list **only for
  Hijri/Gregorian rollover** using the same algorithm (a port of `qistas-theme.js`; share the JSON test vectors in
  `brand/tools/test-theme-runtime.cjs`). Otherwise keep the last palette.
* Splash/intro read the resolved `tagline`/`greeting` and `motifs`; motifs are asset ids (`crescent`, `sparkle`, `bars`).
* Respect `MediaQuery.disableAnimations` (reduced motion) as specified in `brand/index.html#motion`.

## 7. Cultural and legal notes

* National-day themes use colours **inspired by** national palettes, not flags or emblems. Check local rules on flag/emblem
  use before adding either.
* Hijri start dates follow Umm al-Qura by default; use the per-country offset where local sighting differs, and let the
  admin override in the Studio rather than hard-coding a country's practice.
* Event greetings ship in all five launch languages. They were drafted for this prototype and need review by native
  speakers before store release.

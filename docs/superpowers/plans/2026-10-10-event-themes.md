# Event themes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An admin dresses the website, the web app and the Android app for a national day or a season, for the countries and the days chosen (for example Saudi National Day, today and tomorrow, for Saudi Arabia only), while everyone else keeps the usual look.

**Architecture:** Events are their own records (`appearance_events`), layered over the published look at the moment a page or the API is answered: `Appearance::current(Request, surface)` finds the visitor's country (the workspace's country when signed in, else the CDN's country header, else the browser's language region), picks the event that is on today in that country's time zone, and merges its colours, pictures and banners over the live look. Pages link `/theme.css?v={version}&e={event}.{revision}`, an immutable address per look; the API answers `private` with the base look alongside so the app can drop an event that ended while offline.

**Tech Stack:** Laravel 13, Blade, vanilla JS, Pest; the Flutter side lands with the app's Task 5.

**Spec:** `docs/superpowers/specs/2026-10-10-app-billing-notifications-design.md` (owner's request of 10 Oct, "event management for the theme"), on top of `docs/superpowers/specs/2026-10-09-admin-shell-and-appearance-design.md`.

## Decisions

| # | Question | Decision |
|---|---|---|
| V1 | Who sees an event | Its **countries** (ISO codes from `config('qistas.countries')`; none = everyone). The visitor's country is, in order: the signed-in workspace's country; the CDN's header (`CF-IPCountry`, `X-Vercel-IP-Country`, only when `qistas.geo_header` names it); the region of the browser's language (`ar-SA` → SA). Nothing about the visitor is stored. |
| V2 | When | Whole days, **first day to last day inclusive**, in the event's time zone (the first country's, editable). 22 Sep 23:30 UTC is already 23 Sep in Riyadh. |
| V3 | What it changes | Any of: the four colours (layered over the live look's own, so an event that sets only the main colour keeps the canvas), the logo, dark logo, hero and banner pictures, and a welcome banner per place. Each event says which places it dresses (website, web app, Android app). |
| V4 | Two events at once | The one aimed at fewer countries wins (a Saudi event beats an everyone event); then the later start; then the later edit. The studio warns of overlaps and says which wins. |
| V5 | Safety | The colours go through the same contrast repair as a publish; scheduling shows what moved; the served stylesheet is always the repaired palette. The admin console never wears an event. |
| V6 | Life cycle | *Draft* (only the admin sees it) → *Scheduled* (shows on its days) → *On now* / *Ended*, by date. *Stop* returns it to draft at once. Editing a scheduled event takes effect at once and bumps its revision (new stylesheet address). Every change is audited. |
| V7 | Presets | Saudi National Day (23 Sep), Saudi Founding Day (22 Feb), UAE National Day (2 Dec), Kuwait National Day (25–26 Feb), Qatar National Day (18 Dec), Bahrain National Day (16–17 Dec), Oman National Day (20 Nov), White Friday (everyone), Ramadan, Eid al-Fitr, Eid al-Adha (moon-dependent: the admin sets the dates; the preset says so). Each fills name, countries, colours that pass the contrast checks, and banner words in five languages; fixed-date presets suggest the next occurrence. |
| V8 | Smart touches | A twelve-month timeline with *today*, status chips (On now, Starts in 3 days, Ended), *Preview as* a country on a date (the stage shows exactly what that visitor would see), overlap warnings, duration shortcuts (1, 2, 3, 7 days), the next occurrence for national days. |

## Global Constraints

- Migrations additive and reversible, UUID keys, SQLite and PostgreSQL.
- Every new string in five languages (`scripts/merge_translations.py`); `docs/api/openapi.yaml` stays in step (drift test).
- Pint, Larastan level 6, Pest; mutation-check the rules (window, audience, priority, surfaces, gate).

## Review Focus

1. **Time zones at the edges** — the last minute of the last day and the first minute of the first day in the event's zone; a visitor in a different zone than the event's.
2. **A country header from a client** — trusted only when configured; otherwise a request cannot pick its own event by sending a header.
3. **Caching across countries** — no page or API answer that depends on the country may be cached publicly; the stylesheet address carries the event and revision.
4. **An event and the app offline** — the app must stop showing an event after its last day even with no connection.
5. **Deleting or stopping a live event** — its pictures and stylesheet stop at once; nothing published is lost.

---

### Task E1: Events in the store, and who sees what

**Files:** Create `web/database/migrations/2026_10_10_000100_create_appearance_events.php`, `web/app/Models/AppearanceEvent.php`, `web/app/Theme/{Visitor,EventPresets}.php`; Modify `web/app/Theme/{Appearance,AppearanceView}.php`, `config/qistas.php` (`geo_header`); Test `web/tests/Feature/Theme/EventsTest.php`.

**Interfaces:**
- `AppearanceEvent` (uuid; `name`, `countries` json list, `starts_on`, `ends_on` date, `timezone`, `surfaces` json list, `pins` json, `images` json, `banners` json, `status` draft|scheduled, `revision` int, `preset` nullable, `created_by_user_id`, `updated_by_user_id`): `isOnAt(CarbonInterface): bool`, `appliesTo(?string $country, string $surface): bool`, `stateAt(CarbonInterface): 'draft'|'scheduled'|'live'|'ended'`.
- `Visitor::country(Request): ?string`.
- `Appearance::saveEvent(?AppearanceEvent, array $input, User): AppearanceEvent` (validates like `saveDraft`, plus countries ⊆ config, `ends_on ≥ starts_on`, surfaces ⊆ SURFACES, timezone valid), `scheduleEvent(AppearanceEvent, User): AppearanceEvent` (contrast gate → `AppearanceRefused` or repaired list), `stopEvent`, `deleteEvent`, `events(): Collection` (cached), `lookFor(?string $country, string $surface, ?CarbonInterface $now): AppearanceView`, `current(Request, string $surface): AppearanceView`.
- `AppearanceView` gains `event(): ?array{id, name, revision, ends_on, until}` and `cssKey(): string`.

- [ ] Failing tests: an event shows only in its countries, only on its days (time-zone edges), only on its places; everyone-events reach all; priority (fewer countries, later start, later edit); colours layer over the live look and are repaired; pictures and banners override only what they set; drafts and stopped events never show; country from workspace, configured header, browser language, in that order, and an unconfigured header is ignored; invalid input refused (unknown country, end before start, bad colour, bad zone, unknown place); every change audited; only super admins write.
- [ ] Implement; green; mutation check; commit `Add event themes to the Appearance store`.

### Task E2: Events reach the pages and the API

**Files:** Modify `web/app/Http/Controllers/ThemeCssController.php`, `Api/V1/AppearanceController.php`, `resources/views/components/{brand-head,logo,welcome-banner}.blade.php`, the site/auth/app layouts (`surface` passed), `resources/views/site/home.blade.php`, `docs/api/openapi.yaml`; Test `web/tests/Feature/Theme/EventDeliveryTest.php`.

- [ ] Failing tests: a Saudi visitor's home page links `theme.css?v=…&e={id}.{rev}` and shows the event banner and hero; a French visitor sees the usual look; the web app of a Saudi workspace wears it, the admin console never; the stylesheet for an event address holds the event palette and is immutable, an unknown or stopped event falls back; the API answers the event look for a Saudi workspace's token with `event` and `base`, `Cache-Control: private`, and a new ETag when the event starts or ends.
- [ ] Implement; green; commit `Serve event themes to the website, the web app and the API`.

### Task E3: The events studio

**Files:** Create `web/app/Http/Controllers/Admin/AppearanceEventController.php`, `resources/views/admin/appearance/{events,event}.blade.php`, `resources/js/admin-events.js`; Modify `admin/appearance/index.blade.php` (events section), routes, `studio.css`, translations; Test `web/tests/Feature/Admin/AppearanceEventsPageTest.php`.

- [ ] Failing tests: the Appearance page lists events with their state and a timeline; *New event* from a preset fills name, countries, colours, words and the next date; the editor saves a draft, schedules (showing what the contrast repair moved), stops, deletes; mistakes show beside their fields and keep what was typed; overlap warning names the winner; *Preview as* SA on a date shows the event on the stage; other staff read only; pictures upload per event.
- [ ] Implement; green; browser round (desktop, phone, Arabic); commit `Add the events studio to Appearance`; push.

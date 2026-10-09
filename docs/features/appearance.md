# Appearance: colours, pictures, welcome banners and seasonal events

The admin's **Appearance** page (`/admin/appearance`, super admins change, other staff look) controls how the website,
the web app and the Android app look. The admin console itself always keeps the Qistas look, so a bad choice can never
hide the page that fixes it.

## What an admin does there

| Part | What it is |
|---|---|
| **Colours** | Six ready-made looks (Qistas, Emerald, Bordeaux, Ocean, Graphite, Desert rose) or four chosen colours: main, accent, interactive, canvas. Everything else, the whole dark mode included, is worked out by the theme engine. Each colour shows its contrast; the list says what publishing will adjust so every text stays readable. |
| **Pictures** | Logo, logo for dark backgrounds, website hero picture, banner picture. PNG or JPEG up to 2 MB, cleaned of hidden data and resized, stored in the database and served from addresses that never change. |
| **Welcome banners** | One each for the website (under the header), the web app (top of the dashboard) and the Android app (a card on the dashboard): five languages (English required), four tones, an optional button to a page of the site or a secure address, optional first and last day, closable or not. |
| **Preview** | A small website, web app and phone in the draft's colours, light or dark, as the admin types; its palette comes from the same engine that publishes. *Preview as a visitor* shows what someone from a chosen country sees on a chosen date. |
| **Publish / history** | Publish saves the form and makes it the next numbered version; every version is kept. Restore, reset to the Qistas look and discard unpublished changes are one click (with a question first where it matters). |
| **Seasonal events** | A look for a national day or a season, for the countries and the days chosen; everyone else keeps the usual look. See below. |

## Seasonal events

An event has a name, its **countries** (or everyone), a **first and last day** in its own time zone (the first
country's, editable), the **places** it dresses (website, web app, Android app), and any of: its own colours (laid over
the usual ones), pictures, and a banner for each place.

- **Who sees it:** a signed-in person counts as their business's country; a visitor counts as the CDN's country header
  (`QISTAS_GEO_HEADER`, see `docs/DEPLOY.md`) or else their browser's language region.
- **Two at once:** the one aimed at fewer countries wins (a Saudi event beats an everyone event), then the later start,
  then the later edit. The calendar warns of overlaps and names the winner in each shared country.
- **States:** Draft (nobody sees it), Scheduled, On now, Ended. *Stop* returns it to a draft at once; an edit takes
  effect at once.
- **Ready-made:** Saudi National Day and Founding Day; UAE, Kuwait, Qatar, Bahrain and Oman national days; White Friday;
  Ramadan and the two Eids (moon-dependent: the admin sets their dates each year).

## How it reaches people

| Where | How |
|---|---|
| Website, sign-in pages, web app | Every page links `/theme.css?v={version}` (and `&e={event}.{revision}` while an event is worn): colour variables only, cached for a year at that exact address. The logo, hero and banner follow the same look. |
| Android app | `GET /api/v1/appearance` (public, answered `private`, ETag/304): the palette for light and dark, the logo, the banner in the app's language, and while an event is on `event.until` and the usual look as `base`. The app keeps the last answer, opens in it offline, and drops an ended event on time. |

## Code map

- `web/app/Theme/Appearance.php` (+ `Concerns/ManagesEvents.php`): the only writer of the look and the events.
- `web/app/Theme/{ThemeEngine,Color,PaletteReport,Presets,EventPresets,EventCalendar,Visitor,AppearanceView}.php`.
- `web/app/Http/Controllers/Admin/{AppearanceController,AppearanceEventController}.php`; views `resources/views/admin/appearance/`.
- `web/app/Http/Controllers/{ThemeCssController,BrandAssetController}.php`, `Api/V1/AppearanceController.php`.
- App: `app/lib/data/appearance.dart` (look, events, banner, dismissals), `app/lib/features/appearance/welcome_banner.dart`.

## Beyond the reference engine

`ThemeEngine::heroEnd` softens the light end of the hero gradient when a bright green main colour (a national-day
green) would otherwise leave no text colour readable on both ends; every look the reference engine accepts is
unchanged (the shared vectors still match).

## What cannot be proven here

How a look appears on each real phone, the CDN's caching on the live site, the country header from Vercel (until
`QISTAS_GEO_HEADER` is set), and native-speaker review of the Arabic, French, Spanish and Urdu wording.

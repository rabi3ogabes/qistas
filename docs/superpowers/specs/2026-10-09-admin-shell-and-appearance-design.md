# Admin shell and Appearance studio: design note

Two things the owner asked for on 9 Oct 2026:

1. "Make the menu of the dashboard on the **right side**, arranged in a modern, luxury and professional way."
2. "The admin can control the **theme colours and images** and the **welcome banner** of the app, the website and the web app, from the admin dashboard."

Built on [`docs/THEME_ENGINE.md`](../../THEME_ENGINE.md) (26 tokens per mode, derivation, WCAG gate, versioning), whose reference
implementation is `brand/shared/qistas-theme.js`. Design direction follows the impeccable *Operate* rules: restrained colour, a second
neutral layer for the navigation, standard patterns, 150 to 250 ms motion that conveys state, no eyebrow labels, no same-size icon cards.

## Decisions

| # | Question | Decision | Cost if wrong |
|---|---|---|---|
| D1 | Which side is "right" in Arabic? | The navigation is on the **physical right** in every language, as asked (in a right-to-left page that is also the start side). | one CSS rule |
| D2 | Navigation on a phone | A slide-in panel from the right, opened by a menu button; it is a plain `#admin-nav:target` link so it works with no JavaScript, and JavaScript adds focus handling and Escape. | none |
| D3 | Scope of colour control | The **base theme only** (the engine's country and event layers stay for later). The admin picks four brand colours (primary, accent, interactive blue, canvas) or a preset; everything else, **including the whole dark mode**, is derived by the engine. | add layers later: the data model does not block them |
| D4 | Does a bad palette break the product? | No. Publishing runs the engine's contrast checks (22 pairs, light and dark) and **repairs failing foregrounds automatically**, telling the admin what moved. | none |
| D5 | Where images live | **In the database** (re-encoded, resized, no metadata, at most ~1.5 MB before processing), served from immutable content-addressed URLs so the CDN caches them. Vercel's disk is thrown away and no bucket is configured, so the private `files` disk cannot serve public pictures. | a later move to a bucket |
| D6 | Which images | Logo (light and dark variants), hero picture for the website, welcome-banner picture. JPEG and PNG only; never SVG (script risk). | add slots |
| D7 | Welcome banner | One per surface (website, web app, Android app): on/off, tone, picture, call-to-action, dismissible, optional dates, and text **in each of the five languages** (English required, the others fall back to it). | none |
| D8 | History | Draft, then **Publish**: each publish is a new immutable version; "Restore" publishes a copy of an older one; "Reset to Qistas" publishes the factory look. Every step is audited. | none |
| D9 | Who may change it | `super_admin` only (new gate `manage-appearance`). Other staff may look. | none |
| D10 | The admin console itself | Keeps the Qistas look whatever the brand setting, so a bad choice can never lock the owner out of the page that fixes it. Previews inside the page show the chosen look. | none |
| D11 | The Android app | Reads `GET /api/v1/appearance` (public, ETag), keeps the last answer on the phone, applies colours and shows the dashboard banner. The server resolves the full token set; the app never re-derives colours. | none |

## What the visitor sees

- **Website:** the chosen colours and logo on every page, the hero picture on the home page, and the welcome banner under the header.
- **Web app (`/app`):** colours and logo, and the banner at the top of the dashboard (dismissible; remembered per version on this device).
- **Android app:** colours and logo-free (the app keeps its drawn mark), and the banner as a card on the dashboard.
- **Admin console:** unchanged; its menu is now on the right.

## What cannot be proven here

Colour looks on a real phone, the CDN's caching of `/theme.css` and `/brand-assets/*` on the live site (checked after deploy with read-only
requests), and native-speaker review of the new Arabic, French, Spanish and Urdu wording.

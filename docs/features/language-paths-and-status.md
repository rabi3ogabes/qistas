# Language paths, moving pages and the status page (Sprint 2 Task 7, CM07, decision D10)

## Language paths

Every public page answers at its plain address and again under each language's path:

| Plain address (the reader's own language) | Language paths (always that language) |
| --- | --- |
| `/` | `/en`, `/ar`, `/fr`, `/es`, `/ur` |
| `/pricing` | `/en/pricing`, `/ar/pricing`, … |
| `/installment-calculator`, `/switch/notebook`, `/switch/excel`, `/switch/another-app`, `/status`, `/terms`, `/privacy`, `/account/delete` | the same under each language |

- **The plain address keeps choosing for the reader**: `?lang=`, then what this browser remembers, then the signed-in
  person's saved language, then the browser's language. It is the `x-default`.
- **A language path always reads in its language**, beats what was remembered, and is remembered in turn (cookie,
  session, profile), like choosing a language. A `?lang=` on a language path moves to that language's own path, so each
  address keeps one language.
- **English has its own path too** (`/en/…`), so every language has an address of its own for search engines; the plain
  address stays the reader's choice rather than becoming English-only, which keeps Arabic visitors on `/` in Arabic.
- Each page names its canonical (its own address), every language's path as `hreflang` alternates, and the plain
  address as `x-default`. The sitemap lists each page at its plain address and in every language path, each with its
  alternates. The status page is left out of the sitemap (it changes by the minute).
- A page's own links (the header, the footer, the calculator's share link, the legal pages) stay in the path it was
  opened in. The language button on a public page leads to that language's path and keeps the rest of the address;
  elsewhere (sign-in, the app, the admin) it keeps using `?lang=`.
- Only public pages have language paths: `/ar/login` or `/ar/app` are not found. A language the product does not ship
  (`/de/…`, or `/AR/…`) is not found.
- Code: `App\Site\SitePages` (the list of pages and their addresses), the `$site` route group in `routes/web.php`,
  `App\Http\Middleware\SetLocale`.

## Moving to Qistas

Three pages for a shop moving from a notebook (`/switch/notebook`), from Excel (`/switch/excel`) or from another app
(`/switch/another-app`), linked from every public page's footer. Each says how the move goes in numbered steps, then
why the books are safe, then how to start free.

The steps promise only what is switched on for everyone (`App\Site\SwitchGuide`):

- **Importing off** (as it ships): open a workspace, add your customers, record payments.
- **Importing on**: bring the sheet in (or copy the notebook into the template, or export from the old app), check the
  trial run, then confirm.
- **Photos read too** (AI reading on and the Anthropic key saved): photograph a notebook page; the old app's PDF
  reports are read too.

## Status page

`/status` (and `/ar/status`, …) checks on every visit, never cached:

- **Website and app**: answering (the page itself answered).
- **Database**: answering or not.
- **Scheduled work** (instalment alerts, late fees, the morning summary, the nightly copies): running when the last run
  went well under 15 minutes ago, delayed when it failed or went quiet, not started before `CRON_SECRET` and a first
  run. The time of the last run is shown ("Last ran 3 minutes ago").

It shows states and times only: never an error message, a count, a setting or anything about a workspace. Linked
from the footer as "Service status".

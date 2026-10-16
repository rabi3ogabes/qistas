# Site speed

Asked by the owner on 16 October 2026 ("speed up the site"), after forms were found to be sent twice while slow pages
loaded.

## What was measured (16 October 2026, from the owner's computer)

| Request | Time | Where it went |
|---|---|---|
| Any page, first visit after a quiet spell | 2.8 to 4.2 s | A new copy of the server starting: the full database set-up (migrations, feature switches, security rules on every table) ran before it could answer |
| Any page, warm, same connection | 0.47 to 0.52 s | About 0.23 s to Vercel's edge in Mumbai (bom1), about 0.2 s from Mumbai to the server in Washington (iad1) and back, the rest PHP and the database |
| A built file (JavaScript, main stylesheet) | about 0.23 s | Already kept by the edge near the visitor |
| The colours stylesheet every page waits on, robots.txt, the sitemap, brand pictures | about 0.47 s each, every time | Sent to Washington every time: each answer carried a sign-in cookie, and Vercel's edge never keeps an answer that sets a cookie |

The scheduler that should call the site every five minutes is run by GitHub only every few hours, so copies of the
server go quiet and the next visitor waits for a new one.

## What changed

- **Cold starts:** `qistas:setup` leaves a fingerprint of the release (its migrations, its features, the version of
  the security rules, `SecureDatabaseCommand::VERSION`). A copy of the same release that starts later reads it and does
  nothing more (one query instead of hundreds of statements). A new release, or `--force`, does the whole set-up.
  Raise `SecureDatabaseCommand::VERSION` whenever its rules change.
- **Every request:** routes and event listeners are cached when the image is built, the settings when the container
  starts (`docker/entrypoint.sh`), and PHP keeps compiled code in memory without re-checking files
  (`zz-qistas-speed.ini`).
- **Database round trips:** a signed-in page asked the database what the plan allows 13 or 14 times (five queries
  each) and the person's role up to nine times: about 100 queries a page, each a trip to the database. These answers
  are now read once per request (`App\Support\RequestMemo`, used by `Entitlements`, `Tenant::currentPlan()`,
  `User::roleIn()` and the avatar) and forgotten the moment any query writes to a table they come from, when a new
  request starts, and once the clock moves a minute (a plan running out). The dashboard went from 101 queries to 42,
  the customers, contracts and payments lists from 88 to 98 to about 25.
- **The edge:** the colours stylesheet, robots.txt, the sitemap and brand pictures are answered without a session, so
  with no cookie, and say how long they may be kept; Vercel's edge near the visitor keeps them. `/brand/*` files are
  kept a day.
- **Measuring:** every answer has a `Server-Timing` header (`app` = the server's whole time, `db` = the database's,
  and how many queries), visible in the browser's network panel. CI's "Speed checks" step prints it for a few pages,
  checks that a restart of the same release skips the set-up, and that the shared files set no cookie.

## Live after the change (16 October 2026, deployment of cee110c)

| Measure | Before | After |
|---|---|---|
| A new copy of the server starting | full set-up (migrations, switches, grants on every table) | "The database is already set up for this release.": one query |
| Server time, health check | not measured | 6 to 8 ms |
| Server time, home, sign-in, pricing, calculator (warm) | not measured | 65 to 80 ms, 40 to 60 ms of it the database (5 to 11 queries) |
| Database queries, signed-in pages | about 90 to 100 | about 25 to 42 (measured locally; live each query costs 5 to 8 ms) |
| Colours stylesheet, robots.txt, sitemap, favicon | from Washington every time, 0.47 s | from the edge (HIT, no cookie), 0.21 s |
| A public page from the owner's computer, warm connection | 0.46 to 0.52 s | 0.43 to 0.48 s: almost all of it is now the distance below |

Each live query costs 5 to 8 ms (a trip from Vercel to Supabase's pooler), so the signed-in pages gain the most: about
60 fewer queries is roughly 0.3 to 0.5 s less per page. Nobody signs in to the live site for measurements, so that
figure is worked out, not measured.

Every push to main builds and stores a new image, phone-app and documentation commits too, so the registry fills up
again over time. An `ignoreCommand` in vercel.json (bb7fee9) made Vercel refuse the commit outright with this project's
container services, so it was taken out again. The same rule can be set in the dashboard instead (project → Settings →
Git → Ignored Build Step: `git diff HEAD^ HEAD --quiet -- web/ vercel.json`); that is the owner's setting to change.

## The biggest remaining cost: distance

Visitors in the Gulf reach Vercel in Mumbai, and every page is then made in Washington, next to the database in AWS
us-east-1: about 0.2 s of every page is that trip. Moving both the server and the database next to the visitors would
remove most of it:

- a Supabase project in **Mumbai (ap-south-1)** and the Vercel functions in **bom1**;
- the data copied across once (a short read-only window), `DB_URL` changed, the old project kept until checked.

This moves the live database, so it is the owner's decision and needs the owner's Supabase account; it is described,
not done.

## Deploys blocked on 16 October 2026

Vercel refused the deploy of 4980de7: "repository has reached the maximum allowed number of images" (the project's
container registry). Old images are deleted by the owner in the Vercel dashboard, project → Sandboxes → Container
Registry, repository "app"; then the latest commit is deployed again. Done on 16 October 2026 (the first deletion did
not take; the second did), and cee110c went live.

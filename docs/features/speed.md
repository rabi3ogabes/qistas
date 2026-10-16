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
- **The edge:** the colours stylesheet, robots.txt, the sitemap and brand pictures are answered without a session, so
  with no cookie, and say how long they may be kept; Vercel's edge near the visitor keeps them. `/brand/*` files are
  kept a day.
- **Measuring:** every answer has a `Server-Timing` header (`app` = the server's whole time, `db` = the database's,
  and how many queries), visible in the browser's network panel. CI's "Speed checks" step prints it for a few pages,
  checks that a restart of the same release skips the set-up, and that the shared files set no cookie.

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
Registry; then the latest commit is deployed again.

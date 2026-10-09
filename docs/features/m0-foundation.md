# Milestone M0: the foundation

What was built so that every later feature can ship dark and be switched on from a page, with no deploy. Nothing here
changes what a workspace owner sees today: the seven features that already work stay on, and everything new starts off.

Design and decisions: [`docs/superpowers/specs/2026-10-08-m0-foundation-design.md`](../superpowers/specs/2026-10-08-m0-foundation-design.md).
Plan, task by task: [`docs/superpowers/plans/2026-10-08-m0-foundation.md`](../superpowers/plans/2026-10-08-m0-foundation.md).
The brief it implements: [`QISTAS_FEATURES_BRIEF.md`](QISTAS_FEATURES_BRIEF.md), Part 9.

## 1. The switches

A feature is in one of three platform states, set by a super admin on **Admin, Feature control** (`/admin/features`):

| State | Meaning |
|---|---|
| **Off** | Off everywhere: its pages and endpoints answer "not available", its queued work does nothing, both apps hide it. No data is ever deleted by a switch. |
| **Beta** | On only for workspaces that were given access (an override with a reason and, if wished, an end date). |
| **On** | On for every workspace whose **plan** includes it. |

What a feature *is* for one workspace is worked out in one place, in this order: the platform state, then the plan, then the
workspace's own override. The answer is one of three **statuses**:

| Status | Shown to the person | Answer from the API |
|---|---|---|
| `on` | usable | 200 |
| `plan_locked` | shown locked, with the way to upgrade | `402 feature_locked` (or `limit_reached` for a used-up allowance) |
| `platform_off` | not shown at all: there is nothing to buy | `403 feature_unavailable` |

A feature that needs another one (`dependsOn`) follows it: if the other is off at the platform, this one is `platform_off`
with `detail: dependency:<key>`; if the plan lacks the other, this one is `plan_locked`.

**The seven features that already existed are *core***: `customers`, `active_contracts`, `api_tokens`, `pdf_statements`,
`export_csv`, `advanced_reports`, `custom_branding`. They launch On, their platform switch is locked, and their plan chips
stay editable. A feature missing from the table behaves as its `launchState()` says, so a deploy can never lock anyone out.

### The cockpit

Dense rows grouped by epic: a switch (Off, Beta, On), the plans that include it, how many workspaces used it in the last
30 days, what it depends on, and a details drawer (what "off" does to its data and running work). It works without
JavaScript (plain forms); JavaScript makes it instant, with a 6-second **Undo** after any change. Turning off something that
was used in the last 30 days asks for a reason. Presets (**Dark launch**, **Essentials**, **Full programme**, **Restore previous**)
show what would change first and keep a snapshot to restore. **Pause all automation** switches off every feature that sends
or acts on its own, in one step. Every change is audited (who, from, to, why, whether it was an undo).

Only a `super_admin` may change anything; other staff may look.

### From a terminal

```sh
php artisan qistas:features list                                     # every feature: state, plans, use
php artisan qistas:features state advanced_reports beta --reason="trial with two shops"
php artisan qistas:features plan export_csv pro on --limit=100       # a plan's setting for a feature
php artisan qistas:features sync                                      # add rows for features added in code (also runs on every start)
```

A change made here is audited as the operator. A reason is required to reduce a feature that workspaces have been using.

### For code that builds a feature

- Guard a route: `->middleware('feature:advanced_reports')`; in Blade: `@feature('advanced_reports') ... @endfeature`.
- Guard queued work: extend `App\Jobs\FeatureJob`. It checks the switch again **when it runs**: a job queued while the
  feature was on and run after it was turned off does nothing and logs `skipped: feature_off`.
- Count use (counts only, never content): `FeatureUsage::hit(Feature::X)`. It feeds the "used by N workspaces" badge.
- Per-workspace settings: declare a `SettingDefinition` (a switch, a whole number or a choice) in `SettingsRegistry`; the
  owner sees it under **Instalment tools** on the website and in the app, with no new screen.

## 2. Scheduled work without a worker

The host has no scheduler and no queue worker, so something outside calls `GET|POST /internal/cron` every few minutes with
`Authorization: Bearer <CRON_SECRET>`. Each call runs whatever is due in the Laravel scheduler, then works through jobs
waiting on the `database` queue, until a time cap (`CRON_MAX_SECONDS`, 50). It records each run (`cron_runs`).

- No `CRON_SECRET`: **503**, nothing runs. Wrong or missing secret: **401**. Another call still running: **409**. Rate-limited.
- Compared as hashes, so neither the contents nor the length of the secret leak through timing. Not accepted from the
  address or the body.
- `php artisan qistas:cron-status` shows the last run, how long ago, and the last failures. The admin overview's
  *Is everything set up?* shows **Scheduled work**: calm "not set up yet" until the first run, good while the last run is
  under 15 minutes old, a warning when it has gone quiet or failed.
- The caller is the **Scheduler** workflow on GitHub (`.github/workflows/cron.yml`, every five minutes). It does nothing
  until the repository secrets `CRON_URL` and `CRON_SECRET` exist. `vercel.json` is untouched.
- Scheduled today: `qistas:prune-demo` every 15 minutes.

## 3. Private file storage

`App\Support\Files` is the only way files go in and out. A file is kept at `tenants/{workspace}/{kind}/{id}.{ext}` on the
private `files` disk and handed out only through a link that expires within **five minutes**.

- **What it is is read from its bytes.** The name the client gave is never used, not even for the extension. A web page called
  `x.jpg`, a script called `invoice.pdf.php`, a PDF called `x.png` are refused; a real PDF is stored as `.pdf` whatever its name.
- Each **kind** (an enum, so no path can be built from client input) says which types it takes, who may open it and whether
  opening it is audited: `id_front`, `id_back` (audited; owner, manager and accountant only), `payment_proof`, `cheque_front`,
  `cheque_back`, `contract_pdf`, `signature`, `logo`, `statement`. Images up to 6 MB, PDFs up to 10 MB.
- **Pictures are drawn again**, which removes EXIF (where and when a phone took it), profiles and anything appended; a
  sideways phone photo is turned upright first. Pictures larger than 40 million pixels are refused before they are opened.
- `GET /app/files/{file}` checks the workspace and the role, writes an audit row for an ID document, and redirects to the
  short-lived link. Another workspace's file is a 404, exactly like one that does not exist.
- Deleting removes the object and keeps the row (soft delete).
- **Where it lives:** `FILESYSTEM_DISK=s3` with `AWS_*` points it at an S3-compatible bucket (Supabase Storage works through its
  S3 endpoint). Without that it is a private folder, which is fine for development and **not durable on Vercel**.
  Nothing in M0 stores a customer's file yet.

## 4. The PDF renderer

`App\Documents\PdfRenderer::render($view, $data, $language, $quota = null)` returns PDF bytes (mPDF): A4, Geist for Latin and
IBM Plex Sans Arabic for Arabic and Urdu, right-to-left with shaped letters, the brand header and footer with page numbers on
every page, and a slot for a workspace's own branding (logo and accent) that later work fills.

- When a `$quota` feature is given (for example `pdf_statements`), one unit of the monthly allowance is used **after** the
  document exists; a render that fails costs nothing, and one past the limit throws `LimitReached`.
- `QrCode::svg($url)` draws a QR code for a printed document's verification link (no new dependency).
- `php artisan qistas:pdf-preview ar` writes `storage/app/previews/sample-ar.pdf` (and a PNG when `pdftoppm` exists) for the
  eye to check.
- The fonts are copied into `web/resources/fonts` (with their open-font licences) because the web image is built from `web/` only.

## 5. The apps

**Website.** The language control is a button of its own on every page, apart from every menu. **Android app (1.2.0).** The
same button on every screen; entitlements carry `status` (a feature the platform switched off is hidden, one the plan lacks stays
locked); a `403 feature_unavailable` makes the app read the account again (at most once every ten seconds); Settings shows
**Instalment tools** when the server lists any.

## 6. What is proven, and what is not

Proven by tests (and mutation checks that break each rule in turn): the resolution order, the beta edges, the job re-check,
the cron door (no secret, wrong secrets, GET versus POST, overlap, time cap), the upload attacks, quota consumption, the
Arabic text coming back out of a rendered PDF, and the apps' reading of the status.

Not proven here, and said so: real S3 or Supabase behaviour (signed links and EXIF on a real phone photo were checked
against the local disk and a signing-only S3 client), a real scheduled tick on GitHub (needs the owner's two secrets), the
production container (checked by CI's image build), and a real phone.

## 7. What the owner does later

| For | Do | Until then |
|---|---|---|
| Scheduled work | set `CRON_SECRET` in Vercel; add repository secrets `CRON_URL` and `CRON_SECRET` on GitHub | the endpoint answers 503; the workflow does nothing |
| File storage | set `FILESYSTEM_DISK=s3` and the `AWS_*` values | uploads use the local folder (not durable on Vercel) |
| E-mail | the SMTP settings (unchanged from before) | e-mails go to the log |

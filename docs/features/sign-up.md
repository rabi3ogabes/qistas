# Sign-up with five fields, an automatic country, and a record of every sign-in

Asked by the owner on 1 November 2026 as the first release of the "go live" package: make creating an account shorter,
stop asking for what the product can work out, record where each sign-in comes from, and take the public demo away.
This is **Release 1**. Release 2 (an error center for the site, the API, the database and the app) is built: see
`error-center.md`. Release 3 (sign-up and sign-in with a phone number and a code by SMS with WhatsApp as the backup, plus
Google and Microsoft/Outlook accounts) is a separate piece of work with its own design and plan, and is not built yet.

## What a person fills in

Five things, on the website and in the phone app alike: **name, e-mail, password, the password again, and a tick for the
terms**. There is no "Business name" and no "Country".

Behind the form the browser also sends its **time zone** (the website: a hidden field filled by `resources/js/timezone.js`;
the app: the phone's offset from UTC) and, from the app, a short **device hint** (whether the device is a phone or a tablet,
its operating system and the app version; the app has no way to read the phone's model name). Nobody types any of it.

**Older apps.** App versions up to 1.28 still ask for a business name and a country and send them. The server accepts
both: the workspace is then named and placed as typed, counts as set up at once, and shows no card. A country sent this
way wins over the detected one (source `chosen`). The password rules did not change: at least 10 characters with upper
and lower case, a number and a symbol, and known-breached passwords are refused.

## How the country is found

The workspace gets a country, a currency and a time zone at the moment of sign-up. The country is the first of these that
gives an answer:

1. **The CDN's country header**, when `QISTAS_GEO_HEADER` names one (behind Cloudflare: `CF-IPCountry`). The codes that
   mean "no country" (`XX`, `T1`, `A1`, `A2`, `O1`, `EU`, `AP`) are ignored. This is the best source, and the person
   cannot set it from the browser, as long as the host accepts traffic only through the CDN.
2. **The country of the time zone** the browser reports (Asia/Riyadh is Saudi Arabia). A zone that names no place, such
   as UTC, is ignored.
3. **The region in the browser's language** (ar-SA is Saudi Arabia).
4. **`QISTAS_DEFAULT_COUNTRY`**, which is `SA` unless changed.

The workspace remembers which of the four it used (`tenants.country_source`: `header`, `timezone`, `language`, `default`,
or `chosen` once the owner picks one), so the first-run card below can say how sure it is: a country read from the
time zone or the language is flagged as a guess.

The workspace's own time zone is the real zone the browser reported (when it names a place); for the phone, which reports
only its offset from UTC, the first zone of the country that has that offset now; otherwise the country's usual zone.

## The first-run card

Because the workspace has no business name at sign-up, it is created with a placeholder ("Business of Layla", in the
language the person signed up in) and is **not yet set up** (`tenants.setup_confirmed_at` is empty). The first time the
owner opens Today (website or app) a card at the top asks for two things at once:

- **The business name**, required. The placeholder name shows greyed in the empty field until the owner types.
- **The region**, as one sentence ("We think you are in Saudi Arabia: SAR, Saudi Arabia Time." on the website; the app names the zone's city instead) with a *Change* link that opens
  the country chooser, when the country was found automatically (with "Guessed from your device's time zone, so it may be
  wrong" when the zone or the language was the source); as the chooser at once when nothing was found; or as a locked
  line when the region can no longer change (next section). The website's card never sends a time zone: the server derives
  it from the country.

One tap on **Save and continue** stores the name, and the country only if the person changed it; the card then
disappears and never returns for that workspace. Only the owner sees the card. Anyone else in the business sees one quiet
line where it would be ("Your business owner needs to name the business before documents can be printed.").

**What waits for the card.** Documents (contract PDFs, statements, receipts) are refused until the workspace is set up:
the API answers `409` with the error code `workspace_setup_required`; on the website the person is sent back to Today
with a warning; the app opens the card. Everything else works from the first minute, except that the placeholder name is kept out of what a customer reads
until the business is named. The website's reminder list and the customer's signing page carry the name printed on the
documents, if the owner set one, and no name otherwise; the phone's own reminder and receipt texts carry no name at all. Workspaces that existed before this
release were marked as set up from their own creation date, so nobody already using Qistas sees the card.

**An older app on a new website account.** App 1.28 and earlier have no card. If someone signs up on the website (which
asks for no business name) and then uses app 1.28, a document answers "name your business first" and the app can do
nothing about it: name the business on the website, or update the app.

**When the region can change.** Only while the workspace has recorded no money: no contract, no payment, no investor
deposit or withdrawal and no closed day. A currency is stamped into all of those, so changing it afterwards would silently
change what they mean. After the first of them the country, currency and zone are fixed, the card (if it is still
showing) asks only for the name and says why ("... Contact support to change it."), and changing the region means
writing to support. A change made while it is still open also moves the workspace's investor rows to the new currency.

Code: `App\Support\SignupPlace` (detection), `App\Actions\RegisterTenantOwner` and
`App\Actions\Fortify\CreateNewUser` (sign-up), `App\Actions\ConfirmWorkspaceSetup` (the card's save),
`App\Documents\WorkspaceSetupRequired` (the gate in `DocumentService::issue()`), `PUT /api/v1/workspace/setup`
(`workspace.setup`) and `PUT /app/setup` (`app.setup.update`), `AccountPayload` (`tenant.setup`, which an old server
simply leaves out and the app then shows no card). App: `app/lib/features/setup/`.
Tests: `tests/Feature/Tenancy/SignupPlaceTest.php`, `tests/Feature/Api/WorkspaceSetupTest.php`,
`tests/Feature/App/SetupCardTest.php`, `tests/Feature/Documents/SetupGateTest.php`,
`app/test/features/setup_card_test.dart`.

**Known limit: no time-zone picker.** The design asked for a time-zone picker next to the country chooser. It was not
built, on the website or in the app: the server accepts a `timezone` on `PUT /workspace/setup`, but neither card sends one.
In practice the zone is already right from sign-up (the real zone the browser reports, or the zone of the country that
has the phone's offset). It is wrong only when an owner *changes the country* on the card to one with several time zones
(of the 35 supported: United States, Canada, Australia, Indonesia, Spain), because the new country then gets its usual
zone. Support can correct it; it locks after the first contract. A zone picker for those five is the follow-up.

**Known limit: country names in the app's sign-in list.** The website names every country in the person's language; the
app has names for the 35 supported countries only and shows the two-letter code ("RU") for any other. A fuller table,
or a name supplied by the server, is the follow-up.

## What is recorded at every sign-in

One row in `sign_in_events` for every successful sign-in (website, app, the API, the end of the two-step check, and the
sign-up itself) and for every **wrong password on an account that exists**. A wrong password for an address that has no
account is not recorded: there is nobody to attach it to. Per row:

| What | Notes |
|---|---|
| When, how | time; method (`password` now; `otp`, `google`, `microsoft` come with Release 3); channel (`web` or `app`); success or failed |
| Device | `desktop`, `mobile`, `tablet` or `unknown`; operating system and browser (read from the browser's own description with the open-source `matomo/device-detector`); for the app, its version (the phone's model name is not recorded) |
| Where | the IP address, and the country the CDN says it is in (empty without `QISTAS_GEO_HEADER`) |
| Proxy | `not_detected`, `possible` or `detected`, and which signals fired |
| Raw | the browser's user-agent text, cut at 255 characters |

On a **wrong password** nothing the app reports about itself is kept (no device type, model, system or version): that row holds only what the connection's own user-agent text says, so a stranger who knows an e-mail address cannot write anything into someone's list.

**Never stored:** the e-mail or anything else a person typed, the password, a code, a token.

**Kept for 90 days** (`QISTAS_SIGN_IN_DAYS`), then removed by the daily `php artisan qistas:sign-ins:prune`. A person's
rows go when the person is deleted. Rows cannot be edited or deleted in the application; only the prune removes them.

**A flood cannot fill it:** a failed row is written at most once a minute per account and address.

### Who sees it

- **The person**, on the website's Security page and in the app under Devices ("Recent sign-ins"): up to 20 rows, newest
  first, with at most the 5 newest failed attempts among them, in plain words ("Mobile, Chrome on Android, Qatar, proxy
  not detected, 5 minutes ago"). Failed attempts are marked.
- **Platform staff**, on a business's page in the admin console, for each person of that business: up to 10 rows with at
  most 3 failed, and the IP address. Looking is itself recorded in the audit log (`admin.sign_ins_viewed`).
- Nobody else. A workspace's members do not see each other's.

Writing the row never slows or breaks a sign-in: it happens after the response has been sent, and a failure to write is
swallowed. Code: `App\Auth\SignInLog`, `App\Auth\SignInRecorder`, `App\Auth\DeviceProfile`, `App\Auth\ProxyCheck`,
`App\Models\SignInEvent`. Tests: `tests/Feature/Auth/SignInHooksTest.php`, `SignInRecorderTest.php`, `SignInSurfacesTest.php`, `PruneSignInsTest.php`.

### What "proxy detected" really means

The check uses only free signals and says **detected**, **possible** or **not detected**, never "certain". Each signal
can be wrong.

| Signal | Meaning | Counts as |
|---|---|---|
| `tor` | the CDN's own country header says `T1` (Tor), believed only when `QISTAS_GEO_HEADER` is set | detected |
| `via_header` | the request carries a `Via` header, which proxies add to announce themselves | detected |
| `forwarded_chain` | `X-Forwarded-For` lists more addresses than `QISTAS_PROXY_CHAIN_ALLOWED` (default 2: a normal visit already passes the CDN and the host's own proxy) | detected |
| `timezone_country_mismatch` | the connection's country is known and the browser's time zone is not one of that country's zones | possible (weak, alone) |

What it **cannot** see: a VPN or a residential proxy that adds no header and travels from the right country is "not
detected". It is a pointer for the person ("was this me?") and for support, not a security gate: no sign-in is ever
refused because of it. A paid IP-intelligence service can replace the check behind the same method later (the owner chose
"free checks now, paid service later").

After going live, sign in once from your own phone and computer and open the Security page: both should read "proxy not
detected". If your own normal sign-in reads "detected", the host adds a hop: raise `QISTAS_PROXY_CHAIN_ALLOWED` by one
(and check the host does not add a `Via` header).

### A proposal for the privacy page (not published)

> Each time you sign in, and each time someone enters a wrong password for your account, we keep the date, the kind of
> device and browser, the address your connection comes from, the country it appears to be in and whether it seems to come
> through a proxy, for 90 days. You can see these on your Security page, and our support team can see them when helping
> you. They are there so you can spot a sign-in that is not yours. We never keep what you typed.

**Proposal: not published until the owner approves it.** The privacy page is the owner's text; this file only holds the
sentence ready.

## The demo is gone

The public "Try the demo" button, its limits and its admin page were removed. What stays is the container's own demo mode
(`QISTAS_DEMO`, the banner and `DemoSeeder`) for developers and for the end-to-end test; it is off on the live site and
the go-live check fails if it is on. Accounts the old button left behind, and the developer seeder's account, are listed
and removed by `php artisan qistas:purge-demo` (see `go-live.md`).

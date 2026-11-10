# Release 3: sign up and sign in with a phone number, Google or Microsoft

Status: **design for the owner's review** (2026-11-10). Nothing in this document is built. Release 1 (five-field sign-up,
sign-in record, no demo) and Release 2 (error center) are live; see `docs/features/sign-up.md` and
`docs/features/error-center.md`. Release 1's design promised to "keep the door open" for this release: nothing assumes an
account has an e-mail, and `sign_in_events.method` has room for `otp`, `google` and `microsoft`. The audit in section 3.6
shows where that promise is only half true.

## 1. What is being built, and why

The owner asked on 1 November 2026 for sign-up with a **phone number** (a code by SMS, with WhatsApp as the backup) and
with **Google** and **Microsoft/Outlook** accounts, with no e-mail required. The people who use Qistas run a shop from a
phone and live in WhatsApp. An e-mail address and a strong password are the slowest part of starting.

**Success** looks like this:

- A shop owner opens the app, types a name, a phone number and a password (or taps *Continue with Google*), gets a code,
  and is inside in under a minute. No e-mail is needed, now or later.
- Everyone who signs in today with an e-mail and a password notices nothing, and no old app version breaks.
- No new way in is a way to take over somebody else's account, and no new way costs real money to anyone who fakes it
  (a code by SMS costs the owner money every time one is sent).

**What the owner said, and what is assumed.** Said: phone, Google and Outlook sign-up; SMS first with WhatsApp as the
backup; no business name or country at sign-up (done); the owner creates the provider accounts and the keys go to the
server's Environment page. Assumed here, change any of it (section 11): Twilio is the first real provider; a phone number
also signs in (not only signs up); the app hides Google and Microsoft on iPhone until Sign in with Apple exists (section 7.2).

## 2. Three parts, three plans

This is too large for one plan. It ships as three parts in order; each is deployable alone, switched **off** until its
keys exist (like card billing), and has its own plan and review.

| Part | What | Visible to people |
|---|---|---|
| **A. Accounts without an e-mail or a password** | `email` and `password` become optional; the sign-in methods an account has; a safe way to confirm "it is really you" without a password; every place the audit found that breaks | Nothing (fixes, a Sign-in methods page) |
| **B. Phone and code** | Codes, the SMS and WhatsApp senders, sign-up, sign-in, "send me a code", password recovery by code, add or change a phone in Settings; website, API, app | A phone tab on sign-up, "E-mail or phone" on sign-in |
| **C. Google and Microsoft** | OpenID Connect sign-in, the phone app's way through the browser, account linking rules, connect and disconnect in Settings | Two buttons |

## 3. Part A: accounts without an e-mail or a password

### 3.1 Data

| Change | Detail |
|---|---|
| `users.email` | nullable; still unique (NULL is never equal to NULL in SQLite and PostgreSQL, so many accounts may have none). **Blank is stored as NULL, never as an empty string.** |
| `users.password` | nullable. NULL means "this person has no password". |
| `users.phone_e164` | new, `varchar(16)`, nullable, **unique**: the verified phone number in international form (`+966501234567`). |
| `users.phone_verified_at` | new, nullable timestamp. `phone_e164` and this are set and cleared together. |
| `users.phone` | stays: the free-text number on the profile that text-message notices use. When a number is verified it is set to the same number; from then on the profile shows it locked, and changing it runs the code check again. |
| `user_identities` | new: `id` uuid, `user_id` (cascade), `provider` (`google`, `microsoft`), `subject` (the provider's stable `sub`), `tenant_ref` (Microsoft's `tid`, nullable), `email` (as the provider reported it, for display only), `email_verified` bool, `created_at`, `last_used_at`. **Unique on (`provider`, `subject`).** Identity is the `subject`, **never the e-mail** (section 6.4). |
| `otp_challenges` | new, section 5.1. |
| `sign_in_events.method` | accepts `otp`, `google`, `microsoft` (the column is `varchar(16)`; no change). |

The `User::email()` mutator and `RegisterTenantOwner` accept null. `UserFactory` keeps producing e-mail users; new factory states
`phoneOnly()` and `socialOnly()` serve the tests.

### 3.2 The rule every account must keep

An account always has **at least one working way to sign in**:

1. a password **and** an identifier to type with it (a verified phone number or an e-mail), or
2. a verified phone number (sign in with a code), or
3. a linked Google or Microsoft identity.

Disconnecting, removing a phone, removing an e-mail or deleting a password that would leave none of these is refused with
a plain message ("This is your only way to sign in. Add another first."). One class, `App\Auth\SignInMethods`, answers
"what does this person have, and may they remove X", and every screen and endpoint asks it.

### 3.3 Confirming it is really you without a password

Today four things ask for the password again: opening the security area (`password.confirm`), turning two-step sign-in on
or off, changing the password, and deleting the account (an app-store requirement). A person with no password, or with
one they have never used, must still be able to do all four, deletion most of all.

`App\Auth\StepUp` offers the methods the person has: **the password**; **a code sent to their verified phone**; or **a fresh
sign-in with the provider** they linked (within the last 5 minutes). It replaces the direct `Hash::check` calls. A person
with no password can **set one** from the Sign-in methods page after a step-up (so "change password" with no current
password becomes "set a password").

### 3.4 Platform staff keep what they have

Accounts with a `platform_role` **must have an e-mail and a password and can only sign in with them**. Phone codes and
social sign-in are refused for them, a phone or provider cannot be linked, and `qistas:make-admin` still takes an e-mail.
The admin console is the most valuable thing a stolen phone number or Google account could reach, and the owner already
chose password-only for staff (28 Oct 2026).

### 3.5 A security bug to fix first

Fortify's password confirmation looks the user up by **e-mail**. With a null e-mail, Eloquent turns the lookup into
`whereNull('email')`, which finds the **first** account with no e-mail and checks the typed password against *that
person's* hash. Part A replaces it (`Fortify::confirmPasswordsUsing`) with the person who is signed in, through `StepUp`.
This is the first task of Part A and has a test that fails today if two e-mail-less accounts exist.

### 3.6 Everything else the audit found

Grouped; each item becomes a task or part of one, with a test.

**Breaks and must change**

- `CreateNewUser`, `InvitationController` (join by invitation requires an e-mail), `UpdateUserProfileInformation` (a blank
  e-mail fails `required`, so an e-mail-less person cannot save *any* profile change), `MakeAdminCommand` (staff only; stays
  e-mail based, prints safely).
- `AuthenticateUser` (looks up by e-mail only), the API login (`email` is `required`), the login throttle keys (they use the
  typed e-mail; without one, every phone sign-in from one address would share a bucket), password reset (keyed by e-mail).
- `RequestAccountDeletion` and `UpdateUserPassword` (both `Hash::check` against a null hash), the app's profile screen (an
  empty e-mail is a client-side error and `updateProfile` always sends `email`), the app's delete-account screen (the button
  waits for a password), the app's login and register screens (e-mail is the identity).
- `Notifier` (`Mail::to($user)` throws on a null address after the database and push work are done), `ErrorAlerts`
  recipients and `MailController` test e-mail (staff always have an e-mail; add `whereNotNull('email')` as a belt and
  braces and let the go-live check fail a super admin without one).

**Degrades and must be handled**

- The default `Registered` listener, `VerifyEmail` and `ResetPassword` notifications, and `Notifiable::routeNotificationForMail`
  for a null address: send nothing, record nothing, do not queue.
- The "Verify your e-mail" banner (web `layouts/app.blade.php:108`, app `shell.dart:110,192`) shows only when there *is* an
  e-mail that is unverified. An e-mail-less account sees no banner; Sign-in methods offers "Add an e-mail (optional)".
- `AccountPayload` sends `user.email: null`, and now also `user.phone_verified`, `user.has_password` and
  `user.sign_in_methods`; `docs/api/openapi.yaml` gets the nullable fields and new endpoints. Old apps tolerate a null
  e-mail (they show an empty line) but cannot save their profile; that is the reason for the app release in Part B.
- Stripe: `CardCustomers` creates the customer with the owner's e-mail. With none, checkout asks for one ("Where should
  receipts go?", stored as the *billing* e-mail on the workspace, not on the login). The page that prints "Receipts go to"
  and `CardCheckoutController` use it.
- The admin console shows "—" for a missing e-mail (businesses, billing, support, mail pages, owner column, the per-person
  sign-in header) and its owner search also matches a verified phone number.
- Notification settings explain the e-mail channel only to people who have an address.
- `TrustedDevice` signs its cookie with the password hash; with no password it uses the user's id and a per-user secret, so
  "sign-in methods changed" still ends trusted devices.
- Lang strings: about 45 per language mention e-mail; the sign-in, sign-up, forgot-password and delete-account texts are
  reworded to "e-mail or phone" where they decide what to type. French, Spanish and Urdu fall back per key as always;
  Arabic is written with the same care as before and is **not** native-reviewed.

**Cosmetic** (blank line under the name in the web layouts, team list, profile header): handled by one `User::contactLine()`
helper that returns the e-mail, else the formatted phone, else nothing.

**Tests.** 67 test files mention e-mail and 72 use `User::factory()` (which keeps a verified e-mail, so they stay green).
About a dozen in `tests/Feature/Auth`, `Account`, `Api/AuthTest.php` and `Errors` assert the old shape and are rewritten, not
loosened.

## 4. Sign-in identifiers

One box, **"E-mail or phone"**, on the website and in the app.

- Contains `@` → e-mail lookup (as today, lower-cased, trimmed).
- Otherwise → parsed as a phone number with the visitor's detected country as the default region
  (`SignupPlace` already finds it; a Saudi visitor may type `05…`), normalised to E.164, and looked up on `phone_e164`.
  Only **verified** numbers sign anybody in.
- The same answer, the same work and the same time for "no such account", a wrong password and an account that has no
  password: *"These details don't match."* The decoy-hash trick in `AuthenticateUser` already does this; it is extended.
- Throttle keys use the normalised identifier (e-mail or E.164) plus the address, as now.
- Old apps keep sending `email`; the API accepts `identifier` as the new name and `email` as an alias.

## 5. Part B: phone and code

### 5.1 The code

- **Six digits**, `random_int`, valid **10 minutes**, **5 wrong tries** then the challenge is dead, one live challenge per
  phone and purpose (a new one kills the old).
- Stored only as `HMAC-SHA256(app key, challenge id . code)`, compared with `hash_equals`. The code itself is never stored
  and never logged (the error scrubber already hides digit runs in messages; a test pins that a code cannot reach a log).
- `otp_challenges`: `id` uuid, `purpose` (`signup`, `signin`, `reset`, `add_phone`, `step_up`), `phone_e164`, `user_id`
  (nullable), `code_hash`, `channel` (`sms`, `whatsapp`), `attempts`, `sends`, `payload` (encrypted, for a pending sign-up:
  name, **password already hashed**, locale, zone, country; never the plain password), `ip`, `expires_at`, `consumed_at`,
  `created_at`. A challenge is consumed once, atomically (`UPDATE … WHERE consumed_at IS NULL`). Removed by a daily
  `qistas:otp:prune` once it expired more than a day ago (scheduled beside `qistas:sign-ins:prune`).
- **No account exists until the code is right.** A sign-up holds the details in the challenge and creates the user, the
  workspace and the phone verification in one transaction when the code is accepted. Nobody can squat a number they do not
  own, and an abandoned sign-up leaves nothing behind.

### 5.2 Sending: SMS first, WhatsApp as the backup

Two small interfaces, so the provider is a detail:

```php
interface CodeSender {
    public function channel(): string;                 // 'sms' | 'whatsapp'
    public function available(): bool;                 // keys present and switched on
    public function sendCode(string $e164, string $code, string $locale): bool;   // true = the provider accepted it
}
```

- The existing `App\Sms\SmsSender` (`available()`, `send()`) stays for notices; `SmsCodeSender` uses it. `NoSmsProvider`
  stays the default, so **phone sign-in is invisible until a provider is configured**.
- Drivers (one class each, chosen by `QISTAS_SMS_DRIVER` and `QISTAS_WHATSAPP_DRIVER`): `twilio` (SMS and WhatsApp with one
  account), `log` (development only: writes the code to the log; the go-live check **fails** if it is on in production),
  `none`.
- The SMS text is short, in the person's language, ends with the standard one-time-code line for browser and phone
  autofill (`@www.qistasme.com #123456`), and says not to share the code. The WhatsApp message uses an approved
  *authentication* template per language (owner step).
- **Flow.** The first send is SMS. After 30 seconds the screen offers *Send it on WhatsApp instead* (and, if no SMS driver
  is configured but WhatsApp is, WhatsApp is first). A provider that refuses or errors is reported to the error center
  (without the number) and the screen offers the other channel at once.
- The sender's response never shows whether a number exists (section 5.4).

### 5.3 Phone sign-up

1. The sign-up page has **E-mail | Phone**. Phone: name, phone, password twice, the terms. (The password rules do not change.)
2. The server validates and normalises the number, refuses a number that already has an account ("This number already has
   an account. Sign in instead."), and sends the code.
3. The person types the code (one field, `autocomplete="one-time-code"`, numeric keypad). On success the account is made,
   the person is signed in, the sign-in is recorded (`method = otp`, channel web or app) and the first-run card appears
   as it does today. Country and time zone are found exactly as in Release 1.

The app: the same two steps; a "Sending…" state, a countdown for *Send again*, a WhatsApp button, and the code field is
filled by the keyboard's autofill when the SMS arrives.

### 5.4 Phone sign-in, "send me a code", and recovery

- **Password + phone** works as password + e-mail (section 4).
- **Send me a code instead** (on the sign-in page): enter the phone, get a code, sign in. Records `method = otp`. If the
  person turned on authenticator two-step sign-in, it still asks for that code after this one (the second step is their
  choice and always honoured).
- **Forgot password** accepts a phone number: a code, then a new password. It is the *only* recovery path for an account with no e-mail. **New for both reset paths:** a
  successful reset also revokes the person's app tokens (today the e-mail reset ends trusted devices, because they are tied to
  the password hash, but leaves signed-in phones signed in; someone resetting a password usually fears the old one leaked).
- **No enumeration.** For sign-in and recovery the page answers the same ("If this number has an account, a code is on its
  way") whether or not it does, takes the same time, and **sends nothing to a number with no account** (no money spent
  on strangers). Sign-up does say "already has an account", as e-mail sign-up already does.

### 5.5 Adding, changing and removing a phone in Settings

Under **Sign-in methods** (web: Account → Security; app: Settings → Sign-in methods). Adding a number asks for a step-up
first, sends a code *to the new number*, and sets `phone_e164`, `phone_verified_at` and `phone`. Changing runs the same
steps; the old number stops working at once and a notice goes to the old number's WhatsApp/SMS only if the person had
text notices on. Removing is refused when it is the last way in (section 3.2). A number can belong to **one** account.

### 5.6 Guarding the cost

A code costs money and attackers know it (SMS pumping to premium numbers). All of these are in `config/qistas.php` under
`otp`, with environment overrides, and all are tested:

| Guard | Default |
|---|---|
| Countries a code may be sent to | the countries Qistas supports (`qistas.countries`), overridable with `QISTAS_OTP_COUNTRIES`; any other number is refused before anything is sent |
| Per number | 3 sends per 15 minutes, 8 per day (any purpose) |
| Per address | 10 sends per hour, 30 per day |
| Wrong tries | 5 per challenge; 10 per number per hour |
| Resend gap | 45 seconds |
| **Circuit breaker** | 300 sends per hour and 1,500 per day across the site. When it trips, phone entry points say "Codes are paused for a moment, use your e-mail instead", the error center gets an alert (and an instant e-mail to super admins, with the existing 3 per hour cap) and it resets itself an hour later. |
| Cloudflare Turnstile | **not in this release**; the guards above come first, and Turnstile is the named follow-up if abuse appears |

### 5.7 The senders cost money: telling the owner

The admin console gets a small **Codes** card: codes sent in the last 24 hours per channel, failures, and the breaker state
(read from `otp_challenges` and the budget counters, no extra table; challenges are removed a day after they expire, so the
card does not claim more than that). The cost per code is the owner's provider contract and is not shown.

## 6. Part C: Google and Microsoft

### 6.1 How, and why not a package

Both are OpenID Connect. The sign-in is the **authorization-code flow with PKCE, `state` and `nonce`**, a small
`App\Auth\Oidc` client built on Laravel's HTTP client, and `firebase/php-jwt` to check the returned ID token against the
provider's published keys (issuer, audience, expiry, nonce, signature algorithm RS256 only). This is about 250 lines we
can test completely with a generated key pair and `Http::fake`, against Socialite plus a community Microsoft provider that
adds two dependencies and hides the token checks. Scopes: `openid profile email` only (no Google verification review is
needed for those; the consent screen must be published to production).

Microsoft uses the `common` endpoint (work, school and personal accounts). Config lives in `config/services.php`
(`google`, `microsoft`), keys come from the Environment page (`GOOGLE_CLIENT_ID/SECRET`, `MICROSOFT_CLIENT_ID/SECRET`), and
each button appears only when its keys exist **and** `QISTAS_GOOGLE_SIGNIN` / `QISTAS_MICROSOFT_SIGNIN` is on.

### 6.2 The website

`GET /auth/{provider}/redirect` → provider → `GET /auth/{provider}/callback`. State and nonce live in the session and are
single-use. The callback follows the linking rules (6.4) and ends in the normal sign-in (two-step, trusted device, sign-in
record with `method = google|microsoft`, recorded after the answer like every other).

### 6.3 The phone app

Native Google and Microsoft SDKs need per-platform fingerprints and URL schemes and two plugins that drift. Instead the app
opens the **same website flow in the system browser** (`flutter_web_auth_2`, the only new app plugin) with a PKCE challenge,
and the callback page redirects to `qistas://auth/callback?code=…`. The app then calls
`POST /api/v1/auth/exchange {code, verifier}` and receives its token like any sign-in. The code is a random 40 characters,
valid **60 seconds**, usable **once** (`Cache::pull`), bound to the challenge hash, and holds an array, never an object.
The app sends `device_name` and the device hint as it does for a password sign-in.

### 6.4 Linking rules (the part that can go wrong)

Account takeover through "sign in with" is a known class of bug, so the rules are explicit. For a returning ID token with
subject `S`, e-mail `E` and the provider's claim whether `E` is verified:

| # | Situation | What happens |
|---|---|---|
| 1 | `(provider, S)` is already linked | Sign that person in. (A suspended account is refused, as everywhere.) |
| 2 | Not linked; **Google** says `E` is verified; an account has `E` as its **verified** e-mail; that account is not platform staff | Link it and sign in, **audit it**, and send that e-mail a notice ("Google was connected to your account. Not you? Secure it.") |
| 3 | Not linked; an account has `E` but it is **unverified**, or the provider is **Microsoft**, or the provider does not vouch for `E` | Do **not** link. Show "An account with this e-mail exists. Sign in to it to connect Google." After a normal sign-in the pending connection (kept 10 minutes in the session, tied to that browser) is offered and linked on confirmation. Someone who pre-registered the victim's e-mail without verifying it gets nothing, and the real owner proves control by the usual e-mail reset (which now also **marks the e-mail verified**, since the person just proved they own it). |
| 4 | Not linked; no account has `E` (or no `E`) | **New account.** A short confirm page shows the name and e-mail the provider gave, the terms checkbox and *Create my account*; nothing is created before that click. The account has no password, `email` only when it came with a verified claim (Google; Microsoft **personal** accounts, tenant `9188040d-6c67-4c5b-b112-36a304b66dad`, or when the optional `xms_edov` claim is true), and `email_verified_at` is set then. |
| 5 | The target account is platform staff | Always refused for sign-in and for linking. |
| 6 | A different account already holds `(provider, S)` | Linking refused ("That Google account is already connected to another Qistas account."). |

Why Microsoft is stricter: an Entra tenant administrator can set a user's `email` to any address without verifying it (the
"nOAuth" weakness), so a Microsoft e-mail is never used to *find* an account, only displayed. Identity is `(provider, S)`.

### 6.5 Settings

On the Sign-in methods page: *Connect Google / Connect Microsoft* (step-up first), *Disconnect* (section 3.2 decides), and
the list of what is connected with the date last used.

## 7. Cross-cutting

### 7.1 Sign-in record

Release 1's record gains `otp`, `google`, `microsoft`. A wrong code for an existing account is a failed row with
`method = otp` (same once-a-minute throttle). The record still never stores a typed value: **no phone number, no code, no
token**; the Security page and admin view show "by code", "by Google" and so on.

### 7.2 Privacy and stores

- New personal data: a verified **phone number**, and the provider's `sub`, name and e-mail. Deleting the account deletes
  them (the existing deletion already cascades from `users`; `user_identities` and the phone columns are on `users`).
- The terms and privacy pages, the Google Play **Data safety** form and the Apple privacy label need new sentences
  (phone number: collected, for account management; optional). They are **proposals in `docs/features/phone-and-social-signin.md`,
  not published**, as with Release 1 and 2.
- Google's OAuth consent screen needs the privacy URL and the app name; Microsoft's registration needs the same.
- **Apple review rule 4.8:** an iPhone app that offers a third-party login must also offer an equivalent privacy-preserving
  one (Sign in with Apple, or a service that meets Apple's three conditions). The iPhone app is on its way to TestFlight
  (`ios.yml`). So **the iPhone app does not show the Google and Microsoft buttons in this release**; the website and
  Android do. Phone-code and e-mail sign-in are not third-party logins and are offered everywhere. Sign in with Apple is a
  named follow-up, needing the owner's Apple developer account.

### 7.3 The app talks to the server about what is on offer

`GET /api/v1/auth/methods` (public, cached 5 minutes) answers `{phone: {sms, whatsapp}, google, microsoft}`. The app shows
only what is true, so a server without keys looks exactly like today, and a new app on an old server works.

### 7.4 Go-live check and error center

The go-live check gains two lines: **Phone codes** (PASS off or configured; FAIL when the driver is `log` in production or
only half the keys are present; WARN when no breaker limits are set) and **Google and Microsoft sign-in** (PASS off or
configured; WARN when a client secret's recorded expiry, `MICROSOFT_SECRET_EXPIRES`, is within 30 days; FAIL when a button is
on without its keys). Sender failures and a tripped breaker are error-center issues (source `site`), never with a number or
a code in the text.

### 7.5 Rollout and compatibility

Part A deploys with the database migration and the fixes and changes nothing anyone sees. Part B adds the app release
(1.31.0) because the new login and register screens need it; the website and API stay compatible with 1.30.0 and older, which
keep sending `email`. Part C adds the plugin (Android and the website first). Each part follows the Release 1 and 2 routine: a
database export, the panel deploy, read-only checks, and the app builds.

## 8. What the owner has to do (written up as a checklist in `docs/features/phone-and-social-signin.md`)

1. **A text-message provider.** Recommended: **Twilio** (one account does SMS and WhatsApp). Needs a funded account, a way
   to send to Gulf numbers (a messaging service or a registered sender name; some countries require registration, which
   can take days), and for WhatsApp a verified Meta business, a WhatsApp number and five approved *authentication*
   templates (en, ar, fr, es, ur). Keys go on the server's Environment page, never in chat. Any other provider is one new
   driver class.
2. **Google:** a Google Cloud project, the OAuth consent screen *published to production*, a Web client with the redirect
   `https://www.qistasme.com/auth/google/callback`.
3. **Microsoft:** an Entra app registration for *work, school and personal accounts*, redirect
   `https://www.qistasme.com/auth/microsoft/callback`, a client secret (note its expiry), and the optional claim `xms_edov`.
4. Approve the privacy sentences and update the store forms.
5. Decide whether to add **Sign in with Apple** (needs the Apple developer account; unlocks Google and Microsoft on iPhone).

None of this blocks building. Everything is built and tested against fakes first; the buttons appear when the keys do.

## 9. Not in this release

- Sign in with Apple, passkeys, Cloudflare Turnstile, sign-in by WhatsApp message (inbound), inviting a team member by phone
  (invitations stay link-only), multiple phones per account, a phone number change history, an SMS to the old number on
  change beyond what is above.
- A time-zone picker and fuller country names (Release 1's parked items).

## 10. Risks and how the design answers them

| Risk | Answer |
|---|---|
| SMS pumping / toll fraud | Section 5.6: country allow-list, per-number and per-address limits, circuit breaker, no sends to unknown numbers on sign-in |
| Account takeover through linking | Section 6.4; identity is `(provider, sub)`; Microsoft e-mail never trusted; staff excluded |
| SIM swap | Codes are one factor, same as a password; authenticator two-step (the person's choice) is still honoured; staff cannot use codes; new-device notices already exist |
| Locking people out | Section 3.2 invariant; recovery by code for phone accounts; step-up with a phone code for password-less deletion |
| Enumeration | Section 4 and 5.4: one answer, one cost, no sends to strangers |
| Old apps | `email` stays accepted; responses only add fields |
| Provider outage | Both channels offered; the e-mail path never depends on them; errors reach the error center |
| App store rejection (Apple 4.8) | Section 7.2: no third-party buttons on iPhone yet |

## 11. Decisions made for the owner (change any of them)

1. **Twilio** is the first real SMS and WhatsApp driver; the code is generated and checked by Qistas, not by the provider,
   so changing provider later changes one class.
2. A phone number can **sign in** (password, or a code), not only sign up.
3. Codes are **6 digits, 10 minutes, 5 tries**; SMS first, WhatsApp offered after 30 seconds.
4. One phone per account; one account per phone.
5. A code sign-in **still asks for the authenticator code** if the person turned that on.
6. Staff accounts stay **e-mail + password only**.
7. Codes go only to **supported countries** until `QISTAS_OTP_COUNTRIES` says otherwise.
8. Own OIDC client (not Socialite), `flutter_web_auth_2` for the app, a **confirm page** before a new social account exists.
9. **No Google or Microsoft buttons on iPhone** until Sign in with Apple exists.
10. A password reset by e-mail also marks the e-mail verified.
11. An account with no e-mail receives no e-mail at all; its notices go to push and, when the person has text notices on and a
    verified phone, to that phone.
12. A successful password reset (by e-mail or by code) **revokes the person's app tokens**, so a reset really ends a leaked
    password's reach.

## 12. Testing

TDD per task as in Releases 1 and 2. Fake `CodeSender` that captures the code; `Http::fake` for the Twilio and OIDC calls with
a generated RSA key pair (valid, expired, wrong audience, wrong nonce, `alg: none` and HS256 tokens must all fail);
PostgreSQL parity (unique nullable columns, the consume-once `UPDATE`, savepoints around refused inserts) through the PGlite
stand-in **and** the CI PostgreSQL job; the full Flutter suite; a browser pass on the sign-in, sign-up and Sign-in methods
pages at phone and desktop widths in light and dark, in English and Arabic (right-to-left); an end-to-end run on the
container demo with the `log` driver.

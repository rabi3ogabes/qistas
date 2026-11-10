# Release 3, Part A: accounts without an e-mail or a password. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `users.email` and `users.password` optional, add the columns and table the phone and social parts need, and fix everything the audit found that breaks or misbehaves when an account has no e-mail or no password, without changing anything a current user sees.

**Architecture:** One migration (nullable columns, `phone_e164`, `phone_verified_at`, `user_identities`). Two small services carry the new rules: `App\Auth\StepUp` (confirm it is really you by whatever the person has; a registry that Parts B and C add methods to) and `App\Auth\SignInMethods` (the "at least one working way in" rule). Existing actions (`UpdateUserProfileInformation`, `UpdateUserPassword`, `RequestAccountDeletion`, `ResetUserPassword`) and every place that prints or mails an e-mail are made null-safe. No phone or social UI exists yet; those arrive in Parts B and C and plug into the registry.

**Tech Stack:** Laravel 13 / PHP 8.4, Fortify, Sanctum, Pest 4, Pint, Larastan level 6; Flutter 3.41 (Riverpod, dio) for the app tolerance work.

**Spec:** `docs/superpowers/specs/2026-11-10-phone-google-microsoft-signin-design.md` (sections 3 and 7.3; the owner approved it on 2026-11-10). The audit behind section 3.6 is summarised there.

## Global Constraints

- **Blank e-mail is stored as NULL, never `''`.** `users.email` stays unique; NULL never collides.
- `users.password` NULL means "no password". A null-password account must fail a password sign-in with the same generic message and the same work as a wrong password, and must never raise an exception from a hash check.
- **Platform staff keep an e-mail and a password and cannot lose either** (`SignInMethods::canRemove` is false for `isPlatformAdmin()` users for `password` and `email`).
- An account always keeps at least one working way to sign in (spec 3.2); one class (`SignInMethods`) decides, every screen and endpoint asks it.
- Migrations run on SQLite (tests) and PostgreSQL (live). No database-specific SQL. In tests, wrap any insert that is *meant* to be refused in `DB::transaction(...)` (a failed statement aborts PostgreSQL's wrapping transaction). Column widths are enforced on PostgreSQL.
- UUID primary keys (`HasUuids`); `#[Fillable]` explicit on every model; `user_identities` is person-owned (no `tenant_id`), like `sign_in_events`. RLS needs no per-table work (`qistas:secure-database` covers every table).
- Never log, audit or store a typed e-mail, phone, password or code. The audit log names *fields* that changed, never values.
- API errors keep their shapes: validation 422 (`ValidationException`), `ApiException` codes for the rest. API changes only **add** fields or make existing ones nullable; apps up to 1.30.0 keep working (they always send `email` on profile saves and `password` on account deletion).
- User-facing strings live in `lang/`, English and Arabic complete (French, Spanish, Urdu fall back per key). Find where each string family lives from the existing keys; the translation tests must stay green.
- Conventions: business logic in `app/Actions` and `app/Auth`, not controllers; Pint clean (`php vendor/bin/pint`); Larastan level 6 clean (`php vendor/bin/phpstan analyse`); TDD: failing test first, watch it fail for the right reason.
- Commits end with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` (exactly that name). Commit with `git commit -F <msgfile> -- <paths>` (several agents may share the tree). **Never push** and never switch branches or reset: the controller pushes through the user's terminal tool and runs the PostgreSQL pass.
- All commands run from `web/` unless the step says `app/`. Flutter commands always use `--no-pub`.

## Rulings (decided while writing this plan; the spec is otherwise unchanged)

- **R1.** No `tenants.billing_email` column. With no e-mail on the owner, the Stripe customer is created without one and Stripe's own checkout form collects it. (Spec 3.6 said the page would ask and store it; Stripe already does that, and card billing is switched off. The card billing test-mode E2E, already owed, must include an e-mail-less owner.)
- **R2.** At most **one identity per provider per person**: `user_identities` also has a unique index on (`user_id`, `provider`).
- **R3.** The "Sign-in methods page" is a **card on the existing Security page** (read-only in this part), not a new route.
- **R4.** The sign-in box accepting a phone number, the throttle keys, the API `identifier` alias, password reset by phone and join-by-invitation without an e-mail belong to **Part B** (spec sections 4 and 5), not here.
- **R5.** The app gets tolerance and fixes here (models, profile, banner, team lines, delete screen wording) but **no version bump and no Sign-in methods screen**; those come with Part B (app 1.31.0).
- **R6.** Spec decisions 10 and 12 (a successful e-mail password reset marks the e-mail verified and revokes the person's app tokens) are implemented here, in Task 5, because they change an existing flow.

## Review Focus

Failure modes the spec implies that no single task's happy path would catch. Each has its test in the task named.

1. **Two accounts with no e-mail:** the password confirmation must never validate one person's password against another's hash (Task 2).
2. **Blank profile e-mail:** saving a blank must store NULL, two such saves must not collide on the unique index, and a blank must be refused (422, not 500) when it would remove the only way in (Task 4).
3. **Removing the last way in** is refused for every method, including through the profile form, and staff can never lose e-mail or password (Tasks 3 and 4).
4. **A null-password account** signs in with a generic failure, can still use a trusted-device cookie, and can delete its account only through a step-up method (Tasks 1, 5).
5. **Old apps:** a profile save that sends the current `email` keeps working, a save with no `email` key leaves it alone, and `password` on account deletion still works (Tasks 4, 5).
6. **No mail ever goes to nobody:** a notice, alert, test e-mail or digest must not throw, queue a job or consume a budget for an account with no address (Task 6).

---

### Task 1: Schema, user model, factory states

**Files:**
- Create: `database/migrations/2026_11_10_000100_accounts_without_email_or_password.php`
- Create: `app/Models/UserIdentity.php`
- Modify: `app/Models/User.php`, `app/Actions/RegisterTenantOwner.php`, `database/factories/UserFactory.php`
- Test: `tests/Feature/Auth/AccountsWithoutEmailTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces (later tasks and Parts B and C rely on these exact names):
  - Columns: `users.email` nullable; `users.password` nullable; `users.phone_e164` `string(16)` nullable unique; `users.phone_verified_at` nullable timestamp (cast `datetime`); table `user_identities` (`id` uuid pk, `user_id` uuid fk cascade, `provider` `string(16)`, `subject` `string(191)`, `tenant_ref` `string(64)` nullable, `email` `string(255)` nullable, `email_verified` boolean default false, `created_at`, `last_used_at` nullable; unique (`provider`,`subject`); unique (`user_id`,`provider`)).
  - `User::hasPassword(): bool`, `User::hasEmail(): bool`, `User::hasVerifiedPhone(): bool` (`phone_e164` and `phone_verified_at` both set), `User::contactLine(): string` (the e-mail, else `phone`, else `''`), `User::identities(): HasMany` of `UserIdentity`, and `hasVerifiedEmail()` that is **false when there is no e-mail**.
  - `UserIdentity` model (`#[Fillable]`: `provider, subject, tenant_ref, email, email_verified, last_used_at`; `user()` relation).
  - `RegisterTenantOwner::handle(array{name: string, email?: ?string, password?: ?string, ...})`: `email` and `password` optional and nullable.
  - Factory states on `UserFactory`: `emailless()`, `withoutPassword()`, `withVerifiedPhone(string $e164 = '+966501234567')` (sets `phone_e164`, `phone_verified_at`, and `phone` to the same number), `phoneOnly(string $e164 = '+966501234567')` (= `emailless()` + `withVerifiedPhone()`, password kept), `socialOnly(string $provider = 'google')` (= `emailless()` + `withoutPassword()` + one `UserIdentity` row created after the user).

- [ ] **Step 1: Write the failing tests** in `AccountsWithoutEmailTest.php`, one `it()` each:
  - "stores a blank e-mail as NULL so any number of accounts may have none": create two users via `User::factory()->emailless()` and one with `'email' => '   '`; assert all persist and `User::whereNull('email')->count()` is 3 (no `''` anywhere: `User::where('email', '')->count()` is 0).
  - "keeps an e-mail unique, case-insensitively, when there is one": second user with `'email' => 'A@Example.com'` after `'a@example.com'` throws `QueryException` (inside `DB::transaction`).
  - "refuses a second account with the same verified phone number" (`phone_e164` unique, inside `DB::transaction`); two users with NULL `phone_e164` are fine.
  - "never counts an account with no e-mail as verified": `emailless()->create(['email_verified_at' => now()])->hasVerifiedEmail()` is false; a normal factory user is true.
  - "sends no verification or reset notice to an account with no e-mail": with `Notification::fake()`, `$user->sendEmailVerificationNotification()`, `$user->sendPasswordResetNotification('t')` and `event(new Registered($user))` leave `Notification::assertNothingSent()` and `Queue::assertNothingPushed()`.
  - "tells a person with no password from one with a password": `hasPassword()` for `withoutPassword()` is false, for a default user true.
  - "gives the e-mail, else the phone, else nothing as the contact line".
  - "removes a person's identities with the person": `socialOnly()` user deleted ⇒ `UserIdentity::count()` is 0.
  - "allows one identity per provider per person and one person per identity": a second Google row for the same user, and the same (`provider`,`subject`) for another user, are both refused (inside `DB::transaction`).
  - "opens a workspace for an owner with no e-mail and no password": `app(RegisterTenantOwner::class)->handle(['name' => 'Layla', 'locale' => 'en'])` returns a `User` with `email` NULL and `password` NULL, who is the owner of a new tenant with the placeholder name.

- [ ] **Step 2: Run** `php vendor/bin/pest tests/Feature/Auth/AccountsWithoutEmailTest.php` — Expected: FAIL (columns and methods missing).

- [ ] **Step 3: Implement the migration** `accounts_without_email_or_password`: use `->nullable()->change()` for `email` and `password`; add the two phone columns and `user_identities`. `down()` drops what `up()` added and leaves `email`/`password` nullable (a comment says why: restoring NOT NULL would fail once null rows exist).

- [ ] **Step 4: Implement the model changes.**
  - `User::email()` mutator accepts `?string` and stores `null` for null or blank, otherwise the trimmed lower-cased value.
  - Override `hasVerifiedEmail()` (`return $this->email !== null && $this->email_verified_at !== null;`).
  - `sendEmailVerificationNotification()` and `sendPasswordResetNotification()` return at once when `email === null`.
  - Add the cast for `phone_verified_at`, the helpers listed above, `identities()`, and the `@property` docblock lines.
  - `RegisterTenantOwner`: widen the docblock type and create the user without `email`/`password` keys when null (keep the transaction and the audit as they are).
  - `UserFactory`: the states listed under Produces.

- [ ] **Step 5: Run the new test file, then the Auth and Account folders** (`php vendor/bin/pest tests/Feature/Auth tests/Feature/Account`) — Expected: all PASS.

- [ ] **Step 6: Pint, Larastan, commit** (`Part A: schema and model for accounts without an e-mail or a password`).

---

### Task 2: StepUp and the password-confirmation fix

**Files:**
- Create: `app/Auth/StepUpMethod.php`, `app/Auth/StepUp.php`, `app/Auth/PasswordStepUp.php`
- Modify: `app/Providers/AppServiceProvider.php`, `app/Providers/FortifyServiceProvider.php`, `resources/views/auth/confirm-password.blade.php`
- Test: `tests/Feature/Auth/StepUpTest.php`

**Interfaces:**
- Consumes: Task 1 (`User::hasPassword()`, `emailless()`/`phoneOnly()`/`socialOnly()` states).
- Produces:
  - `interface StepUpMethod { public function id(): string; public function offeredTo(User $user): bool; public function verify(User $user, string $proof): bool; }`
  - `final class StepUp { public function __construct(iterable $methods); /** @return list<string> */ public function methodsFor(User $user): array; public function verify(User $user, string $method, string $proof): bool; public function verifyPassword(User $user, ?string $password): bool; }` — `verify` is false (never throws) for an unknown method or one not offered to that person.
  - `PasswordStepUp` (id `'password'`, offered when `hasPassword()`, verifies with `Hash::check`).
  - The container tag **`step-up.methods`**; `StepUp` is built from `app()->tagged('step-up.methods')`. Parts B and C add their method classes to the tag.

- [ ] **Step 1: Write the failing tests** in `StepUpTest.php`:
  - "checks the password against the signed-in person even when two accounts have no e-mail": two `phoneOnly()` users with different known passwords (A created first); acting as B, `post(route('password.confirm.store'), ['password' => A's password])` leaves the session **without** `auth.password_confirmed_at`; B's own password sets it. (This fails today: Fortify looks the user up by e-mail and finds A.)
  - "does not let a person without a password through the confirmation": `socialOnly()` user, any password ⇒ not confirmed, no exception.
  - "offers only the methods a person has": a person with a password gets `['password']`; a `socialOnly()` person gets `[]`; with a fake `StepUpMethod` (id `'fake'`, offered to everyone) added to the tag in the test, both get it.
  - "never verifies an unknown or unoffered method": `verify($user, 'nope', 'x')` and `verify($socialOnly, 'password', 'x')` are false.
  - "explains on the confirmation page when the account has no password": `get(route('password.confirm'))` as a `socialOnly()` user shows the new notice and no password field; as a normal user shows the password field.
  - "still confirms a normal person by password" (regression: right password sets the timestamp, wrong one does not).

- [ ] **Step 2: Run** `php vendor/bin/pest tests/Feature/Auth/StepUpTest.php` — Expected: FAIL.

- [ ] **Step 3: Implement** the three classes and the registration: in `AppServiceProvider` tag `PasswordStepUp::class` as `step-up.methods` and bind `StepUp` from the tagged services. In `FortifyServiceProvider::boot()` call `Fortify::confirmPasswordsUsing(fn ($user, ?string $password): bool => $user instanceof User && app(StepUp::class)->verifyPassword($user, $password))` and pass `methods => app(StepUp::class)->methodsFor(auth()->user())` to the confirm view. The view shows the password form only when `'password'` is in `$methods`, otherwise one sentence (new string, English and Arabic) saying this account has no password and the person should sign in again.

- [ ] **Step 4: Run** the new file plus `tests/Feature/Auth/SecurityPageTest.php tests/Feature/Auth/TwoFactorTest.php tests/Feature/Account` — Expected: PASS.

- [ ] **Step 5: Pint, Larastan, commit** (`Part A: step-up confirmation, and password confirmation that checks the signed-in person`).

---

### Task 3: SignInMethods, the "at least one way in" rule

**Files:**
- Create: `app/Auth/SignInMethods.php`
- Test: `tests/Feature/Auth/SignInMethodsTest.php`

**Interfaces:**
- Consumes: Task 1.
- Produces: `final class SignInMethods` with `public const PASSWORD = 'password'; EMAIL = 'email'; PHONE = 'phone'; GOOGLE = 'google'; MICROSOFT = 'microsoft';` and
  - `public function for(User $user): array` → list of the *working* ways in, in the order password, phone, google, microsoft. **Password** works when `hasPassword()` and the person has an identifier to type (an e-mail or a verified phone). **Phone** (sign in with a code) works when `hasVerifiedPhone()`. **google/microsoft** work when a `UserIdentity` of that provider exists. An e-mail alone is not a way in.
  - `public function canRemove(User $user, string $method): bool` — true when, with that thing gone, `for()` would still list at least one way. Removing `email` can break `password` (no identifier left); removing `phone` can break `password` and `phone`; removing `password` or an identity removes only itself. For platform staff, `password` and `email` are **never** removable.
  - `public function assertCanRemove(User $user, string $method): void` — throws `ValidationException::withMessages([$method => __('This is your only way to sign in. Add another first.')])`.

- [ ] **Step 1: Write the failing tests** (a data-driven matrix, one row per case, expecting `for()` and `canRemove()` for each method):
  - e-mail + password only: `for` is `['password']`; cannot remove `email`, cannot remove `password`.
  - e-mail + password + verified phone: `for` is `['password','phone']`; can remove `email` (password still works through the phone), can remove `password`, can remove `phone`.
  - `phoneOnly()` (password + phone, no e-mail): cannot remove `phone` (password would have no identifier); can remove `password` (the code remains).
  - `socialOnly()` with no e-mail: `for` is `['google']`; cannot remove `google`; add a verified phone ⇒ can.
  - an unverified phone (`phone_e164` set, `phone_verified_at` null) counts for nothing.
  - platform admin with e-mail, password and a verified phone: `canRemove` is false for `password` and for `email`.
  - `assertCanRemove` throws a `ValidationException` whose message is the string above, and does nothing when removal is allowed.

- [ ] **Step 2: Run** the file — Expected: FAIL (class missing).
- [ ] **Step 3: Implement** `SignInMethods` (a private helper computes the ways for a hypothetical "without X" state from plain booleans, so `canRemove` never mutates the model).
- [ ] **Step 4: Run** the file — Expected: PASS. **Step 5:** Pint, Larastan, commit (`Part A: the at-least-one-way-in rule`).

---

### Task 4: Profile details with an optional e-mail

**Files:**
- Modify: `app/Actions/Fortify/UpdateUserProfileInformation.php`, `app/Http/Controllers/Workspace/ProfileController.php`, `resources/views/app/profile.blade.php`, the lang files for the new strings
- Test: `tests/Feature/Account/OptionalEmailTest.php`

**Interfaces:**
- Consumes: Tasks 1 and 3.
- Produces: new behaviour of `UpdateUserProfileInformation::update(User $user, array $input): void`:
  - `email` key **absent** → the e-mail is untouched (an old app always sends the current one; a new app may omit it).
  - `email` present and **blank** → remove it: `SignInMethods::assertCanRemove($user, 'email')` (rethrown into the `updateProfileInformation` error bag, key `email`), then store NULL and clear `email_verified_at`.
  - `email` present, non-blank, **different** → validated (`email:rfc`, max 255, unique ignoring self), stored lower-cased, unverified, verification notice sent (as today). Present and **equal** → untouched.
  - `name` stays required. `phone`: when `$user->hasVerifiedPhone()` and the sent phone differs from `$user->phone`, refuse with `__('Your phone number is verified. Change it under Sign-in methods.')` on key `phone`.
  - The web `ProfileController::update` flash message says "We sent a link to your new e-mail…" only when the new e-mail is non-null and changed.
  - `profile.blade.php`: the e-mail field is no longer `required` and is labelled optional; the confirmed / not-confirmed badge shows only when there is an e-mail.

- [ ] **Step 1: Write the failing tests** (`OptionalEmailTest.php`):
  - "saves a profile for a person who has no e-mail" (`phoneOnly()` user; `PUT /user/profile-information` with `name` and `email => ''`): no errors, `email` is NULL (not `''`).
  - "two people saving a blank e-mail do not collide": two `phoneOnly()` users each save a blank e-mail; both succeed.
  - "leaves the e-mail alone when none is sent": `PUT` with only `name` and `locale`: e-mail and verification unchanged.
  - "refuses to blank the only way in": a normal user sends `email => ''`: `assertSessionHasErrorsIn('updateProfileInformation', 'email')`, the e-mail is still there.
  - "lets a person with a verified phone remove the e-mail": `withVerifiedPhone()` user blanks it: e-mail NULL, `email_verified_at` NULL, `Notification::assertNothingSent()`.
  - "asks an account that had no e-mail to confirm the one it adds": `phoneOnly()` user sends `New@Example.com`: stored lower-cased, `email_verified_at` NULL, `VerifyEmailQueued` sent.
  - "keeps a verified phone number from being retyped on the profile".
  - API: "an old app that sends its current e-mail keeps working" (`PUT /api/v1/me` with the existing shape) and "answers 422, not 500, when a blank would remove the only way in".
  - The existing `tests/Feature/Auth/ProfileTest.php` and `tests/Feature/Account/ProfileTest.php` stay green unchanged.

- [ ] **Step 2: Run** the new file — Expected: FAIL.
- [ ] **Step 3: Implement** the action, controller message and view changes as specified; add the new strings (English and Arabic).
- [ ] **Step 4: Run** the new file and `tests/Feature/Auth tests/Feature/Account tests/Feature/Api` — Expected: PASS.
- [ ] **Step 5:** Pint, Larastan, commit (`Part A: the profile saves with an optional e-mail`).

---

### Task 5: Password, account deletion and password reset for people without a password

**Files:**
- Modify: `app/Actions/Fortify/UpdateUserPassword.php`, `app/Actions/Fortify/ResetUserPassword.php`, `app/Actions/Account/RequestAccountDeletion.php`, `app/Http/Controllers/Workspace/AccountDeletionController.php`, `app/Http/Controllers/Api/V1/AccountDeletionController.php`, `app/Http/Controllers/Api/V1/ProfileController.php` (only if its `only([...])` lists need the new keys), `resources/views/app/profile.blade.php` and the account-deletion view (wording), lang files
- Test: `tests/Feature/Account/StepUpAccountTest.php`; extend `tests/Feature/Account/AccountDeletionTest.php` and `tests/Feature/Auth/PasswordResetTest.php` where a case belongs there

**Interfaces:**
- Consumes: Tasks 1–3.
- Produces:
  - `UpdateUserPassword::update(User $user, array $input)`: if `$user->hasPassword()` the rule is unchanged (`current_password`). If not, the input must carry `step_up_method` and `step_up_proof` and `StepUp::verify(...)` must accept them (error key `step_up_proof`, message `__('We could not confirm it is you.')`); on success the first password is stored and `Audit::record('account.password_set', userId: $user->id)` is written. Fortify's `PUT /user/password` already passes the whole request.
  - `RequestAccountDeletion::handle(User, Tenant, array $input)`: `$input['password']` is checked as today when the person has a password; otherwise `$input['step_up_method']` + `$input['step_up_proof']` must verify through `StepUp` (error key `step_up_proof`). A person who has a password may also use a step-up method. Second-factor and the rest are unchanged. Both controllers validate `password` as `required_without:step_up_proof` and `step_up_method` as `required_with:step_up_proof`.
  - `ResetUserPassword::reset`: also sets `email_verified_at = now()` when it was null (the person just proved the mailbox is theirs), and deletes the person's app tokens (`tokens()->where('abilities', 'like', '%"app"%')`, the same rule as `logoutAll`).

- [ ] **Step 1: Write the failing tests:**
  - With a fake `StepUpMethod` (`'fake'`, accepts proof `'ok'`) added to the `step-up.methods` tag for the test:
    - "sets a first password after a step-up" (`socialOnly()` user: `PUT /user/password` with `step_up_method=fake`, `step_up_proof=ok`, strong password ⇒ `hasPassword()` true, audit row `account.password_set`); "refuses a wrong proof" (error in bag `updatePassword`, key `step_up_proof`, still no password).
    - "deletes a business with a step-up instead of a password" (owner `socialOnly()` + typed business name, via `POST /api/v1/account/deletion`) ⇒ 202 and the workspace is marked for deletion; wrong proof ⇒ 422 and nothing changes.
  - "still deletes with the password, for apps up to 1.30.0" (existing shape).
  - "answers a password sign-in for an account with no password exactly like a wrong password": `POST /api/v1/auth/login` for a `socialOnly()` user with an e-mail (create it with an e-mail so the lookup finds it) ⇒ 401 `invalid_credentials`, same body as a wrong password for an ordinary user, and a failed sign-in row is written.
  - "keeps a trusted device working for a person without a password": `TrustedDevice` cookie issued and read for a `socialOnly()` user.
  - "marks the e-mail verified after a reset by e-mail": unverified user completes `PasswordReset` ⇒ `email_verified_at` set.
  - "ends the person's phones after a reset": an `app`-ability token exists before, is gone after; a non-app (integration) token survives.

- [ ] **Step 2: Run** them — Expected: FAIL.
- [ ] **Step 3: Implement** as specified. Wording: the delete and password views say "Confirm with your password" for people with one; for people without one they show the available `StepUp` methods' ids only through a generic sentence (Parts B and C supply the real controls).
- [ ] **Step 4: Run** `tests/Feature/Account tests/Feature/Auth tests/Feature/Api` — Expected: PASS.
- [ ] **Step 5:** Pint, Larastan, commit (`Part A: first password, deletion and reset for accounts without a password`).

---

### Task 6: Nothing is mailed to nobody; staff stay e-mail and password

**Files:**
- Modify: `app/Notifications/Alerts/Notifier.php`, `app/Notifications/Channels.php` (only if a test shows a gap), `app/Errors/ErrorAlerts.php`, `app/Support/GoLiveCheck.php`, `app/Http/Controllers/Admin/MailController.php`, `app/Console/Commands/MakeAdminCommand.php`, `resources/views/app/notifications-settings.blade.php`, lang files
- Test: extend `tests/Feature/Notifications/NotificationCentreTest.php`, `tests/Feature/Errors/` (the alerts test), `tests/Feature/Deployment/GoLiveCheckTest.php`, the admin mail test and the make-admin command test (find them; create `tests/Feature/Auth/StaffNeedEmailAndPasswordTest.php` if the command has none)

**Interfaces:**
- Consumes: Task 1.
- Produces: no new public names; behaviour:
  - `Notifier`: the e-mail step is skipped when `$user->email === null` (the database and push work still happen; no exception).
  - `Channels`: the e-mail channel is off for a person with no e-mail (already true through `hasVerifiedEmail()`; the test pins it).
  - `ErrorAlerts::recipients()`: only active super admins **with an e-mail** (`whereNotNull('email')`).
  - `GoLiveCheck::admin()`: passes only for an active super admin with an e-mail **and** a password; the failure line says so.
  - `MailController::test`: for an account without an e-mail, answers with a warning (`__('Your account has no e-mail address to send the test to.')`) and sends nothing.
  - `MakeAdminCommand`: refuses to grant a role to an account whose `password` is NULL, printing `That account has no password. Staff sign in with an e-mail and a password; set one first.` and returning `FAILURE`; revoking is unchanged.
  - The notification-settings hint "Verify your e-mail…" shows only for a person with an unverified e-mail; a person without one is told they can add an e-mail under Your profile.

- [ ] **Step 1: Write the failing tests:** the Notifier case (a `phoneOnly()` user with the e-mail channel forced on: `Mail::assertNothingQueued()` and no exception, the in-app row still written); the channel case; "does not spend the instant-alert budget on a super admin with no address" (a super admin created with `emailless()` plus a normal one: one mail goes out, the budget counter moves once); the go-live case (an `emailless()` super admin alone ⇒ FAIL; with e-mail and password ⇒ PASS); the admin test-mail case; the make-admin case (null password refused; revoke still works).
- [ ] **Step 2: Run** them — Expected: FAIL (the Notifier, ErrorAlerts, GoLiveCheck, MailController and MakeAdmin cases).
- [ ] **Step 3: Implement** as listed. **Step 4: Run** `tests/Feature/Notifications tests/Feature/Errors tests/Feature/Deployment tests/Feature/Admin` — Expected: PASS.
- [ ] **Step 5:** Pint, Larastan, commit (`Part A: no mail to accounts without an address, staff keep e-mail and password`).

---

### Task 7: API payload, OpenAPI, Security card, feature doc

**Files:**
- Modify: `app/Http/Api/AccountPayload.php`, `app/Http/Controllers/SecurityController.php`, `resources/views/account/security.blade.php`, `docs/api/openapi.yaml`, lang files
- Create: `docs/features/accounts-without-email.md`
- Test: extend the existing `/me` API test (find it under `tests/Feature/Api`) and `tests/Feature/Auth/SecurityPageTest.php`

**Interfaces:**
- Consumes: Tasks 1–3.
- Produces: `AccountPayload::for()` adds, inside `user`: `has_password` (bool), `phone_verified` (bool), `sign_in_methods` (list of ids from `SignInMethods::for`), `step_up_methods` (list from `StepUp::methodsFor`); `email` may be `null` and `email_verified` is then `false`. The Security page gets a card "How you sign in" listing the ways in (words, not ids), whether a password is set and whether an e-mail is on file, each with a link to the profile page; read-only in this part.

- [ ] **Step 1: Write the failing tests:** payload for a normal user (`has_password` true, `sign_in_methods` `['password']`, `step_up_methods` `['password']`, `phone_verified` false), for `phoneOnly()` (`email` null, `email_verified` false, methods `['password','phone']`), for `socialOnly()` (`has_password` false, `step_up_methods` `[]`); the Security page renders for a `phoneOnly()` user without printing an empty e-mail and shows the card; the page for an ordinary user shows the password and e-mail rows.
- [ ] **Step 2: Run** them — Expected: FAIL.
- [ ] **Step 3: Implement** the payload and the card. Update `openapi.yaml`: `User.email` nullable, `PUT /me` no longer requires `email`, the four new `User` fields, `TeamMember.email` nullable. Write `docs/features/accounts-without-email.md`: what changed and why, the data model, the "at least one way in" rule, step-up, the staff exception, what Parts B and C add, and the owner-facing note that **nothing is visible yet**.
- [ ] **Step 4: Run** `tests/Feature/Api tests/Feature/Auth` — Expected: PASS.
- [ ] **Step 5:** Pint, Larastan, commit (`Part A: the account payload, the Security card and the feature doc`).

---

### Task 8: Showing, searching and billing people who have no e-mail

**Files:**
- Modify: `resources/views/components/layouts/app.blade.php` (the verification banner and the line under the name), `resources/views/components/layouts/admin.blade.php`, `resources/views/admin/businesses/index.blade.php` and `show.blade.php`, `resources/views/admin/billing/index.blade.php`, `resources/views/admin/support/index.blade.php`, `resources/views/admin/mail/index.blade.php`, `app/Http/Controllers/Admin/BusinessController.php`, `app/Actions/Team/TeamDirectory.php`, `app/Http/Controllers/Api/V1/TeamController.php`, `resources/views/app/team.blade.php`, `app/Billing/Card/CardCustomers.php`, `app/Billing/Card/LiveStripeGateway.php` (and the fake gateway), `app/Http/Controllers/Workspace/CardCheckoutController.php`, `resources/views/app/billing/checkout.blade.php`
- Test: new `tests/Feature/Admin/PeopleWithoutEmailTest.php`, extend the billing and team tests that cover those files

**Interfaces:**
- Consumes: Task 1 (`contactLine()`, `hasVerifiedEmail()`).
- Produces: `TeamDirectory::members()` returns `email` as `?string` (NULL, never `''`); the team API's `email` is nullable; `CardCustomers::for()` omits `email` from the Stripe customer payload when the owner has none (R1); the verification banner shows only when `$user->hasEmail() && ! $user->hasVerifiedEmail()`.

- [ ] **Step 1: Write the failing tests:** an owner with no e-mail sees no "Verify your e-mail" banner on the web app; the line under the name shows the contact line (their phone) in both layouts; the admin businesses list, a business page, billing, support and mail pages render for an e-mail-less owner and show "—" where the address would be; the admin search finds that owner by a typed digit-run of their verified phone and by business name; `TeamDirectory::members()` gives NULL and the team page and `GET /api/v1/team` render it; `CardCustomers::for()` with a fake gateway that records its input creates a customer **without** an `email` key for an e-mail-less owner and with one otherwise; the card checkout page for an e-mail-less owner does not print "Receipts go to".
- [ ] **Step 2: Run** them — Expected: FAIL.
- [ ] **Step 3: Implement** per file; use `User::contactLine()` for the layout lines; the admin owner-search adds `OR phone_e164 LIKE ?` with the typed digits when the term is mostly digits; keep every query portable across SQLite and PostgreSQL.
- [ ] **Step 4: Run** `tests/Feature/Admin tests/Feature/Billing tests/Feature/Team tests/Feature/Api` — Expected: PASS.
- [ ] **Step 5:** Pint, Larastan, commit (`Part A: pages, admin lists, team and card billing for people with no e-mail`).

---

### Task 9: The phone app tolerates accounts with no e-mail or password

**Files (all under `app/`):**
- Modify: `lib/data/models.dart` (`Account` and `TeamMember`), `lib/features/settings/profile_screen.dart`, `lib/features/settings/settings_screen.dart`, `lib/features/team/team_screen.dart`, `lib/app/shell.dart`, `lib/features/settings/delete_account_screen.dart`, `lib/data/qistas_api.dart`, the five `assets/i18n/*.json` files
- Test: new `test/features/account_without_email_test.dart`; update the existing profile, session, team and sign-up tests under `test/` (find them with a search for `email`) if they assert the old shape

**Interfaces:**
- Consumes: the payload fields from Task 7 (`email` nullable, `has_password`, `phone_verified`, `sign_in_methods`, `step_up_methods`).
- Produces: `Account` gains `bool hasPassword`, `bool phoneVerified`, `List<String> signInMethods`, `List<String> stepUpMethods`, and `bool get hasEmail` (`email.isNotEmpty`); an absent field means the old server's answer (`hasPassword` true, the lists empty). `TeamMember.email` stays a string and is empty when absent.

- [ ] **Step 1: Write the failing tests:** parsing a payload with `email: null` gives `hasEmail == false` and does not throw; a payload without the new fields parses with `hasPassword == true`; the profile screen for an account without an e-mail can save (name change) without the "enter your e-mail" error, shows no "confirmed" chip, and sends `email` blank only when the person cleared a non-empty field; the shell shows the "verify your e-mail" strip only when `hasEmail && !emailVerified`; the settings and team lines show nothing (no blank line) when there is no e-mail; the delete-account screen for `hasPassword == false` shows the new "contact support" sentence instead of a password field (Part B replaces it with the step-up control).
- [ ] **Step 2: Run** `flutter test --no-pub test/features/account_without_email_test.dart` (from `app/`) — Expected: FAIL.
- [ ] **Step 3: Implement.** Add the new strings to all five i18n files (English and Arabic complete, others may fall back as the existing files do).
- [ ] **Step 4: Run** `flutter analyze --no-pub` and `flutter test --no-pub` — Expected: no analyzer issues; all tests pass.
- [ ] **Step 5: Commit** (`Part A: the app tolerates accounts with no e-mail or password`). No version bump (R5).

---

## Part A is done when

The controller has run, on the final commit: the whole web suite; Pint and Larastan; the PostgreSQL stand-in pass (`DB_EMULATE_PREPARES=true`, `--exclude-group=db-errors`, `--no-pub` for Flutter) **and** the CI "Tests (PostgreSQL)" job; the Flutter suite; and a final whole-branch review on the most capable model. Then: spec section 14 (plan deviations R1–R6) is added, the memory notes are updated, the web is deployed dark with the panel `deploy` tool after a database export, and the live site is checked read-only (`/login`, `/register`, `/api/v1/auth/login` shapes unchanged).

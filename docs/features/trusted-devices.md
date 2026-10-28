# Two-factor for the admin team, trusted devices and a new setup key

Asked by the owner on 27 October 2026: signing in to the admin area asked for an authenticator code every time, and
the owner wanted it easier. First it asked far less often (trusted devices, below); then, on 28 October, the owner
made it optional for the team.

## Optional for the team, unless a super admin requires it

Two-factor is each person's choice, administrators included. Anyone turns it on or off on their own Security page
(`/account/security`, which asks for the password again first), and the admin area opens either way. Turning it off is
written to the audit log (`two_factor.disabled`). The Security page leads back to the admin area for the team and to the
dashboard for everyone else.

**Admin → Team security** (`/admin/security`) shows the rule and the team: who is a super admin or an admin, who is
suspended, and who uses two-factor ("1 of 3 people on the team use two-factor"). A super admin can switch the rule to
**Required**; from then on the admin area opens only with two-factor, and anyone without it is sent to set it up the
next time they open it (the Security page then says administrators must use it). A super admin cannot require it while
their own two-factor is off, so nobody locks the team out by accident. Every change of the rule is audited
(`admin.team_two_factor_changed`). Other staff can see the page but not change it.

Code: `App\Auth\TeamTwoFactor` (platform setting `security.require_team_two_factor`, off unless set),
`App\Http\Middleware\RequireTwoFactorForAdmins`, `App\Http\Controllers\Admin\TeamSecurityController`, gate
`manage-team-security`. Tests: `tests/Feature/Admin/TeamSecurityTest.php` (8 of 8 mutations caught).

## Don't ask again on this device

The code page (and the last step of setting two-factor authentication up) has a box, ticked by default:
**Don't ask for a code on this device for 30 days**, with the hint to untick it on a shared or public computer. After
a correct code with the box ticked, that browser asks only for the password for 30 days. The password is always
checked.

The browser keeps a cookie (one per person, encrypted, HTTP-only, SameSite=Lax) holding an expiry and a signature over
the person, that expiry, their password hash, their two-factor secret and the date of their last "Forget trusted
devices". So the trust ends, on every device at once, when:

- 30 days have passed;
- the password changes (including "sign out everywhere", which re-hashes it);
- two-factor authentication is turned off and on again (a new secret);
- the person presses **Forget trusted devices** on the Security page.

A device trusted by one person never skips the code for another. The Security page says until when the current device
skips the code. Audit log: `two_factor.device_trusted`, `two_factor.devices_forgotten`.

Code: `App\Auth\TrustedDevice`, `App\Actions\Auth\SkipTwoFactorOnTrustedDevice` (Fortify's sign-in step that asks for
the code), `App\Http\Responses\RemembersTrustedDevice` (Fortify's answer to a correct code), column
`users.trusted_devices_reset_at`. Tests: `tests/Feature/Auth/TrustedDeviceTest.php` (14 of 14 mutations caught).

## A new key while setting up

While setup is not confirmed, the Security page offers **Get a new key**: a fresh secret and QR code, and the old key
stops working. Use it when the key was shown to anyone else (pasted in a chat, a screenshot) or the scan went wrong. A
confirmed key is never replaced from there; turning two-factor authentication off and on again is the deliberate way.
Audit log: `two_factor.setup_restarted`.

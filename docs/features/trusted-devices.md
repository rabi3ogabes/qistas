# Trusted devices and a new setup key

Asked by the owner on 27 October 2026: signing in to the admin area asked for an authenticator code every time, and
the owner wanted it easier. Two-factor authentication stays required for administrators; it now asks far less often.

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
